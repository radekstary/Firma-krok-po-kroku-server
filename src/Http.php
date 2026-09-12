<?php

declare(strict_types=1);

namespace Firma;

/**
 * Cienka warstwa HTTP: odczyt zadania (metoda, sciezka, naglowki, JSON body) i wysylka odpowiedzi
 * JSON z wlasciwym kodem. Trzyma format bledu w jednym miejscu, zeby klient zawsze dostawal
 * `{ "error": "...", "message": "..." }`.
 */
final class Http
{
    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Sciezka bez query-string, bez prefiksu skryptu. Np. "/api/health". */
    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? rtrim($path, '/') ?: '/' : '/';
    }

    /** Wartosc naglowka (case-insensitive) albo null. */
    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /** Token z naglowka `Authorization: Bearer <token>` albo null. */
    public static function bearerToken(): ?string
    {
        $auth = self::header('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Body zadania jako tablica (JSON). Rzuca [HttpError] 422 przy niepoprawnym JSON.
     * @return array<string, mixed>
     */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpError(422, 'invalid_json', 'Cialo zadania nie jest poprawnym obiektem JSON.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Odpowiedzi API niosa dane osobowe i token sesji — nie wolno ich cache'owac.
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function error(int $status, string $error, string $message): void
    {
        self::json(['error' => $error, 'message' => $message], $status);
    }
}
