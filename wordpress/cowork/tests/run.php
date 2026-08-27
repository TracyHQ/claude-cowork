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
require_once __DIR__ . '/../lib/ChangeStamp.php';
require_once __DIR__ . '/FakePackages.php';
require_once __DIR__ . '/FakeRowSource.php';
require_once __DIR__ . '/FakeWriters.php';
// Defines ABSPATH, which is what the real writers check before they will load at all.
require_once __DIR__ . '/FakeWordPress.php';
require_once __DIR__ . '/../lib/class-claude-cowork-writers.php';

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
// Four calls for three rows: `done` is now a short batch and nothing else, so a table whose size
// is an exact multiple of the batch costs one empty round trip to learn it has ended. That is the
// deliberate price of never ending early on a table the site is deleting from.
$r1 = $dumper->dumpChunk('jos_menu', 0, 1);
$r2 = $dumper->dumpChunk('jos_menu', $r1['next_offset'], 1);
$r3 = $dumper->dumpChunk('jos_menu', $r2['next_offset'], 1);
$r4 = $dumper->dumpChunk('jos_menu', $r3['next_offset'], 1);
check('a full batch is never assumed to be the last', $r3['done'], false);
check('resume walks to done across 4 single-row chunks', $r4['done'], true);
check('resume total rows == 3', $r1['rows'] + $r2['rows'] + $r3['rows'] + $r4['rows'], 3);

check('dumper tables() lists what the source knows', $dumper->tables(), ['jos_menu', 'jos_empty']);

// -------------------------------------------- DbDumper, keyset paging ------
//
// The bug this pages by key to avoid: a dump reads a table the site is still writing to, and
// OFFSET names a position rather than a row. Measured on a live site 2026-08-12 — `#__session`
// gave 2636 rows of which 2635 were distinct, and the import died on `ERROR 1062` after the whole
// 312 MB export had already run. A session id is random, so a new session lands in the MIDDLE of
// the key order, which is what `insertRow(..., 0)` reproduces here.

/** Every INSERT value in a chunk's SQL, so a test can ask what was actually emitted. */
function emitted(string $sql): array
{
    preg_match_all("/\\('([^']*)'/", $sql, $m);
    return $m[1];
}

function sessionSource(): FakeRowSource
{
    return new FakeRowSource([
        'jos_session' => [
            'create'  => 'CREATE TABLE `jos_session` (`session_id` varchar(32), `data` varchar(255))',
            'columns' => ['session_id', 'data'],
            'key'     => ['session_id'],
            'rows'    => [['b', 'two'], ['c', 'three'], ['d', 'four'], ['e', 'five']],
        ],
    ]);
}

// First, prove the old path really is broken — a test that cannot fail on the bug proves nothing.
$broken = sessionSource();
$brokenDumper = new DbDumper($broken);
$b1 = $brokenDumper->dumpChunk('jos_session', 0, 2);
$broken->insertRow('jos_session', ['a', 'one'], 0);   // a new session, sorting ahead of the cursor
$b2 = $brokenDumper->dumpChunk('jos_session', $b1['next_offset'], 2);
$brokenIds = array_merge(emitted($b1['sql']), emitted($b2['sql']));
check('offset paging emits a row twice when the site writes mid-dump',
    count($brokenIds) - count(array_unique($brokenIds)), 1);

// Then the fix: the cursor names the last ROW, so nothing behind it can move.
$live = sessionSource();
$liveDumper = new DbDumper($live);
$k1 = $liveDumper->dumpChunkFrom('jos_session', null, 2);
checkTrue('keyset first chunk has DROP+CREATE', str_contains($k1['sql'], 'DROP TABLE IF EXISTS `jos_session`'));
check('keyset first chunk rows', $k1['rows'], 2);
check('keyset first chunk not done', $k1['done'], false);

$live->insertRow('jos_session', ['a', 'one'], 0);
$k2 = $liveDumper->dumpChunkFrom('jos_session', $k1['next_cursor'], 2);
$k3 = $liveDumper->dumpChunkFrom('jos_session', $k2['next_cursor'], 2);
$keysetIds = array_merge(emitted($k1['sql']), emitted($k2['sql']), emitted($k3['sql']));
check('keyset paging emits no row twice', count($keysetIds) - count(array_unique($keysetIds)), 0);
check('keyset paging emits every row that existed when it started', $keysetIds, ['b', 'c', 'd', 'e']);
check('keyset walks to done', $k3['done'], true);
checkTrue('keyset resume does not repeat DROP', !str_contains($k2['sql'], 'DROP TABLE'));

// A delete behind the cursor is the worse half of the same bug: offset SKIPS a row, silently.
$shrinking = sessionSource();
$shrinkDumper = new DbDumper($shrinking);
$s1 = $shrinkDumper->dumpChunk('jos_session', 0, 2);
$shrinking->deleteRow('jos_session', 0);
$s2 = $shrinkDumper->dumpChunk('jos_session', $s1['next_offset'], 2);
check('offset paging skips a row when one is deleted mid-dump',
    array_merge(emitted($s1['sql']), emitted($s2['sql'])), ['b', 'c', 'e']);

$shrinking2 = sessionSource();
$shrinkDumper2 = new DbDumper($shrinking2);
$t1 = $shrinkDumper2->dumpChunkFrom('jos_session', null, 2);
$shrinking2->deleteRow('jos_session', 0);
$t2 = $shrinkDumper2->dumpChunkFrom('jos_session', $t1['next_cursor'], 2);
check('keyset loses nothing when a row is deleted behind it',
    array_merge(emitted($t1['sql']), emitted($t2['sql'])), ['b', 'c', 'd', 'e']);

// A composite key needs no special handling from the caller: the cursor carries both columns.
$mapSource = new FakeRowSource([
    'jos_user_usergroup_map' => [
        'create'  => 'CREATE TABLE `jos_user_usergroup_map` (`user_id` int, `group_id` int)',
        'columns' => ['user_id', 'group_id'],
        'key'     => ['user_id', 'group_id'],
        'rows'    => [['1', '2'], ['1', '3'], ['2', '2']],
    ],
]);
$mapDumper = new DbDumper($mapSource);
$m1 = $mapDumper->dumpChunkFrom('jos_user_usergroup_map', null, 2);
$m2 = $mapDumper->dumpChunkFrom('jos_user_usergroup_map', $m1['next_cursor'], 2);
check('composite key pages without repeating the shared first column',
    array_merge(emitted($m1['sql']), emitted($m2['sql'])), ['1', '1', '2']);
check('composite key walks to done', $m2['done'], true);

// No unique NOT NULL key: there is no cursor to be had, so it falls back and SAYS it fell back.
$keyless = new FakeRowSource([
    'jos_keyless' => [
        'create' => 'CREATE TABLE `jos_keyless` (`note` varchar(64))',
        'rows'   => [['one'], ['two'], ['three']],
    ],
]);
$keylessDumper = new DbDumper($keyless);
$n1 = $keylessDumper->dumpChunkFrom('jos_keyless', null, 2);
$n2 = $keylessDumper->dumpChunkFrom('jos_keyless', $n1['next_cursor'], 2);
checkTrue('keyless table warns in the dump that it was paged by offset',
    str_contains($n1['sql'], 'paged by offset'));
check('keyless table still dumps every row', array_merge(emitted($n1['sql']), emitted($n2['sql'])),
    ['one', 'two', 'three']);
check('keyless table walks to done', $n2['done'], true);

// An empty table is done on the first call, and still carries its schema.
$emptyKeyed = new FakeRowSource([
    'jos_none' => [
        'create'  => 'CREATE TABLE `jos_none` (`id` int)',
        'columns' => ['id'],
        'key'     => ['id'],
        'rows'    => [],
    ],
]);
$e1 = (new DbDumper($emptyKeyed))->dumpChunkFrom('jos_none', null, 100);
check('keyset empty table done immediately', $e1['done'], true);
checkTrue('keyset empty table still emits DROP+CREATE', str_contains($e1['sql'], 'DROP TABLE'));

