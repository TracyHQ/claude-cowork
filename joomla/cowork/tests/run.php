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
require_once __DIR__ . '/../lib/Extensions.php';
require_once __DIR__ . '/../lib/SiteWriter.php';
require_once __DIR__ . '/../lib/ChangeStamp.php';
require_once __DIR__ . '/../lib/Engine.php';
require_once __DIR__ . '/../lib/Door.php';
require_once __DIR__ . '/FakeRowSource.php';

// PHP 7.4 (Joomla 3's floor) has no str_contains — polyfill it so the harness runs there too.
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool { return $needle === '' || strpos($haystack, $needle) !== false; }
}

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
mkdir("$tmp/cache");
mkdir("$tmp/images");
file_put_contents("$tmp/index.php", '<?php echo "hi";');
file_put_contents("$tmp/configuration.php", '<?php $host="localhost";');
file_put_contents("$tmp/images/logo.png", "PNGDATA");
file_put_contents("$tmp/cache/should_skip.dat", "nope");
// Akeeba Backup writes multi-hundred-MB archives here (com_akeeba is 7.x and earlier); a live
// backup of www.joomlart.com left a 603 MB .sql that bloated and shifted the tar (2026-08-11).
mkdir("$tmp/administrator/components/com_akeeba/backup", 0777, true);
file_put_contents("$tmp/administrator/components/com_akeeba/backup/site-backup.sql", "SELECT 1;");

$walker = new FileWalker($tmp);
$batch1 = $walker->listBatch('', 2);
check('batch1 count', count($batch1['files']), 2);
check('batch1 not done', $batch1['done'], false);
checkTrue('cache dir excluded from listing', !in_array('cache/should_skip.dat', array_map(fn($f) => $f['path'], $batch1['files']), true));
checkTrue(
    'akeeba backup excluded from listing',
    !in_array(
        'administrator/components/com_akeeba/backup/site-backup.sql',
        array_map(fn($f) => $f['path'], array_merge($batch1['files'], $walker->listBatch($batch1['next_cursor'], 50)['files'])),
        true
    )
);

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

// --- protected paths --------------------------------------------------------
// The confinement in readFile() is about DIRECTION: realpath plus a prefix test stops
// ../../etc/passwd and a symlink pointing out of the tree. It says nothing about a
// well-formed path that stays inside. The write side already learned this — media.upload
// carries the same list, and its comment names the case verbatim. The read side did not.

