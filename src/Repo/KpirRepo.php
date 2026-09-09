<?php

declare(strict_types=1);

namespace Firma\Repo;

use Firma\Db;
use Firma\HttpError;
use PDO;

/**
 * Wpisy KPiR z synchronizacja wielourzadzeniowa. Trzy operacje:
 *  - [reserve] tworzy wpis, **idempotentnie po id** (klientowy UUID) — ponowienie zwraca istniejacy,
 *    nie duplikat;
 *  - [save] edytuje z **optymistyczna kontrola wersji** (`If-Match: rev`) — niezgodnosc = konflikt
 *    z aktualnym stanem (klient przeladowuje);
 *  - [pull] zwraca delte po kursorze `server_seq` (z tombstonami).
 *
 * `server_seq` (monotoniczny, z tabeli `counters`) nadajemy w tej samej transakcji co zapis, wiec
 * kazda zmiana ma unikalny, rosnacy kursor — podstawa niezawodnej delty. Lp. liczy klient.
 */
final class KpirRepo
{
    /** Kolumny tresci (klucz DTO => kolumna). Bez pol technicznych (rev/server_seq/updated_at). */
    private const CONTENT = [
        'year'             => 'year',
        'dataZdarzenia'    => 'data_zdarzenia',
        'nrKsef'           => 'nr_ksef',
        'nrDowodu'         => 'nr_dowodu',
        'kontrahentId'     => 'kontrahent_id',
        'kontrahentNazwa'  => 'kontrahent_nazwa',
        'kontrahentAdres'  => 'kontrahent_adres',
        'opis'             => 'opis',
        'przychodSprzedaz' => 'przychod_sprzedaz',
        'przychodPozostaly'=> 'przychod_pozostaly',
        'zakupTowarow'     => 'zakup_towarow',
        'kosztyUboczne'    => 'koszty_uboczne',
        'wynagrodzenia'    => 'wynagrodzenia',
        'pozostaleWydatki' => 'pozostale_wydatki',
        'wolna'            => 'wolna',
        'kosztyBR'         => 'koszty_br',
        'uwagi'            => 'uwagi',
        'documentId'       => 'document_id',
        'extras'           => 'extras',
    ];

    /** Kolumny groszowe (BIGINT) — rzutowane na int przy zapisie i odczycie. */
    private const MONEY = [
        'przychod_sprzedaz', 'przychod_pozostaly', 'zakup_towarow',
        'koszty_uboczne', 'wynagrodzenia', 'pozostale_wydatki', 'koszty_br',
    ];

    /**
     * Tworzy/rezerwuje wpis. Idempotentne po id: jesli istnieje, zwraca aktualny stan bez zmian.
     * Nowy wpis dostaje rev=1 i swiezy server_seq. Serwer jest autorytetem dla rev/server_seq.
     *
     * @param array<string, mixed> $dto
     * @return array<string, mixed> DTO gotowe do JSON
     */
    public static function reserve(string $ksiegaId, array $dto): array
    {
        $id = self::requireId($dto);
        return Db::transaction(function (PDO $pdo) use ($ksiegaId, $id, $dto): array {
            $existing = self::fetchRow($pdo, $ksiegaId, $id);
            if ($existing !== null) {
                return self::rowToDto($existing); // idempotencja — bez nadpisania
            }

            $now = time();
            $seq = self::nextSeq($pdo);
            $content = self::contentValues($dto);
            $cols = array_merge(
                ['id', 'ksiega_id'],
                array_keys($content),
                ['deleted', 'created_at', 'updated_at', 'rev', 'server_seq']
            );
            $placeholders = implode(', ', array_map(static fn($c) => ':' . $c, $cols));
            $sql = 'INSERT INTO kpir_entries (' . implode(', ', $cols) . ") VALUES ($placeholders)";

            $params = [':id' => $id, ':ksiega_id' => $ksiegaId];
            foreach ($content as $col => $val) {
                $params[':' . $col] = $val;
            }
            $params[':deleted']    = self::boolInt($dto['deleted'] ?? false);
            $params[':created_at'] = self::intOr($dto['createdAt'] ?? null, $now);
            $params[':updated_at'] = $now;
            $params[':rev']        = 1;
            $params[':server_seq'] = $seq;

            $pdo->prepare($sql)->execute($params);
            $row = self::fetchRow($pdo, $ksiegaId, $id);
            if ($row === null) {
                throw new \RuntimeException('Nie udalo sie odczytac wpisu po rezerwacji.');
            }
            return self::rowToDto($row);
        });
    }