// The cursor is this class's own business, and a caller that mangles it is told so rather than
// being handed a dump that quietly starts the table again.
$threw = false;
try {
    (new DbDumper(sessionSource()))->dumpChunkFrom('jos_session', 'not-base64-json!!', 2);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
checkTrue('a corrupt cursor is refused, not silently restarted', $threw);


// ------------------------------------------------------------ FileWalker ---

$tmp = sys_get_temp_dir() . '/tracy_migration_test_' . bin2hex(random_bytes(4));
mkdir($tmp);
mkdir("$tmp/wp-content", 0777, true);
mkdir("$tmp/wp-content/cache");
mkdir("$tmp/images");
file_put_contents("$tmp/index.php", '<?php echo "hi";');
file_put_contents("$tmp/configuration.php", '<?php $host="localhost";');
file_put_contents("$tmp/images/logo.png", "PNGDATA");
file_put_contents("$tmp/wp-content/cache/should_skip.dat", "nope");

$walker = new FileWalker($tmp);
$batch1 = $walker->listBatch('', 2);
check('batch1 count', count($batch1['files']), 2);
check('batch1 not done', $batch1['done'], false);
checkTrue('wp-content/cache excluded from listing', !in_array('wp-content/cache/should_skip.dat', array_map(fn($f) => $f['path'], $batch1['files']), true));

$batch2 = $walker->listBatch($batch1['next_cursor'], 2);
check('batch2 done (only 3 real files: index.php, configuration.php, images/logo.png)', $batch2['done'], true);

$allPaths = array_merge(
    array_map(fn($f) => $f['path'], $batch1['files']),
    array_map(fn($f) => $f['path'], $batch2['files'])
);
sort($allPaths);
check('walked files exclude wp-content/cache/', $allPaths, ['configuration.php', 'images/logo.png', 'index.php']);

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
unlink("$tmp/wp-content/cache/should_skip.dat");
rmdir("$tmp/images");
rmdir("$tmp/wp-content/cache");
rmdir("$tmp/wp-content");
rmdir($tmp);


// ── files.pack across parts, when the site is not standing still ────────────────────────────
//
// A webroot is not a frozen thing: a log grows, a cache file is rewritten, a session is
// dropped — all while the pack is running, which on a real site takes minutes. The header for
// an entry declares its size, and everything after that entry is positioned by that number, so
// an entry whose content does not match its own header shifts the rest of the archive by the
// difference. `tar` then finds content where a header should be, reports "Skipping to next
// header", loses an entry, and exits non-zero.
//
// Measured on beta.tracy.ai (2026-08-10): 11.203 of 11.204 entries survived, the break at the
// seam between two parts. This reproduces the mechanism at a size a test can afford.
$growTmp = sys_get_temp_dir() . '/tracy_pack_grow_' . bin2hex(random_bytes(4));
mkdir($growTmp);
// Bigger than one part, so it must span two of them.
file_put_contents("$growTmp/big.bin", str_repeat('A', 6 * 1024 * 1024));
file_put_contents("$growTmp/zz-after.txt", 'the entry that gets lost');

final class CapturingUploader implements Uploader
{
    public array $parts = [];
    public function put(string $url, string $body): array
    {
        $this->parts[] = $body;
        return ['ok' => true, 'etag' => '"' . md5($body) . '"'];
    }
}

$capture = new CapturingUploader();
$growEngine = new Engine('a-token-at-least-16', [], null, new FileWalker($growTmp), $capture);

$part1 = $growEngine->handle([
    'token' => 'a-token-at-least-16',
    'action' => 'files.pack',
    'params' => ['put_url' => 'https://example.test/part1', 'target_bytes' => 5 * 1024 * 1024],
]);
checkTrue('pack part 1 succeeded', $part1['ok'] === true);
checkTrue('pack part 1 stopped mid-file', $part1['next_offset'] > 0 && $part1['done'] === false);

// The site keeps living between the two calls.
file_put_contents("$growTmp/big.bin", str_repeat('A', 6 * 1024 * 1024) . str_repeat('B', 4096));

$part2 = $growEngine->handle([
    'token' => 'a-token-at-least-16',
    'action' => 'files.pack',
    'params' => [
        'put_url' => 'https://example.test/part2',
        'target_bytes' => 5 * 1024 * 1024,
        'path' => $part1['next_path'],
        'offset' => $part1['next_offset'],
        'size' => $part1['next_size'] ?? 0,
    ],
]);
checkTrue('pack part 2 succeeded', $part2['ok'] === true);

$archive = implode('', $capture->parts);
$tarFile = "$growTmp.tar";
file_put_contents($tarFile, $archive);
exec('tar -tf ' . escapeshellarg($tarFile) . ' 2>&1', $listed, $status);
check('a growing file does not corrupt the archive', $status, 0);
check('every entry survives', count(array_filter($listed, fn($l) => strpos($l, 'tar:') !== 0)), 2);

// And the other direction: a file TRUNCATED mid-pack. Stopping short would shift the archive
// exactly as growing does, so the remainder is zero-filled to the size the header declared.
$shrinkTmp = sys_get_temp_dir() . '/tracy_pack_shrink_' . bin2hex(random_bytes(4));
mkdir($shrinkTmp);
file_put_contents("$shrinkTmp/big.bin", str_repeat('A', 6 * 1024 * 1024));
file_put_contents("$shrinkTmp/zz-after.txt", 'still here');

$shrinkCapture = new CapturingUploader();
$shrinkEngine = new Engine('a-token-at-least-16', [], null, new FileWalker($shrinkTmp), $shrinkCapture);
$s1 = $shrinkEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'files.pack',
    'params' => ['put_url' => 'https://example.test/p1', 'target_bytes' => 5 * 1024 * 1024]]);
file_put_contents("$shrinkTmp/big.bin", str_repeat('A', 1024)); // the log got rotated
$s2 = $shrinkEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'files.pack',
    'params' => ['put_url' => 'https://example.test/p2', 'target_bytes' => 5 * 1024 * 1024,
                 'path' => $s1['next_path'], 'offset' => $s1['next_offset'], 'size' => $s1['next_size']]]);
checkTrue('pack survives a file that shrank', $s2['ok'] === true);
$shrinkTar = "$shrinkTmp.tar";
file_put_contents($shrinkTar, implode('', $shrinkCapture->parts));
exec('tar -tf ' . escapeshellarg($shrinkTar) . ' 2>&1', $shrunkList, $shrunkStatus);
check('a truncated file does not corrupt the archive', $shrunkStatus, 0);
check('the entry after it is still there', count(array_filter($shrunkList, fn($l) => strpos($l, 'tar:') !== 0)), 2);

// ── files.pack when the HEADER is what fills the part ────────────────────────────────────────
//
// The guard above stops before writing a header into a part that is ALREADY full. It does not
// stop when writing the header is what fills it: the read loop then never runs, $offset stays 0,
// and the cursor points back at this same file at offset 0 — so the next part writes its header
// a SECOND time. 512 spare bytes, and everything after them shifted.
//
// Measured on beta.tracy.ai (2026-08-10) at two seams, both at exact multiples of the 8 MiB part
// size: one extra block of content, tar losing an entry each time. The fixture below lands a
// header exactly on the boundary on purpose.
$edgeTmp = sys_get_temp_dir() . '/tracy_pack_edge_' . bin2hex(random_bytes(4));
mkdir($edgeTmp);
$target = 5 * 1024 * 1024;
// header (512) + content = target - 512, so the NEXT header lands exactly on the target.
file_put_contents("$edgeTmp/a-big.bin", str_repeat('A', $target - 1024));
file_put_contents("$edgeTmp/b-on-the-seam.txt", 'the entry whose header lands on the boundary');
file_put_contents("$edgeTmp/c-after.txt", 'and the one after it');

$edgeCapture = new CapturingUploader();
$edgeEngine = new Engine('a-token-at-least-16', [], null, new FileWalker($edgeTmp), $edgeCapture);
$cursor = ['put_url' => 'https://example.test/e1', 'target_bytes' => $target];
for ($i = 0; $i < 5; $i++) {
    $r = $edgeEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'files.pack', 'params' => $cursor]);
    if (!$r['ok']) { break; }
    if ($r['done']) { break; }
    $cursor = ['put_url' => "https://example.test/e" . ($i + 2), 'target_bytes' => $target,
               'path' => $r['next_path'], 'offset' => $r['next_offset'], 'size' => $r['next_size']];
}
$edgeTar = "$edgeTmp.tar";
file_put_contents($edgeTar, implode('', $edgeCapture->parts));
exec('tar -tf ' . escapeshellarg($edgeTar) . ' 2>&1', $edgeList, $edgeStatus);
check('a header landing on a part boundary does not duplicate', $edgeStatus, 0);
check('all three entries survive', count(array_filter($edgeList, fn($l) => strpos($l, 'tar:') !== 0)), 3);