function refusal(FileWalker $w, string $rel): string
{
    try {
        $w->readFile($rel);
        return '(no refusal)';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

file_put_contents("$tmp/configuration.php-2026-01-01", '<?php $host="rotated";');
file_put_contents("$tmp/.env", "SECRET=1");
$guarded = new FileWalker($tmp);

foreach (['configuration.php', './configuration.php', 'configuration.php-2026-01-01', '.env'] as $rel) {
    check("readFile refuses {$rel}", refusal($guarded, $rel), 'refused: protected path');
}

// A refusal must not echo the path back: the message would otherwise answer
// "does this file exist?" for anything the caller cares to try.
checkTrue('refusal message does not repeat the path',
    strpos(refusal($guarded, 'configuration.php'), 'configuration') === false);

check('readFile still serves ordinary files', $guarded->readFile('index.php'), '<?php echo "hi";');

try {
    $guarded->absolutePath('configuration.php');
    $absRefused = '(no refusal)';
} catch (RuntimeException $e) {
    $absRefused = $e->getMessage();
}
check('absolutePath refuses it too', $absRefused, 'refused: protected path');

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

checkTrue('engine db.tables carries row estimates', isset($dbTablesResp['details'][0]['rows']));
check(
    'engine db.tables details name their table',
    array_map(function ($d) { return $d['name']; }, $dbTablesResp['details']),
    ['jos_menu', 'jos_empty']
);

// ---- db.cleanup / db.restore (ADR 0083: trash by rename, never drop) ----

$trashSource = new FakeRowSource([
    'jos_users' => ['create' => 'CREATE TABLE `jos_users` (`id` int)', 'rows' => [['1']]],
    'jos_sh404sef_urls' => ['create' => 'CREATE TABLE `jos_sh404sef_urls` (`id` int)', 'rows' => [['1'], ['2']]],
    'jos_sh404sef_pageids' => ['create' => 'CREATE TABLE `jos_sh404sef_pageids` (`id` int)', 'rows' => [['1']]]
]);
$trashEngine = new Engine($goodToken, [], new DbDumper($trashSource));

$refuseCore = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.cleanup', 'params' => ['tables' => ['jos_users']]]);
check('db.cleanup refuses a core-looking table', $refuseCore['error'], 'refused');

$refuseMissing = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.cleanup', 'params' => ['tables' => ['jos_nope']]]);
check('db.cleanup refuses a missing table', $refuseMissing['error'], 'not_found');

$cleanup = $trashEngine->handle([
    'token' => $goodToken,
    'action' => 'db.cleanup',
    'params' => ['tables' => ['jos_sh404sef_urls', 'jos_sh404sef_pageids']]
]);
check('db.cleanup ok', $cleanup['ok'], true);
check('db.cleanup renames the whole batch', count($cleanup['renamed']), 2);
$trashName = $cleanup['renamed'][0]['to'];
checkTrue('db.cleanup trash name carries the prefix', strpos($trashName, '_tracy_trash_') === 0);
checkTrue('db.cleanup trash name keeps the old name', str_contains($trashName, '__jos_sh404sef_urls'));
$afterCleanup = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.tables']);
checkTrue('cleaned table left the name list', !in_array('jos_sh404sef_urls', $afterCleanup['tables'], true));

$reTrash = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.cleanup', 'params' => ['tables' => [$trashName]]]);
check('db.cleanup refuses a table already in the trash', $reTrash['error'], 'bad_params');

$restore = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.restore', 'params' => ['tables' => [$trashName]]]);
check('db.restore ok', $restore['ok'], true);
check('db.restore rebuilds the original name', $restore['restored'][0]['to'], 'jos_sh404sef_urls');
$afterRestore = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.tables']);
checkTrue('restored table is back in the name list', in_array('jos_sh404sef_urls', $afterRestore['tables'], true));

$restorePlain = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.restore', 'params' => ['tables' => ['jos_sh404sef_urls']]]);
check('db.restore refuses a table not in the trash', $restorePlain['error'], 'bad_params');

$purgePlain = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.purge', 'params' => ['tables' => ['jos_sh404sef_urls']]]);
check('db.purge refuses a plain table — it only empties the trash', $purgePlain['error'], 'refused');

$reCleanup = $trashEngine->handle([
    'token' => $goodToken,
    'action' => 'db.cleanup',
    'params' => ['tables' => ['jos_sh404sef_urls']]
]);
$trashAgain = $reCleanup['renamed'][0]['to'];
$purge = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.purge', 'params' => ['tables' => [$trashAgain]]]);
check('db.purge ok', $purge['ok'], true);
check('db.purge drops the trash table', $purge['dropped'], [$trashAgain]);
$afterPurge = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.tables']);
checkTrue('purged table is gone for good', !in_array($trashAgain, $afterPurge['tables'], true));

$purgeMissing = $trashEngine->handle(['token' => $goodToken, 'action' => 'db.purge', 'params' => ['tables' => [$trashAgain]]]);
check('db.purge refuses a table that no longer exists', $purgeMissing['error'], 'not_found');

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
unlink("$tmp/administrator/components/com_akeeba/backup/site-backup.sql");
rmdir("$tmp/administrator/components/com_akeeba/backup");
rmdir("$tmp/administrator/components/com_akeeba");
rmdir("$tmp/administrator/components");
rmdir("$tmp/administrator");
rmdir("$tmp/images");
rmdir("$tmp/cache");
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
// Every Joomla site has one, and this fixture did not — which is the whole reason a refusal on
// the read path could stop every real export while the suite stayed green. `cli/` is here for
// the same reason: it is on the protected list and it is Joomla's own code, so an archive
// without it is not a copy of the site.
file_put_contents("$growTmp/configuration.php", '<?php $offset="America/Chicago"; $sef=1;');
mkdir("$growTmp/cli");
file_put_contents("$growTmp/cli/joomla.php", '<?php // core cli entry point');

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
$entries = array_values(array_filter($listed, fn($l) => strpos($l, 'tar:') !== 0));
check('every entry survives', count($entries), 4);

// The regression this fixture now carries. `absolutePath()` refuses a protected path, and the
// packer used to read through it, so ONE such file in the tree ended the whole archive:
// wisdeaf.org died at 34 MB of 266 with `pack_failed: refused: protected path`, five runs in a
// row, while this suite stayed green because the fixture was two invented files.
//
// A backup that omits the site's own configuration is not a backup: the fleet reads those ~91
// settings back out to keep the customer's timezone and SEF rules. So the archive carries it,
// and the door a CALLER knocks on still refuses — the two assertions below are the whole point,
// and they have to hold together.
checkTrue('the archive carries the site configuration the fleet reads back',
    in_array('configuration.php', $entries, true));
checkTrue('and carries Joomla core code that sits on the protected list',
    in_array('cli/joomla.php', $entries, true));
check('while a caller asking for it directly is still refused',
    refusal(new FileWalker($growTmp), 'configuration.php'), 'refused: protected path');

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

// ------------------------------------------------------------- Extensions --
//
// The only action that writes to a site, so the tests are about what it REFUSES as much as
// what it does. A fake manager stands in for Joomla's installer: what is checked here is the
// engine's gate, not Joomla's unzip.

final class FakeExtensions implements ExtensionManager
{
    public array $installed = [];
    public array $asked = [];
    public bool $refuse = false;

    public function installFromUrl(string $url): array
    {
        $this->asked[] = $url;
        if ($this->refuse) {
            return ['ok' => false, 'error' => 'JInstaller: :Install: Cannot find Joomla XML setup file'];
        }
        return ['ok' => true, 'name' => 'JA Teline V', 'type' => 'template', 'version' => '1.2.3'];
    }

    public function listInstalled(): array
    {
        return $this->installed;
    }

    public array $manifest = ['platform' => 'joomla', 'platformVersion' => '5.1.2', 'extensions' => []];

    public function coreManifest(): array
    {
        return $this->manifest;
    }
}

$TOKEN = 'a-token-at-least-16';
$fakeExtensions = new FakeExtensions();
$fakeExtensions->installed = [
    ['name' => 'Claude Cowork', 'type' => 'component', 'element' => 'com_claudecowork', 'folder' => '', 'package_id' => 0, 'version' => '0.3.0', 'enabled' => true],
    // Xmap's per-product plugin: same element as K2's own component, told apart only by the
    // group, and tied to its package only by package_id.
    ['name' => 'Xmap - K2 Plugin', 'type' => 'plugin', 'element' => 'com_k2', 'folder' => 'xmap', 'package_id' => 42, 'version' => '2.3.3', 'enabled' => true],
];
$extEngine = new Engine($TOKEN, [], null, null, null, $fakeExtensions);

$listed = $extEngine->handle(['token' => $TOKEN, 'action' => 'extension.list']);
check('extension.list returns what the site holds', $listed['extensions'][0]['element'], 'com_claudecowork');

// The per-site core source (ADR 0070 addendum): the engine hands the adapter's manifest through
// verbatim — the core flag is the adapter's verdict, never re-derived here.
$fakeExtensions->manifest = [
    'platform'        => 'joomla',
    'platformVersion' => '5.1.2',
    'extensions'      => [
        ['type' => 'component', 'element' => 'com_content', 'folder' => null, 'core' => true, 'enabled' => true, 'version' => '5.1.2'],
        ['type' => 'component', 'element' => 'com_weblinks', 'folder' => null, 'core' => false, 'enabled' => true, 'version' => '4.4.2'],
    ],
];
$manifested = $extEngine->handle(['token' => $TOKEN, 'action' => 'core.manifest']);
check('core.manifest answers the platform', $manifested['manifest']['platform'], 'joomla');
check('core.manifest keeps the adapter verdict', $manifested['manifest']['extensions'][1]['core'], false);
// Without the group, one product's row answers to another's claim: `com_k2` under Xmap is
// Xmap's, and K2's own component is not.
check('a plugin carries the group that tells it from another product', $listed['extensions'][1]['folder'], 'xmap');
// Without this, a package that arrives as eight rows is counted as eight products.
check('and the package that installed it', (string) $listed['extensions'][1]['package_id'], '42');

$installedOk = $extEngine->handle([
    'token' => $TOKEN,
    'action' => 'extension.install',
    'params' => ['url' => 'https://example.test/pkg_teline.zip'],
]);
checkTrue('a well-formed package installs', $installedOk['ok'] === true);
check('and reports what went on', $installedOk['installed']['name'], 'JA Teline V');

// Plain HTTP would put a customer's site package on the wire for anyone to replace.
$http = $extEngine->handle([
    'token' => $TOKEN,
    'action' => 'extension.install',
    'params' => ['url' => 'http://example.test/pkg_teline.zip'],
]);
check('http is refused before anything is fetched', $http['message'], 'https required');

// Joomla names the download after the URL and reads the archive type from that name, so an
// address with no .zip on the end fails deep inside the installer with "Unable to detect
// manifest file" — a message nobody can act on. Refused here instead.
$noZip = $extEngine->handle([
    'token' => $TOKEN,
    'action' => 'extension.install',
    'params' => ['url' => 'https://example.test/download?id=42'],
]);
check('a URL that is not a .zip is refused', $noZip['message'], 'package URL must end in .zip');

check('nothing refused was ever fetched', count($fakeExtensions->asked), 1);

$fakeExtensions->refuse = true;
$refused = $extEngine->handle([
    'token' => $TOKEN,
    'action' => 'extension.install',
    'params' => ['url' => 'https://example.test/pkg_broken.zip'],
]);
// The installer's own words, not "install failed": one of these can be acted on.
checkTrue('a refused package carries the installer reason', strpos($refused['message'], 'Cannot find Joomla XML setup file') !== false);

// A site that never wired the manager is a site that cannot be written to at all.
$readOnlyEngine = new Engine($TOKEN, [], null, null, null, null);
$unwired = $readOnlyEngine->handle([
    'token' => $TOKEN,
    'action' => 'extension.install',
    'params' => ['url' => 'https://example.test/pkg_teline.zip'],
]);
check('an unwired site refuses to install anything', $unwired['message'], 'extension manager not wired');

// The token gate covers the new actions exactly as it covers the read ones.
$noToken = $extEngine->handle([
    'token' => 'wrong-token-but-long-enough',
    'action' => 'extension.install',
    'params' => ['url' => 'https://example.test/pkg_teline.zip'],
]);
check('a wrong token installs nothing', $noToken['error'], 'unauthorized');

// -------------------------------------------------------- SiteWriter / Apply --
//
// The write side and its undo. Fakes stand in for Joomla's tables and media folder; what is
// checked is the engine's promise — every edit is recorded so the whole Apply can be reversed to
// exactly what was there, and an edit whose undo cannot be recorded is rolled back rather than
// left standing (ADR 0048).

final class FakeSiteWriter implements SiteWriter
{
    /** @var array<string,array<int,array<string,?scalar>>> */
    public array $store = [];
    private int $nextId = 100;
    public int $purges = 0;

    /** Mirrors the real catalog's shape: identity/installer kinds refuse create, most kinds trash.
     * Tree kinds (menuItem/category/tag) create since 0.8.14 — the real writer routes them
     * through Joomla's Table API; here a plain insert stands in for it. */
    private const NO_CREATE = ['user', 'extensionParams', 'templateStyle'];
    private const TRASH = [
        'article' => 'state', 'field' => 'state', 'banner' => 'state', 'bannerClient' => 'state',
        'category' => 'published', 'tag' => 'published', 'menuItem' => 'published',
        'redirect' => 'published', 'contact' => 'published', 'newsfeed' => 'published',
        'module' => 'published',
    ];

    public function canCreate(string $kind): bool
    {
        return !in_array($kind, self::NO_CREATE, true);
    }

    public function trashColumn(string $kind): ?string
    {
        return self::TRASH[$kind] ?? null;
    }

    public function read(string $kind, int $id): ?array
    {
        return $this->store[$kind][$id] ?? null;
    }
    public function write(string $kind, int $id, array $fields): int
    {
        if ($id === 0) {
            $id = $this->nextId++;
        }
        $this->store[$kind][$id] = $fields;
        return $id;
    }
    public function delete(string $kind, int $id): void
    {
        unset($this->store[$kind][$id]);
    }
    private const TREE = ['menuItem', 'category', 'tag'];
    /** @var array<string,array<int,array{parent_id:int,after:int}>> */
    public array $pos = [];
    public function positionOf(string $kind, int $id): ?array
    {
        if (!in_array($kind, self::TREE, true)) {
            return null;
        }
        return $this->pos[$kind][$id] ?? null;
    }
    public function move(string $kind, int $id, int $parentId, int $after): void
    {
        if (!in_array($kind, self::TREE, true)) {
            throw new RuntimeException("a {$kind} is not tree-shaped and cannot be moved");
        }
        if (!isset($this->pos[$kind][$id])) {
            throw new RuntimeException("no {$kind} with id {$id}");
        }
        $this->pos[$kind][$id] = ['parent_id' => $parentId, 'after' => $after];
    }
    public function list(string $kind, int $offset, int $limit): array
    {
        $rows = $this->store[$kind] ?? [];
        ksort($rows);
        $out = [];
        foreach (array_slice($rows, $offset, $limit, true) as $id => $fields) {
            $out[] = ['id' => $id] + $fields;
        }
        return $out;
    }
    public function purgeCache(): void
    {
        $this->purges++;
    }
}

final class FakeMediaWriter implements MediaWriter
{
    /** @var array<string,string> */
    public array $store = [];
    public function read(string $path): ?string
    {
        return $this->store[$path] ?? null;
    }
    public function write(string $path, string $bytes): void
    {
        $this->store[$path] = $bytes;
    }
    public function delete(string $path): void
    {
        unset($this->store[$path]);
    }
}

final class FakeApplyLog implements ApplyLog
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $log = [];
    public function record(string $applyId, array $entry): void
    {
        $this->log[$applyId][] = $entry;
    }
    public function entries(string $applyId): array
    {
        return $this->log[$applyId] ?? [];
    }
    public function clear(string $applyId): void
    {
        unset($this->log[$applyId]);
    }
}

/** A log that cannot record — to prove a write with no recordable undo is rolled back. */
final class FailingApplyLog implements ApplyLog
{
    public function record(string $applyId, array $entry): void
    {
        throw new RuntimeException('log write failed');
    }
    public function entries(string $applyId): array
    {
        return [];
    }
    public function clear(string $applyId): void
    {
    }
}

$WTOKEN = 'write-token-at-least-16chars';
$writer = new FakeSiteWriter();
$mediaW = new FakeMediaWriter();
$log = new FakeApplyLog();
$wEngine = new Engine($WTOKEN, [], null, null, null, null, $writer, $mediaW, $log);

// insert an article
$ins = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'A1', 'kind' => 'article', 'fields' => ['title' => 'Hello']]]);
check('content.update inserts', $ins['ok'], true);
check('an insert reports created', $ins['created'], true);
$newId = $ins['id'];
check('the row is now in the site', $writer->read('article', $newId), ['title' => 'Hello']);
check('the write was logged under its apply', count($log->entries('A1')), 1);
check('a content write purges cache', $writer->purges, 1);