    /**
     * Edycja z kontrola wersji. Zwraca:
     *  - ['status'=>'ok', 'entry'=>dto] po udanym zapisie (rev+1, nowy server_seq),
     *  - ['status'=>'conflict', 'entry'=>dto] gdy rev sie nie zgadza (aktualny stan serwera),
     *  - ['status'=>'not_found'] gdy wpisu nie ma.
     *
     * @param array<string, mixed> $dto
     * @return array{status:string, entry?:array<string,mixed>}
     */
    public static function save(string $ksiegaId, string $entryId, array $dto, int $expectedRev): array
    {
        return Db::transaction(function (PDO $pdo) use ($ksiegaId, $entryId, $dto, $expectedRev): array {
            $current = self::fetchRow($pdo, $ksiegaId, $entryId);
            if ($current === null) {
                return ['status' => 'not_found'];
            }
            if ((int) $current['rev'] !== $expectedRev) {
                return ['status' => 'conflict', 'entry' => self::rowToDto($current)];
            }

            $now = time();
            $seq = self::nextSeq($pdo);
            $content = self::contentValues($dto);
            $set = [];
            foreach (array_keys($content) as $col) {
                $set[] = "$col = :$col";
            }
            $set[] = 'deleted = :deleted';
            $set[] = 'updated_at = :updated_at';
            $set[] = 'rev = rev + 1';
            $set[] = 'server_seq = :server_seq';
            $sql = 'UPDATE kpir_entries SET ' . implode(', ', $set)
                 . ' WHERE id = :id AND ksiega_id = :ksiega_id AND rev = :expected';

            $params = [
                ':id' => $entryId, ':ksiega_id' => $ksiegaId, ':expected' => $expectedRev,
                ':deleted' => self::boolInt($dto['deleted'] ?? false),
                ':updated_at' => $now, ':server_seq' => $seq,
            ];
            foreach ($content as $col => $val) {
                $params[':' . $col] = $val;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                // Wyscig: ktos zmienil rev miedzy odczytem a UPDATE. Zwroc aktualny stan.
                $fresh = self::fetchRow($pdo, $ksiegaId, $entryId);
                return $fresh === null
                    ? ['status' => 'not_found']
                    : ['status' => 'conflict', 'entry' => self::rowToDto($fresh)];
            }

            $row = self::fetchRow($pdo, $ksiegaId, $entryId);
            return ['status' => 'ok', 'entry' => self::rowToDto($row ?? [])];
        });
    }

    /**
     * Delta: wpisy ksiegi ze `server_seq` wiekszym niz [since] (null = od poczatku), z tombstonami,
     * rosnaco. `nextCursor` = najwyzszy server_seq w wyniku (albo dotychczasowy, gdy pusto).
     *
     * @return array{entries:list<array<string,mixed>>, nextCursor:?string}
     */
    public static function pull(string $ksiegaId, ?string $since): array
    {
        $sinceSeq = ($since !== null && ctype_digit($since)) ? (int) $since : 0;
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM kpir_entries WHERE ksiega_id = :kid AND server_seq > :since ORDER BY server_seq ASC'
        );
        $stmt->execute([':kid' => $ksiegaId, ':since' => $sinceSeq]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        $entries = [];
        $maxSeq = $sinceSeq;
        foreach ($rows as $row) {
            $entries[] = self::rowToDto($row);
            $maxSeq = max($maxSeq, (int) $row['server_seq']);
        }
        return [
            'entries'    => $entries,
            'nextCursor' => (string) $maxSeq,
        ];
    }

    // --- pomocnicze ---

