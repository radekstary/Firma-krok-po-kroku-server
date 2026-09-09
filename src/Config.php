<?php

declare(strict_types=1);

namespace Firma;

/**
 * Ladowanie konfiguracji z `config.local.php` (poza repo). Rzuca czytelnie, gdy pliku brak —
 * to typowy blad przy pierwszym wdrozeniu (zapomniany `cp config.local.php.example config.local.php`).
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$data === null) {
            $path = dirname(__DIR__) . '/config.local.php';
            if (!is_file($path)) {
                throw new \RuntimeException(
                    'Brak config.local.php — skopiuj config.local.php.example i uzupelnij dane.'
                );
            }
            /** @var array<string, mixed> $loaded */
            $loaded = require $path;
            self::$data = $loaded;
        }
        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public static function section(string $key): array
    {
        $value = self::get($key, []);
        return is_array($value) ? $value : [];
    }
}