// revert the insert -> the row is gone
$rev = $wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'A1']]);
check('revert reports what it undid', $rev['reverted'], 1);
check('reverting an insert deletes the row', $writer->read('article', $newId), null);
check('a reverted apply is forgotten', count($log->entries('A1')), 0);

// update an existing template style, then revert -> the old params come back
$writer->store['templateStyle'][7] = ['params' => '{"color":"blue"}'];
$upd = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'A2', 'kind' => 'templateStyle', 'id' => 7, 'fields' => ['params' => '{"color":"red"}']]]);
check('updating an existing row is not a create', $upd['created'], false);
check('the new value is live', $writer->read('templateStyle', 7), ['params' => '{"color":"red"}']);
$wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'A2']]);
check('reverting an update restores the before-state', $writer->read('templateStyle', 7), ['params' => '{"color":"blue"}']);

// the read half of the content mirror (ADR 0071): list pages, get returns the full row
$writer->store['article'][3] = ['title' => 'First', 'alias' => 'first', 'introtext' => '<p>long body</p>'];
$writer->store['article'][9] = ['title' => 'Second', 'alias' => 'second', 'introtext' => '<p>x</p>'];
$lst = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => ['kind' => 'article']]);
check('content.list answers', $lst['ok'], true);
check('content.list returns the rows in id order', array_column($lst['items'], 'id'), [3, 9]);
$page = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list',
    'params' => ['kind' => 'article', 'offset' => 1, 'limit' => 1]]);
