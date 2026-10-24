<?php
/**
 * The cheap questions a sync asks first: files.list without the sha1, files.hash for the paths that
 * may have changed, and site.fingerprint for "has anything moved at all?" — the Joomla engine's
 * contract on this engine, so "Sync from live" reads a WordPress site the way it reads a Joomla one.
 * Required from run.php, whose check() and checkTrue() it uses.
 */

$FPTOKEN = bin2hex(random_bytes(16));

$fpRoot = sys_get_temp_dir() . '/tracy_fingerprint_test_' . bin2hex(random_bytes(4));
$fpOutside = sys_get_temp_dir() . '/tracy_fingerprint_outside_' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($fpOutside, 'not the site');
foreach (['wp-content/cache', 'images', 'bulk', 'wp-content/plugins/forms/vendor/psr/log', 'wp-content/plugins/forms/cache',
    'wp-content/updraft', 'wp-content/plugins/editor', 'staging'] as $dir) {
    mkdir("{$fpRoot}/{$dir}", 0777, true);
}
file_put_contents("{$fpRoot}/index.php", '<?php echo "hi";');
// The site's secrets: listed by size and mtime, never hashed or handed back one by one.
file_put_contents("{$fpRoot}/wp-config.php", '<?php define("DB_PASSWORD", "s3cret");');
file_put_contents("{$fpRoot}/wp-config.php.bak", '<?php define("DB_PASSWORD", "old");');
file_put_contents("{$fpRoot}/wp-config-old.php", '<?php define("DB_PASSWORD", "older");');
file_put_contents("{$fpRoot}/staging/wp-config.php", '<?php define("DB_PASSWORD", "staging");');
file_put_contents("{$fpRoot}/.env", 'DB_PASSWORD=s3cret');
// Core's sample carries placeholders only, and a plugin's own file named like one is code.
file_put_contents("{$fpRoot}/wp-config-sample.php", '<?php define("DB_PASSWORD", "password_here");');
file_put_contents("{$fpRoot}/wp-content/plugins/editor/wp-config-editor.php", '<?php // an editor');
file_put_contents("{$fpRoot}/images/a.png", 'AAAA');
file_put_contents("{$fpRoot}/images/b.png", 'BBBBBB');
// Directories called `log` and `cache` below the root are code the site needs, and the walk keeps them.
file_put_contents("{$fpRoot}/wp-content/plugins/forms/vendor/psr/log/LoggerInterface.php", '<?php interface LoggerInterface {}');
file_put_contents("{$fpRoot}/wp-content/plugins/forms/cache/Pool.php", '<?php class Pool {}');
file_put_contents("{$fpRoot}/wp-content/cache/skip.dat", 'nope');
file_put_contents("{$fpRoot}/wp-content/updraft/backup.zip", 'PK');
// Archives the walk leaves out wherever they sit, and one only at the top.
file_put_contents("{$fpRoot}/images/site.sql", 'SELECT 1;');
file_put_contents("{$fpRoot}/backup-site-2026.tar.gz", 'gz');
// A file named `0`: as an array key it is an int, and a map of one int key 0 encodes as a JSON list.
file_put_contents("{$fpRoot}/0", 'zero');
// 600 files: more than a hashing page may hold, fewer than a stat page may.
for ($i = 0; $i < 600; $i++) {
    file_put_contents(sprintf('%s/bulk/f%04d.txt', $fpRoot, $i), str_repeat('x', $i % 7));
}
$fpLinks = function_exists('symlink')
    && @symlink("{$fpRoot}/images/a.png", "{$fpRoot}/images/link.png")
    && @symlink($fpOutside, "{$fpRoot}/outside.txt")
    && @symlink("{$fpRoot}/images", "{$fpRoot}/linkdir");

$fpWalker = new FileWalker($fpRoot);
$fpSource = new FakeRowSource([
    'wp_a' => ['create' => 'CREATE TABLE `wp_a` (`id` int)', 'rows' => [['1'], ['2']], 'columns' => ['id'], 'key' => ['id']],
    'wp_b' => ['create' => 'CREATE TABLE `wp_b` (`id` int)', 'rows' => [['7']], 'columns' => ['id'], 'key' => ['id']],
    // An engine that cannot checksum: the server answers NULL for it.
    'wp_c' => ['create' => 'CREATE TABLE `wp_c` (`id` int)', 'rows' => [], 'checksum' => null],
]);
$fpEngine = new Engine($FPTOKEN, ['php' => PHP_VERSION], new DbDumper($fpSource), $fpWalker);
$fp = function (string $action, array $params = []) use ($fpEngine, $FPTOKEN): array {
    return $fpEngine->handle(['token' => $FPTOKEN, 'action' => $action, 'params' => $params]);
};