    /** Kolejny monotoniczny server_seq (w ramach transakcji wywolujacego). */
    private static function nextSeq(PDO $pdo): int
    {
        $pdo->prepare("UPDATE counters SET value = value + 1 WHERE name = 'kpir_seq'")->execute();
        $seq = $pdo->query("SELECT value FROM counters WHERE name = 'kpir_seq'")->fetchColumn();
        return (int) $seq;
    }

    /** @return array<string,mixed>|null */
    private static function fetchRow(PDO $pdo, string $ksiegaId, string $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM kpir_entries WHERE id = :id AND ksiega_id = :kid');
        $stmt->execute([':id' => $id, ':kid' => $ksiegaId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Wartosci kolumn tresci z DTO (z rzutowaniem grosze->int). Braki pomijamy — nie nadpisujemy
     * kolumny wartoscia domyslna, jesli klient jej nie przyslal (przy save to istotne).
     * @param array<string,mixed> $dto
     * @return array<string,int|string|null>
     */
    private static function contentValues(array $dto): array
    {
        $out = [];
        foreach (self::CONTENT as $dtoKey => $col) {
            if (!array_key_exists($dtoKey, $dto)) {
                continue;
            }
            $val = $dto[$dtoKey];
            if (in_array($col, self::MONEY, true)) {
                $out[$col] = (int) $val;
            } elseif ($col === 'year') {
                $out[$col] = (int) $val;
            } elseif ($col === 'document_id') {
                $out[$col] = $val === null ? null : (int) $val;
            } else {
                $out[$col] = $val === null ? null : (string) $val;
            }
        }
        return $out;
    }

    /**
     * Wiersz DB -> DTO JSON (typy: grosze/int -> int, deleted -> bool). Pola techniczne serwera
     * (server_seq) NIE trafiaja do DTO — klient dostaje kursor osobno z [pull].
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function rowToDto(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'ksiegaId'         => (string) $row['ksiega_id'],
            'year'             => (int) $row['year'],
            'dataZdarzenia'    => (string) $row['data_zdarzenia'],
            'nrKsef'           => self::nullStr($row['nr_ksef'] ?? null),
            'nrDowodu'         => self::nullStr($row['nr_dowodu'] ?? null),
            'kontrahentId'     => self::nullStr($row['kontrahent_id'] ?? null),
            'kontrahentNazwa'  => self::nullStr($row['kontrahent_nazwa'] ?? null),
            'kontrahentAdres'  => self::nullStr($row['kontrahent_adres'] ?? null),
            'opis'             => self::nullStr($row['opis'] ?? null),
            'przychodSprzedaz' => (int) $row['przychod_sprzedaz'],
            'przychodPozostaly'=> (int) $row['przychod_pozostaly'],
            'zakupTowarow'     => (int) $row['zakup_towarow'],
            'kosztyUboczne'    => (int) $row['koszty_uboczne'],
            'wynagrodzenia'    => (int) $row['wynagrodzenia'],
            'pozostaleWydatki' => (int) $row['pozostale_wydatki'],
            'wolna'            => self::nullStr($row['wolna'] ?? null),
            'kosztyBR'         => (int) $row['koszty_br'],
            'uwagi'            => self::nullStr($row['uwagi'] ?? null),
            'documentId'       => isset($row['document_id']) && $row['document_id'] !== null
                                    ? (int) $row['document_id'] : null,
            'extras'           => self::nullStr($row['extras'] ?? null),
            'createdAt'        => (int) $row['created_at'],
            'updatedAt'        => (int) $row['updated_at'],
            'rev'              => (int) $row['rev'],
            'deleted'          => ((int) $row['deleted']) === 1,
        ];
    }

    /** @param array<string,mixed> $dto */
    private static function requireId(array $dto): string
    {
        $id = trim((string) ($dto['id'] ?? ''));
        if ($id === '') {
            throw new HttpError(422, 'missing_id', 'Wpis musi miec id (klientowy UUID).');
        }
        return $id;
    }

    private static function nullStr(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    private static function boolInt(mixed $v): int
    {
        return ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
    }

    private static function intOr(mixed $v, int $default): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }
}