check('content.list pages by offset', array_column($page['items'], 'id'), [9]);
$past = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list',
    'params' => ['kind' => 'article', 'offset' => 99]]);
check('a page past the end is empty, not an error', $past['items'], []);
$withBody = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list',
    'params' => ['kind' => 'article', 'include_body' => true]]);
check('include_body carries the row itself, not just its summary',
    $withBody['items'][0]['introtext'], '<p>long body</p>');
check('a page carrying bodies is capped smaller',
    count($wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list',
        'params' => ['kind' => 'article', 'include_body' => true, 'limit' => 500]])['items']), 2);
check('content.list refuses a kind it does not know',
    $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => ['kind' => 'wombat']])['error'], 'bad_params');
$got = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.get', 'params' => ['kind' => 'article', 'id' => 3]]);
check('content.get returns the full row', $got['item'], $writer->store['article'][3]);
check('content.get names a missing row',
    $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.get', 'params' => ['kind' => 'article', 'id' => 77]])['error'], 'not_found');
check('content.get requires an id',
    $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.get', 'params' => ['kind' => 'article']])['error'], 'bad_params');
check('reads record nothing in the apply log', count($log->entries('A1')), 0);
unset($writer->store['article'][3], $writer->store['article'][9]);

// media: a new file, then revert deletes it
$mw = $wEngine->handle(['token' => $WTOKEN, 'action' => 'media.upload',
    'params' => ['apply_id' => 'M1', 'path' => 'images/logo.png', 'content_b64' => base64_encode('PNGDATA')]]);
check('media.upload lands the file', $mediaW->read('images/logo.png'), 'PNGDATA');
check('a new upload reports created', $mw['created'], true);
$wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'M1']]);
check('reverting a new upload deletes it', $mediaW->read('images/logo.png'), null);

