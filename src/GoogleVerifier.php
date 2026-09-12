<?php

declare(strict_types=1);

namespace Firma;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Weryfikacja **ID tokenu Google** (RS256) przy logowaniu. Nie piszemy krypto recznie — podpis
 * sprawdza sprawdzona biblioteka `firebase/php-jwt` na kluczach publicznych Google (JWKS).
 * Po weryfikacji podpisu i czasu waznosci dodatkowo sprawdzamy `aud` (nasz GOOGLE_CLIENT_ID)
 * i `iss` — bez tego token wystawiony dla innej aplikacji przeszedlby jako "wazny podpisem".
 *
 * JWKS pobieramy raz i cache'ujemy w pliku (TTL), bo na hostingu wspoldzielonym sieciowy strzal
 * do Google przy KAZDYM logowaniu bylby wolny i zawodny.
 */
final class GoogleVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const CACHE_TTL = 3600; // 1 h — Google rotuje klucze rzadko, naglowek max-age bywa dluzszy
    private const ISSUERS   = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * Zwraca zaufane roszczenia tokenu (`sub`, `email`, ...) albo rzuca [HttpError] 401.
     * @return array<string, mixed>
     */
    public static function verify(string $idToken): array
    {
        $clientId = (string) Config::get('google_client_id', '');
        if ($clientId === '') {
            throw new HttpError(500, 'config_error', 'Brak google_client_id w konfiguracji serwera.');
        }

        try {
            $keys = self::keys();
            $payload = JWT::decode($idToken, $keys); // sprawdza podpis + exp/nbf/iat
        } catch (\Throwable $e) {
            throw new HttpError(401, 'invalid_id_token', 'Nieprawidlowy token logowania Google.');
        }

        $claims = (array) $payload;

        $aud = (string) ($claims['aud'] ?? '');
        if (!hash_equals($clientId, $aud)) {
            throw new HttpError(401, 'invalid_audience', 'Token wystawiony dla innej aplikacji.');
        }

        $iss = (string) ($claims['iss'] ?? '');
        if (!in_array($iss, self::ISSUERS, true)) {
            throw new HttpError(401, 'invalid_issuer', 'Nieprawidlowy wystawca tokenu.');
        }

        if (($claims['sub'] ?? '') === '') {
            throw new HttpError(401, 'invalid_id_token', 'Token bez identyfikatora uzytkownika.');
        }

        // Tozsamosc i realizacja zaproszen opieraja sie na e-mailu — musi byc obecny i POTWIERDZONY
        // przez Google. Bez tego konto z niezweryfikowanym adresem mogloby przejac cudze zaproszenie.
        $email = (string) ($claims['email'] ?? '');
        $verified = ($claims['email_verified'] ?? null);
        $isVerified = $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';
        if ($email === '' || !$isVerified) {
            throw new HttpError(401, 'email_unverified', 'Wymagane konto Google ze zweryfikowanym adresem e-mail.');
        }

        return $claims;
    }

    /**
     * Zestaw kluczy publicznych Google (JWKS) z cache'em plikowym.
     * @return array<string, Key>
     */
    private static function keys(): array
    {
        $jwks = self::fetchJwks();
        return JWK::parseKeySet($jwks);
    }

    /** @return array<string, mixed> */
    private static function fetchJwks(): array
    {
        $cacheFile = sys_get_temp_dir() . '/firma_google_jwks.json';
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['keys'])) {
                /** @var array<string, mixed> $cached */
                return $cached;
            }
        }

        $raw = self::httpGet(self::CERTS_URL);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['keys'])) {
            // Awaria pobrania — sprobuj przeterminowanego cache, zeby logowanie nie padlo od razu.
            if (is_file($cacheFile)) {
                $stale = json_decode((string) file_get_contents($cacheFile), true);
                if (is_array($stale) && isset($stale['keys'])) {
                    /** @var array<string, mixed> $stale */
                    return $stale;
                }
            }
            throw new HttpError(503, 'jwks_unavailable', 'Nie udalo sie pobrac kluczy Google.');
        }

        @file_put_contents($cacheFile, $raw, LOCK_EX);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code !== 200) {
            throw new HttpError(503, 'jwks_unavailable', 'Nie udalo sie pobrac kluczy Google.');
        }
        return $body;
    }
}
