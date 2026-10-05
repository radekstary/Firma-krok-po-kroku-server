<?php

declare(strict_types=1);

namespace Firma\Repo;

use Firma\Db;
use Firma\HttpError;
use PDO;

/**
 * Ogolny magazyn rekordow ksiegi (dokumenty z faktura, kontrahenci, dane firmy...). Serwer NIE
 * interpretuje tresci — przechowuje `payload` (JSON) i wersjonuje go tak samo jak wpisy KPiR:
 *  - [reserve] tworzy rekord **idempotentnie po (collection, id)** — ponowienie zwraca istniejacy;
 *  - [save] edytuje z **optymistyczna kontrola wersji** (`If-Match: rev`) — niezgodnosc = konflikt;
 *  - [pull] zwraca delte po kursorze `server_seq` (z tombstonami).
 *
 * Jedna tabela zamiast tabeli na kazdy typ: nowy rodzaj danych to wpis w [COLLECTIONS], a nie
 * migracja schematu i nowe API (wdrozenie na hostingu jest reczne).
 */
final class RecordRepo
{
    /** Dozwolone kolekcje — reszta 422 (ochrona przed zasmiecaniem bazy dowolnymi typami). */
    public const COLLECTIONS = ['document', 'contractor', 'company'];

    /** Maksymalny rozmiar payloadu (bajty JSON). Faktura z wieloma pozycjami miesci sie z zapasem. */
    public const MAX_PAYLOAD_BYTES = 512 * 1024;

    /**
     * @param array<string, mixed> $dto { collection, id, payload, deleted?, createdAt? }
     * @return array<string, mixed>
     */
    public static function reserve(string $ksiegaId, array $dto): array
    {
        $collection = self::requireCollection($dto['collection'] ?? null);
        $id = self::requireId($dto['id'] ?? null);
        $payload = self::encodePayload($dto['payload'] ?? null);
        return Db::transaction(function (PDO $pdo) use ($ksiegaId, $collection, $id, $payload, $dto): array {
            $existing = self::fetchRow($pdo, $ksiegaId, $collection, $id);
            if ($existing !== null) {
                return self::rowToDto($existing); // idempotencja — bez nadpisania
            }
            $now = time();
            $pdo->prepare(
                'INSERT INTO sync_records (ksiega_id, collection, id, payload, deleted, created_at, updated_at, rev, server_seq)
                 VALUES (:kid, :col, :id, :payload, :deleted, :created, :updated, 1, :seq)'
            )->execute([
                ':kid' => $ksiegaId, ':col' => $collection, ':id' => $id, ':payload' => $payload,
                ':deleted' => self::boolInt($dto['deleted'] ?? false),
                ':created' => is_numeric($dto['createdAt'] ?? null) ? (int) $dto['createdAt'] : $now,
                ':updated' => $now, ':seq' => self::nextSeq($pdo),
            ]);
            $row = self::fetchRow($pdo, $ksiegaId, $collection, $id);
            if ($row === null) {
                throw new \RuntimeException('Nie udalo sie odczytac rekordu po rezerwacji.');
            }
            return self::rowToDto($row);
        });
    }