// media: overwrite an existing file, then revert restores the old bytes
$mediaW->store['images/hero.jpg'] = 'OLDBYTES';
$wEngine->handle(['token' => $WTOKEN, 'action' => 'media.upload',
    'params' => ['apply_id' => 'M2', 'path' => 'images/hero.jpg', 'content_b64' => base64_encode('NEWBYTES')]]);
check('overwrite lands the new bytes', $mediaW->read('images/hero.jpg'), 'NEWBYTES');
$wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'M2']]);
check('reverting an overwrite restores the old bytes', $mediaW->read('images/hero.jpg'), 'OLDBYTES');

// apply.list names what was touched, without the before payload
$wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'L1', 'kind' => 'module', 'fields' => ['content' => 'x']]]);
$wEngine->handle(['token' => $WTOKEN, 'action' => 'media.upload',
    'params' => ['apply_id' => 'L1', 'path' => 'images/a.png', 'content_b64' => base64_encode('a')]]);
$list = $wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.list', 'params' => ['apply_id' => 'L1']]);
check('apply.list returns every step', count($list['steps']), 2);
check('apply.list names the content kind', $list['steps'][0]['kind'], 'module');
checkTrue('apply.list does not leak the before payload', !isset($list['steps'][0]['before']));

// refusals ------------------------------------------------------------------------------------
$badKind = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'X', 'kind' => 'wombat', 'fields' => ['a' => 'b']]]);
check('an unknown kind is refused', $badKind['error'], 'bad_params');

// ADR 0080: full catalog — generic actions over kinds ------------------------------------------

// A menu item rename is a content edit: update lands, and revert restores the old title.
$writer->store['menuItem'][101] = ['title' => 'Home', 'published' => 1];
$menuUpd = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'M1', 'kind' => 'menuItem', 'id' => 101, 'fields' => ['title' => 'New Home']]]);
check('a menu item can be renamed', $menuUpd['ok'], true);
check('the rename landed', $writer->store['menuItem'][101]['title'], 'New Home');
$menuRevert = $wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'M1']]);
check('the rename reverts', $menuRevert['ok'], true);
check('the old title is back', $writer->store['menuItem'][101]['title'], 'Home');

// Tree-shaped kinds create through the Table path (0.8.14): the insert lands, and reverting
// deletes the node this run made — the tree returns to exactly its prior shape.
$menuCreate = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'M2', 'kind' => 'menuItem',
        'fields' => ['title' => 'Tracy', 'menutype' => 'mainmenu', 'link' => 'https://tracy.ai']]]);
check('creating a menu item lands', $menuCreate['ok'], true);
checkTrue('the create minted an id', ($menuCreate['id'] ?? 0) > 0);
check('the create says created', $menuCreate['created'], true);
$createdId = (int) $menuCreate['id'];
check('the new item is in the store', $writer->store['menuItem'][$createdId]['title'], 'Tracy');
$menuCreateRevert = $wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'M2']]);
check('a create reverts', $menuCreateRevert['ok'], true);
checkTrue('the reverted node is gone', !isset($writer->store['menuItem'][$createdId]));

// Identity kinds still refuse create — the boundary moved to where it belongs, not away.
$userCreate = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'M3', 'kind' => 'user', 'fields' => ['name' => 'Eve']]]);
check('creating a user is refused', $userCreate['error'], 'unsupported');

// Moving a tree node (0.8.15): parent_id / move_after on an existing node re-hang it, and the
// revert returns it to its exact old slot — parent AND position, not just parent.
$writer->store['menuItem'][102] = ['title' => 'Extensions', 'published' => 1];
$writer->pos['menuItem'][102] = ['parent_id' => 5, 'after' => 7];
$mv = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'V1', 'kind' => 'menuItem', 'id' => 102, 'fields' => ['parent_id' => 1]]]);
check('a tree node can be moved', $mv['ok'], true);
check('the response says moved', $mv['moved'], true);
check('the node hangs under the new parent', $writer->pos['menuItem'][102]['parent_id'], 1);
$mvList = $wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.list', 'params' => ['apply_id' => 'V1']]);
check('apply.list names the move', $mvList['steps'][0]['op'], 'move');
$mvRevert = $wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'V1']]);
check('a move reverts', $mvRevert['ok'], true);
check('the node is back under its old parent', $writer->pos['menuItem'][102]['parent_id'], 5);
check('and back in its old slot', $writer->pos['menuItem'][102]['after'], 7);

// Move and rename in one call: both land, one revert undoes both.
$mvBoth = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'V2', 'kind' => 'menuItem', 'id' => 102,
        'fields' => ['title' => 'Add-ons', 'parent_id' => 9]]]);
check('move plus rename lands', $mvBoth['ok'], true);
check('the rename landed too', $writer->store['menuItem'][102]['title'], 'Add-ons');
check('the move landed too', $writer->pos['menuItem'][102]['parent_id'], 9);
$mvBothRevert = $wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'V2']]);
check('one revert undoes both', $mvBothRevert['ok'], true);
check('title restored', $writer->store['menuItem'][102]['title'], 'Extensions');
check('position restored', $writer->pos['menuItem'][102]['parent_id'], 5);

// Flat kinds do not move — an article changes category through catid, a plain column write.
$writer->store['article'][66] = ['title' => 'A post', 'state' => 1];
$mvFlat = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'V3', 'kind' => 'article', 'id' => 66, 'fields' => ['parent_id' => 2]]]);
check('moving a flat kind is refused', $mvFlat['error'], 'unsupported');