// ── every non-trailing part is exactly the same length ──────────────────────────────────────
//
// R2 refuses to assemble an upload whose non-trailing parts differ ("All non-trailing parts must
// have the same length"), where S3 tolerates it. Measured against the real store on 2026-08-10,
// after 21 parts and 167 MB had already been sent — the whole upload thrown away at the last
// call. It is the reason the cursor counts bytes within an entry rather than within a file.
$evenTmp = sys_get_temp_dir() . '/tracy_pack_even_' . bin2hex(random_bytes(4));
mkdir($evenTmp);
// Sizes chosen to land headers all over the place relative to the part boundary.
foreach ([3_000_000, 17, 2_500_000, 900_000, 1_048_576, 33, 4_000_000] as $i => $bytes) {
    file_put_contents(sprintf('%s/f%02d.bin', $evenTmp, $i), str_repeat(chr(65 + $i), $bytes));
}
$evenTarget = 5 * 1024 * 1024;
$evenCapture = new CapturingUploader();
$evenEngine = new Engine('a-token-at-least-16', [], null, new FileWalker($evenTmp), $evenCapture);
$c = ['put_url' => 'https://example.test/1', 'target_bytes' => $evenTarget];
for ($i = 0; $i < 20; $i++) {
    $r = $evenEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'files.pack', 'params' => $c]);
    if (!$r['ok'] || $r['done']) { break; }
    $c = ['put_url' => 'https://example.test/' . ($i + 2), 'target_bytes' => $evenTarget,
          'path' => $r['next_path'], 'offset' => $r['next_offset'], 'size' => $r['next_size']];
}
$sizes = array_map('strlen', $evenCapture->parts);
$nonTrailing = array_slice($sizes, 0, -1);
checkTrue('more than one part, or the case is not exercised', count($sizes) > 1);
check('every non-trailing part is exactly the target size', array_unique($nonTrailing), [$evenTarget]);
checkTrue('the trailing part is no larger than the target', end($sizes) <= $evenTarget + 1024);

$evenTar = "$evenTmp.tar";
file_put_contents($evenTar, implode('', $evenCapture->parts));
exec('tar -tf ' . escapeshellarg($evenTar) . ' 2>&1', $evenList, $evenStatus);
check('and the archive is still whole', $evenStatus, 0);
check('with every file in it', count(array_filter($evenList, fn($l) => strpos($l, 'tar:') !== 0)), 7);

// ------------------------------------------- long paths and stray backups ---
//
// Both learned from one real site (juneflower.vn, 2026-08-14): a webpack chunk shipped by
// Elementor Pro whose filename alone is 101 characters, and a 1.6 GB backup archive sitting in
// the webroot that a clone has no use for. The first killed the run outright; the second was
// most of the twenty minutes it spent before dying.

$longTmp = sys_get_temp_dir() . '/tracy_longpath_' . bin2hex(random_bytes(4));
mkdir("$longTmp/wp-content/plugins/elementor-pro/assets/js/notes", 0777, true);

// Exactly the file that stopped the real run: 101 characters, no directory boundary to split on.
$longName = 'vendors-node_modules_radix-ui_react-alert-dialog_dist_index_module_js-node_modules_radix-ui_r-c71607.js';
$longRel  = "wp-content/plugins/elementor-pro/assets/js/notes/{$longName}";
file_put_contents("$longTmp/$longRel", 'chunk');
file_put_contents("$longTmp/index.php", '<?php');

// And a backup archive of the kind plugins drop straight in the webroot.
file_put_contents("$longTmp/backup-example.com-1-19-2026.tar.gz", 'not a real archive');
file_put_contents("$longTmp/db-export.sql.gz", 'nor this');

$longWalker = new FileWalker($longTmp);
$longPaths  = array_map(fn($f) => $f['path'], $longWalker->listBatch('', 50)['files']);

checkTrue('a backup archive in the webroot is left out', !in_array('backup-example.com-1-19-2026.tar.gz', $longPaths, true));
checkTrue('a loose database dump is left out', !in_array('db-export.sql.gz', $longPaths, true));
checkTrue('the long-named chunk is still packed', in_array($longRel, $longPaths, true));
checkTrue('ordinary files are untouched', in_array('index.php', $longPaths, true));

// The originals EWWW Image Optimizer keeps: a second, heavier copy of the whole media library
// that no page ever requests. Nearly half of what juneflower.vn was carrying (2026-08-14).
mkdir("$longTmp/wp-content/ew-backup/2023/03", 0777, true);
file_put_contents("$longTmp/wp-content/ew-backup/2023/03/IMG_3418-scaled.jpg", 'the untouched original');
mkdir("$longTmp/wp-content/uploads/2023/03", 0777, true);
file_put_contents("$longTmp/wp-content/uploads/2023/03/IMG_3418-scaled.jpg", 'the one the site serves');
// And a dump left somewhere other than the webroot root.
mkdir("$longTmp/wp-content/uploads/2024", 0777, true);
file_put_contents("$longTmp/wp-content/uploads/2024/old-site.sql", 'SELECT 1');

$ewPaths = array_map(fn($f) => $f['path'], (new FileWalker($longTmp))->listBatch('', 50)['files']);
checkTrue('EWWW originals are left out', !in_array('wp-content/ew-backup/2023/03/IMG_3418-scaled.jpg', $ewPaths, true));
checkTrue('the image the site actually serves is kept', in_array('wp-content/uploads/2023/03/IMG_3418-scaled.jpg', $ewPaths, true));
checkTrue('a dump buried in uploads is left out too', !in_array('wp-content/uploads/2024/old-site.sql', $ewPaths, true));

// The archive has to be readable by tar itself, not merely by us.
$longCapture = new CapturingUploader();
$longEngine  = new Engine('a-token-at-least-16', [], null, new FileWalker($longTmp), $longCapture);
$cursor = ''; $offset = 0; $declared = 0; $guard = 0;
do {
    $r = $longEngine->handle([
        'token'  => 'a-token-at-least-16',
        'action' => 'files.pack',
        'params' => ['put_url' => 'https://example.test/p', 'target_bytes' => 4096,
                     'path' => $cursor, 'offset' => $offset, 'size' => $declared],
    ]);
    $cursor = $r['next_path'] ?? ''; $offset = $r['next_offset'] ?? 0; $declared = $r['next_size'] ?? 0;
} while (empty($r['done']) && ++$guard < 60);

$longTar = "$longTmp/out.tar";
file_put_contents($longTar, implode('', $longCapture->parts));
exec('tar -tf ' . escapeshellarg($longTar) . ' 2>&1', $longList, $longStatus);
check('tar reads the archive with a 101-character filename', $longStatus, 0);
checkTrue('and the long path survives the round trip', in_array($longRel, array_map('trim', $longList), true));


// ---- ChangeStamp: the hint a watching preview reads ----------------------------------------

$stampRoot = sys_get_temp_dir() . '/cowork-stamp-' . bin2hex(random_bytes(4));
mkdir($stampRoot);
$stamp = new ChangeStamp($stampRoot);

checkTrue('nothing to read before anything changed', $stamp->read() === null);

$stamp->touch('theme');
$first = $stamp->read();
checkTrue('a change is recorded', is_array($first) && isset($first['at']));
check('and says coarsely what kind it was', $first['reason'] ?? null, 'theme');

// One user action fires several hooks. Collapsing them is what keeps a preview from reloading
// three times for one edit — and a reload mid-edit is worse than a reload a second late.
$stamp->touch('content');
check('a second change in the same second does not overwrite the first', $stamp->read()['reason'], 'theme');

// The file is polled every few seconds by something that will parse it. A reader must never
// catch it half-written, which is why the write goes through a temporary file and a rename.
checkTrue('no temporary file is left behind', !file_exists($stampRoot . '/' . ChangeStamp::FILENAME . '.tmp'));

