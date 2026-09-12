<?php

declare(strict_types=1);

namespace Firma\Repo;

use Firma\Db;
use Firma\Roles;
use Firma\Uuid;

/**
 * Ksiegi, czlonkostwo i zaproszenia. Rola uzytkownika w ksiedze decyduje o dostepie (egzekwowane
 * serwerowo). Zaproszenie po e-mailu obsluguje przypadek, gdy zapraszany nie ma jeszcze konta —
 * przy pierwszym logowaniu ([claimInvitesFor]) zaproszenia zamieniaja sie w czlonkostwo.
 */
final class KsiegaRepo
{
    /** Zaproszenie wygasa po 14 dniach (liczone od utworzenia). */
    private const INVITE_TTL_SECONDS = 14 * 24 * 60 * 60;

    /**
     * Ksiegi uzytkownika wraz z rola.
     * @return list<array{id:string, nazwa:string, role:string, created_at:int}>
     */
    public static function listForUser(int $userId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT k.id, k.nazwa, m.role, k.created_at
             FROM ksiega k
             JOIN ksiega_membership m ON m.ksiega_id = k.id
             WHERE m.user_id = :uid
             ORDER BY k.created_at ASC'
        );
        $stmt->execute([':uid' => $userId]);
        /** @var list<array{id:string, nazwa:string, role:string, created_at:int}> $rows */
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['created_at'] = (int) $r['created_at'];
        }
        return $rows;
    }

    /**
     * Tworzy ksiege; zakladajacy zostaje OWNER. Atomowo (ksiega + czlonkostwo).
     * @return array{id:string, nazwa:string, role:string, created_at:int}
     */
    public static function create(int $userId, string $nazwa): array
    {
        $id = Uuid::v4();
        $now = time();
        Db::transaction(function ($pdo) use ($id, $userId, $nazwa, $now): void {
            $pdo->prepare(
                'INSERT INTO ksiega (id, owner_user_id, nazwa, created_at) VALUES (:id,:uid,:nazwa,:now)'
            )->execute([':id' => $id, ':uid' => $userId, ':nazwa' => $nazwa, ':now' => $now]);
            $pdo->prepare(
                'INSERT INTO ksiega_membership (ksiega_id, user_id, role) VALUES (:id,:uid,:role)'
            )->execute([':id' => $id, ':uid' => $userId, ':role' => Roles::OWNER]);
        });
        return ['id' => $id, 'nazwa' => $nazwa, 'role' => Roles::OWNER, 'created_at' => $now];
    }

    /** Rola uzytkownika w ksiedze albo null (brak dostepu). */
    public static function roleOf(string $ksiegaId, int $userId): ?string
    {
        $stmt = Db::pdo()->prepare(
            'SELECT role FROM ksiega_membership WHERE ksiega_id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $ksiegaId, ':uid' => $userId]);
        $role = $stmt->fetchColumn();
        return is_string($role) ? $role : null;
    }

    /**
     * Nadaje dostep do ksiegi wskazanemu e-mailowi. Gdy e-mail ma juz konto -> od razu czlonkostwo;
     * inaczej -> zaproszenie do zrealizowania przy pierwszym logowaniu.
     * @return array{kind:'membership'|'invite', email:string, role:string}
     */
    public static function shareByEmail(string $ksiegaId, string $email, string $role): array
    {
        $email = strtolower(trim($email));
        $user = self::findUserByEmail($email);
        if ($user !== null) {
            Db::pdo()->prepare(
                'INSERT INTO ksiega_membership (ksiega_id, user_id, role) VALUES (:id,:uid,:role)
                 ON DUPLICATE KEY UPDATE role = VALUES(role)'
            )->execute([':id' => $ksiegaId, ':uid' => $user['id'], ':role' => $role]);
            return ['kind' => 'membership', 'email' => $email, 'role' => $role];
        }

        Db::pdo()->prepare(
            'INSERT INTO ksiega_invite (id, ksiega_id, email, role, created_at, accepted)
             VALUES (:id,:kid,:email,:role,:now,0)'
        )->execute([
            ':id' => Uuid::v4(), ':kid' => $ksiegaId, ':email' => $email,
            ':role' => $role, ':now' => time(),
        ]);
        return ['kind' => 'invite', 'email' => $email, 'role' => $role];
    }

    /**
     * Realizuje oczekujace zaproszenia dla e-maila zalogowanego uzytkownika (wolane po logowaniu).
     * Zamienia je w czlonkostwo i oznacza jako zaakceptowane. Zwraca liczbe zrealizowanych.
     */
    public static function claimInvitesFor(int $userId, string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }
        $minCreated = time() - self::INVITE_TTL_SECONDS;
        return (int) Db::transaction(function ($pdo) use ($userId, $email, $minCreated): int {
            // Tylko NIEWYGASLE zaproszenia (created_at w oknie TTL) — stare zapomniane nie dzialaja.
            $sel = $pdo->prepare(
                'SELECT id, ksiega_id, role FROM ksiega_invite
                 WHERE email = :email AND accepted = 0 AND created_at >= :minCreated'
            );
            $sel->execute([':email' => $email, ':minCreated' => $minCreated]);
            /** @var list<array{id:string, ksiega_id:string, role:string}> $invites */
            $invites = $sel->fetchAll();
            $count = 0;
            foreach ($invites as $inv) {
                $pdo->prepare(
                    'INSERT INTO ksiega_membership (ksiega_id, user_id, role) VALUES (:kid,:uid,:role)
                     ON DUPLICATE KEY UPDATE role = VALUES(role)'
                )->execute([':kid' => $inv['ksiega_id'], ':uid' => $userId, ':role' => $inv['role']]);
                $pdo->prepare('UPDATE ksiega_invite SET accepted = 1 WHERE id = :id')
                    ->execute([':id' => $inv['id']]);
                $count++;
            }
            return $count;
        });
    }

    /**
     * Czlonkowie ksiegi (do wgladu dla OWNER).
     * @return list<array{user_id:int, email:string, role:string}>
     */
    public static function members(string $ksiegaId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT m.user_id, u.email, m.role
             FROM ksiega_membership m JOIN users u ON u.id = m.user_id
             WHERE m.ksiega_id = :id ORDER BY m.role DESC, u.email ASC'
        );
        $stmt->execute([':id' => $ksiegaId]);
        /** @var list<array{user_id:int, email:string, role:string}> $rows */
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['user_id'] = (int) $r['user_id'];
        }
        return $rows;
    }

    /** @return array{id:int, email:string}|null */
    private static function findUserByEmail(string $email): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT id, email FROM users WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute([':email' => strtolower($email)]);
        /** @var array{id:int, email:string}|false $row */
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }
}