// A move with nowhere to go is a bad request, not a write.
$mvNowhere = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'V4', 'kind' => 'menuItem', 'id' => 102, 'fields' => ['move_after' => 0]]]);
check('a move without a destination is refused', $mvNowhere['error'], 'bad_params');

// content.delete is a trash write (-2), recorded like any edit, so it reverts.
$writer->store['article'][55] = ['title' => 'Old post', 'state' => 1];
$del = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.delete',
    'params' => ['apply_id' => 'D1', 'kind' => 'article', 'id' => 55]]);
check('content.delete trashes', $del['ok'], true);
check('the row is in the trash, not gone', $writer->store['article'][55]['state'], -2);
$delRevert = $wEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'D1']]);
check('a delete reverts', $delRevert['ok'], true);
check('the article is published again', $writer->store['article'][55]['state'], 1);

// A kind with no trash column cannot be deleted through Apply at all.
$delMenutype = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.delete',
    'params' => ['apply_id' => 'D2', 'kind' => 'menutype', 'id' => 3]]);
check('deleting a menutype is refused', $delMenutype['error'], 'unsupported');
$delMissing = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.delete',
    'params' => ['apply_id' => 'D3', 'kind' => 'article', 'id' => 9999]]);
check('deleting a missing row says not_found', $delMissing['error'], 'not_found');

$noApply = $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['kind' => 'article', 'fields' => ['title' => 'x']]]);
check('a write without an apply_id is refused', $noApply['message'], 'apply_id required');

$traversal = $wEngine->handle(['token' => $WTOKEN, 'action' => 'media.upload',
    'params' => ['apply_id' => 'X', 'path' => '../configuration.php', 'content_b64' => base64_encode('x')]]);
check('a traversing media path is refused', $traversal['message'], 'unusable media path');
checkTrue('nothing was written outside the media root', !isset($mediaW->store['../configuration.php']));

// A well-formed path that is not under a media tree is live code, not an asset — refused.
$notMedia = $wEngine->handle(['token' => $WTOKEN, 'action' => 'media.upload',
    'params' => ['apply_id' => 'X', 'path' => 'configuration.php', 'content_b64' => base64_encode('x')]]);
check('a media write outside the media roots is refused', $notMedia['message'], 'unusable media path');
checkTrue('live code was not touched', !isset($mediaW->store['configuration.php']));

$tooBig = $wEngine->handle(['token' => $WTOKEN, 'action' => 'media.upload',
    'params' => ['apply_id' => 'X', 'path' => 'images/big.bin', 'content_b64' => base64_encode(str_repeat('A', 8 * 1024 * 1024 + 1))]]);
check('a media upload past the inline ceiling is refused', $tooBig['error'], 'too_large');

$wrongToken = $wEngine->handle(['token' => 'nope-but-long-enough-here', 'action' => 'content.update',
    'params' => ['apply_id' => 'X', 'kind' => 'article', 'fields' => ['title' => 'x']]]);
check('a wrong token writes nothing', $wrongToken['error'], 'unauthorized');

// a site with no writer wired cannot be written to at all
$readOnlyW = new Engine($WTOKEN);
$unwiredW = $readOnlyW->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'X', 'kind' => 'article', 'fields' => ['title' => 'x']]]);
check('an unwired site refuses content writes', $unwiredW['error'], 'unavailable');

// the rollback promise: a write whose undo cannot be recorded is undone, not left standing
$rbWriter = new FakeSiteWriter();
$rbEngine = new Engine($WTOKEN, [], null, null, null, null, $rbWriter, $mediaW, new FailingApplyLog());
$rb = $rbEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'R1', 'kind' => 'article', 'fields' => ['title' => 'ghost']]]);
check('a write whose undo cannot be recorded fails', $rb['ok'], false);
check('and the site holds no orphan for it', $rbWriter->read('article', 100), null);

// ----------------------------------------------------------------- ChangeStamp --
// The preview watches a file at the webroot because the site cannot call Tracy back. What is
// tested here is WHEN it is written: after a change that landed, and never after a refusal.

$stampRoot = sys_get_temp_dir() . '/cowork-stamp-' . getmypid();
@mkdir($stampRoot, 0777, true);
$stampFile = $stampRoot . '/' . ChangeStamp::FILENAME;
@unlink($stampFile);

$sWriter = new FakeSiteWriter();
$sLog = new FakeApplyLog();
$stamp = new ChangeStamp($stampRoot);
$sEngine = new Engine($WTOKEN, [], null, null, null, new FakeExtensions(), $sWriter, new FakeMediaWriter(), $sLog, $stamp);

check('no stamp before anything happens', is_file($stampFile), false);

$sEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'S1', 'kind' => 'article', 'fields' => ['title' => 'Stamped']]]);
check('a content write stamps the webroot', is_file($stampFile), true);
$stamped = $stamp->read();
check('the stamp carries a time', is_int($stamped['at'] ?? null), true);
check('and a coarse reason', $stamped['reason'] ?? null, 'content');

// A refusal must not move it: the site did not change, and a preview reloaded for nothing shows
// the customer the same page with a fresh timestamp — which reads as "something happened".
@unlink($stampFile);
$sEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'S2', 'kind' => 'nonsense', 'fields' => ['title' => 'x']]]);
check('a refused write leaves no stamp', is_file($stampFile), false);

$sEngine->handle(['token' => 'wrong-token-but-long-enough', 'action' => 'content.update',
    'params' => ['apply_id' => 'S3', 'kind' => 'article', 'fields' => ['title' => 'x']]]);