// This runs inside a customer's admin request. A hardened host with a read-only webroot must
// lose the auto-reload, not the admin screen.
$readOnly = sys_get_temp_dir() . '/cowork-ro-' . bin2hex(random_bytes(4));
mkdir($readOnly, 0500);
(new ChangeStamp($readOnly))->touch('theme');
checkTrue('an unwritable webroot is survived silently', (new ChangeStamp($readOnly))->read() === null);
@rmdir($readOnly);

array_map('unlink', glob($stampRoot . '/*'));
@rmdir($stampRoot);


// ---- Plugins and themes: what the engine does with a package manager -----------------------

$pkgEngine = new Engine('a-token-at-least-16', [], null, null, null, new FakePackages());
$call = static function (string $action, array $params = []) use ($pkgEngine): array {
    return $pkgEngine->handle(['token' => 'a-token-at-least-16', 'action' => $action, 'params' => $params]);
};

// WordPress keeps plugins and themes apart, and so does the action list — no `kind` parameter
// that every caller has to learn.
check('plugins are listed under their own action', $call('plugin.list')['plugins'][0]['file'], 'akismet/akismet.php');
check('themes are listed under theirs', $call('theme.list')['themes'][0]['stylesheet'], 'twentytwentytwo');

// The per-site core source (ADR 0070 addendum): passed through verbatim, verdicts included.
check('core.manifest names the platform', $call('core.manifest')['manifest']['platform'], 'wordpress');
check('core.manifest keeps the child-theme verdict', $call('core.manifest')['manifest']['extensions'][1]['core'], false);

// The URL is checked before the site is asked to fetch anything: one https .zip, so the
// installer can never be pointed at a path on disk.
check('http is refused', $call('plugin.install', ['url' => 'http://example.test/p.zip'])['message'], 'https required');
check('a non-zip is refused', $call('theme.install', ['url' => 'https://example.test/p.tar'])['message'], 'package URL must end in .zip');
check('a missing url is a caller mistake, not a failed install', $call('plugin.install')['error'], 'bad_params');
check('http is classified the same way', $call('plugin.install', ['url' => 'http://e.test/p.zip'])['error'], 'bad_params');

// Install stops at installed. Activation is separate because they fail differently — a package
// can install fine and still refuse to run.
$installed = $call('theme.install', ['url' => 'https://example.test/tt2.zip']);
check('installing a theme reports what arrived', $installed['installed']['stylesheet'], 'twentytwentytwo');
checkTrue('and does not switch to it', FakePackages::$active === 'tracy');

// Switching returns the theme it replaced, so a caller can put it back without having read the
// site first — the one piece of state a switch destroys.
$switched = $call('theme.activate', ['stylesheet' => 'twentytwentytwo']);
check('activating a theme says what it replaced', $switched['previous'], 'tracy');
check('and the site is on the new one', FakePackages::$active, 'twentytwentytwo');

check('a theme that is not there is refused', $call('theme.activate', ['stylesheet' => 'nope'])['error'], 'activate_failed');

// Switching a plugin on destroys the same kind of state a theme switch does: whether it was
// already running. What this covers is the ENGINE contract: the prior state the packages layer
// reports is carried out to the caller. It does NOT cover Claude_Cowork_Packages itself, which
// no test in this suite reaches - it needs the wp-admin includes, and tests/wp/ does not exist.
// So the site-local `active_plugins` read added alongside this is unguarded here. Activating an active plugin is a no-op, so a caller that undid it by
// deactivating would switch off a plugin the site was legitimately running.
FakePackages::$activePlugins = [];
$firstOn = $call('plugin.activate', ['file' => 'akismet/akismet.php']);
checkTrue('activating a plugin reports that it was off before', $firstOn['was_active'] === false);
$againOn = $call('plugin.activate', ['file' => 'akismet/akismet.php']);
checkTrue('and reports that it was already on the second time', $againOn['was_active'] === true);

// A site wired for reading only must refuse every write action rather than half-answering.
$readOnlyEngine = new Engine('a-token-at-least-16', []);
$refused = $readOnlyEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'theme.install', 'params' => ['url' => 'https://e.test/x.zip']]);
check('a read-only site refuses to install', $refused['error'], 'unavailable');

// -------------------------------------------------------------- Apply, and its undo --
//
// The write side against fakes: what is checked is the engine's promise — every edit is recorded
// before it counts as done, and an edit whose undo cannot be recorded is rolled back rather than
// left standing (ADR 0048).

echo "\nApply\n";

$WTOKEN = 'write-token-at-least-16chars';
$writer = new FakeSiteWriter();
$mediaW = new FakeMediaWriter();
$log = new FakeApplyLog();
$wEngine = new Engine($WTOKEN, [], null, null, null, null, $writer, $mediaW, $log);
$apply = static function (string $action, array $params) use ($wEngine, $WTOKEN): array {
    return $wEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
};

// A new post, then revert -> it is gone.
$ins = $apply('content.update', ['apply_id' => 'A1', 'kind' => 'post', 'fields' => ['post_title' => 'Hello']]);
check('content.update inserts', $ins['ok'], true);
check('an insert reports created', $ins['created'], true);
$newId = $ins['id'];
check('the post is now on the site', $writer->read('post', $newId), ['post_title' => 'Hello']);
check('the write was logged under its apply', count($log->entries('A1')), 1);
check('a content write purges cache', $writer->purges, 1);

$rev = $apply('apply.revert', ['apply_id' => 'A1']);
check('revert reports what it undid', $rev['reverted'], 1);
check('reverting an insert deletes the post', $writer->read('post', $newId), null);
check('a reverted apply is forgotten', count($log->entries('A1')), 0);

// An existing post, then revert -> the old words come back.
$writer->store['post'][7] = ['post_title' => 'Old title'];
$upd = $apply('content.update', ['apply_id' => 'A2', 'kind' => 'post', 'id' => 7, 'fields' => ['post_title' => 'New title']]);
check('updating an existing post is not a create', $upd['created'], false);
check('the new title is live', $writer->read('post', 7), ['post_title' => 'New title']);
$apply('apply.revert', ['apply_id' => 'A2']);
check('reverting an update restores the before-state', $writer->read('post', 7), ['post_title' => 'Old title']);

// Post meta: the same bookkeeping, addressed by post id AND key. This is where the SEO plugins
// keep a description, so it is the kind an Apply reaches for most.
$meta = $apply('content.update', [
    'apply_id' => 'A3', 'kind' => 'postmeta', 'id' => 7, 'key' => '_yoast_wpseo_metadesc',
    'fields' => ['value' => 'A description that fits.'],
]);
check('a new meta reports created', $meta['created'], true);
check('the apply names the key it wrote', $meta['key'], '_yoast_wpseo_metadesc');
check('the meta is on the post', $writer->read('postmeta', 7, '_yoast_wpseo_metadesc'), ['value' => 'A description that fits.']);
$apply('apply.revert', ['apply_id' => 'A3']);
check('reverting a new meta removes it', $writer->read('postmeta', 7, '_yoast_wpseo_metadesc'), null);

// An option is a name with no id at all — the third shape, and the reason `key` exists.
$writer->store['option']['0:blogname'] = ['value' => 'Old name'];
$apply('content.update', ['apply_id' => 'A4', 'kind' => 'option', 'key' => 'blogname', 'fields' => ['value' => 'New name']]);
check('an option write lands', $writer->read('option', 0, 'blogname'), ['value' => 'New name']);
$apply('apply.revert', ['apply_id' => 'A4']);
check('reverting an option restores the old value', $writer->read('option', 0, 'blogname'), ['value' => 'Old name']);

// Media: a new file, and the attachment that makes it visible in the library, both undone.
$mw = $apply('media.upload', [
    'apply_id' => 'M1', 'path' => 'wp-content/uploads/2026/08/logo.png', 'content_b64' => base64_encode('PNGDATA'),
]);
check('media.upload lands the file', $mediaW->read('wp-content/uploads/2026/08/logo.png'), 'PNGDATA');
check('a new upload reports created', $mw['created'], true);
checkTrue('a new upload joins the media library', $mw['attachment'] > 0);
$apply('apply.revert', ['apply_id' => 'M1']);
check('reverting a new upload deletes the file', $mediaW->read('wp-content/uploads/2026/08/logo.png'), null);
check('and takes its attachment with it', $mediaW->attachments, []);

