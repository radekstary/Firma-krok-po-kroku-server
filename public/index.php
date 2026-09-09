<?php

declare(strict_types=1);

/**
 * Front controller. Wszystkie zadania trafiaja tu (przez .htaccess) i sa rozdzielane przez router.
 * Faza 1 (szkielet): jedyna dzialajaca trasa to `/api/health`. Kolejne slice'y dokladaja auth,
 * ksiegi i sync — kazda jako osobna trasa, bez zmiany tego pliku poza rejestracja.
 */

use Firma\Auth;
use Firma\Config;
use Firma\Db;
use Firma\GoogleVerifier;
use Firma\Http;
use Firma\HttpError;
use Firma\Repo\KpirRepo;
use Firma\Repo\KsiegaRepo;
use Firma\Repo\UserRepo;
use Firma\Roles;
use Firma\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

// --- Wymuszenie HTTPS (RODO): przekierowanie 301 na wersje szyfrowana. .htaccess robi to samo,
//     ale trzymamy warstwe aplikacyjna na wypadek innego serwera niz Apache. ---
try {
    $forceHttps = (bool) Config::get('force_https', true);
} catch (\Throwable) {
    // Brak config.local.php — nie blokuj samego /health diagnostycznie, ale nizej i tak zwroci blad DB.
    $forceHttps = false;
}
$isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($forceHttps && !$isHttps) {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($host !== '') {
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
}

$router = new Router();

/**
 * GET /api/health — sonda diagnostyczna. Sprawdza, ze aplikacja wstaje i ma polaczenie z baza.
 * Zwraca `db: "ok"` albo `db: "error"` (bez szczegolow wyjatku, zeby nie wyciekaly do klienta).
 */
$router->add('GET', '/api/health', static function (): void {
    $db = 'unknown';
    try {
        Db::pdo()->query('SELECT 1');
        $db = 'ok';
    } catch (\Throwable) {
        $db = 'error';
    }
    Http::json([
        'status' => 'ok',
        'time'   => gmdate('c'),
        'db'     => $db,
    ]);
});

/**
 * POST /api/auth/google — wymiana ID tokenu Google na sesyjny JWT aplikacji.
 * Body: { "idToken": "<Google ID token>" }. Weryfikuje token u zrodla (podpis JWKS, aud, iss),
 * upsertuje uzytkownika po `sub` i zwraca { sessionToken, user }.
 */
$router->add('POST', '/api/auth/google', static function (): void {
    $body = Http::jsonBody();
    $idToken = trim((string) ($body['idToken'] ?? ''));
    if ($idToken === '') {
        throw new HttpError(422, 'missing_id_token', 'Brak pola idToken.');
    }

    $claims = GoogleVerifier::verify($idToken);
    $sub   = (string) $claims['sub'];
    $email = (string) ($claims['email'] ?? '');

    $user = UserRepo::upsertByGoogleSub($sub, $email);
    // Zrealizuj zaproszenia wystawione na ten e-mail, zanim konto istnialo.
    KsiegaRepo::claimInvitesFor($user['id'], $user['email']);
    $sessionToken = Auth::issueSessionToken($user['id']);

    Http::json([
        'sessionToken' => $sessionToken,
        'user' => [
            'id'    => $user['id'],
            'email' => $user['email'],
        ],
    ]);
});

/**
 * GET /api/me — kto jestem (test middleware autoryzacji). Wymaga sesyjnego tokenu.
 */
$router->add('GET', '/api/me', static function (): void {
    $userId = Auth::requireUserId();
    $user = UserRepo::findById($userId);
    if ($user === null) {
        throw new HttpError(401, 'invalid_session', 'Konto nie istnieje.');
    }
    Http::json(['id' => $user['id'], 'email' => $user['email']]);
});

/**
 * GET /api/ksiegi — ksiegi zalogowanego uzytkownika (z jego rola).
 */
$router->add('GET', '/api/ksiegi', static function (): void {
    $userId = Auth::requireUserId();
    Http::json(['ksiegi' => KsiegaRepo::listForUser($userId)]);
});

/**
 * POST /api/ksiegi — { "nazwa": "..." } → nowa ksiega; zakladajacy = OWNER.
 */
$router->add('POST', '/api/ksiegi', static function (): void {
    $userId = Auth::requireUserId();
    $body = Http::jsonBody();
    $nazwa = trim((string) ($body['nazwa'] ?? ''));
    if ($nazwa === '') {
        throw new HttpError(422, 'missing_nazwa', 'Nazwa ksiegi jest wymagana.');
    }
    Http::json(['ksiega' => KsiegaRepo::create($userId, $nazwa)], 201);
});

/**
 * GET /api/ksiegi/{id}/czlonkowie — lista czlonkow (tylko OWNER).
 */
$router->add('GET', '/api/ksiegi/{id}/czlonkowie', static function (array $p): void {
    $userId = Auth::requireUserId();
    Roles::requireAtLeast(KsiegaRepo::roleOf($p['id'], $userId), Roles::OWNER);
    Http::json(['czlonkowie' => KsiegaRepo::members($p['id'])]);
});

/**
 * POST /api/ksiegi/{id}/czlonkowie — { "email": "...", "role": "EDITOR|VIEWER" } (tylko OWNER).
 * Gdy e-mail ma konto → czlonkostwo od razu; inaczej → zaproszenie do realizacji przy logowaniu.
 */
$router->add('POST', '/api/ksiegi/{id}/czlonkowie', static function (array $p): void {
    $userId = Auth::requireUserId();
    Roles::requireAtLeast(KsiegaRepo::roleOf($p['id'], $userId), Roles::OWNER);
    $body = Http::jsonBody();
    $email = trim((string) ($body['email'] ?? ''));
    $role  = strtoupper(trim((string) ($body['role'] ?? '')));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new HttpError(422, 'invalid_email', 'Wymagany poprawny adres e-mail.');
    }
    if (!Roles::isAssignable($role)) {
        throw new HttpError(422, 'invalid_role', 'Rola musi byc EDITOR albo VIEWER.');
    }
    Http::json(['wynik' => KsiegaRepo::shareByEmail($p['id'], $email, $role)], 201);
});

