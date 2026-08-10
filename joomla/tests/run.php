<?php
/**
 * A test runner with no PHPUnit, because the hosts this code targets have no composer either.
 * Chay: php tests/run.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/SqlValue.php';
require_once __DIR__ . '/../lib/RowSource.php';
require_once __DIR__ . '/../lib/DbDumper.php';
require_once __DIR__ . '/../lib/FileWalker.php';
require_once __DIR__ . '/../lib/Token.php';
require_once __DIR__ . '/../lib/TarStream.php';
require_once __DIR__ . '/../lib/Uploader.php';
require_once __DIR__ . '/../lib/Engine.php';
require_once __DIR__ . '/FakeRowSource.php';

$passed = 0;
$failed = 0;

function check(string $name, $got, $want): void
{
    global $passed, $failed;
    $ok = $got === $want;
    if ($ok) {
        $passed++;
        echo "  ok   {$name}\n";
    } else {
        $failed++;
        echo "  FAIL {$name}\n";
        echo '       got : ' . var_export($got, true) . "\n";
        echo '       want: ' . var_export($want, true) . "\n";
    }
}

function checkTrue(string $name, bool $cond): void
{
    check($name, $cond, true);
}

// ---------------------------------------------------------------- SqlValue --

check('escape quote', SqlValue::escape("it's"), "it\\'s");
check('escape backslash', SqlValue::escape('a\\b'), 'a\\\\b');
check('escape newline+null+tab-control', SqlValue::escape("a\nb\x00c\x1a"), 'a\\nb\\0c\\Z');
check('literal null', SqlValue::literal(null), 'NULL');
check('literal string', SqlValue::literal('hi'), "'hi'");
check('rowTuple mixed', SqlValue::rowTuple(['7', null, "o'clock"]), "('7',NULL,'o\\'clock')");
check(
    'insert extended',
    SqlValue::insert('jos_menu', [['1', 'a'], ['2', 'b']]),
    "INSERT INTO `jos_menu` VALUES ('1','a'),('2','b');\n"
);
check('insert empty rows -> empty string', SqlValue::insert('t', []), '');
check('table name with backtick escaped', SqlValue::insert('a`b', [['1']]), "INSERT INTO `a``b` VALUES ('1');\n");

// round-trip vs Python unescape logic (spot check the same byte mapping)
$raw = "quote:' backslash:\\ nul:\x00 nl:\n cr:\r sub:\x1a dq:\" plain";
$escaped = SqlValue::escape($raw);
// Unescaped by hand, against the same table the escaper uses, so the round trip is checked
// rather than assumed.
$map = ['0' => "\x00", "'" => "'", '"' => '"', 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", '\\' => '\\'];
$decoded = '';
$n = strlen($escaped);
for ($i = 0; $i < $n; $i++) {
    if ($escaped[$i] === '\\' && $i + 1 < $n) {
        $decoded .= $map[$escaped[$i + 1]] ?? $escaped[$i + 1];
        $i++;
    } else {
        $decoded .= $escaped[$i];
    }
}
check('escape is self-inverse under python-style unescape', $decoded, $raw);

// ------------------------------------------------------------- DbDumper ----

$src = new FakeRowSource([
    'jos_menu' => [
        'create' => 'CREATE TABLE `jos_menu` (`id` int, `link` varchar(255))',
        'rows'   => [
            ['1', 'https://www.joomlart.com/'],
            ['2', 'https://www.joomlart.com/about'],
            ['3', null],
        ],
    ],
    'jos_empty' => ['create' => 'CREATE TABLE `jos_empty` (`id` int)', 'rows' => []],
]);
$dumper = new DbDumper($src);

$chunk = $dumper->dumpChunk('jos_menu', 0, 2);
checkTrue('first chunk has DROP+CREATE', str_contains($chunk['sql'], 'DROP TABLE IF EXISTS `jos_menu`'));
checkTrue('first chunk has CREATE stmt', str_contains($chunk['sql'], 'CREATE TABLE `jos_menu`'));
check('first chunk rows read', $chunk['rows'], 2);
check('first chunk not done', $chunk['done'], false);
check('first chunk next_offset', $chunk['next_offset'], 2);

$chunk2 = $dumper->dumpChunk('jos_menu', $chunk['next_offset'], 2);
checkTrue('second chunk no DROP (mid-table)', !str_contains($chunk2['sql'], 'DROP TABLE'));
check('second chunk rows read', $chunk2['rows'], 1);
check('second chunk done', $chunk2['done'], true);
checkTrue('second chunk has NULL literal', str_contains($chunk2['sql'], 'NULL'));

$emptyChunk = $dumper->dumpChunk('jos_empty', 0, 100);
check('empty table done immediately', $emptyChunk['done'], true);
check('empty table rows', $emptyChunk['rows'], 0);
checkTrue('empty table still emits DROP+CREATE', str_contains($emptyChunk['sql'], 'DROP TABLE'));

// Resuming from any offset must not repeat DROP, and the chunks must add up to every row.
$r1 = $dumper->dumpChunk('jos_menu', 0, 1);
$r2 = $dumper->dumpChunk('jos_menu', $r1['next_offset'], 1);
$r3 = $dumper->dumpChunk('jos_menu', $r2['next_offset'], 1);
check('resume walks to done across 3 single-row chunks', $r3['done'], true);
check('resume total rows == 3', $r1['rows'] + $r2['rows'] + $r3['rows'], 3);

check('dumper tables() lists what the source knows', $dumper->tables(), ['jos_menu', 'jos_empty']);

// ------------------------------------------------------------ FileWalker ---

$tmp = sys_get_temp_dir() . '/tracy_migration_test_' . bin2hex(random_bytes(4));
mkdir($tmp);
mkdir("$tmp/cache");
mkdir("$tmp/images");
file_put_contents("$tmp/index.php", '<?php echo "hi";');
file_put_contents("$tmp/configuration.php", '<?php $host="localhost";');
file_put_contents("$tmp/images/logo.png", "PNGDATA");
file_put_contents("$tmp/cache/should_skip.dat", "nope");

$walker = new FileWalker($tmp);
$batch1 = $walker->listBatch('', 2);
check('batch1 count', count($batch1['files']), 2);
check('batch1 not done', $batch1['done'], false);
checkTrue('cache dir excluded from listing', !in_array('cache/should_skip.dat', array_map(fn($f) => $f['path'], $batch1['files']), true));

$batch2 = $walker->listBatch($batch1['next_cursor'], 2);
check('batch2 done (only 3 real files: index.php, configuration.php, images/logo.png)', $batch2['done'], true);

$allPaths = array_merge(
    array_map(fn($f) => $f['path'], $batch1['files']),
    array_map(fn($f) => $f['path'], $batch2['files'])
);
sort($allPaths);
check('walked files exclude cache/', $allPaths, ['configuration.php', 'images/logo.png', 'index.php']);

$read = $walker->readFile('images/logo.png');
check('readFile content matches', $read, 'PNGDATA');

$threw = false;
try {
    $walker->readFile('../../../etc/passwd');
} catch (Throwable $e) {
    $threw = true;
}
checkTrue('path traversal blocked', $threw);

$threw2 = false;
try {
    $walker->readFile('does/not/exist.php');
} catch (Throwable $e) {
    $threw2 = true;
}
checkTrue('missing file throws', $threw2);

// -------------------------------------------------------------- Token -----

checkTrue('unconfigured token rejects everything', !Token::check(null, 'anything'));
checkTrue('unconfigured (short) token rejects', !Token::check('short', 'short'));
$goodToken = bin2hex(random_bytes(16));
checkTrue('configured token accepts exact match', Token::check($goodToken, $goodToken));
checkTrue('configured token rejects wrong value', !Token::check($goodToken, 'wrong-token-wrong-token'));
checkTrue('configured token rejects empty provided', !Token::check($goodToken, ''));
checkTrue('configured token rejects null provided', !Token::check($goodToken, null));

// -------------------------------------------------------------- Engine ----

$engine = new Engine($goodToken, ['php' => PHP_VERSION], $dumper, $walker);

$unauth = $engine->handle(['token' => 'nope', 'action' => 'info']);
check('engine rejects bad token', $unauth['ok'], false);
check('engine bad token error code', $unauth['error'], 'unauthorized');

$noToken = $engine->handle(['action' => 'info']);
check('engine rejects missing token', $noToken['ok'], false);

$info = $engine->handle(['token' => $goodToken, 'action' => 'info']);
check('engine info ok', $info['ok'], true);
checkTrue('engine info carries php version', isset($info['info']['php']));

$badAction = $engine->handle(['token' => $goodToken, 'action' => 'nope']);
check('engine unknown action', $badAction['error'], 'bad_action');

$dbTablesResp = $engine->handle(['token' => $goodToken, 'action' => 'db.tables']);
check('engine db.tables ok', $dbTablesResp['ok'], true);
check('engine db.tables lists source tables', $dbTablesResp['tables'], ['jos_menu', 'jos_empty']);

$dbResp = $engine->handle(['token' => $goodToken, 'action' => 'db.dump', 'params' => ['table' => 'jos_menu', 'offset' => 0, 'limit' => 2]]);
check('engine db.dump ok', $dbResp['ok'], true);
check('engine db.dump rows', $dbResp['rows'], 2);
checkTrue('engine db.dump sql_b64 decodes to expected content', str_contains(base64_decode($dbResp['sql_b64']), 'jos_menu'));

$dbMissingTable = $engine->handle(['token' => $goodToken, 'action' => 'db.dump', 'params' => []]);
check('engine db.dump missing table param', $dbMissingTable['error'], 'bad_params');

$dbLimitClamped = $engine->handle(['token' => $goodToken, 'action' => 'db.dump', 'params' => ['table' => 'jos_menu', 'offset' => 0, 'limit' => 999999]]);
check('engine db.dump clamps oversized limit (still succeeds)', $dbLimitClamped['ok'], true);

$flResp = $engine->handle(['token' => $goodToken, 'action' => 'files.list', 'params' => ['after' => '', 'limit' => 10]]);
check('engine files.list ok', $flResp['ok'], true);
check('engine files.list done true (small dir)', $flResp['done'], true);

$frResp = $engine->handle(['token' => $goodToken, 'action' => 'file.read', 'params' => ['path' => 'images/logo.png']]);
check('engine file.read ok', $frResp['ok'], true);
check('engine file.read content matches', base64_decode($frResp['content_b64']), 'PNGDATA');
check('engine file.read sha1 matches', $frResp['sha1'], sha1('PNGDATA'));

$frTraversal = $engine->handle(['token' => $goodToken, 'action' => 'file.read', 'params' => ['path' => '../../../etc/passwd']]);
check('engine file.read blocks traversal', $frTraversal['ok'], false);
check('engine file.read traversal error code', $frTraversal['error'], 'read_failed');

$engineNoDeps = new Engine($goodToken);
$noDump = $engineNoDeps->handle(['token' => $goodToken, 'action' => 'db.dump', 'params' => ['table' => 'x']]);
check('engine without dumper wired -> unavailable', $noDump['error'], 'unavailable');

// ---- site.stats -------------------------------------------------------------------------
// The two questions a caller settles before committing anyone to a wait: how big is this, and
// has it moved since last time.
$stats = $engine->handle(['token' => $goodToken, 'action' => 'site.stats']);
check('site.stats succeeds', $stats['ok'], true);
check('site.stats counts the files it would pack', $stats['files']['files'], 3);
check('site.stats reports a newest mtime', $stats['files']['newest'] > 0, true);
check('site.stats sums the bytes', $stats['files']['bytes'] > 0, true);
check('site.stats counts tables', $stats['db']['tables'], 2);
// Skipped directories are not work, so they must not appear in an estimate of the work.
check('site.stats leaves out skipped directories', $stats['files']['files'], 3);

// Half an answer beats none: a site whose database cannot be reached is still worth sizing.
$statsNoDb = $engineNoDeps->handle(['token' => $goodToken, 'action' => 'site.stats']);
check('site.stats without deps still succeeds', $statsNoDb['ok'], true);
check('site.stats without deps reports no db', isset($statsNoDb['db']), false);

// cleanup
unlink("$tmp/index.php");
unlink("$tmp/configuration.php");
unlink("$tmp/images/logo.png");
unlink("$tmp/cache/should_skip.dat");
rmdir("$tmp/images");
rmdir("$tmp/cache");
rmdir($tmp);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