/** Every page of files.list from the start, with the params given: the paths, the entries, the calls. */
function fpWalkAll(callable $fp, array $params): array
{
    $entries = [];
    $after = '';
    $calls = 0;
    do {
        $r = $fp('files.list', ['after' => $after] + $params);
        $calls++;
        if (!$r['ok'] || $calls > 2000) {
            break;
        }
        $entries = array_merge($entries, $r['files']);
        $after = (string) $r['next_cursor'];
    } while (!$r['done']);
    return [array_column($entries, 'path'), $entries, $calls];
}

/** The digest site.fingerprint promises, computed here from files.list's stat answer. */
function fpDigest(array $entries): string
{
    $text = '';
    foreach ($entries as $e) {
        $text .= $e['path'] . "\0" . $e['size'] . "\0" . $e['mtime'] . "\n";
    }
    return sha1($text);
}

// ---- info ---------------------------------------------------------------------------------------

$fpInfo = $fp('info')['info'];
check('fingerprint: info advertises site.fingerprint', $fpInfo['site_fingerprint'] ?? null, ['version' => 2, 'max_files' => 50000]);
check('fingerprint: info advertises files.list without the sha1', $fpInfo['files_list'] ?? null, ['stat' => true, 'max_stat_limit' => 5000]);
check('fingerprint: info advertises files.hash', $fpInfo['files_hash'] ?? null, ['max_paths' => 500]);
checkTrue('fingerprint: info keeps db_dump_many and what the host said', isset($fpInfo['db_dump_many'], $fpInfo['php']));

// ---- files.list hash:false ----------------------------------------------------------------------

[$fpHashPaths, $fpHashEntries] = fpWalkAll($fp, ['limit' => 500]);
[$fpStatPaths, $fpStatEntries] = fpWalkAll($fp, ['limit' => 5000, 'hash' => false]);
check('files.list hash:false walks the same paths in the same order', $fpStatPaths, $fpHashPaths);
check('files.list hash:false entries are {path, size, mtime}', array_unique(array_map(function ($e) { return implode(',', array_keys($e)); }, $fpStatEntries)), ['path,size,mtime']);
check('files.list hash:false sizes are the hashing walk\'s sizes', array_column($fpStatEntries, 'size'), array_column($fpHashEntries, 'size'));
checkTrue('files.list hash:false mtime is filemtime, in whole seconds', $fpStatEntries[0]['mtime'] === filemtime("{$fpRoot}/" . $fpStatEntries[0]['path']));
checkTrue('files.list hash:false leaves out wp-content/cache, the backups and the archives, keeps a plugin\'s log/ and cache/',
    !in_array('wp-content/cache/skip.dat', $fpStatPaths, true)
    && !in_array('wp-content/updraft/backup.zip', $fpStatPaths, true)
    && !in_array('images/site.sql', $fpStatPaths, true)
    && !in_array('backup-site-2026.tar.gz', $fpStatPaths, true)
    && in_array('wp-content/plugins/forms/vendor/psr/log/LoggerInterface.php', $fpStatPaths, true)
    && in_array('wp-content/plugins/forms/cache/Pool.php', $fpStatPaths, true));
// The protected files are LISTED, by size and mtime: a sync must see wp-config.php change. Their
// bytes come only in the site's own archive (files.pack), never one by one.
$fpStatByPath = array_column($fpStatEntries, null, 'path');
check('files.hash:false lists wp-config.php by path, size and mtime',
    $fpStatByPath['wp-config.php'] ?? null,
    ['path' => 'wp-config.php', 'size' => filesize("{$fpRoot}/wp-config.php"), 'mtime' => filemtime("{$fpRoot}/wp-config.php")]);
checkTrue('files.hash:false lists every protected file', isset($fpStatByPath['wp-config.php.bak'], $fpStatByPath['wp-config-old.php'], $fpStatByPath['staging/wp-config.php'], $fpStatByPath['.env']));
check('files.list default still answers {path, size, sha1}', array_keys($fpHashEntries[0]), ['path', 'size', 'sha1']);
check('files.list default still answers the sha1', $fpHashEntries[0]['sha1'], sha1_file("{$fpRoot}/" . $fpHashEntries[0]['path']));

