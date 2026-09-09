<?php

declare(strict_types=1);

namespace Firma;

/**
 * Generator UUID v4 (losowy). Serwer nadaje identyfikatory ksiag i zaproszen; wpisy KPiR maja
 * UUID nadawany po stronie klienta (rezerwacja) i tu tylko przyjmowany.
 */
final class Uuid
{
    public static function v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // wersja 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // wariant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Czy ciag wyglada jak UUID (walidacja wejscia z klienta). */
    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