// Media: replacing a file keeps the attachment it already had, and the old bytes come back.
$mediaW->store['wp-content/uploads/hero.jpg'] = 'OLDBYTES';
$over = $apply('media.upload', [
    'apply_id' => 'M2', 'path' => 'wp-content/uploads/hero.jpg', 'content_b64' => base64_encode('NEWBYTES'),
]);
check('overwrite lands the new bytes', $mediaW->read('wp-content/uploads/hero.jpg'), 'NEWBYTES');
check('replacing a file creates no second attachment', $over['attachment'], 0);
$apply('apply.revert', ['apply_id' => 'M2']);
check('reverting an overwrite restores the old bytes', $mediaW->read('wp-content/uploads/hero.jpg'), 'OLDBYTES');

// apply.list names what was touched, without the before payload.
$apply('content.update', ['apply_id' => 'L1', 'kind' => 'post', 'fields' => ['post_content' => 'x']]);
$apply('media.upload', ['apply_id' => 'L1', 'path' => 'wp-content/uploads/a.png', 'content_b64' => base64_encode('a')]);
$list = $apply('apply.list', ['apply_id' => 'L1']);
check('apply.list returns every step', count($list['steps']), 2);
check('apply.list names the content kind', $list['steps'][0]['kind'], 'post');
checkTrue('apply.list does not leak the before payload', !isset($list['steps'][0]['before']));

// Refusals.
check(
    'an unknown kind is refused',
    $apply('content.update', ['apply_id' => 'X', 'kind' => 'user', 'fields' => ['a' => 'b']])['error'],
    'bad_params'
);
check(
    'a write without an apply_id is refused',
    $apply('content.update', ['kind' => 'post', 'fields' => ['post_title' => 'x']])['message'],
    'apply_id required'
);

$traversal = $apply('media.upload', ['apply_id' => 'X', 'path' => '../wp-config.php', 'content_b64' => base64_encode('x')]);
check('a traversing media path is refused', $traversal['message'], 'unusable media path');
checkTrue('nothing was written outside uploads', !isset($mediaW->store['../wp-config.php']));

// A well-formed path outside uploads is live code, not an asset.
$notMedia = $apply('media.upload', ['apply_id' => 'X', 'path' => 'wp-content/plugins/evil.php', 'content_b64' => base64_encode('x')]);
check('a media write outside uploads is refused', $notMedia['message'], 'unusable media path');
checkTrue('live code was not touched', !isset($mediaW->store['wp-content/plugins/evil.php']));

$tooBig = $apply('media.upload', [
    'apply_id' => 'X', 'path' => 'wp-content/uploads/big.bin',
    'content_b64' => base64_encode(str_repeat('A', 8 * 1024 * 1024 + 1)),
]);
check('a media upload past the inline ceiling is refused', $tooBig['error'], 'too_large');

$wrongToken = $wEngine->handle(['token' => 'nope-but-long-enough-here', 'action' => 'content.update',
    'params' => ['apply_id' => 'X', 'kind' => 'post', 'fields' => ['post_title' => 'x']]]);
check('a wrong token writes nothing', $wrongToken['error'], 'unauthorized');

$unwired = $readOnlyEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'content.update',
    'params' => ['apply_id' => 'X', 'kind' => 'post', 'fields' => ['post_title' => 'x']]]);
check('an unwired site refuses content writes', $unwired['error'], 'unavailable');

// The rollback promise: a write whose undo cannot be recorded is undone, not left standing.
$rbWriter = new FakeSiteWriter();
$rbEngine = new Engine($WTOKEN, [], null, null, null, null, $rbWriter, new FakeMediaWriter(), new FailingApplyLog());
$rb = $rbEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'R1', 'kind' => 'post', 'fields' => ['post_title' => 'ghost']]]);
check('a write whose undo cannot be recorded fails', $rb['ok'], false);
check('and the site does not keep it', $rbWriter->store['post'] ?? [], []);

// ------------------------------------------------ What the real writer will and will not write --
//
// The rules above are the engine's. These are the writer's, and they are the ones that decide what
// reaches a customer's live site: which post fields are accepted, which options are refused.

echo "\nSite writer rules\n";

$siteWriter = new Claude_Cowork_Site_Writer();

WP_Fake::$posts[42] = ['ID' => 42, 'post_title' => 'A page', 'post_author' => 3];
$siteWriter->write('post', 42, ['post_title' => 'Renamed', 'post_author' => 99]);
check('a whitelisted field is written', WP_Fake::$posts[42]['post_title'], 'Renamed');
check('a field outside the whitelist is not', WP_Fake::$posts[42]['post_author'], 3);

$refusedFields = null;
try {
    $siteWriter->write('post', 42, ['comment_status' => 'closed']);
} catch (RuntimeException $e) {
    $refusedFields = $e->getMessage();
}
checkTrue('a write with no writable field is refused, not silently dropped', $refusedFields !== null);
checkTrue('and the refusal names what was allowed', strpos((string) $refusedFields, 'post_title') !== false);

// A new post is a draft unless the caller says otherwise: a page appearing live on a customer's
// site because nobody mentioned a status is the one outcome an Apply must not produce.
$draftId = $siteWriter->write('post', 0, ['post_title' => 'Fresh']);
check('a new post is not published by default', WP_Fake::$posts[$draftId]['post_status'], 'draft');
check('and it is a post unless told otherwise', WP_Fake::$posts[$draftId]['post_type'], 'post');

// Post meta, including the underscore keys the SEO plugins use.
$siteWriter->write('postmeta', 42, ['value' => 'Fits in a SERP.'], '_yoast_wpseo_metadesc');
check('protected meta keys are writable', WP_Fake::$meta['42:_yoast_wpseo_metadesc'], 'Fits in a SERP.');
check('a meta reads back as its before-state', $siteWriter->read('postmeta', 42, '_yoast_wpseo_metadesc'), ['value' => 'Fits in a SERP.']);
check('a meta that was never set reads as absent', $siteWriter->read('postmeta', 42, '_nothing_here'), null);

$noPost = null;
try {
    $siteWriter->write('postmeta', 4242, ['value' => 'x'], '_k');
} catch (RuntimeException $e) {
    $noPost = $e->getMessage();
}
checkTrue('a meta on a post that does not exist is refused', $noPost !== null);

// Options: writable, except the ones that would take the site away from whoever has to fix it.
$siteWriter->write('option', 0, ['value' => 'Tracy demo'], 'blogname');
check('an ordinary option is written', WP_Fake::$options['blogname'], 'Tracy demo');

WP_Fake::$options['siteurl'] = 'https://real.test';
foreach (['siteurl', 'active_plugins', 'claude_cowork_token', '_transient_anything'] as $protected) {
    $stopped = null;
    try {
        $siteWriter->write('option', 0, ['value' => 'hijacked'], $protected);
    } catch (RuntimeException $e) {
        $stopped = $e->getMessage();
    }
    checkTrue("{$protected} cannot be written", $stopped !== null);
}
check('and the protected value is untouched', WP_Fake::$options['siteurl'], 'https://real.test');

// An option that is absent reads as absent, not as false — so its undo is a delete, not a write
// of the default WordPress happened to hand back.
check('an option that was never set reads as absent', $siteWriter->read('option', 0, 'never_set_option'), null);
WP_Fake::$options['stored_false'] = false;
check('an option legitimately holding false is not mistaken for absent', $siteWriter->read('option', 0, 'stored_false'), ['value' => false]);

// The media writer's own guard, independent of the engine's string check.
$mediaWriter = new Claude_Cowork_Media_Writer(__DIR__);
$escaped = null;
try {
    $mediaWriter->read('wp-content/uploads/../../../etc/passwd');
} catch (RuntimeException $e) {
    $escaped = $e->getMessage();
}
checkTrue('the media writer refuses a path that escapes uploads', $escaped !== null);

// ------------------------------------------------------- taking an update on request --
// WordPress finds updates on its own clock: our manifest answer is cached six hours, and the cron
// that acts on it runs twice a day. Right for a site nobody watches; wrong for the minutes after a
// release, when a fix can be published and still be half a day from the site it was written for.
// This action is the same work brought forward, and it takes no parameters — which version to
// install is `update.json`'s answer, never a caller's.

