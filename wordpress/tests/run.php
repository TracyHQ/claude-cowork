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


echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
