<?php

declare(strict_types=1);

namespace Firma;

/**
 * Role w ksiedze i ich hierarchia. VIEWER < EDITOR < OWNER. Egzekwowanie po stronie serwera
 * (klient nie jest zaufany): [requireAtLeast] rzuca 403, gdy rola za niska, albo 404, gdy
 * uzytkownik w ogole nie ma dostepu (nie zdradzamy istnienia cudzej ksiegi).
 */
final class Roles
{
    public const OWNER  = 'OWNER';
    public const EDITOR = 'EDITOR';
    public const VIEWER = 'VIEWER';

    private const RANK = [self::VIEWER => 1, self::EDITOR => 2, self::OWNER => 3];

    public static function rank(?string $role): int
    {
        return $role !== null ? (self::RANK[$role] ?? 0) : 0;
    }

    public static function atLeast(?string $role, string $min): bool
    {
        return self::rank($role) >= self::rank($min);
    }

    /** Poprawna wartosc roli do nadania czlonkowi (nie pozwalamy nadac OWNER przez zaproszenie). */
    public static function isAssignable(string $role): bool
    {
        return $role === self::EDITOR || $role === self::VIEWER;
    }

    /**
     * Sprawdza, ze [role] (rola uzytkownika w ksiedze, null = brak dostepu) spelnia minimum [min].
     * Brak dostepu -> 404 (ukrycie istnienia zasobu); za niska rola -> 403.
     */
    public static function requireAtLeast(?string $role, string $min): void
    {
        if ($role === null) {
            throw new HttpError(404, 'not_found', 'Nie znaleziono ksiegi.');
        }
        if (!self::atLeast($role, $min)) {
            throw new HttpError(403, 'forbidden', 'Brak uprawnien do tej operacji.');
        }
    }
}