FakePackages::$selfUpdate = ['ok' => true, 'updated' => true, 'before' => '0.6.1', 'after' => '0.6.2'];
$took = $pkgEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'plugin.selfUpdate', 'params' => []]);
check('plugin.selfUpdate answers', $took['ok'], true);
check('and says which version it left', $took['before'], '0.6.1');
check('and which one it arrived at', $took['after'], '0.6.2');

// The common answer, and not a failure: nothing newer is announced.
FakePackages::$selfUpdate = ['ok' => true, 'updated' => false, 'before' => '0.6.2', 'after' => '0.6.2'];
$none = $pkgEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'plugin.selfUpdate', 'params' => []]);
check('already current is an ok, not an error', $none['ok'], true);
check('and says nothing moved', $none['updated'], false);

// An upgrade that reports success and leaves the old version is the exact failure this action
// exists to end — it must not be answered as ok. (25/08/2026: an upload said `ready` while the
// site stayed three versions behind, and it cost a day.)
FakePackages::$selfUpdate = ['ok' => false, 'error' => 'upgrade reported success but the version is still 0.6.1'];
$stuck = $pkgEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'plugin.selfUpdate', 'params' => []]);
check('an upgrade that did not land is an error', $stuck['ok'], false);
check('and it is told apart from an install failure', $stuck['error'], 'update_failed');

// ------------------------------------------------------------ update manifest --
// The Plugins screen asks raw.githubusercontent for update.json and compares it against the
// header of the copy on disk. Two files must therefore agree about one number, and nothing but
// this check would notice the day they stop.
//
// update.json sits at `wordpress/`, not here beside the plugin it describes, and must stay there:
// the URL is compiled into every plugin copy already running on a site, so the path is a published
// address. Tidying it into cowork/ would 404 them all, silently.

$pluginHeader = file_get_contents(__DIR__ . '/../claude-cowork/claude-cowork.php');
preg_match('/^\s*\*\s*Version:\s*(.+)$/m', $pluginHeader, $m);
$headerVersion = trim($m[1] ?? '');
$manifest = json_decode(file_get_contents(__DIR__ . '/../../update.json'), true);

check('update.json names the version the plugin header does', $manifest['version'] ?? null, $headerVersion);
checkTrue(
    'the package it points at is the release asset for that version',
    ($manifest['package'] ?? '') === "https://github.com/TracyHQ/claude-cowork/releases/download/wordpress-v{$headerVersion}/claude-cowork-{$headerVersion}.zip"
);
// The header selects the filter name: change the host and the hook in update.php is never called.
checkTrue('the plugin declares an Update URI on github.com', (bool) preg_match('#^\s*\*\s*Update URI:\s*https://github\.com/#m', $pluginHeader));

// Knowing a newer version exists is not the same as getting it. WordPress shows the Plugins screen
// a notice and waits for somebody to press Update — which on a site nobody administers by hand
// means the plugin stays on whatever shipped the day it was installed, and every fix after that is
// dead code there. Measured 25/08/2026: tracy.ai ran 0.4.0 while the release was 0.6.0, so every
// `templatePart` write was refused by a plugin that had never heard of the kind.
$updateSource = file_get_contents(__DIR__ . '/../claude-cowork/update.php');
// A cache of our own must not survive an explicit re-check. WordPress clears its update cache when
// somebody presses "Check again"; a plugin that keeps answering from six hours ago turns that
// button into a no-op, which is exactly what it was on 25/08/2026 with a release minutes old.
checkTrue(
    'an explicit force-check bypasses our own cache',
    (bool) preg_match('/force-check/', $updateSource)
);
checkTrue(
    'the plugin asks WordPress to update it without being asked',
    (bool) preg_match('/add_filter\(\s*[\'"]auto_update_plugin[\'"]/', $updateSource)
);
// Scoped to this plugin's own file. The filter runs for EVERY plugin on the site, so returning a
// blanket true here would switch on automatic updates for extensions that are not ours — on a
// customer's site, with their business on it.
checkTrue(
    'and only for its own file',
    (bool) preg_match('/claude_cowork_auto_update/', $updateSource)
);

// the read half of the content mirror (ADR 0071)
$writer->posts = [
    ['id' => 7, 'type' => 'post', 'title' => 'Hello', 'slug' => 'hello', 'content' => '<p>Body</p>', 'excerpt' => ''],
    ['id' => 9, 'type' => 'page', 'title' => 'About', 'slug' => 'about', 'content' => '<p>Us</p>', 'excerpt' => ''],
];
$lst = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => []]);
check('content.list answers', $lst['ok'], true);
check('content.list returns posts in id order', array_column($lst['items'], 'id'), [7, 9]);
check('a summary page carries no bodies', isset($lst['items'][0]['content']), false);
$full = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => ['include_body' => true]]);
check('include_body carries the body', $full['items'][0]['content'], '<p>Body</p>');
check('content.list pages by offset',
    array_column($wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list',
        'params' => ['offset' => 1, 'limit' => 1]])['items'], 'id'), [9]);
check('content.get names a missing row', $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.get',
    'params' => ['id' => 404]])['error'], 'not_found');
check('content.get requires an id', $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.get',
    'params' => []])['error'], 'bad_params');
$writer->posts = [];

// --------------------------------------------- the guard against dying silently --

// A fatal is the one failure the engine's own try/catch cannot reach: memory exhausted, a
// timeout, a stack overflow. PHP prints its notice and stops, so what leaves the site is not
// JSON — and the relay turns that into a bare COMPONENT_BAD_RESPONSE with the cause thrown away.
// Measured 24/08/2026 on tracy.ai: two writes died this way, ninety minutes apart, and both
// arrived as the same 502 while the fatal named its file and line the whole time.
//
// The judgement is kept apart from the printing precisely so it can be checked here, with no
// web server, no shutdown and no real fatal — `payloadFor()` decides, `arm()` only prints.
require_once __DIR__ . '/../lib/FatalGuard.php';

$answer = FatalGuard::payloadFor([
    'type'    => E_ERROR,
    'message' => 'Allowed memory size of 134217728 bytes exhausted',
    'file'    => '/var/www/html/wp-includes/post.php',
    'line'    => 4210,
]);
check('a fatal answers as a refusal, not as silence', $answer['ok'] ?? null, false);
check('and is named as a fatal, so it is not mistaken for a refused write', $answer['error'] ?? null, 'fatal');
checkTrue(
    'the message keeps the file and line, which is the whole point of the line',
    str_contains((string) ($answer['message'] ?? ''), 'wp-includes/post.php:4210')
);
checkTrue(
    'and keeps what PHP said went wrong',
    str_contains((string) ($answer['message'] ?? ''), 'memory size')
);

// A warning is not a death: the request finished, and its real answer must travel untouched.
check('a mere warning leaves the answer alone', FatalGuard::payloadFor([
    'type'    => E_WARNING,
    'message' => 'Undefined array key "x"',
    'file'    => '/w/x.php',
    'line'    => 1,
]), null);
check('and a clean request is left alone too', FatalGuard::payloadFor(null), null);


// ------------------------------------------------------ templatePart (the "code" kind) --

// The kind that changes what the site LOOKS like. Joomla has had this since the beginning
// (`module`, `templateStyle`); WordPress had post/postmeta/option, all content and configuration,
// so on a block theme — where the header IS a template part — Apply could reach a site's words
// and never its appearance. Measured 24/08/2026 on tracy.ai: a logo swap was impossible on the
// live site for exactly this reason, while the same swap on Joomla is a module edit.

WP_Fake::reset();
$tpWriter = new Claude_Cowork_Site_Writer();

// Nothing overrides the theme yet, which is what makes the undo of the first write a delete —
// and a delete is what hands the part back to the theme's own file.
check('an untouched part reads as absent', $tpWriter->read('templatePart', 0, 'header'), null);

$tpId = $tpWriter->write('templatePart', 0, ['content' => '<!-- wp:site-title /-->', 'area' => 'header'], 'header');
checkTrue('writing one yields an id', $tpId > 0);