$fpBig = $fp('files.list', ['limit' => 5000, 'hash' => false]);
check('files.list hash:false takes a page of up to 5000', [count($fpBig['files']), $fpBig['done'], $fpBig['next_cursor']], [count($fpHashPaths), true, end($fpHashPaths)]);
check('files.list with the sha1 still stops at 500', count($fp('files.list', ['limit' => 5000])['files']), 500);

[$fpPaged, , $fpPagedCalls] = fpWalkAll($fp, ['limit' => 7, 'hash' => false]);
check('files.list hash:false pages by after/limit and covers every path once', $fpPaged, $fpHashPaths);
check('files.list hash:false pages as often as the paths need', $fpPagedCalls, (int) ceil(count($fpHashPaths) / 7));
$fpPage1Hash = $fp('files.list', ['after' => 'images/a.png', 'limit' => 3]);
$fpPage1Stat = $fp('files.list', ['after' => 'images/a.png', 'limit' => 3, 'hash' => false]);
check('files.list hash:false cursor and done are the hashing answer\'s',
    [array_column($fpPage1Stat['files'], 'path'), $fpPage1Stat['next_cursor'], $fpPage1Stat['done']],
    [array_column($fpPage1Hash['files'], 'path'), $fpPage1Hash['next_cursor'], $fpPage1Hash['done']]);
check('files.list refuses a hash that is not a boolean', $fp('files.list', ['hash' => 'no'])['error'], 'bad_params');

// ---- files.hash ---------------------------------------------------------------------------------

$fpHashes = $fp('files.hash', ['paths' => [
    'images/a.png', 'index.php', 'images', '',
]]);
check('files.hash refuses an empty path in the list', $fpHashes['error'] ?? null, 'bad_params');
$fpHashes = $fp('files.hash', ['paths' => [
    'images/a.png', 'index.php', 'wp-content/plugins/forms/vendor/psr/log/LoggerInterface.php',
    'images/missing.png', '../index.php', 'images/../index.php', './index.php', 'images//a.png', '/etc/passwd',
    'wp-content/cache/skip.dat', 'WP-Content/Cache/skip.dat', 'wp-content/updraft/backup.zip',
    'images/site.sql', 'backup-site-2026.tar.gz',
    'wp-config.php', 'WP-CONFIG.PHP', './wp-config.php', 'wp-config.php.bak', 'wp-config-old.php', 'staging/wp-config.php', '.env',
    'wp-config-sample.php', 'wp-content/plugins/editor/wp-config-editor.php',
    'images',
]]);
check('files.hash ok', $fpHashes['ok'], true);
check('files.hash answers an object keyed by path', is_object($fpHashes['hashes']), true);
check('files.hash answers each path asked, a sha1 or null', (array) $fpHashes['hashes'], [
    'images/a.png' => sha1('AAAA'),
    'index.php' => sha1('<?php echo "hi";'),
    'wp-content/plugins/forms/vendor/psr/log/LoggerInterface.php' => sha1('<?php interface LoggerInterface {}'),
    'images/missing.png' => null,
    '../index.php' => null,
    'images/../index.php' => null,
    './index.php' => null,
    'images//a.png' => null,
    '/etc/passwd' => null,
    'wp-content/cache/skip.dat' => null,
    'WP-Content/Cache/skip.dat' => null,
    'wp-content/updraft/backup.zip' => null,
    'images/site.sql' => null,
    'backup-site-2026.tar.gz' => null,
    // file.read refuses them, so this does too: no answer about the site's secrets, not even a hash.
    'wp-config.php' => null,
    'WP-CONFIG.PHP' => null,
    './wp-config.php' => null,
    'wp-config.php.bak' => null,
    'wp-config-old.php' => null,
    'staging/wp-config.php' => null,
    '.env' => null,
    'wp-config-sample.php' => sha1('<?php define("DB_PASSWORD", "password_here");'),
    'wp-content/plugins/editor/wp-config-editor.php' => sha1('<?php // an editor'),
    'images' => null,
]);

// ---- the protected files: listed, packed, never read alone ---------------------------------------

