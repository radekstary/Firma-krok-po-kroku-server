<?php

declare(strict_types=1);

/**
 * Front controller. Wszystkie zadania trafiaja tu (przez .htaccess) i sa rozdzielane przez router.
 * Faza 1 (szkielet): jedyna dzialajaca trasa to `/api/health`. Kolejne slice'y dokladaja auth,
 * ksiegi i sync — kazda jako osobna trasa, bez zmiany tego pliku poza rejestracja.
 */

use Firma\Config;
use Firma\Db;
use Firma\Http;
use Firma\HttpError;
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

try {
    $router->dispatch(Http::method(), Http::path());
} catch (HttpError $e) {
    Http::error($e->status, $e->errorCode, $e->getMessage());
} catch (\Throwable $e) {
    // Nie ujawniamy szczegolow wyjatku klientowi; log serwera zachowuje slad.
    error_log('[firma-server] ' . $e->getMessage());
    Http::error(500, 'internal_error', 'Wewnetrzny blad serwera.');
}
