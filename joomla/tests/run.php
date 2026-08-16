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
}

$TOKEN = 'a-token-at-least-16';
$fakeExtensions = new FakeExtensions();
$fakeExtensions->installed = [
    ['name' => 'Claude Cowork', 'type' => 'component', 'element' => 'com_claudecowork', 'version' => '0.3.0', 'enabled' => true],
];
$extEngine = new Engine($TOKEN, [], null, null, null, $fakeExtensions);

$listed = $extEngine->handle(['token' => $TOKEN, 'action' => 'extension.list']);
check('extension.list returns what the site holds', $listed['extensions'][0]['element'], 'com_claudecowork');

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
    'params' => ['apply_id' => 'X', 'kind' => 'user', 'fields' => ['a' => 'b']]]);
check('an unknown kind is refused', $badKind['error'], 'bad_params');

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

$pkg = simplexml_load_file(__DIR__ . '/../pkg_claudecowork.xml');
$com = simplexml_load_file(__DIR__ . '/../com_claudecowork/claudecowork.xml');
$upd = simplexml_load_file(__DIR__ . '/../update.xml');
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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