$row = WP_Fake::$posts[$tpId];
check('it is filed as a template part', $row['post_type'], 'wp_template_part');
check('under the slug the caller named', $row['post_name'], 'header');
// Published, not drafted. A drafted override is never rendered, so the caller would be told the
// write succeeded and see no change — the worst answer available.
check('and published, because a drafted override renders nothing', $row['post_status'], 'publish');
check('it carries the active theme, which is how WordPress knows the override applies',
    WP_Fake::$terms[$tpId . ':wp_theme'], ['tracy']);
check('and the area, which is how the Site Editor files it',
    WP_Fake::$terms[$tpId . ':wp_template_part_area'], ['header']);

// Second write to the same slug edits the override rather than stacking a second one: two rows
// for one part is a site whose header depends on which row WordPress reads first.
$again = $tpWriter->write('templatePart', 0, ['content' => '<!-- second -->'], 'header');
check('writing the same slug again edits the same row', $again, $tpId);
check('and there is still only one override', count(WP_Fake::$posts), 1);
check('the area survives a write that did not mention it',
    WP_Fake::$terms[$tpId . ':wp_template_part_area'], ['header']);

$before = $tpWriter->read('templatePart', 0, 'header');
check('reading it back gives the content an undo would restore', $before['content'], '<!-- second -->');
check('and the area with it', $before['area'], 'header');

// WordPress filters `post_content` on the way in when the caller lacks `unfiltered_html`: KSES
// deletes every tag outside its allow-list, `<svg>` among them, and says nothing. On 27/08/2026 a
// 16,298-character header carrying a logo went in and 3,822 characters came out — the logo gone,
// the site's own header emptied — and Apply answered ok. A writer that cannot see that has no way
// to stop reporting a success it did not achieve, so it must read back and compare.
WP_Fake::reset();
$kses = new Claude_Cowork_Site_Writer();
WP_Fake::$contentFilter = static function (string $html): string {
    return preg_replace('#<svg\b.*?</svg>#is', '', $html);
};
$sent = '<!-- wp:html --><a href="/"><svg viewBox="0 0 10 10"><path d="M0 0"/></svg></a><!-- /wp:html -->';
$threw = '';
try {
    $kses->write('templatePart', 0, ['content' => $sent, 'area' => 'header'], 'header');
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
checkTrue('a write whose content WordPress altered is refused, not reported as done', '' !== $threw);
checkTrue('and the refusal says the content was changed on the way in',
    false !== stripos($threw, 'changed') || false !== stripos($threw, 'stripped') || false !== stripos($threw, 'filtered'));
checkTrue('and it names the tag that did not survive, so the caller can pick another route',
    false !== stripos($threw, 'svg'));
WP_Fake::$contentFilter = null;

// Content that survives untouched is written exactly as before: the read-back must not become a
// second way for a good write to fail.
WP_Fake::reset();
$clean = new Claude_Cowork_Site_Writer();
$cleanId = $clean->write('templatePart', 0, ['content' => '<!-- wp:site-title /-->', 'area' => 'header'], 'header');
checkTrue('an unaltered write still succeeds', $cleanId > 0);

// WordPress normalises markup on the way in — a closing slash, a space, an entity spelled out —
// and a body that comes back LONGER has plainly lost nothing. On 27/08/2026 the first version of
// this guard refused a write that grew by a single character, which blocked a legitimate logo swap
// twice in a row while catching nothing at all. A check that stops real work to prevent nothing is
// worse than the silence it replaced.
WP_Fake::reset();
$tidy = new Claude_Cowork_Site_Writer();
WP_Fake::$contentFilter = static function (string $html): string {
    return str_replace('<img src="x.png">', '<img src="x.png" />', $html);
};
$tidyId = $tidy->write('templatePart', 0, ['content' => '<a href="/"><img src="x.png"></a>', 'area' => 'header'], 'header');
checkTrue('markup WordPress tidied, not stripped, is accepted', $tidyId > 0);

// And a body that lost real text, with every tag still standing, is still refused: KSES strips
// attributes and inline handlers too, and a caller told nothing would report that as done.
WP_Fake::reset();
$thin = new Claude_Cowork_Site_Writer();
WP_Fake::$contentFilter = static function (string $html): string {
    return str_replace(str_repeat('keep this text ', 40), '', $html);
};
$thinThrew = '';
try {
    $thin->write('templatePart', 0, ['content' => '<p>' . str_repeat('keep this text ', 40) . '</p>', 'area' => 'header'], 'header');
} catch (Throwable $e) {
    $thinThrew = $e->getMessage();
}
checkTrue('a body that came back much shorter is refused even with every tag intact', '' !== $thinThrew);
WP_Fake::$contentFilter = null;

WP_Fake::reset();
$tpWriter = new Claude_Cowork_Site_Writer();
$tpId = $tpWriter->write('templatePart', 0, ['content' => '<!-- second -->', 'area' => 'header'], 'header');

// A file that lost bytes on the way here must not land looking like a success. On 27/08/2026 an
// agent typed a 4,935-byte PNG out as base64 by hand; three characters came through wrong, the
// file arrived exactly the right size and unopenable, and the Apply answered ok. The page showed
// the wreckage. A checksum taken before the bytes left is the only thing that can see that.
$sumWriter = new FakeSiteWriter();
$sumMedia  = new FakeMediaWriter();
$sumEngine = new Engine($WTOKEN, [], null, null, null, null, $sumWriter, $sumMedia, new FakeApplyLog());
$sumApply  = static function (string $action, array $params) use ($sumEngine, $WTOKEN): array {
    return $sumEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
};
$goodBytes = 'the original bytes';
$goodSum   = hash('sha256', $goodBytes);

$okUp = $sumApply('media.upload', [
    'apply_id' => 'S1', 'path' => 'wp-content/uploads/2026/08/x.png',
    'content_b64' => base64_encode($goodBytes), 'sha256' => $goodSum,
]);
checkTrue('an upload whose checksum matches is accepted', ($okUp['ok'] ?? false) === true);

$badUp = $sumApply('media.upload', [
    'apply_id' => 'S2', 'path' => 'wp-content/uploads/2026/08/y.png',
    'content_b64' => base64_encode('the 0riginal bytes'), 'sha256' => $goodSum,
]);
check('an upload whose bytes changed on the way is refused', $badUp['ok'] ?? null, false);
check('and it is named as a corruption, not a write failure', $badUp['error'] ?? null, 'corrupt_upload');
check('and nothing was written for it', $sumMedia->read('wp-content/uploads/2026/08/y.png'), null);

$noSum = $sumApply('media.upload', [
    'apply_id' => 'S3', 'path' => 'wp-content/uploads/2026/08/z.png',
    'content_b64' => base64_encode($goodBytes),
]);
checkTrue('an older caller that sends no checksum still works', ($noSum['ok'] ?? false) === true);

// Another theme's override must be invisible: a site that switched themes keeps the old rows,
// and editing by slug alone would rewrite a header nobody has seen for months.
WP_Fake::$stylesheet = 'twentytwentyfive';
check('a part of another theme is not found', $tpWriter->read('templatePart', 0, 'header'), null);
WP_Fake::$stylesheet = 'tracy';

// Deleting the override is how the theme's own file comes back — the undo of a create, and a
// deliberate act in its own right.
$tpWriter->delete('templatePart', 0, 'header');
check('deleting the override hands the part back to the theme', $tpWriter->read('templatePart', 0, 'header'), null);

// The two ways a caller gets this wrong, both answered rather than half-written.
$refused = null;
try {
    $tpWriter->write('templatePart', 0, ['content' => 'x'], '');
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
checkTrue('a part with no slug is refused, naming what is missing',
    is_string($refused) && str_contains($refused, 'slug'));

$refused = null;
try {
    $tpWriter->write('templatePart', 0, ['area' => 'header'], 'header');
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
checkTrue('and one with no content is refused too',
    is_string($refused) && str_contains($refused, 'content'));

WP_Fake::reset();


// ---------------------------------------------------------------- content.delete --

// WordPress could create and edit and never remove. Joomla has had `content.delete` from the
// start; this is the same concept mapped onto the mechanism WordPress actually has — its own
// trash, which keeps the page recoverable from the admin screens long after this Apply's revert
// window closes. Tracy is never the only way back from a delete.

$writer->store = [];
$writer->trashed = [];
$writer->write('post', 7, ['post_title' => 'Old news', 'post_status' => 'publish']);

$del = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.delete',
    'params' => ['apply_id' => 'd1', 'kind' => 'post', 'id' => 7]]);
check('a post can be deleted', $del['ok'], true);
check('and it went to the trash rather than being erased', $writer->trashed, ['post:7']);

// The before-state is what makes it reversible, and it is read BEFORE the trash so the undo
// carries the status the page actually had.
$entries = $log->entries('d1');
check('the delete is recorded under its apply_id', count($entries), 1);
check('with the row as it stood', $entries[0]['before']['post_title'], 'Old news');
check('including the status a revert has to put back', $entries[0]['before']['post_status'], 'publish');

// Configuration has no trash to sit in. Refused with a sentence rather than throwing halfway.
$refused = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.delete',
    'params' => ['apply_id' => 'd2', 'kind' => 'option', 'key' => 'blogname']]);
