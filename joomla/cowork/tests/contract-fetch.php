<?php
// Loaded by run.php. A site's contract profile, fetched from the release's assets (lib/ContractFetch.php),
// and the profiles install and update prune (script.php) — TCH #1568. The release side of the same
// thing (one zip per profile, the index) is scripts/contract-assets.test.mjs.
echo "\nContract profiles: fetched, checked, pruned\n";

require_once __DIR__ . '/../lib/ContractFetch.php';
if (!defined('_JEXEC')) define('_JEXEC', 1);
require_once __DIR__ . '/../script.php';

/** A source that is slow to open and to read, for PackageDownload's stream copy: one byte, then the end. */
final class ContractFetchSlowStream
{
    public static float $open = 0.0;
    public static float $read = 0.0;
    /** @var resource|null */
    public $context;
    private bool $sent = false;
    public function stream_open($path, $mode, $options, &$opened): bool { usleep((int) (self::$open * 1e6)); return true; }
    public function stream_read($count) { if ($this->sent) return ''; usleep((int) (self::$read * 1e6)); $this->sent = true; return 'x'; }
    public function stream_eof(): bool { return $this->sent; }
    public function stream_stat() { return []; }
    public function stream_set_option($option, $arg1, $arg2): bool { return false; }
}

(static function (): void {
    /** A zip as build.sh writes one, from [name => bytes]; `$mode` per name overrides the unix mode. */
    $zip = static function (array $files, array $mode = []): string {
        $locals = ''; $central = ''; $offset = 0;
        foreach ($files as $name => $bytes) {
            $packed = gzdeflate($bytes, 9);
            $crc = crc32($bytes);
            $head = pack('vvvvvVVVvv', 20, 0x0800, 8, 0, 0x21, $crc, strlen($packed), strlen($bytes), strlen($name), 0);
            $locals .= "PK\x03\x04" . $head . $name . $packed;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 0x031e, 20, 0x0800, 8, 0, 0x21, $crc, strlen($packed), strlen($bytes), strlen($name), 0, 0, 0, 0,
                ($mode[$name] ?? 0100644) << 16, $offset) . $name;
            $offset += 30 + strlen($name) + strlen($packed);
        }
        return $locals . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), $offset, 0);
    };
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
        rmdir($path);
    };
    $list = static function (string $dir): array {
        $out = [];
        if (!is_dir($dir)) return $out;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) $out[] = substr($file->getPathname(), strlen($dir) + 1);
        sort($out);
        return $out;
    };
    $manifest = static fn(string $id, ?string $named = null): string => json_encode(['id' => $id] + ($named === null ? [] : ['quickstart' => ['contract' => $named]]));

    $tmp = sys_get_temp_dir() . '/contract-fetch-' . bin2hex(random_bytes(6));
    try {
        // ── fetch ────────────────────────────────────────────────────────────────────────────────
        // A site's lib/ with an index, and a "release" folder the stub downloader serves from.
        $site = function (array $assets) use ($tmp, $zip): array {
            static $n = 0;
            $root = $tmp . '/site' . (++$n);
            mkdir($root . '/lib', 0777, true);
            mkdir($root . '/release', 0777, true);
            $index = [];
            foreach ($assets as $id => $asset) {
                $bytes = is_string($asset['zip'] ?? null) ? $asset['zip'] : $zip($asset['files']);
                file_put_contents($root . '/release/' . ContractFetch::asset($id), $bytes);
                $index[$id] = ['asset' => ContractFetch::asset($id), 'sha256' => $asset['sha256'] ?? hash('sha256', $bytes), 'bytes' => $asset['bytes'] ?? strlen($bytes)];
            }
            ksort($index);
            file_put_contents($root . '/lib/contracts.json', json_encode(['contracts' => $index]));
            return [$root . '/lib', $root . '/release'];
        };
        $calls = [];
        // The real downloader (PackageDownload, stream copy) reading the release folder in place of GitHub.
        $fetcher = static function (string $lib, string $release, ?callable $now = null) use (&$calls): ContractFetch {
            $base = 'https://github.example/releases/download/joomla-v1.2.3/';
            return new ContractFetch($lib, $base, [], static function (string $url, string $target, int $bytes, string $sha256) use (&$calls, $base, $release): array {
                $calls[] = $url;
                return PackageDownload::toFile($release . '/' . substr($url, strlen($base)), $target, $bytes, $sha256, [], false, ContractFetch::TIMEOUT);
            }, $now);
        };

        check('fetch: the asset of a profile is its id with "/" as "--"', ContractFetch::asset('tracy-business/j6/1.2.6'), 'tracy-business--j6--1.2.6.zip');
        check('fetch: assets are read from the installed version\'s release, at the one release prefix',
            [ContractFetch::releaseOf('0.24.53'), ContractFetch::releaseOf(null)], ['https://github.com/TracyHQ/claude-cowork/releases/download/joomla-v0.24.53/', null]);

        // The happy path: the profile, then the one it names in quickstart.contract.
        [$lib, $release] = $site([
            'acme/j6/1.1.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.1.0', 'acme/j6/1.0.0'), 'content-map.json' => '{"a":1}', 'presentation-lock.json' => '{}']],
            'acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.0.0', 'acme/j6/1.1.0'), 'content-map.json' => '{}', 'presentation-lock.json' => '{}']],
            'other/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('other/j6/1.0.0')]],
        ]);
        $calls = [];
        check('fetch: a listed profile that is not on disk is fetched', $fetcher($lib, $release)->ensure('acme/j6/1.1.0'), null);
        check('fetch: from the release, then the profile it names (and not back again: a cycle stops)', $calls,
            ['https://github.example/releases/download/joomla-v1.2.3/acme--j6--1.1.0.zip', 'https://github.example/releases/download/joomla-v1.2.3/acme--j6--1.0.0.zip']);
        check('fetch: both land whole, and nothing else (no temporary file, no marker)', $list($lib . '/contracts'), [
            'acme', 'acme/j6', 'acme/j6/1.0.0', 'acme/j6/1.0.0/content-map.json', 'acme/j6/1.0.0/manifest.json', 'acme/j6/1.0.0/presentation-lock.json',
            'acme/j6/1.1.0', 'acme/j6/1.1.0/content-map.json', 'acme/j6/1.1.0/manifest.json', 'acme/j6/1.1.0/presentation-lock.json']);
        check('fetch: the bytes are the profile\'s', file_get_contents($lib . '/contracts/acme/j6/1.1.0/content-map.json'), '{"a":1}');
        $calls = [];
        check('fetch: a profile on disk is not fetched again', [$fetcher($lib, $release)->ensure('acme/j6/1.1.0'), $calls], [null, []]);

        // The profiles it names are needed as much as the profile: one that cannot be fetched fails it.
        [$lib, $release] = $site([
            'acme/j6/1.1.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.1.0', 'acme/j6/1.0.0')]],
            'acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.0.0')], 'sha256' => str_repeat('0', 64)],
        ]);
        $named = $fetcher($lib, $release)->ensure('acme/j6/1.1.0');
        check('fetch: a named profile that cannot be fetched fails the profile that names it',
            [strpos((string) $named, 'fetch failed: ') === 0, strpos((string) $named, 'acme/j6/1.0.0') !== false], [true, true]);
        check('fetch: the profile itself stays, the named one is absent and its failure remembered',
            [is_file($lib . '/contracts/acme/j6/1.1.0/manifest.json'), is_dir($lib . '/contracts/acme/j6/1.0.0'), is_file($lib . '/contracts/acme/j6/1.0.0.fetch-failed')], [true, false, true]);
        check('fetch: and it keeps failing while the named one is absent', strpos((string) $fetcher($lib, $release)->ensure('acme/j6/1.1.0'), 'fetch failed: ') === 0, true);
        [$lib, $release] = $site(['acme/j6/1.1.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.1.0', 'acme/j6/0.1.0')]]]);
        check('fetch: so does a named profile the index does not list',
            strpos((string) $fetcher($lib, $release)->ensure('acme/j6/1.1.0'), 'fetch failed: ') === 0, true);

        // An id the index does not list is refused as before: no reason, no download, no marker.
        $calls = [];
        $unknown = $fetcher($lib, $release);
        check('fetch: an unknown id is refused as it always was, with nothing fetched',
            [$unknown->ensure('acme/j6/9.9.9'), $unknown->ensure('../etc/j6/1.0.0'), $calls, is_file($lib . '/contracts/acme/j6/9.9.9.fetch-failed')],
            [ContractFetch::UNKNOWN, ContractFetch::UNKNOWN, [], false]);
        check('fetch: without an index nothing is fetched, and it says why',
            (new ContractFetch($tmp . '/no-such-lib', 'https://x/', [], static function () { throw new LogicException('called'); }))->ensure('acme/j6/1.0.0'), 'contracts.json is unreadable');

        // A wrong sha256: refused, and nothing lands.
        [$lib, $release] = $site(['acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.0.0')], 'sha256' => str_repeat('0', 64)]]);
        $answer = $fetcher($lib, $release)->ensure('acme/j6/1.0.0');
        checkTrue('fetch: a wrong sha256 is refused, saying so (' . $answer . ')', is_string($answer) && strpos($answer, 'fetch failed: sha256 ') === 0);
        check('fetch: and nothing lands but the marker', $list($lib . '/contracts'), ['acme', 'acme/j6', 'acme/j6/1.0.0.fetch-failed']);
        // The same when the downloader does not check: the index is checked again here.
        [$lib, $release] = $site(['acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.0.0')], 'sha256' => str_repeat('a', 64)]]);
        $lax = new ContractFetch($lib, 'https://x/', [], static fn(string $url, string $target): array => ['ok' => copy($release . '/acme--j6--1.0.0.zip', $target)]);
        check('fetch: a downloader that skips the sha256 does not get the profile in', [$lax->ensure('acme/j6/1.0.0'), is_dir($lib . '/contracts/acme/j6/1.0.0')],
            ['fetch failed: sha256 is not the listed one', false]);
        [$lib, $release] = $site(['acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.0.0')], 'bytes' => 7]]);
        $answer = $fetcher($lib, $release)->ensure('acme/j6/1.0.0');
        checkTrue('fetch: a size other than the listed one is refused (' . $answer . ')', is_string($answer) && strpos($answer, 'fetch failed: ') === 0 && !is_dir($lib . '/contracts/acme/j6/1.0.0'));

        // Entries that escape the folder, whatever the index says of the zip's bytes.
        foreach (['a "../" entry' => [['manifest.json' => $manifest('acme/j6/1.0.0'), '../escaped.json' => 'x'], []],
                  'a nested "../" entry' => [['manifest.json' => $manifest('acme/j6/1.0.0'), 'sub/../../escaped.json' => 'x'], []],
                  'an absolute entry' => [['manifest.json' => $manifest('acme/j6/1.0.0'), '/tmp/escaped.json' => 'x'], []],
                  'a backslash entry' => [['manifest.json' => $manifest('acme/j6/1.0.0'), '..\\escaped.json' => 'x'], []],
                  'a symlink' => [['manifest.json' => $manifest('acme/j6/1.0.0'), 'link.json' => '/etc/passwd'], ['link.json' => 0120777]]] as $what => [$files, $mode]) {
            [$lib, $release] = $site(['acme/j6/1.0.0' => ['zip' => $zip($files, $mode)]]);
            $answer = $fetcher($lib, $release)->ensure('acme/j6/1.0.0');
            checkTrue("fetch: {$what} is refused ({$answer})", is_string($answer) && strpos($answer, 'fetch failed: ') === 0);
            check("fetch: {$what}: nothing lands, inside or outside the folder", [$list($lib . '/contracts'), file_exists($lib . '/escaped.json'), file_exists($lib . '/contracts/acme/escaped.json')],
                [['acme', 'acme/j6', 'acme/j6/1.0.0.fetch-failed'], false, false]);
        }
        [$lib, $release] = $site(['acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/2.0.0')]]]);
        check('fetch: an asset holding another profile is refused', $fetcher($lib, $release)->ensure('acme/j6/1.0.0'), 'fetch failed: the asset holds no manifest for acme/j6/1.0.0');

        // Backoff: a failure is not tried again for a few minutes; the refusal keeps the reason.
        [$lib, $release] = $site(['acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.0.0')], 'sha256' => str_repeat('0', 64)]]);
        $clock = 1000000;
        $now = static function () use (&$clock): int { return $clock; };
        $calls = [];
        $first = $fetcher($lib, $release, $now)->ensure('acme/j6/1.0.0');
        $clock += ContractFetch::BACKOFF - 1;
        $second = $fetcher($lib, $release, $now)->ensure('acme/j6/1.0.0');
        check('fetch: within the backoff a second attempt is not made', count($calls), 1);
        checkTrue('fetch: and the refusal still says why (' . $second . ')', is_string($second) && strpos($second, 'fetch failed: sha256 ') === 0 && $first !== null);
        $clock += 2;
        $fetcher($lib, $release, $now)->ensure('acme/j6/1.0.0');
        check('fetch: after the backoff it is tried again', count($calls), 2);
        // Fixed at the source, the next attempt after the backoff clears the marker.
        $index = json_decode(file_get_contents($lib . '/contracts.json'), true);
        $index['contracts']['acme/j6/1.0.0']['sha256'] = hash_file('sha256', $release . '/acme--j6--1.0.0.zip');
        file_put_contents($lib . '/contracts.json', json_encode($index));
        $clock += ContractFetch::BACKOFF + 1;
        check('fetch: a later success clears the marker', [$fetcher($lib, $release, $now)->ensure('acme/j6/1.0.0'), $list($lib . '/contracts')],
            [null, ['acme', 'acme/j6', 'acme/j6/1.0.0', 'acme/j6/1.0.0/manifest.json']]);
        [$lib] = $site(['acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.0.0')]]]);
        check('fetch: without an installed version nothing can be fetched, and it says why',
            (new ContractFetch($lib, null))->ensure('acme/j6/1.0.0'), 'fetch failed: the installed version is unknown');
        check('fetch: and that failure is remembered too, though no profile folder was made',
            is_file($lib . '/contracts/acme/j6/1.0.0.fetch-failed'), true);

        // A folder without a manifest (half of something) is replaced, never read.
        [$lib, $release] = $site(['acme/j6/1.0.0' => ['files' => ['manifest.json' => $manifest('acme/j6/1.0.0'), 'content-map.json' => '{}']]]);
        mkdir($lib . '/contracts/acme/j6/1.0.0', 0777, true);
        file_put_contents($lib . '/contracts/acme/j6/1.0.0/content-map.json', 'half');
        check('fetch: a half folder is replaced by the whole profile', [$fetcher($lib, $release)->ensure('acme/j6/1.0.0'), file_get_contents($lib . '/contracts/acme/j6/1.0.0/content-map.json')], [null, '{}']);

        // ── prune ────────────────────────────────────────────────────────────────────────────────
        $P = 'pkg_claudecoworkInstallerScript';
        check('prune: a derived binding holds no profile, whatever the params name',
            $P::heldProfiles(json_encode(['mode' => 'derived', 'contract' => 'derived/x']), json_encode(['contract' => 'acme/j6/1.0.0'])), []);
        check('prune: a binding that holds the mark but is no derived binding is unclear: nothing is pruned',
            [$P::heldProfiles('{"mode":"derived"', ''), $P::heldProfiles('{"mode":"derived"}', ''), $P::heldProfiles('{"mode":"derived","contract":"acme/j6/1.0.0"}', '{"contract":"acme/j6/1.0.0"}'),
             $P::heldProfiles(json_encode(['note' => '"mode":"derived"']), '{"contract":"acme/j6/1.0.0"}')],
            [null, null, null, null]);
        check('prune: the contract param, trimmed', $P::heldProfiles(null, json_encode(['contract' => " acme/j6/1.0.0\n", 'token' => 't'])), ['acme/j6/1.0.0']);
        check('prune: no contract param holds none', [$P::heldProfiles(null, '{}'), $P::heldProfiles(null, ''), $P::heldProfiles(null, '{"contract":"  "}')], [[], [], []]);
        check('prune: no component row, params that are not JSON or a contract the engine refuses: unclear',
            [$P::heldProfiles(null, null), $P::heldProfiles(null, '{"contract":'), $P::heldProfiles(null, '{"contract":"../x/1.0.0"}'), $P::heldProfiles(null, '{"contract":7}')],
            [null, null, null, null]);

        $tree = static function () use ($tmp, $manifest): string {
            static $n = 0;
            $root = $tmp . '/prune' . (++$n);
            $c = $root . '/' . pkg_claudecoworkInstallerScript::CONTRACTS;
            foreach (['acme/j6/1.1.0' => 'acme/j6/1.0.0', 'acme/j6/1.0.0' => 'acme/j6/1.1.0', 'acme/j6/0.9.0' => null, 'other/j6/1.0.0' => null, 'other/j5/2.0.0' => null] as $id => $named) {
                mkdir($c . '/' . $id, 0777, true);
                file_put_contents($c . '/' . $id . '/manifest.json', $manifest($id, $named));
            }
            file_put_contents($c . '/README.md', 'not a profile');
            mkdir($c . '/obsolete-probe', 0777, true);
            file_put_contents($c . '/obsolete-probe/manifest.json', '{}');
            file_put_contents($c . '/acme/j6/0.8.0.fetch-failed', '{}');
            return $root;
        };
        $root = $tree();
        $c = $root . '/' . $P::CONTRACTS;
        check('prune: keeps the held profile and the one it names, deletes the rest', $P::prune($c, ['acme/j6/1.1.0']), ['acme/j6/0.9.0', 'other/j5/2.0.0', 'other/j6/1.0.0']);
        check('prune: and leaves what is not a profile folder', $list($c), ['README.md', 'acme', 'acme/j6', 'acme/j6/0.8.0.fetch-failed', 'acme/j6/1.0.0', 'acme/j6/1.0.0/manifest.json',
            'acme/j6/1.1.0', 'acme/j6/1.1.0/manifest.json', 'obsolete-probe', 'obsolete-probe/manifest.json']);
        $root = $tree();
        $c = $root . '/' . $P::CONTRACTS;
        check('prune: an unbound site keeps no profile', $P::prune($c, []), ['acme/j6/0.9.0', 'acme/j6/1.0.0', 'acme/j6/1.1.0', 'other/j5/2.0.0', 'other/j6/1.0.0']);
        $root = $tree();
        $c = $root . '/' . $P::CONTRACTS;
        check('prune: a held profile not on disk keeps nothing else (it is fetched when needed)', $P::prune($c, ['acme/j6/3.0.0']), ['acme/j6/0.9.0', 'acme/j6/1.0.0', 'acme/j6/1.1.0', 'other/j5/2.0.0', 'other/j6/1.0.0']);
        $root = $tree();
        $c = $root . '/' . $P::CONTRACTS;
        file_put_contents($c . '/acme/j6/1.1.0/manifest.json', '{"id":');
        $corrupt = $list($c);
        check('prune: a held manifest that cannot be read deletes nothing', [$P::prune($c, ['acme/j6/1.1.0']), $list($c)], [[], $corrupt]);

        $whole = $list($tree() . '/' . $P::CONTRACTS);
        // Through the database, as postflight runs it.
        $db = static function (?callable $binding, ?string $params) {
            return new class($binding, $params) {
                private $binding; private ?string $params; private string $sql = '';
                public function __construct(?callable $binding, ?string $params) { $this->binding = $binding; $this->params = $params; }
                public function quote($v): string { return "'" . addslashes((string) $v) . "'"; }
                public function quoteName($v): string { return '`' . $v . '`'; }
                public function getQuery($new = false) {
                    return new class { private array $p = []; public function __call($m, $a) { $this->p[] = $m . ' ' . $a[0]; return $this; } public function __toString(): string { return implode(' ', $this->p); } };
                }
                public function setQuery($q) { $this->sql = (string) $q; return $this; }
                public function loadResult() {
                    if (strpos($this->sql, '#__claudecowork_content_contract') !== false) return $this->binding ? ($this->binding)() : null;
                    if (strpos($this->sql, 'params') !== false && strpos($this->sql, "'com_claudecowork'") !== false) return $this->params;
                    throw new RuntimeException('unexpected query ' . $this->sql);
                }
            };
        };
        $root = $tree();
        check('prune: postflight reads the param and prunes', $P::pruneContracts($db(null, '{"contract":"other/j6/1.0.0"}'), $root), ['acme/j6/0.9.0', 'acme/j6/1.0.0', 'acme/j6/1.1.0', 'other/j5/2.0.0']);
        $root = $tree();
        $threw = null;
        try { $P::pruneContracts($db(static function () { throw new RuntimeException('no table'); }, '{}'), $root); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
        check('prune: a binding that cannot be read deletes nothing', [$threw, $list($root . '/' . $P::CONTRACTS)], ['no table', $whole]);
        $root = $tree();
        check('prune: params that cannot be read delete nothing', [$P::pruneContracts($db(null, 'not json'), $root), $list($root . '/' . $P::CONTRACTS)], [null, $whole]);
        $root = $tree();
        check('prune: a derived binding that does not parse deletes nothing',
            [$P::pruneContracts($db(static function () { return '{"mode":"derived"'; }, ''), $root), $list($root . '/' . $P::CONTRACTS)], [null, $whole]);
        $root = $tree();
        check('prune: no binding row and no contract param keeps none',
            $P::pruneContracts($db(static function () { return null; }, ''), $root), ['acme/j6/0.9.0', 'acme/j6/1.0.0', 'acme/j6/1.1.0', 'other/j5/2.0.0', 'other/j6/1.0.0']);
        $root = $tree();
        check('prune: no component row deletes nothing', [$P::pruneContracts($db(null, null), $root), $list($root . '/' . $P::CONTRACTS)], [null, $whole]);
        checkTrue('prune: postflight runs it, after the receipt and on its own guard',
            strpos(file_get_contents(__DIR__ . '/../script.php'), 'self::pruneContracts(Factory::getDbo(), JPATH_ROOT);') !== false);

        // One deadline for the whole download, the open included; past it the download failed.
        stream_wrapper_register('cfslow', ContractFetchSlowStream::class);
        try {
            $timed = static function (float $open, float $read) use ($tmp): array {
                ContractFetchSlowStream::$open = $open;
                ContractFetchSlowStream::$read = $read;
                $got = PackageDownload::toFile('cfslow://asset', $tmp . '/slow.bin', 10, null, [], false, 1);
                return [$got['ok'], $got['ok'] ? null : strpos((string) $got['error'], 'timed out') !== false, is_file($tmp . '/slow.bin')];
            };
            check('download: within the budget it lands', $timed(0.0, 0.0), [true, null, true]);
            @unlink($tmp . '/slow.bin');
            check('download: a slow open counts against the budget: past it, a failure and no file', $timed(1.2, 0.0), [false, true, false]);
            check('download: so does a slow open then a slow read', $timed(0.6, 0.6), [false, true, false]);
        } finally {
            stream_wrapper_unregister('cfslow');
        }

        // Redirects, over a real http server: each hop gets only what is left of the budget.
        $router = $tmp . '/router.php';
        file_put_contents($router, '<?php
            $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
            if (preg_match("~^/slow/(\\d+)$~", $path, $m)) { usleep(700000); header("Location: /slow/" . ($m[1] + 1), true, 302); return; }
            if (preg_match("~^/fast/(\\d+)$~", $path, $m)) { header("Location: " . ($m[1] < 3 ? ($m[1] + 1) : "/asset"), true, 302); return; }
            if ($path === "/loop") { header("Location: /loop", true, 302); return; }
            if ($path === "/file") { header("Location: file:///etc/hosts", true, 302); return; }
            if ($path === "/asset") { echo "body"; return; }
            http_response_code(404);');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $server = proc_open([PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, $router], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 100 && !($up = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)); $i++) usleep(50000);
            if ($up) fclose($up);
            $base = 'http://127.0.0.1:' . $port;
            $get = static function (string $path, int $budget) use ($base, $tmp): array {
                @unlink($tmp . '/hop.bin');
                $began = microtime(true);
                $got = PackageDownload::toFile($base . $path, $tmp . '/hop.bin', 100, null, [], false, $budget);
                return [$got['ok'], $got['error'], microtime(true) - $began, is_file($tmp . '/hop.bin') ? file_get_contents($tmp . '/hop.bin') : null];
            };
            [$ok, $error, $took] = $get('/slow/1', 1);
            check('download: a chain of slow redirects fails at the budget, not a full timeout per hop',
                [$ok, strpos((string) $error, 'timed out') !== false, $took < 1.5], [false, true, true]);
            [$ok, $error, , $body] = $get('/fast/1', 5);
            check('download: a chain of redirects, relative and absolute, still lands', [$ok, $error, $body], [true, null, 'body']);
            check('download: more than five redirects are refused', strpos((string) $get('/loop', 5)[1], 'more than 5 redirects') !== false, true);
            check('download: a redirect off the web is refused', strpos((string) $get('/file', 5)[1], 'is refused') !== false, true);
            check('download: an error status is a failure', [$get('/missing', 5)[0], strpos((string) $get('/missing', 5)[1], 'HTTP 404') !== false], [false, true]);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    } finally {
        $remove($tmp);
    }
})();