    /**
     * @param array<string, mixed> $dto { payload, deleted? }
     * @return array{status:string, record?:array<string,mixed>}
     */
    public static function save(string $ksiegaId, string $collection, string $id, array $dto, int $expectedRev): array
    {
        $collection = self::requireCollection($collection);
        $payload = self::encodePayload($dto['payload'] ?? null);
        return Db::transaction(function (PDO $pdo) use ($ksiegaId, $collection, $id, $payload, $dto, $expectedRev): array {
            $current = self::fetchRow($pdo, $ksiegaId, $collection, $id);
            if ($current === null) {
                return ['status' => 'not_found'];
            }
            if ((int) $current['rev'] !== $expectedRev) {
                return ['status' => 'conflict', 'record' => self::rowToDto($current)];
            }
            $stmt = $pdo->prepare(
                'UPDATE sync_records SET payload = :payload, deleted = :deleted, updated_at = :updated,
                        rev = rev + 1, server_seq = :seq
                 WHERE ksiega_id = :kid AND collection = :col AND id = :id AND rev = :expected'
            );
            $stmt->execute([
                ':payload' => $payload, ':deleted' => self::boolInt($dto['deleted'] ?? false),
                ':updated' => time(), ':seq' => self::nextSeq($pdo),
                ':kid' => $ksiegaId, ':col' => $collection, ':id' => $id, ':expected' => $expectedRev,
            ]);
            $row = self::fetchRow($pdo, $ksiegaId, $collection, $id);
            if ($stmt->rowCount() === 0) {
                // Wyscig miedzy odczytem a UPDATE — zwroc aktualny stan.
                return $row === null ? ['status' => 'not_found'] : ['status' => 'conflict', 'record' => self::rowToDto($row)];
            }
            return ['status' => 'ok', 'record' => self::rowToDto($row ?? [])];
        });
    }

    /** @return array{records:list<array<string,mixed>>, nextCursor:string} */
    public static function pull(string $ksiegaId, ?string $since): array
    {
        $sinceSeq = ($since !== null && ctype_digit($since)) ? (int) $since : 0;
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM sync_records WHERE ksiega_id = :kid AND server_seq > :since ORDER BY server_seq ASC'
        );
        $stmt->execute([':kid' => $ksiegaId, ':since' => $sinceSeq]);
        $records = [];
        $maxSeq = $sinceSeq;
        foreach ($stmt->fetchAll() as $row) {
            $records[] = self::rowToDto($row);
            $maxSeq = max($maxSeq, (int) $row['server_seq']);
        }
        return ['records' => $records, 'nextCursor' => (string) $maxSeq];
    }

    // --- pomocnicze ---

    private static function nextSeq(PDO $pdo): int
    {
        $pdo->prepare("UPDATE counters SET value = value + 1 WHERE name = 'rec_seq'")->execute();
        return (int) $pdo->query("SELECT value FROM counters WHERE name = 'rec_seq'")->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private static function fetchRow(PDO $pdo, string $ksiegaId, string $collection, string $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM sync_records WHERE ksiega_id = :kid AND collection = :col AND id = :id');
        $stmt->execute([':kid' => $ksiegaId, ':col' => $collection, ':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function rowToDto(array $row): array
    {
        return [
            'collection' => (string) $row['collection'],
            'id'         => (string) $row['id'],
            'ksiegaId'   => (string) $row['ksiega_id'],
            'payload'    => json_decode((string) $row['payload'], true),
            'createdAt'  => (int) $row['created_at'],
            'updatedAt'  => (int) $row['updated_at'],
            'rev'        => (int) $row['rev'],
            'deleted'    => ((int) $row['deleted']) === 1,
        ];
    }

    private static function requireCollection(mixed $c): string
    {
        $c = (string) ($c ?? '');
        if (!in_array($c, self::COLLECTIONS, true)) {
            throw new HttpError(422, 'invalid_collection', 'Nieznany rodzaj rekordu.');
        }
        return $c;
    }

    private static function requireId(mixed $id): string
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '' || strlen($id) > 36) {
            throw new HttpError(422, 'invalid_id', 'Rekord musi miec id (do 36 znakow).');
        }
        return $id;
    }

    /** Payload musi byc obiektem JSON; zwraca zserializowany tekst. */
    private static function encodePayload(mixed $payload): string
    {
        if (!is_array($payload)) {
            throw new HttpError(422, 'invalid_payload', 'Pole payload musi byc obiektem JSON.');
        }
        $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($text === false) {
            throw new HttpError(422, 'invalid_payload', 'Nie da sie zapisac payloadu.');
        }
        if (strlen($text) > self::MAX_PAYLOAD_BYTES) {
            throw new HttpError(413, 'payload_too_large', 'Rekord jest za duzy.');
        }
        return $text;
    }

    private static function boolInt(mixed $v): int
    {
        return ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
    }
}
