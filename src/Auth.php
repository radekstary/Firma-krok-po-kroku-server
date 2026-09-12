<?php

declare(strict_types=1);

namespace Firma;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Sesja aplikacji: po zalogowaniu Google wydajemy **wlasny** JWT (HS256, sekret z konfiguracji),
 * a kolejne zadania autoryzujemy tym tokenem — NIE ID tokenem Google (ktory jest krotkozyjacy
 * i przeznaczony do jednorazowej wymiany). Middleware [requireUserId] zwraca id zalogowanego
 * uzytkownika albo rzuca 401.
 */
final class Auth
{
    private const ALG = 'HS256';

    /** Wydaje sesyjny token dla uzytkownika (id z tabeli users). */
    public static function issueSessionToken(int $userId): string
    {
        $now = time();
        $ttl = (int) Config::get('session_ttl', 30 * 24 * 60 * 60);
        $payload = [
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];
        return JWT::encode($payload, self::secret(), self::ALG);
    }

    /**
     * Dekoduje sesyjny token i zwraca id uzytkownika. Rzuca [HttpError] 401, gdy token brak,
     * niepoprawny albo przeterminowany.
     */
    public static function userIdFromToken(string $token): int
    {
        try {
            $payload = JWT::decode($token, new Key(self::secret(), self::ALG));
        } catch (\Throwable) {
            throw new HttpError(401, 'invalid_session', 'Sesja wygasla lub jest nieprawidlowa.');
        }
        $sub = ((array) $payload)['sub'] ?? null;
        if (!is_int($sub) && !(is_string($sub) && ctype_digit($sub))) {
            throw new HttpError(401, 'invalid_session', 'Sesja bez identyfikatora uzytkownika.');
        }
        return (int) $sub;
    }

    /**
     * Middleware autoryzacji: czyta `Authorization: Bearer <token>` z zadania i zwraca id
     * uzytkownika. Chronione trasy wolaja to na wejsciu.
     */
    public static function requireUserId(): int
    {
        $token = Http::bearerToken();
        if ($token === null) {
            throw new HttpError(401, 'missing_token', 'Brak tokenu sesji (Authorization: Bearer).');
        }
        return self::userIdFromToken($token);
    }

    private static function secret(): string
    {
        $secret = (string) Config::get('jwt_secret', '');
        if (strlen($secret) < 32) {
            throw new HttpError(500, 'config_error', 'Sekret JWT za krotki (min 32 znaki) lub nieustawiony.');
        }
        return $secret;
    }
}
