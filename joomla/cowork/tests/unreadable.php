<?php
/** Permission failures through the real walker, pack, and tar reader. Run as a non-root user. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/Engine.php';
$passed = 0;
$failed = 0;
function check(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { $passed++; echo "ok - {$name}\n"; }
    else { $failed++; echo "not ok - {$name}: " . var_export($actual, true) . "\n"; }
}
if (is_readable('/root') && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Run this permission test as a non-root user.\n"); exit(2);
}
// ── a folder or file PHP may not read is left out and named, never the end of the walk ──────────
//
// One `certificates/thumbs` the host had made unreadable stopped eleven imports of a Joomla 6 site
// running: RecursiveDirectoryIterator threw "Failed to open directory: Permission denied" and
// files.pack answered pack_failed for the whole site (05/11/2026). Root reads everything, so this
// runs as any other user (the CI runner, a docker `--user`) and says so when it cannot.
$lockTmp = sys_get_temp_dir() . '/tracy_unreadable_' . bin2hex(random_bytes(4));
mkdir("$lockTmp/certificates/thumbs", 0777, true);
mkdir("$lockTmp/images", 0777, true);
file_put_contents("$lockTmp/index.php", '<?php // home');
file_put_contents("$lockTmp/certificates/a.pdf", 'pdf bytes');
file_put_contents("$lockTmp/certificates/thumbs/a.jpg", 'jpg bytes');
file_put_contents("$lockTmp/images/secret.png", 'png bytes');
file_put_contents("$lockTmp/images/zz-later.png", 'later bytes');
chmod("$lockTmp/certificates/thumbs", 0000);
chmod("$lockTmp/images/secret.png", 0000);
if (!is_readable("$lockTmp/images/secret.png")) {
    $lockToken = 'a-token-at-least-16';
    $lockWalker = new FileWalker($lockTmp);
    check('an unreadable folder and file are left out of the walk',
        array_map(fn($f) => $f['path'], $lockWalker->listBatch('', 50)['files']),
        ['certificates/a.pdf', 'images/zz-later.png', 'index.php']);
    check('and named, folders ending in a slash',
        $lockWalker->unreadable(), ['paths' => ['certificates/thumbs/', 'images/secret.png'], 'count' => 2]);

    $lockEngine = new Engine($lockToken, [], null, new FileWalker($lockTmp), null);
    $lockPack = $lockEngine->handle(['token' => $lockToken, 'action' => 'files.pack', 'params' => ['inline' => true]]);
    check('files.pack goes on past them', [$lockPack['ok'] ?? null, $lockPack['done'] ?? null], [true, true]);
    check('files.pack names them', $lockPack['unreadable'] ?? null,
        ['paths' => ['certificates/thumbs/', 'images/secret.png'], 'count' => 2]);
    $lockTar = $lockTmp . '.tar';
    file_put_contents($lockTar, base64_decode((string) ($lockPack['part_b64'] ?? '')));
    exec('tar -tf ' . escapeshellarg($lockTar) . ' 2>&1', $lockNames, $lockStatus);
    sort($lockNames);
    check('the archive holds what could be read, whole', [$lockStatus, $lockNames],
        [0, ['certificates/a.pdf', 'images/zz-later.png', 'index.php']]);
    $lockList = $lockEngine->handle(['token' => $lockToken, 'action' => 'files.list', 'params' => ['after' => '', 'limit' => 50]]);
    check('files.list names them too', $lockList['unreadable']['count'] ?? null, 2);

    // Made unreadable after the walk listed it, before the pack reached it: skipped and named.
    chmod("$lockTmp/images/secret.png", 0644);
    $lateWalker = new FileWalker($lockTmp);
    $lateWalker->pathsAfter('', 50);
    chmod("$lockTmp/images/zz-later.png", 0000);
    $lateEngine = new Engine($lockToken, [], null, $lateWalker, null);
    $latePack = $lateEngine->handle(['token' => $lockToken, 'action' => 'files.pack', 'params' => ['inline' => true]]);
    file_put_contents($lockTar, base64_decode((string) ($latePack['part_b64'] ?? '')));
    $lateNames = [];
    exec('tar -tf ' . escapeshellarg($lockTar) . ' 2>&1', $lateNames, $lateStatus);
    sort($lateNames);
    check('a file made unreadable mid-pack is skipped, the archive whole',
        [$latePack['ok'] ?? null, $lateStatus, $lateNames],
        [true, 0, ['certificates/a.pdf', 'images/secret.png', 'index.php']]);
    check('and named with the folder', $latePack['unreadable']['paths'] ?? null,
        ['certificates/thumbs/', 'images/zz-later.png']);
    chmod("$lockTmp/images/secret.png", 0000);
    clearstatcache();
    $resumeEngine = new Engine($lockToken, [], null, new FileWalker($lockTmp), null);
    $resume = $resumeEngine->handle(['token' => $lockToken, 'action' => 'files.pack', 'params' => [
        'inline' => true, 'path' => 'images/secret.png', 'offset' => 512, 'size' => 9,
    ]]);
    check('a resumed unreadable entry refuses rather than substituting zero bytes',
        [$resume['ok'] ?? null, $resume['error'] ?? null], [false, 'read_failed']);
    @unlink($lockTar);
} else {
    fwrite(STDERR, "Permission fixture is readable; run as a non-root user.\n"); exit(2);
}
chmod("$lockTmp/certificates/thumbs", 0755);
chmod("$lockTmp/images/zz-later.png", 0644);
chmod("$lockTmp/images/secret.png", 0644);
exec('rm -rf ' . escapeshellarg($lockTmp));

echo "{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