check('an unauthorized call leaves no stamp', is_file($stampFile), false);

// One deliverable is several actions; a preview must not reload once per action.
$sEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'S4', 'kind' => 'article', 'fields' => ['title' => 'First']]]);
$firstAt = $stamp->read()['at'];
$sEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'S4', 'kind' => 'article', 'fields' => ['title' => 'Second']]]);
check('a burst of writes coalesces into one stamp', $stamp->read()['at'], $firstAt);

// An engine with no stamp is the ordinary case in tests and on a read-only webroot.
$noStamp = new Engine($WTOKEN, [], null, null, null, null, new FakeSiteWriter(), new FakeMediaWriter(), new FakeApplyLog());
$plain = $noStamp->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'S5', 'kind' => 'article', 'fields' => ['title' => 'y']]]);
check('a write still succeeds with no stamp wired', $plain['ok'], true);

// A webroot that cannot be written is a hardened host, not a failure to report.
$readOnlyStamp = new ChangeStamp($stampRoot . '/does/not/exist');
$readOnlyStamp->touch('content');
check('an unwritable webroot is silent', $readOnlyStamp->read(), null);

@unlink($stampFile);
@rmdir($stampRoot);

// ------------------------------------------------------------- update server --
// "Check For Updates" reads update.xml from the repository and compares it against the manifest
// of the copy on disk. Three files must agree about one number, and nothing but this notices the
// day they stop.
//
// update.xml sits at `joomla/`, not here beside the component it describes, and must stay there: a
// site records that raw.githubusercontent URL when the package is INSTALLED, so the path is a
// published address every already-installed site still asks. Tidying it into reader/ would 404
// them all, and they would report "up to date" forever without a word.

$pkg = simplexml_load_file(__DIR__ . '/../pkg_claudecowork.xml');
$com = simplexml_load_file(__DIR__ . '/../com_claudecowork/claudecowork.xml');
$upd = simplexml_load_file(__DIR__ . '/../../update.xml');
$pkgVersion = trim((string) $pkg->version);

check('the component carries the package version', trim((string) $com->version), $pkgVersion);
check('update.xml names that version too', trim((string) $upd->update->version), $pkgVersion);
check('and points at that version\'s release asset',
    trim((string) $upd->update->downloads->downloadurl),
    "https://github.com/TracyHQ/claude-cowork/releases/download/joomla-v{$pkgVersion}/pkg_claudecowork-{$pkgVersion}.zip");
check('update.xml is about the package, not the component alone', trim((string) $upd->update->element), 'pkg_claudecowork');
// Without a declared server Joomla has nowhere to ask, and the backend never mentions an update.
check('the package declares where to ask',
    trim((string) $pkg->updateservers->server),
    'https://raw.githubusercontent.com/TracyHQ/claude-cowork/main/joomla/update.xml');

// --- update.json: the same release, for a different asker ------------------------------------
// update.xml answers Joomla's own updater: it carries the targetplatform rule and every past
// version, because a site on an older Joomla still has to find the last release that supported
// it. Tracy Desk asks a different question — "what is current" — has no XML parser in the main
// process, and has no targetplatform rule to apply. Two files, one number.
//
// It sits beside update.xml at `joomla/`, not here in the component, for the same reason that one
// does: the raw.githubusercontent path is compiled into every desk already installed on somebody's
// machine, so it is a published address. Tidying it inwards would 404 them all, silently.
$json = json_decode(file_get_contents(__DIR__ . '/../../update.json'), true);
check('update.json names the package version', $json['version'] ?? null, $pkgVersion);
checkTrue(
    'and points at that version\'s release asset',
    ($json['package'] ?? '') === "https://github.com/TracyHQ/claude-cowork/releases/download/joomla-v{$pkgVersion}/pkg_claudecowork-{$pkgVersion}.zip");

// --- core.upgrade: the one write that moves a Joomla version --------------------------------
// The engine validates the target and delegates the site-touching work to a CoreUpgrader, so
// the routing and the guard are testable with a fake. The real JoomlaCoreUpgrader is proven
// against a live 5.4.8 -> 6.1.3 in the lab; here we prove the wiring, not the upgrade.
final class FakeCoreUpgrader implements CoreUpgrader
{
    public array $asked = [];
    public bool $refuse = false;

    public function upgrade(string $to, string $step): array
    {
        $this->asked[] = $to . '/' . $step;
        if ($this->refuse) {
            return ['ok' => false, 'error' => 'the updater refused'];
        }
        return ['ok' => true, 'step' => $step, 'version' => $to . '.9'];
    }
}

$fakeUp   = new FakeCoreUpgrader();
$upToken  = 'a-token-at-least-16';
$upEngine = new Engine($upToken, ['joomla' => '5.4.8'], null, null, null, null, null, null, null, null, $fakeUp);

$upBadTarget = $upEngine->handle(['token' => $upToken, 'action' => 'core.upgrade', 'params' => ['to' => '7.0', 'step' => 'prepare']]);
check('a target off the chain is refused', $upBadTarget['error'], 'bad_params');
check('and nothing was asked of the upgrader', count($fakeUp->asked), 0);

$upBadStep = $upEngine->handle(['token' => $upToken, 'action' => 'core.upgrade', 'params' => ['to' => '6.1', 'step' => 'go']]);
check('an unknown step is refused', $upBadStep['error'], 'bad_params');
check('and still nothing was asked', count($fakeUp->asked), 0);

$upPrep = $upEngine->handle(['token' => $upToken, 'action' => 'core.upgrade', 'params' => ['to' => '6.1', 'step' => 'prepare']]);
checkTrue('prepare delegates and succeeds', ($upPrep['ok'] ?? false) === true);
check('prepare reached the upgrader as a step', $fakeUp->asked[0], '6.1/prepare');
check('and the step is carried back', $upPrep['step'], 'prepare');