foreach (['wp-config.php', 'WP-Config.php', './wp-config.php', 'wp-config.php.bak', 'wp-config-old.php', 'staging/wp-config.php', '.env'] as $fpSecret) {
    $fpRead = $fp('file.read', ['path' => $fpSecret]);
    check("file.read refuses {$fpSecret} without its bytes", [$fpRead['ok'], $fpRead['error'] ?? null, $fpRead['message'] ?? null, isset($fpRead['content_b64'])],
        [false, 'read_failed', 'refused: protected path', false]);
}
check('file.read still serves core\'s wp-config-sample.php', base64_decode($fp('file.read', ['path' => 'wp-config-sample.php'])['content_b64'] ?? ''), '<?php define("DB_PASSWORD", "password_here");');
check('file.read still serves a plugin file named like the configuration', $fp('file.read', ['path' => 'wp-content/plugins/editor/wp-config-editor.php'])['ok'], true);
$fpCut = $fp('files.pack', ['inline' => true, 'paths' => ['wp-config.php', 'staging/wp-config.php', 'index.php']]);
check('files.pack cut to the protected files packs them: the site\'s own archive carries its configuration',
    [$fpCut['ok'], $fpCut['done'], $fpCut['files']], [true, true, 3]);
$fpTar = $fpRoot . '-cut.tar';
file_put_contents($fpTar, base64_decode($fpCut['part_b64']));
$fpTarNames = [];
exec('tar -tf ' . escapeshellarg($fpTar) . ' 2>&1', $fpTarNames);
$fpTarConfig = [];
exec('tar -xOf ' . escapeshellarg($fpTar) . ' wp-config.php 2>/dev/null', $fpTarConfig);
unlink($fpTar);
check('the cut pack holds them, in the walk order', $fpTarNames, ['index.php', 'staging/wp-config.php', 'wp-config.php']);
check('with the configuration\'s own bytes', implode("\n", $fpTarConfig), '<?php define("DB_PASSWORD", "s3cret");');
$fpZero = $fp('files.hash', ['paths' => ['0']]);
check('files.hash keeps a path named 0 a key when encoded', json_encode($fpZero['hashes']), json_encode(['0' => sha1('zero')], JSON_FORCE_OBJECT));
if ($fpLinks) {
    check('files.hash follows a symlink that stays inside, refuses one that leads out or through a linked directory',
        (array) $fp('files.hash', ['paths' => ['images/link.png', 'outside.txt', 'linkdir/a.png']])['hashes'],
        ['images/link.png' => sha1('AAAA'), 'outside.txt' => null, 'linkdir/a.png' => null]);
}
check('files.hash refuses no paths', $fp('files.hash', ['paths' => []])['error'], 'bad_params');
check('files.hash refuses a path that is not a string', $fp('files.hash', ['paths' => ['index.php', 7]])['error'], 'bad_params');
check('files.hash refuses more than 500 paths', $fp('files.hash', ['paths' => array_fill(0, 501, 'index.php')])['error'], 'bad_params');
check('files.hash takes 500', count((array) $fp('files.hash', ['paths' => array_map(function ($i) { return sprintf('bulk/f%04d.txt', $i); }, range(0, 499))])['hashes']), 500);
check('files.hash without a walker is unavailable', (new Engine($FPTOKEN))->handle(['token' => $FPTOKEN, 'action' => 'files.hash', 'params' => ['paths' => ['index.php']]])['error'], 'unavailable');

// ---- site.fingerprint: files ----------------------------------------------------------------------

$fpOne = $fp('site.fingerprint');
check('fingerprint ok', $fpOne['ok'], true);
check('fingerprint shape', array_keys($fpOne['fingerprint']), ['version', 'files', 'tables', 'seconds']);
check('fingerprint version', $fpOne['fingerprint']['version'], 2);
check('fingerprint files shape', array_keys($fpOne['fingerprint']['files']), ['count', 'bytes', 'digest', 'next_after']);
check('fingerprint counts the walk files.list walks', $fpOne['fingerprint']['files']['count'], count($fpHashPaths));
check('fingerprint bytes are the walk\'s bytes', $fpOne['fingerprint']['files']['bytes'], array_sum(array_column($fpStatEntries, 'size')));
check('fingerprint digest is sha1 of path NUL size NUL mtime LF, in walk order', $fpOne['fingerprint']['files']['digest'], fpDigest($fpStatEntries));
check('fingerprint of the whole tree has no next page', $fpOne['fingerprint']['files']['next_after'], null);
checkTrue('fingerprint seconds is a number', is_float($fpOne['fingerprint']['seconds']) || is_int($fpOne['fingerprint']['seconds']));
check('fingerprint of the same tree twice is the same digest',
    (new Engine($FPTOKEN, [], null, new FileWalker($fpRoot)))->handle(['token' => $FPTOKEN, 'action' => 'site.fingerprint', 'params' => ['checksums' => false]])['fingerprint']['files'],
    $fpOne['fingerprint']['files']);

