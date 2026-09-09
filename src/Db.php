<?php

declare(strict_types=1);

namespace Firma;

use PDO;

/**
 * Dostep do bazy przez PDO. Wylacznie **prepared statements** (bez sklejania SQL) — ochrona przed
 * SQL injection wynika z uzycia parametrow, nie z ucieczki. Pojedyncze polaczenie na zadanie
 * (PHP jest request-owy), leniwie inicjowane.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = Config::section('db');
            $name    = (string) ($cfg['name'] ?? '');
            $charset = (string) ($cfg['charset'] ?? 'utf8mb4');
            $socket  = (string) ($cfg['socket'] ?? '');
            // Niektore hostingi wspoldzielone lacza sie po gniezdzie unix zamiast po TCP.
            $where = $socket !== ''
                ? "unix_socket={$socket}"
                : 'host=' . (string) ($cfg['host'] ?? 'localhost');
            $dsn = "mysql:{$where};dbname={$name};charset={$charset}";
            self::$pdo = new PDO(
                $dsn,
                (string) ($cfg['user'] ?? ''),
                (string) ($cfg['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return self::$pdo;
    }

    /**
     * Uruchamia domkniecie w transakcji; commit przy sukcesie, rollback przy wyjatku.
     * Uzywane tam, gdzie zapis wpisu i inkrementacja `counters.kpir_seq` musza byc atomowe.
     *
     * @template T
     * @param callable(PDO):T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