check('an option cannot be deleted', $refused['error'], 'unsupported');
check('and it says so in words', str_contains((string) $refused['message'], 'cannot be deleted'), true);

$missing = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.delete',
    'params' => ['apply_id' => 'd3', 'kind' => 'post', 'id' => 404]]);
check('deleting something absent is not_found, not a silent success', $missing['error'], 'not_found');
check('and nothing was trashed for it', $writer->trashed, ['post:7']);

$noId = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.delete',
    'params' => ['apply_id' => 'd4', 'kind' => 'post']]);
check('a delete with no id is refused', $noId['ok'], false);

$writer->store = [];
$writer->trashed = [];


// ------------------------------------------------------- term and menuItem --

// Joomla edits a category as a row and a menu as another. WordPress keeps both in taxonomies, so
// a plugin that only wrote posts and options could rename neither — and a WordPress menu is a
// `nav_menu` term with `nav_menu_item` posts hanging off it, which is two shapes and therefore
// two kinds rather than one kind pretending.

WP_Fake::reset();
$tw = new Claude_Cowork_Site_Writer();

$catId = $tw->write('term', 0, ['name' => 'Guides', 'slug' => 'guides'], 'category');
checkTrue('a category can be created', $catId > 0);
check('and reads back with what an undo would restore', $tw->read('term', $catId, 'category'), [
    'name' => 'Guides', 'slug' => 'guides', 'description' => '', 'parent' => 0,
]);

$tw->write('term', $catId, ['name' => 'How-to guides'], 'category');
$renamed = $tw->read('term', $catId, 'category');
check('renaming keeps the slug, because a slug is an address', $renamed['slug'], 'guides');
check('and the new name is there', $renamed['name'], 'How-to guides');

// A taxonomy the site never registered is refused. WordPress would happily file a term in a
// vocabulary nothing reads, and the caller would be told it worked and see nothing anywhere.
$refused = null;
try {
    $tw->write('term', 0, ['name' => 'x'], 'categories');
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
checkTrue('a mistyped taxonomy is refused, naming it', is_string($refused) && str_contains($refused, 'categories'));

$refused = null;
try {
    $tw->write('term', 0, ['slug' => 'nameless'], 'category');
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
checkTrue('a new term with no name is refused', is_string($refused) && str_contains($refused, 'name'));

// A term cannot be deleted through Apply, and that is a decision rather than an omission:
// re-creating one mints a NEW id, and every post filed under the old one loses its category.
check('a term cannot be deleted through Apply', $tw->canTrash('term'), false);

// --- menu entries ---

WP_Fake::$termRows[901] = ['term_id' => 901, 'name' => 'Primary', 'slug' => 'primary',
    'description' => '', 'parent' => 0, 'taxonomy' => 'nav_menu'];

$itemId = $tw->write('menuItem', 0, ['title' => 'Pricing', 'url' => '/pricing/'], 'primary');
checkTrue('a menu entry can be created', $itemId > 0);
$item = $tw->read('menuItem', $itemId);
check('it carries the title', $item['title'], 'Pricing');
// The destination lives in meta, not in the row. A kind that wrote only the row would produce an
// entry that renders as a link to nowhere.
check('and the destination, which lives in meta rather than the row', $item['url'], '/pricing/');
check('published, because a menu entry nobody can see is not an entry',
    WP_Fake::$posts[$itemId]['post_status'], 'publish');
check('and it belongs to the menu it was written into', WP_Fake::$menuOf[$itemId], 901);

// An edit that only moves an entry must not blank the link it points at.
$tw->write('menuItem', $itemId, ['position' => 3], 'primary');
$moved = $tw->read('menuItem', $itemId);
check('moving an entry keeps its destination', $moved['url'], '/pricing/');
check('and its title', $moved['title'], 'Pricing');
check('while the position changed', $moved['position'], 3);

$refused = null;
try {
    $tw->write('menuItem', 0, ['title' => 'x'], 'no-such-menu');
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
checkTrue('writing into a menu that does not exist is refused, naming it',
    is_string($refused) && str_contains($refused, 'no-such-menu'));

check('a menu entry can be removed', $tw->canTrash('menuItem'), true);
$tw->trash('menuItem', $itemId);
check('and it is gone rather than trashed, because a trashed entry still renders',
    isset(WP_Fake::$posts[$itemId]), false);

WP_Fake::reset();


// ------------------------------------------- db.cleanup / db.restore / db.purge --

// Joomla has retired residue tables since ADR 0083; WordPress could not, and a WordPress site is
// no cleaner after a few years of installing and removing plugins. Same mechanism — rename into
// a trash name, never a DROP — with the ONE thing that could not be ported: the list of tables
// that must never be touched. The two CMSs share no table names at all.

$trashSource = new FakeRowSource([
    'wp_posts'      => ['create' => 'CREATE TABLE `wp_posts` (`ID` int)', 'rows' => [['1']]],
    'wp_postmeta'   => ['create' => 'CREATE TABLE `wp_postmeta` (`meta_id` int)', 'rows' => [['1']]],
    'wp_yoast_junk' => ['create' => 'CREATE TABLE `wp_yoast_junk` (`id` int)', 'rows' => [['1'], ['2']]],
    'wp_old_slider' => ['create' => 'CREATE TABLE `wp_old_slider` (`id` int)', 'rows' => [['1']]],
]);
$trashEngine = new Engine($WTOKEN, [], new DbDumper($trashSource));

// The check that had to be rewritten rather than copied: `wp_postmeta` holds every page's SEO and
// builder payload, and a Joomla list would have waved it straight through.
check('cleanup refuses a WordPress core table',
    $trashEngine->handle(['token' => $WTOKEN, 'action' => 'db.cleanup',
        'params' => ['tables' => ['wp_postmeta']]])['error'], 'refused');
check('and refuses a missing one',
    $trashEngine->handle(['token' => $WTOKEN, 'action' => 'db.cleanup',
        'params' => ['tables' => ['wp_nope']]])['error'], 'not_found');

$cleanup = $trashEngine->handle(['token' => $WTOKEN, 'action' => 'db.cleanup',
    'params' => ['tables' => ['wp_yoast_junk', 'wp_old_slider']]]);
check('residue tables are retired', $cleanup['ok'], true);
check('the whole batch at once', count($cleanup['renamed']), 2);
$trashName = $cleanup['renamed'][0]['to'];
checkTrue('into a trash name', strpos($trashName, '_tracy_trash_') === 0);
checkTrue('that keeps the old name, so a person can see what it was',
    str_contains($trashName, '__wp_yoast_junk'));
checkTrue('and the table is out of the list',
    !in_array('wp_yoast_junk', $trashEngine->handle(['token' => $WTOKEN, 'action' => 'db.tables'])['tables'], true));

// Reversible is the whole point of renaming rather than dropping.
$restored = $trashEngine->handle(['token' => $WTOKEN, 'action' => 'db.restore',
    'params' => ['tables' => [$trashName]]]);
check('a retired table comes back', $restored['ok'], true);
checkTrue('under the name it had',
    in_array('wp_yoast_junk', $trashEngine->handle(['token' => $WTOKEN, 'action' => 'db.tables'])['tables'], true));

// Purge is the one destructive action here, so it may only ever point at the trash.
check('purge refuses anything that is not in the trash',
    $trashEngine->handle(['token' => $WTOKEN, 'action' => 'db.purge',
        'params' => ['tables' => ['wp_yoast_junk']]])['error'], 'refused');


echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