$fpFresh = function (array $params = []) use ($FPTOKEN, $fpRoot): array {
    clearstatcache();
    return (new Engine($FPTOKEN, [], null, new FileWalker($fpRoot)))->handle(['token' => $FPTOKEN, 'action' => 'site.fingerprint', 'params' => $params + ['checksums' => false]])['fingerprint']['files'];
};
$fpMtime = filemtime("{$fpRoot}/images/b.png");
touch("{$fpRoot}/images/b.png", $fpMtime + 100);
$fpTouched = $fpFresh();
checkTrue('fingerprint: a touched mtime changes the digest', $fpTouched['digest'] !== $fpOne['fingerprint']['files']['digest']);
check('fingerprint: and nothing else', [$fpTouched['count'], $fpTouched['bytes']], [$fpOne['fingerprint']['files']['count'], $fpOne['fingerprint']['files']['bytes']]);
touch("{$fpRoot}/images/b.png", $fpMtime);
check('fingerprint: the mtime put back is the digest back', $fpFresh()['digest'], $fpOne['fingerprint']['files']['digest']);
file_put_contents("{$fpRoot}/images/b.png", 'BBBBBBB');
touch("{$fpRoot}/images/b.png", $fpMtime);
$fpGrown = $fpFresh();
checkTrue('fingerprint: a changed size changes the digest, the mtime kept', $fpGrown['digest'] !== $fpOne['fingerprint']['files']['digest']);
check('fingerprint: and the bytes', $fpGrown['bytes'], $fpOne['fingerprint']['files']['bytes'] + 1);
file_put_contents("{$fpRoot}/images/b.png", 'BBBBBB');
touch("{$fpRoot}/images/b.png", $fpMtime);
check('fingerprint: the size put back is the digest back', $fpFresh()['digest'], $fpOne['fingerprint']['files']['digest']);

$fpConfigTime = filemtime("{$fpRoot}/wp-config.php");
file_put_contents("{$fpRoot}/wp-config.php", '<?php define("DB_PASSWORD", "n3w");');
touch("{$fpRoot}/wp-config.php", $fpConfigTime + 60);
checkTrue('fingerprint: an edited wp-config.php changes the digest, though nothing reads it', $fpFresh()['digest'] !== $fpOne['fingerprint']['files']['digest']);
file_put_contents("{$fpRoot}/wp-config.php", '<?php define("DB_PASSWORD", "s3cret");');
touch("{$fpRoot}/wp-config.php", $fpConfigTime);
check('fingerprint: put back, the digest is back', $fpFresh()['digest'], $fpOne['fingerprint']['files']['digest']);

// Paging: fixed pages of 37, each the digest of its own slice of the walk, every path in exactly one.
$fpPages = [];
$fpAfter = '';
do {
    $page = $fp('site.fingerprint', ['after' => $fpAfter, 'limit' => 37, 'checksums' => false])['fingerprint']['files'];
    $fpPages[] = $page;
    $fpAfter = (string) $page['next_after'];
} while ($page['next_after'] !== null && count($fpPages) < 100);
$fpExpected = array_chunk($fpStatEntries, 37);
check('fingerprint pages: as many as the walk needs at 37 a page', count($fpPages), count($fpExpected));
check('fingerprint pages: every path counted once', array_sum(array_column($fpPages, 'count')), count($fpHashPaths));
check('fingerprint pages: each page is the digest of its slice', array_column($fpPages, 'digest'), array_map('fpDigest', $fpExpected));
check('fingerprint pages: each next_after is its slice\'s last path, the last page none',
    array_column($fpPages, 'next_after'),
    array_merge(array_map(function ($chunk) { return $chunk[count($chunk) - 1]['path']; }, array_slice($fpExpected, 0, -1)), [null]));
check('fingerprint pages: the same cut twice', $fp('site.fingerprint', ['after' => $fpPages[2]['next_after'], 'limit' => 37, 'checksums' => false])['fingerprint']['files'], $fpPages[3]);
// A cursor that is no longer in the tree resumes at the next path, never at the top.
check('fingerprint: an after that names no file starts at the next path that does',
    $fp('site.fingerprint', ['after' => 'images/azz', 'limit' => 2, 'checksums' => false])['fingerprint']['files'],
    $fp('site.fingerprint', ['after' => 'images/a.png', 'limit' => 2, 'checksums' => false])['fingerprint']['files']);
check('fingerprint: an after past the last path is an empty page',
    $fp('site.fingerprint', ['after' => "\x7f", 'checksums' => false])['fingerprint']['files'],
    ['count' => 0, 'bytes' => 0, 'digest' => sha1(''), 'next_after' => null]);