$upFin = $upEngine->handle(['token' => $upToken, 'action' => 'core.upgrade', 'params' => ['to' => '6.1', 'step' => 'finalise']]);
check('finalise reached the upgrader as its own step', $fakeUp->asked[1], '6.1/finalise');
check('and the reported version is carried back', $upFin['version'], '6.1.9');

$fakeUp->refuse = true;
$upFail = $upEngine->handle(['token' => $upToken, 'action' => 'core.upgrade', 'params' => ['to' => '6.1', 'step' => 'prepare']]);
check('an upgrader refusal becomes a clean error, not a 500', $upFail['error'], 'upgrade_failed');

// No upgrader wired: a read-only build must not pretend it can move a version.
$upNone = (new Engine($upToken))->handle(['token' => $upToken, 'action' => 'core.upgrade', 'params' => ['to' => '6.1', 'step' => 'prepare']]);
check('with no upgrader wired the action is unavailable', $upNone['error'], 'unavailable');


// --- files.restore: the safe half of a restore (files, never the database) ------------------
final class FakeFilesRestorer implements FilesRestorer
{
    public array $asked = [];
    public bool $refuse = false;

    public function restore(string $getUrl): array
    {
        $this->asked[] = $getUrl;
        if ($this->refuse) {
            return ['ok' => false, 'error' => 'the restorer refused'];
        }
        return ['ok' => true, 'files' => 42];
    }
}

$fakeRestore = new FakeFilesRestorer();
$frToken     = 'a-token-at-least-16';
$frEngine    = new Engine($frToken, [], null, null, null, null, null, null, null, null, null, $fakeRestore);

$frNoUrl = $frEngine->handle(['token' => $frToken, 'action' => 'files.restore', 'params' => []]);
check('files.restore without a url is refused', $frNoUrl['error'], 'bad_params');
check('and nothing was asked of the restorer', count($fakeRestore->asked), 0);

$frBadUrl = $frEngine->handle(['token' => $frToken, 'action' => 'files.restore', 'params' => ['get_url' => 'ftp://x/y.tar']]);
check('a non-http url is refused', $frBadUrl['error'], 'bad_params');

$frOk = $frEngine->handle(['token' => $frToken, 'action' => 'files.restore', 'params' => ['get_url' => 'https://r2.example/snap.tar']]);
checkTrue('a valid restore delegates and succeeds', ($frOk['ok'] ?? false) === true);
check('the url reached the restorer', $fakeRestore->asked[0], 'https://r2.example/snap.tar');
check('and the file count is carried back', $frOk['files'], 42);

$fakeRestore->refuse = true;
$frFail = $frEngine->handle(['token' => $frToken, 'action' => 'files.restore', 'params' => ['get_url' => 'https://r2.example/snap.tar']]);
check('a restorer refusal becomes a clean error, not a 500', $frFail['error'], 'restore_failed');

$frNone = (new Engine($frToken))->handle(['token' => $frToken, 'action' => 'files.restore', 'params' => ['get_url' => 'https://r2.example/snap.tar']]);
check('with no restorer wired the action is unavailable', $frNone['error'], 'unavailable');


// ---------------------------------------------------------------- Door -----
// The one question the system plugin asks of every request before Joomla routes it: is this the
// API door? Pure so it can be pinned here — the plugin itself only runs inside Joomla.

checkTrue('the door answers option+task', Door::wants(['option' => 'com_claudecowork', 'task' => 'api.exec']));
checkTrue('task is matched case-insensitively', Door::wants(['option' => 'com_claudecowork', 'task' => 'API.Exec']));
checkTrue('format is not required — the door decides the format itself', Door::wants(['option' => 'com_claudecowork', 'task' => 'api.exec', 'format' => 'html']));
check('another component is not the door', Door::wants(['option' => 'com_content', 'task' => 'api.exec']), false);
check('another task of this component is not the door', Door::wants(['option' => 'com_claudecowork', 'task' => 'display']), false);
check('an empty request is not the door', Door::wants([]), false);
check('array-valued query parts never match', Door::wants(['option' => ['com_claudecowork'], 'task' => 'api.exec']), false);

// ---------------------------------------------------------------- packaging --
// The door plugin travels INSIDE pkg_claudecowork, so one install or one update puts it on the
// site. A plugin left out of the package manifest builds fine and is never installed — nothing
// would say so except a site whose front end is blocked and whose import still fails.

$pkgFiles = [];
foreach ($pkg->files->file as $file) {
    $pkgFiles[(string) $file['id']] = ['type' => (string) $file['type'], 'group' => (string) $file['group'], 'zip' => trim((string) $file)];
}
check('the package carries the door plugin', $pkgFiles['claudecoworkapi'] ?? null, ['type' => 'plugin', 'group' => 'system', 'zip' => 'plg_system_claudecoworkapi.zip']);
$doorManifest = simplexml_load_file(__DIR__ . '/../plg_system_claudecoworkapi/claudecoworkapi.xml');
checkTrue('the door plugin has a manifest', $doorManifest !== false);
check('the door plugin is a system plugin', (string) $doorManifest['group'], 'system');
check('the door plugin registers its namespace', trim((string) $doorManifest->namespace), 'Tracy\Plugin\System\ClaudeCoworkApi');
check('the door plugin enables itself on install', trim((string) $doorManifest->scriptfile), 'script.php');
checkTrue('build.sh zips the door plugin', str_contains(file_get_contents(__DIR__ . '/../build.sh'), 'plg_system_claudecoworkapi.zip'));


echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
