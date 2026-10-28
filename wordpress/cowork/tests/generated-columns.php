<?php
/** Real MySQL/MariaDB round trip: run with TRACY_TEST_DB_HOST and mysqli installed. */
require_once __DIR__ . '/../lib/DbDumper.php';
require_once __DIR__ . '/../lib/MysqliRowSource.php';

$db = new mysqli(getenv('TRACY_TEST_DB_HOST') ?: '127.0.0.1', 'root', 't');
$db->query('CREATE DATABASE IF NOT EXISTS generated_dump_test');
$db->select_db('generated_dump_test');
foreach (['stored', 'virtual'] as $kind) {
    $table = 'test_' . $kind;
    $db->query("DROP TABLE IF EXISTS `{$table}`");
    $db->query("CREATE TABLE `{$table}` (id INT PRIMARY KEY, doubled INT AS (amount * 2) {$kind}, amount INT, note VARCHAR(40), hidden INT INVISIBLE DEFAULT 7)");
    $db->query("INSERT INTO `{$table}` (id, amount, note, hidden) VALUES (1,3,'one',9),(2,5,'two',11),(3,7,'three',13)");
    foreach (['offset', 'cursor', 'range', 'cut', 'keyless'] as $mode) {
        if ($mode === 'keyless') $db->query("ALTER TABLE `{$table}` DROP PRIMARY KEY");
        $dumper = new DbDumper(new MysqliRowSource($db));
        $dump = '';
        if ($mode === 'offset') {
            for ($offset = 0; ; ) {
                $part = $dumper->dumpChunk($table, $offset, 2);
                $dump .= $part['sql'];
                $offset = $part['next_offset'];
                if ($part['done']) break;
            }
        } elseif ($mode === 'range') {
            $dump = $dumper->createOf($table) . ";\n";
            $after = null;
            do {
                $part = $dumper->rowsAfter($table, 'id', $after, 2);
                $dump .= $part['sql'];
                $after = $part['last'];
            } while ($part['rows'] === 2);
        } else {
            $cursor = null;
            do {
                $part = $dumper->dumpChunkFrom($table, $cursor, 2, $mode === 'cut' ? static function ($bytes) { return false; } : null);
                $dump .= $part['sql'];
                $cursor = $part['next_cursor'];
            } while (!$part['done']);
        }
        if (strpos($dump, '(`id`,`amount`,`note`,`hidden`) VALUES') === false) {
            throw new RuntimeException("{$kind}/{$mode}: writable column list missing");
        }
        $db->query("DROP TABLE `{$table}`");
        $db->multi_query($dump);
        do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
        $actual = $db->query("SELECT id,doubled,amount,note,hidden FROM `{$table}` ORDER BY id")->fetch_all();
        $expected = [['1','6','3','one','9'],['2','10','5','two','11'],['3','14','7','three','13']];
        if (json_encode($actual) !== json_encode($expected)) throw new RuntimeException("{$kind}/{$mode}: round trip changed rows");
        echo "ok - generated {$kind} {$mode} round trip\n";
    }
}