check('fingerprint: limit 0 is one path', $fp('site.fingerprint', ['limit' => 0, 'checksums' => false])['fingerprint']['files']['count'], 1);
check('fingerprint: files false leaves the walk out', $fp('site.fingerprint', ['files' => false])['fingerprint']['files'], null);
check('fingerprint: files must be a boolean', $fp('site.fingerprint', ['files' => 'yes'])['error'], 'bad_params');

// ---- site.fingerprint: tables ---------------------------------------------------------------------

$fpTables = $fp('site.fingerprint', ['files' => false])['fingerprint']['tables'];
check('fingerprint tables: an object keyed by table', is_object($fpTables), true);
check('fingerprint tables: every table db.tables lists, in its order', array_keys((array) $fpTables), $fp('db.tables')['tables']);
checkTrue('fingerprint tables: a checksum is a decimal string', is_string($fpTables->wp_a) && ctype_digit($fpTables->wp_a));
check('fingerprint tables: NULL from the server is null', $fpTables->wp_c, null);
check('fingerprint tables: the same tables twice, the same checksums', (array) $fp('site.fingerprint', ['files' => false])['fingerprint']['tables'], (array) $fpTables);
$fpSource->insertRow('wp_a', ['3']);
checkTrue('fingerprint tables: a new row changes its table\'s checksum, not another\'s',
    ($fpAfterRow = (array) $fp('site.fingerprint', ['files' => false])['fingerprint']['tables'])['wp_a'] !== $fpTables->wp_a
    && $fpAfterRow['wp_b'] === $fpTables->wp_b);
check('fingerprint tables: a subset answers those, in the order asked',
    array_keys((array) $fp('site.fingerprint', ['files' => false, 'tables' => ['wp_b', 'wp_a', 'wp_b']])['fingerprint']['tables']),
    ['wp_b', 'wp_a']);
check('fingerprint tables: a name db.tables does not list is refused', $fp('site.fingerprint', ['files' => false, 'tables' => ['wp_a', 'wp_nope']])['error'], 'bad_params');
check('fingerprint tables: an empty subset is refused', $fp('site.fingerprint', ['tables' => []])['error'], 'bad_params');
check('fingerprint tables: checksums false leaves the database out', $fp('site.fingerprint', ['checksums' => false])['fingerprint']['tables'], null);
check('fingerprint: both false is an empty fingerprint', array_slice($fp('site.fingerprint', ['files' => false, 'checksums' => false])['fingerprint'], 0, 3), ['version' => 2, 'files' => null, 'tables' => null]);

$fpMany = [];
for ($i = 1; $i <= 120; $i++) {
    $fpMany[sprintf('wp_t%03d', $i)] = ['create' => 'CREATE TABLE t (`id` int)', 'rows' => [[(string) $i]], 'columns' => ['id'], 'key' => ['id']];
}
$fpManySource = new FakeRowSource($fpMany);
$fpManyAnswer = (new Engine($FPTOKEN, [], new DbDumper($fpManySource)))->handle(['token' => $FPTOKEN, 'action' => 'site.fingerprint', 'params' => ['files' => false]]);
check('fingerprint tables: 120 tables are three CHECKSUM TABLE statements of at most 50', array_map('count', $fpManySource->checksumCalls), [50, 50, 20]);
check('fingerprint tables: and every one answered', count((array) $fpManyAnswer['fingerprint']['tables']), 120);

// ---- site.fingerprint: skip_columns, a checksum of the substance --------------------------------
// On a live Joomla site CHECKSUM TABLE moves on almost every request (content.hits on each page view,
// users.lastvisitDate on each login), so "nothing changed" was never seen. The caller sends the same
// names to every site; a WordPress table that has none of them is checksummed whole, by its substance.

