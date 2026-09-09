<?php

declare(strict_types=1);

namespace Firma\Repo;

use Firma\Db;

/**
 * Uzytkownicy tozsamosci Google. Klucz tozsamosci to `google_sub` (stabilny id konta Google),
 * a nie e-mail (e-mail bywa zmieniany). Logowanie robi upsert: przy pierwszym razie zaklada
 * konto, przy kolejnych odswieza e-mail.
 */
final class UserRepo
{
    /**
     * Zaklada lub odswieza uzytkownika po `google_sub`; zwraca rekord (id, email).
     * @return array{id:int, email:string, google_sub:string}
     */
    public static function upsertByGoogleSub(string $googleSub, string $email): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO users (google_sub, email, created_at) VALUES (:sub, :email, :now)
             ON DUPLICATE KEY UPDATE email = VALUES(email)'
        );
        $stmt->execute([':sub' => $googleSub, ':email' => $email, ':now' => time()]);

        $find = $pdo->prepare('SELECT id, email, google_sub FROM users WHERE google_sub = :sub');
        $find->execute([':sub' => $googleSub]);
        /** @var array{id:int, email:string, google_sub:string}|false $row */
        $row = $find->fetch();
        if ($row === false) {
            throw new \RuntimeException('Nie udalo sie odczytac uzytkownika po upsercie.');
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @return array{id:int, email:string, google_sub:string}|null */
    public static function findById(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT id, email, google_sub FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        /** @var array{id:int, email:string, google_sub:string}|false $row */
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }
}
