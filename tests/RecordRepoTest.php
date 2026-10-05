<?php

declare(strict_types=1);

/**
 * Testy RecordRepo na SQLite w pamieci (bez MySQL): `php tests/RecordRepoTest.php`.
 * Sprawdzaja logike wersji/idempotencji/delty — SQL repozytorium jest przenosny (INSERT/UPDATE/SELECT).
 */

use Firma\Db;
use Firma\HttpError;
use Firma\Repo\RecordRepo;

require dirname(__DIR__) . '/src/bootstrap.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? "ok   " : "FAIL ") . $what . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function freshDb(): void
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE counters (name TEXT PRIMARY KEY, value INTEGER NOT NULL)");
    $pdo->exec("INSERT INTO counters VALUES ('rec_seq', 0)");
    $pdo->exec("CREATE TABLE sync_records (ksiega_id TEXT NOT NULL, collection TEXT NOT NULL, id TEXT NOT NULL,
        payload TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, rev INTEGER NOT NULL,
        deleted INTEGER NOT NULL DEFAULT 0, server_seq INTEGER NOT NULL, PRIMARY KEY (ksiega_id, collection, id))");
    Db::useForTesting($pdo);
}

function expectHttpError(int $status, callable $fn, string $what): void
{
    try {
        $fn();
        check(false, "$what (brak wyjatku)");
    } catch (HttpError $e) {
        check($e->status === $status, "$what -> $status");
    }
}

$K = 'ks-1';

// 1. Rezerwacja idempotentna: ponowienie nie nadpisuje i nie tworzy duplikatu.
freshDb();
$a = RecordRepo::reserve($K, ['collection' => 'document', 'id' => 'd1', 'payload' => ['counterparty' => 'Jan', 'amountGrosze' => 300000]]);
check($a['rev'] === 1 && $a['payload']['counterparty'] === 'Jan', 'reserve tworzy rekord z rev=1');
$b = RecordRepo::reserve($K, ['collection' => 'document', 'id' => 'd1', 'payload' => ['counterparty' => 'Inny']]);
check($b['payload']['counterparty'] === 'Jan', 'ponowny reserve zwraca istniejacy (bez nadpisania)');
check(count(RecordRepo::pull($K, null)['records']) === 1, 'po ponowieniu nadal 1 rekord');

// 2. Ta sama para (collection, id) w innej kolekcji/ksiedze to osobne rekordy.
RecordRepo::reserve($K, ['collection' => 'contractor', 'id' => 'd1', 'payload' => ['name' => 'ACME']]);
RecordRepo::reserve('ks-2', ['collection' => 'document', 'id' => 'd1', 'payload' => ['counterparty' => 'Obcy']]);
check(count(RecordRepo::pull($K, null)['records']) === 2, 'kolekcja jest czescia klucza; inna ksiega niewidoczna');

// 3. Save z kontrola wersji + konflikt.
$ok = RecordRepo::save($K, 'document', 'd1', ['payload' => ['counterparty' => 'Jan Kowalski']], 1);
check($ok['status'] === 'ok' && $ok['record']['rev'] === 2, 'save z poprawnym rev podbija rev');
$conf = RecordRepo::save($K, 'document', 'd1', ['payload' => ['counterparty' => 'Stary']], 1);
check($conf['status'] === 'conflict' && $conf['record']['payload']['counterparty'] === 'Jan Kowalski', 'save ze starym rev = konflikt z aktualnym stanem');
check(RecordRepo::save($K, 'document', 'nie-ma', ['payload' => []], 1)['status'] === 'not_found', 'save nieistniejacego = not_found');

// 4. Delta po kursorze i tombstone.
$full = RecordRepo::pull($K, null);
$cursor = $full['nextCursor'];
RecordRepo::save($K, 'document', 'd1', ['payload' => ['counterparty' => 'Jan Kowalski'], 'deleted' => true], 2);
$delta = RecordRepo::pull($K, $cursor);
check(count($delta['records']) === 1 && $delta['records'][0]['deleted'] === true, 'delta zawiera tylko zmieniony rekord, z tombstonem');
check((int) $delta['nextCursor'] > (int) $cursor, 'kursor rosnie');
check(count(RecordRepo::pull($K, $delta['nextCursor'])['records']) === 0, 'pull od najnowszego kursora jest pusty');

// 5. Kolekcje etapu 2 sa przyjmowane (klucze z aplikacji: okres terminu, RRRR-MM, stale id).
foreach ([['payment', 'MANUAL-1759650000000'], ['payment', 'PIT-Q-2026-Q1'], ['salary', '2026-03'], ['settings', 'tax'], ['logo', 'logo']] as [$col, $rid]) {
    $r = RecordRepo::reserve($K, ['collection' => $col, 'id' => $rid, 'payload' => ['v' => 1]]);
    check($r['collection'] === $col && $r['id'] === $rid && $r['rev'] === 1, "kolekcja $col przyjeta");
}

// 6. Walidacja.
expectHttpError(422, fn() => RecordRepo::reserve($K, ['collection' => 'hasla', 'id' => 'x', 'payload' => []]), 'nieznana kolekcja');
expectHttpError(422, fn() => RecordRepo::reserve($K, ['collection' => 'document', 'id' => '', 'payload' => []]), 'brak id');
expectHttpError(422, fn() => RecordRepo::reserve($K, ['collection' => 'document', 'id' => 'y', 'payload' => 'tekst']), 'payload nie-obiekt');
expectHttpError(413, fn() => RecordRepo::reserve($K, ['collection' => 'document', 'id' => 'z', 'payload' => ['x' => str_repeat('a', RecordRepo::MAX_PAYLOAD_BYTES)]]), 'payload za duzy');

echo $failures === 0 ? "\nWSZYSTKO OK\n" : "\nBLEDY: $failures\n";
exit($failures === 0 ? 0 : 1);