$fpSubSource = new FakeRowSource([
    'wp_hits' => ['create' => 'CREATE TABLE c', 'columns' => ['id', 'title', 'hits'], 'key' => ['id'],
        'rows' => [['1', 'Home', '10'], ['2', 'About', '3']]],
    'wp_logins' => ['create' => 'CREATE TABLE u', 'columns' => ['id', 'name', 'lastvisitDate'], 'key' => ['id'],
        'rows' => [['1', 'Ana', '2026-10-01 10:00:00']]],
    'wp_empty' => ['create' => 'CREATE TABLE e', 'columns' => ['id'], 'rows' => []],
    'wp_counter' => ['create' => 'CREATE TABLE n', 'columns' => ['hits'], 'rows' => [['1'], ['2']]],
]);
$fpSubEngine = new Engine($FPTOKEN, [], new DbDumper($fpSubSource));
$fpSub = function (array $params) use ($fpSubEngine, $FPTOKEN): array {
    return $fpSubEngine->handle(['token' => $FPTOKEN, 'action' => 'site.fingerprint', 'params' => ['files' => false] + $params]);
};
$fpSkip = ['skip_columns' => ['lastvisitDate', 'hits']];
$fpSubOne = $fpSub($fpSkip)['fingerprint'];
check('skip_columns: the answer echoes the names used, sorted', $fpSubOne['skip_columns'], ['hits', 'lastvisitDate']);
check('skip_columns: the echo sits beside tables', array_keys($fpSubOne), ['version', 'files', 'tables', 'skip_columns', 'seconds']);
checkTrue('skip_columns: every table answers "<rows>:<xor>"', count(array_filter((array) $fpSubOne['tables'], function ($v) { return is_string($v) && preg_match('/^\d+:\d+$/D', $v); })) === 4);
check('skip_columns: a table with no rows is 0:0', $fpSubOne['tables']->wp_empty, '0:0');
check('skip_columns: a table with no column left is <rows>:0', $fpSubOne['tables']->wp_counter, '2:0');
check('skip_columns: the count leads the value', explode(':', $fpSubOne['tables']->wp_hits)[0], '2');
$fpSubSource->deleteRow('wp_hits', 0);
$fpSubSource->insertRow('wp_hits', ['1', 'Home', '11']);
$fpSubSource->deleteRow('wp_logins', 0);
$fpSubSource->insertRow('wp_logins', ['1', 'Ana', '2026-10-22 09:00:00']);
$fpSubTwo = $fpSub($fpSkip)['fingerprint'];
check('skip_columns: a page view and a login keep every value', (array) $fpSubTwo['tables'], (array) $fpSubOne['tables']);
check('skip_columns: names match ignoring case, as MySQL names columns',
    (array) $fpSub(['skip_columns' => ['HITS', 'lastVisitDate']])['fingerprint']['tables'], (array) $fpSubOne['tables']);
$fpSubPlain = (array) $fpSub([])['fingerprint']['tables'];
$fpSubSource->deleteRow('wp_hits', 0);
$fpSubSource->insertRow('wp_hits', ['1', 'Home', '12']);
checkTrue('skip_columns: without them the same hit moves CHECKSUM TABLE', (array) $fpSub([])['fingerprint']['tables'] !== $fpSubPlain);
$fpSubSource->deleteRow('wp_hits', 0);
$fpSubSource->insertRow('wp_hits', ['1', 'Welcome', '12']);
$fpSubThree = (array) $fpSub($fpSkip)['fingerprint']['tables'];
checkTrue('skip_columns: a changed title moves its table', $fpSubThree['wp_hits'] !== $fpSubOne['tables']->wp_hits);
check('skip_columns: and no other', [$fpSubThree['wp_logins'], $fpSubThree['wp_empty'], $fpSubThree['wp_counter']],
    [$fpSubOne['tables']->wp_logins, $fpSubOne['tables']->wp_empty, $fpSubOne['tables']->wp_counter]);
check('skip_columns: no CHECKSUM TABLE is asked when they are given', $fpSubSource->checksumCalls, [[ 'wp_hits', 'wp_logins', 'wp_empty', 'wp_counter'], ['wp_hits', 'wp_logins', 'wp_empty', 'wp_counter']]);
check('skip_columns: a subset of tables takes them too', array_keys((array) $fpSub($fpSkip + ['tables' => ['wp_logins']])['fingerprint']['tables']), ['wp_logins']);
check('skip_columns: an empty list is CHECKSUM TABLE and no echo', array_key_exists('skip_columns', $fpSub(['skip_columns' => []])['fingerprint']), false);
check('skip_columns: without them there is no echo', array_keys($fpSub([])['fingerprint']), ['version', 'files', 'tables', 'seconds']);
check('skip_columns: duplicates are used once', $fpSub(['skip_columns' => ['hits', 'hits']])['fingerprint']['skip_columns'], ['hits']);
foreach ([['hits`'], ['1hits'], ['hi ts'], ['a.b'], [''], [7], [str_repeat('a', 65)], 'hits'] as $fpBad) {
    check('skip_columns: refused ' . json_encode($fpBad), $fpSub(['skip_columns' => $fpBad])['error'] ?? null, 'bad_params');
}
check('skip_columns: 50 names are taken', $fpSub(['skip_columns' => array_map(function ($i) { return "c_{$i}"; }, range(1, 50))])['ok'], true);
check('skip_columns: 51 are refused', $fpSub(['skip_columns' => array_map(function ($i) { return "c_{$i}"; }, range(1, 51))])['error'], 'bad_params');
check('skip_columns: a 64-character name is taken', $fpSub(['skip_columns' => [str_repeat('a', 64)]])['ok'], true);