/**
 * POST /api/ksiegi/{id}/wpisy — rezerwacja/utworzenie wpisu (body = KpirEntryDto).
 * Idempotentne po id: ponowienie zwraca istniejacy wpis, nie duplikat. Rola >= EDITOR.
 */
$router->add('POST', '/api/ksiegi/{id}/wpisy', static function (array $p): void {
    $userId = Auth::requireUserId();
    Roles::requireAtLeast(KsiegaRepo::roleOf($p['id'], $userId), Roles::EDITOR);
    $dto = Http::jsonBody();
    if (isset($dto['ksiegaId']) && (string) $dto['ksiegaId'] !== $p['id']) {
        throw new HttpError(422, 'ksiega_mismatch', 'ksiegaId w tresci nie zgadza sie ze sciezka.');
    }
    Http::json(['entry' => KpirRepo::reserve($p['id'], $dto)], 201);
});

/**
 * PUT /api/ksiegi/{id}/wpisy/{entryId} — edycja/tombstone z optymistyczna kontrola wersji.
 * Naglowek `If-Match: <rev>` obowiazkowy. 409 przy niezgodnosci (zwraca aktualny stan). Rola >= EDITOR.
 */
$router->add('PUT', '/api/ksiegi/{id}/wpisy/{entryId}', static function (array $p): void {
    $userId = Auth::requireUserId();
    Roles::requireAtLeast(KsiegaRepo::roleOf($p['id'], $userId), Roles::EDITOR);

    $ifMatch = Http::header('If-Match');
    if ($ifMatch === null || !ctype_digit(trim($ifMatch))) {
        throw new HttpError(428, 'missing_if_match', 'Wymagany naglowek If-Match z numerem rev.');
    }
    $expectedRev = (int) trim($ifMatch);

    $dto = Http::jsonBody();
    if (isset($dto['ksiegaId']) && (string) $dto['ksiegaId'] !== $p['id']) {
        throw new HttpError(422, 'ksiega_mismatch', 'ksiegaId w tresci nie zgadza sie ze sciezka.');
    }
    if (isset($dto['id']) && (string) $dto['id'] !== $p['entryId']) {
        throw new HttpError(422, 'id_mismatch', 'id w tresci nie zgadza sie ze sciezka.');
    }

    $result = KpirRepo::save($p['id'], $p['entryId'], $dto, $expectedRev);
    switch ($result['status']) {
        case 'ok':
            Http::json(['entry' => $result['entry']]);
            return;
        case 'conflict':
            Http::json(['error' => 'version_conflict', 'current' => $result['entry']], 409);
            return;
        default:
            throw new HttpError(404, 'not_found', 'Nie znaleziono wpisu.');
    }
});

/**
 * GET /api/ksiegi/{id}/wpisy?since=<server_seq> — delta zmian (z tombstonami) + nextCursor.
 * Rola >= VIEWER.
 */
$router->add('GET', '/api/ksiegi/{id}/wpisy', static function (array $p): void {
    $userId = Auth::requireUserId();
    Roles::requireAtLeast(KsiegaRepo::roleOf($p['id'], $userId), Roles::VIEWER);
    $since = isset($_GET['since']) ? (string) $_GET['since'] : null;
    $delta = KpirRepo::pull($p['id'], $since);
    Http::json($delta);
});

try {
    $router->dispatch(Http::method(), Http::path());
} catch (HttpError $e) {
    Http::error($e->status, $e->errorCode, $e->getMessage());
} catch (\Throwable $e) {
    // Nie ujawniamy szczegolow wyjatku klientowi; log serwera zachowuje slad.
    error_log('[firma-server] ' . $e->getMessage());
    Http::error(500, 'internal_error', 'Wewnetrzny blad serwera.');
}