// WordPress's own tables, with the names "Sync from live" sends to every site (live-pull SKIP_COLUMNS):
// none of them is a WordPress column, so each table is its substance whole; an edit in place moves it.
$fpLiveSkip = ['skip_columns' => ['hits', 'checked_out', 'checked_out_time', 'lastvisitDate', 'lastResetTime', 'resetCount',
    'last_execution', 'next_execution', 'times_executed', 'times_failed', 'last_exit_code', 'locked', 'last_check_timestamp']];
$fpWpSource = new FakeRowSource([
    'wp_options' => ['create' => 'CREATE TABLE o', 'columns' => ['option_id', 'option_name', 'option_value', 'autoload'], 'key' => ['option_id'],
        'rows' => [['1', 'siteurl', 'https://example.test', 'yes'], ['2', 'blogname', 'My Site', 'yes']]],
    'wp_posts' => ['create' => 'CREATE TABLE p', 'columns' => ['ID', 'post_title', 'post_content', 'post_modified'], 'key' => ['ID'],
        'rows' => [['1', 'Hello', '<p>Hi</p>', '2026-10-01 10:00:00']]],
]);
$fpWp = function () use ($FPTOKEN, $fpWpSource, $fpLiveSkip): array {
    return (array) (new Engine($FPTOKEN, [], new DbDumper($fpWpSource)))->handle(['token' => $FPTOKEN, 'action' => 'site.fingerprint',
        'params' => ['files' => false] + $fpLiveSkip])['fingerprint']['tables'];
};
$fpWpOne = $fpWp();
check('WordPress tables: the same database read by a fresh engine is the same print', $fpWp(), $fpWpOne);
$fpWpSource->deleteRow('wp_options', 1);
$fpWpSource->insertRow('wp_options', ['2', 'blogname', 'My New Site', 'yes'], 1);
$fpWpTwo = $fpWp();
check('WordPress tables: a row edited in place moves its table and no other',
    [$fpWpTwo['wp_options'] !== $fpWpOne['wp_options'], $fpWpTwo['wp_posts'] === $fpWpOne['wp_posts'], explode(':', $fpWpTwo['wp_options'])[0]],
    [true, true, '2']);
$fpWpSource->deleteRow('wp_options', 1);
$fpWpSource->insertRow('wp_options', ['2', 'blogname', 'My Site', 'yes'], 1);
check('WordPress tables: the edit put back is the print back', $fpWp(), $fpWpOne);

check('fingerprint without a dumper: checksums are unavailable', (new Engine($FPTOKEN, [], null, $fpWalker))->handle(['token' => $FPTOKEN, 'action' => 'site.fingerprint'])['error'], 'unavailable');
check('fingerprint without a dumper: the files alone still answer', (new Engine($FPTOKEN, [], null, $fpWalker))->handle(['token' => $FPTOKEN, 'action' => 'site.fingerprint', 'params' => ['checksums' => false]])['ok'], true);
check('fingerprint without a walker: the files are unavailable', (new Engine($FPTOKEN, [], new DbDumper($fpSource)))->handle(['token' => $FPTOKEN, 'action' => 'site.fingerprint'])['error'], 'unavailable');

// One real answer, as the door encodes it, when asked for (COWORK_TEST_SHOW_FINGERPRINT=1).
if (getenv('COWORK_TEST_SHOW_FINGERPRINT')) {
    echo json_encode($fp('site.fingerprint', ['limit' => 5]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo json_encode($fpSub($fpSkip), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo json_encode($fp('files.list', ['limit' => 2, 'hash' => false]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo json_encode($fp('files.hash', ['paths' => ['images/a.png', 'images/missing.png', '../index.php']]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo json_encode(array_intersect_key($fp('info')['info'], ['site_fingerprint' => 1, 'files_list' => 1, 'files_hash' => 1]), JSON_PRETTY_PRINT) . "\n";
}

// ---- clean up -------------------------------------------------------------------------------------

$fpClean = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fpRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($fpClean as $entry) {
    $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($fpRoot);
@unlink($fpOutside);
