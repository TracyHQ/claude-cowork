<?php
// The self-updater never downloads inside a visitor's request (0.24.28). Before it, onAfterRespond
// of a visitor's page read the whole ~42 MB package into memory; at memory_limit 128M that was a
// fatal error, Joomla appended its "500 Whoops" page to the visitor's page (status 200), and the
// claim written before the download made the site retry forever (61 fatals in a day on 20 of 24
// fleet demos, 14/10/2026). What this holds:
//
// 1. PackageDownload writes to a file in chunks: a 40 MB download under memory_limit 24M, by curl
//    and by the stream copy, with the sha256 checked from the file.
// 2. UpdateBackoff: a failed version waits 1 h, 2 h, 4 h … at most a day; a newer one does not wait.
// 3. The plugin itself, against Joomla stand-ins, each case in its own PHP process: the visitor's
//    request only claims and knocks (no manifest read, no download, no installer), a worker needs
//    the claim's key, and an attempt is written down before the download so a fatal still counts.
//
// Run alone (`php tests/updater-out-of-request.php`) or from run.php.

namespace {
    if (isset($argv) && ($argv[1] ?? null) === '--updater-case' && isset($argv[2])
        && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        define('UP_CASE', json_decode($argv[2], true));
        define('_JEXEC', 1);
        define('JPATH_ADMINISTRATOR', UP_CASE['root'] . '/administrator');
    }
}

namespace Joomla\Event {
    if (\defined('UP_CASE')) {
        interface SubscriberInterface { public static function getSubscribedEvents(): array; }
    }
}

namespace Joomla\Database {
    if (\defined('UP_CASE')) {
        trait DatabaseAwareTrait
        {
            private $db;
            public function setDatabase($db): void { $this->db = $db; }
            public function getDatabase() { return $this->db; }
        }
    }
}

namespace Joomla\CMS\Log {
    if (\defined('UP_CASE')) {
        final class Log
        {
            public const ALL = 30719;
            public const INFO = 64;
            public const WARNING = 16;
            public static function addLogger(array $o, int $p = self::ALL, array $c = []): void {}
            public static function add($entry, int $priority = 64, string $category = ''): void { \UpRecord::$calls[] = 'log: ' . $entry; }
        }
    }
}

namespace Joomla\CMS\Plugin {
    if (\defined('UP_CASE')) {
        abstract class CMSPlugin
        {
            protected $params;
            private $app;
            public function __construct($dispatcher, array $config = [])
            {
                $this->params = new class ($config['params'] ?? []) {
                    private $v;
                    public function __construct(array $v) { $this->v = $v; }
                    public function get($k, $d = null) { return $this->v[$k] ?? $d; }
                };
            }
            public function setApplication($app): void { $this->app = $app; }
            public function getApplication() { return $this->app; }
        }
    }
}

namespace Joomla\CMS\Uri {
    if (\defined('UP_CASE')) {
        final class Uri { public static function root(): string { return 'http://fixture.invalid/'; } }
    }
}

namespace Joomla\CMS\Http {
    if (\defined('UP_CASE')) {
        final class HttpFactory
        {
            public static function getHttp(array $o = [], $adapters = null): object
            {
                return new class {
                    public function get(string $url, array $h = [], $timeout = null): object
                    {
                        \UpRecord::$calls[] = 'http GET ' . $url;
                        // The package's manifest is the case's; the template's is not there.
                        return strpos($url, '/template/') === false ? (object) ['code' => 200, 'body' => UP_CASE['manifest']] : (object) ['code' => 404, 'body' => ''];
                    }
                };
            }
        }
    }
}

namespace Joomla\CMS\Installer {
    if (\defined('UP_CASE')) {
        final class InstallerHelper
        {
            public static function downloadPackage($url, $target = false) { \UpRecord::$calls[] = 'downloadPackage'; return false; }
            public static function unpack($p, $a = false) { \UpRecord::$calls[] = 'unpack'; return false; }
            public static function cleanupInstall($p, $d) { \UpRecord::$calls[] = 'cleanupInstall'; }
        }
        final class Installer
        {
            public static function getInstance() { \UpRecord::$calls[] = 'installer'; return new self(); }
            public function install($dir) { return false; }
        }
    }
}

namespace Joomla\CMS {
    if (\defined('UP_CASE')) {
        final class Factory
        {
            public static $app;
            public static function getApplication() { return self::$app; }
        }
    }
}

namespace {
    if (\defined('UP_CASE')) {
        final class UpRecord { public static $calls = []; }

        /** One #__extensions table, enough for the plugin's reads and compare-and-swap writes. */
        final class UpDb
        {
            public $params;
            private $quoted = [];
            private $query;
            private $affected = 0;
            public function __construct(string $params) { $this->params = $params; }
            public function quoteName($n) { return '`' . $n . '`'; }
            public function quote($v) { $this->quoted[] = (string) $v; return 'Q#' . (count($this->quoted) - 1); }
            public function value(string $s): ?string { return preg_match('/Q#(\d+)/', $s, $m) ? $this->quoted[(int) $m[1]] : null; }
            public function getQuery($new = false)
            {
                return new class {
                    public $kind = 'select'; public $select = ''; public $set = ''; public $where = [];
                    public function select($c) { $this->select = $c; return $this; }
                    public function from($t) { return $this; }
                    public function update($t) { $this->kind = 'update'; return $this; }
                    public function set($s) { $this->set = $s; return $this; }
                    public function where($w) { $this->where = array_merge($this->where, (array) $w); return $this; }
                    public function bind($k, &$v) { return $this; }
                };
            }
            public function setQuery($q) { $this->query = $q; return $this; }
            public function loadResult()
            {
                if ($this->query->select === '`manifest_cache`') return json_encode(['version' => UP_CASE['installed']]);
                return $this->params;
            }
            public function execute()
            {
                $this->affected = 0;
                $expected = $this->value((string) end($this->query->where));
                if ($expected === $this->params) {
                    $this->params = $this->value($this->query->set);
                    $this->affected = 1;
                }
                return true;
            }
            public function getAffectedRows() { return $this->affected; }
        }

        $db  = new UpDb(UP_CASE['params']);
        $app = new class {
            public $closed = false;
            public function get($k, $d = null) { return UP_CASE['config'][$k] ?? $d; }
            public function getInput()
            {
                return new class {
                    public $get;
                    public function __construct()
                    {
                        $this->get = new class {
                            public function getCmd($k) { return (string) (UP_CASE['get'][$k] ?? ''); }
                            public function getAlnum($k) { return preg_replace('/[^A-Za-z0-9]/', '', (string) (UP_CASE['get'][$k] ?? '')); }
                        };
                    }
                };
            }
            public function close() { $this->closed = true; }
        };
        Joomla\CMS\Factory::$app = $app;

        $dir = __DIR__ . '/../plg_system_claudecoworkupdate/src/Extension/';
        require $dir . 'UpdateBackoff.php';
        require $dir . 'ClaudeCoworkUpdate.php';
        $plugin = new Tracy\Plugin\System\ClaudeCoworkUpdate\Extension\ClaudeCoworkUpdate(null, ['params' => UP_CASE['plugin']]);
        $plugin->setApplication($app);
        $plugin->setDatabase($db);

        ob_start();
        UP_CASE['event'] === 'respond' ? $plugin->onAfterRespond() : $plugin->onAfterInitialise();
        $printed = ob_get_clean();

        echo json_encode(['calls' => UpRecord::$calls, 'params' => json_decode($db->params, true), 'printed' => $printed, 'closed' => $app->closed]);
        exit(0);
    }
}

namespace {
    if (!\defined('UP_CASE')) {
        if (!function_exists('check')) {
            $passed = 0; $failed = 0;
            function check(string $name, $got, $want): void { global $passed, $failed; if ($got === $want) { $passed++; echo "  ok   {$name}\n"; } else { $failed++; echo "  FAIL {$name}\n       got : " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n"; } }
            function checkTrue(string $name, bool $c): void { check($name, $c, true); }
            $upStandalone = true;
        }
        if (!defined('_JEXEC')) define('_JEXEC', 1);

        echo "\nSelf-updater: never inside a visitor's request\n";
        $upDir = __DIR__ . '/../plg_system_claudecoworkupdate/src/Extension/';
        require_once $upDir . 'UpdateBackoff.php';
        $B = 'Tracy\\Plugin\\System\\ClaudeCoworkUpdate\\Extension\\UpdateBackoff';

        // ── 2. backoff ──────────────────────────────────────────────────────────────────────────
        check('backoff: nothing on record is due', $B::due(null, '0.24.28', 1000), true);
        check('backoff: 1 h after the first failure', [$B::wait(1), $B::wait(2), $B::wait(3)], [3600, 7200, 14400]);
        check('backoff: never more than a day', [$B::wait(6), $B::wait(40)], [86400, 86400]);
        $rec = $B::begin(null, '0.24.28', 1000);
        check('backoff: an attempt is recorded as the first', $rec, ['version' => '0.24.28', 'count' => 1, 'at' => 1000]);
        check('backoff: the same version is not retried a quarter hour later', $B::due($rec, '0.24.28', 1000 + 900), false);
        check('backoff: it is retried after an hour', $B::due($rec, '0.24.28', 1000 + 3600), true);
        check('backoff: a newer version does not wait for an older one', $B::due($rec, '0.24.29', 1001), true);
        check('backoff: a second attempt of the same version counts on', $B::begin($rec, '0.24.28', 5000)['count'], 2);
        check('backoff: a different version starts again at one', $B::begin($rec, '0.24.29', 5000)['count'], 1);

        // ── 1. a download goes to a file in chunks ───────────────────────────────────────────────
        $tmp = sys_get_temp_dir() . '/cowork-updater-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        $src = $tmp . '/source.zip';
        $fh = fopen($src, 'wb');
        $block = random_bytes(1024 * 1024);
        for ($i = 0; $i < 40; $i++) fwrite($fh, $block);
        fclose($fh);
        $want = hash_file('sha256', $src);
        $probe = $tmp . '/probe.php';
        file_put_contents($probe, '<?php require ' . var_export(__DIR__ . '/../lib/PackageDownload.php', true) . ';'
            . ' $P = "PackageDownload";'
            . ' $r = $P::toFile($argv[1], $argv[2], (int) $argv[3], $argv[4] === "-" ? null : $argv[4], [], $argv[5] === "curl");'
            . ' $r["exists"] = is_file($argv[2]); $r["peak"] = memory_get_peak_usage(true); echo json_encode($r);');
        $download = static function (string $url, string $sha, string $how, int $max = 104857600) use ($probe, $tmp): array {
            $target = $tmp . '/target-' . bin2hex(random_bytes(3)) . '.zip';
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=24M ' . (extension_loaded('curl') ? '' : '') . escapeshellarg($probe) . ' '
                . implode(' ', array_map('escapeshellarg', [$url, $target, (string) $max, $sha, $how])) . ' 2>&1');
            $r = json_decode((string) $out, true);
            return is_array($r) ? $r + ['target' => $target] : ['ok' => false, 'error' => (string) $out, 'target' => $target];
        };
        // `php -n` leaves out every ini file, so curl is there only when it is compiled in; the
        // stream copy has no such condition and always runs.
        $hows = ['stream'];
        if (trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg('echo function_exists("curl_init") ? 1 : 0;'))) === '1') $hows[] = 'curl';
        foreach ($hows as $how) {
            $r = $download(($how === 'curl' ? 'file://' : '') . $src, $want, $how);
            check("download ({$how}): 40 MB arrives whole under memory_limit 24M", [$r['ok'] ?? null, $r['bytes'] ?? null, $r['sha256'] ?? null], [true, 40 * 1024 * 1024, $want]);
            checkTrue("download ({$how}): memory stays under 16 MB", ($r['peak'] ?? PHP_INT_MAX) < 16 * 1024 * 1024);
            $bad = $download(($how === 'curl' ? 'file://' : '') . $src, str_repeat('0', 64), $how);
            check("download ({$how}): a sha256 that differs is refused and the file removed", [$bad['ok'] ?? null, $bad['exists'] ?? null], [false, false]);
            $big = $download(($how === 'curl' ? 'file://' : '') . $src, '-', $how, 1024 * 1024);
            check("download ({$how}): a package over the limit is refused and the file removed", [$big['ok'] ?? null, $big['exists'] ?? null], [false, false]);
        }
        echo '  (download transports tried: ' . implode(', ', $hows) . ")\n";

        // The worker requires PackageDownload from the component's lib/ (0.24.29), so the fixture's
        // JPATH_ADMINISTRATOR carries that one file; LoopbackRoute stays absent on purpose (below).
        @mkdir($tmp . '/administrator/components/com_claudecowork/lib', 0777, true);
        copy(__DIR__ . '/../lib/PackageDownload.php', $tmp . '/administrator/components/com_claudecowork/lib/PackageDownload.php');

        // ── 3. the plugin ───────────────────────────────────────────────────────────────────────
        $manifest = static function (string $version, string $extra = ''): string {
            return '<updates><update><version>' . $version . '</version><downloads><downloadurl>https://github.com/TracyHQ/claude-cowork/releases/download/joomla-v'
                . $version . '/pkg_claudecowork-' . $version . '.zip</downloadurl></downloads>' . $extra . '</update></updates>';
        };
        $run = static function (array $case) use ($tmp): array {
            $case += ['root' => $tmp, 'plugin' => [], 'get' => [], 'installed' => '0.24.25', 'manifest' => '', 'config' => ['tmp_path' => $tmp]];
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --updater-case ' . escapeshellarg(json_encode($case)) . ' 2>&1');
            $r = json_decode((string) $out, true);
            if (!is_array($r)) { echo "       case output: {$out}\n"; return ['calls' => [], 'params' => [], 'printed' => '', 'closed' => false]; }
            return $r;
        };
        $network = static function (array $calls): array {
            return array_values(array_filter($calls, static function ($c) { return strpos($c, 'log: ') !== 0; }));
        };

        $v = $run(['event' => 'respond', 'params' => '{}', 'manifest' => $manifest('0.24.28')]);
        check('visitor: no manifest read, no download, no installer in the visitor\'s request', $network($v['calls']), []);
        checkTrue('visitor: it claims the run with a key', isset($v['params']['last_check'], $v['params']['run_key']) && strlen($v['params']['run_key']) === 32);
        // No component lib under this fixture's JPATH_ADMINISTRATOR, so there is no loopback route.
        checkTrue('visitor: a site that cannot reach itself marks the update pending and says so',
            isset($v['params']['pending']) && (bool) preg_grep('/loopback/', $v['calls']));

        $again = $run(['event' => 'respond', 'params' => json_encode(['last_check' => time()])]);
        check('visitor: within the quarter hour nothing at all happens', [$again['calls'], $again['params']], [[], ['last_check' => $again['params']['last_check'] ?? null]]);

        $off = $run(['event' => 'respond', 'params' => '{}', 'plugin' => ['autoupdate' => 0]]);
        check('visitor: the autoupdate switch off claims nothing', $off['params'], []);

        $plain = $run(['event' => 'init', 'params' => json_encode(['run_key' => str_repeat('a', 32), 'run_key_at' => time()])]);
        check('worker: an ordinary request is not the worker', [$plain['printed'], $plain['closed']], ['', false]);

        $key = str_repeat('a', 32);
        $live = json_encode(['last_check' => time(), 'run_key' => $key, 'run_key_at' => time()]);
        $wrong = $run(['event' => 'init', 'params' => $live, 'get' => ['claudecoworkupdate' => 'run', 'key' => str_repeat('b', 32)], 'manifest' => $manifest('0.24.28')]);
        check('worker: a wrong key is refused before anything is read', [$wrong['printed'], $network($wrong['calls']), $wrong['closed']], ['claudecoworkupdate: refused', [], true]);

        $stale = $run(['event' => 'init', 'params' => json_encode(['run_key' => $key, 'run_key_at' => time() - 301]), 'get' => ['claudecoworkupdate' => 'run', 'key' => $key]]);
        check('worker: a key older than five minutes is refused', $stale['printed'], 'claudecoworkupdate: refused');

        $current = $run(['event' => 'init', 'params' => $live, 'get' => ['claudecoworkupdate' => 'run', 'key' => $key], 'installed' => '0.24.28', 'manifest' => $manifest('0.24.28')]);
        check('worker: with the right key it reads the manifests and finds nothing newer', $current['printed'], 'claudecoworkupdate: pkg_claudecowork=current tpl_tracy=current');
        checkTrue('worker: the key works once', !isset($current['params']['run_key']));

        $waiting = $run(['event' => 'init', 'get' => ['claudecoworkupdate' => 'run', 'key' => $key], 'manifest' => $manifest('0.24.28'),
            'params' => json_encode(['run_key' => $key, 'run_key_at' => time(), 'attempts' => ['pkg_claudecowork' => ['version' => '0.24.28', 'count' => 1, 'at' => time() - 900]]])]);
        checkTrue('worker: a version that failed a quarter hour ago waits, nothing downloaded',
            strpos($waiting['printed'], 'pkg_claudecowork=waiting') !== false && !in_array('unpack', $waiting['calls'], true));

        // The site's proxy points at a closed port: curl fails at once, without the network.
        $fails = $run(['event' => 'init', 'params' => $live, 'get' => ['claudecoworkupdate' => 'run', 'key' => $key],
            'manifest' => $manifest('0.24.28', '<sha256>' . str_repeat('c', 64) . '</sha256>'),
            'config' => ['tmp_path' => $tmp, 'proxy_enable' => 1, 'proxy_host' => '127.0.0.1', 'proxy_port' => 9]]);
        checkTrue('worker: a failed download is reported, never handed to the installer',
            strpos($fails['printed'], 'pkg_claudecowork=failed') !== false && !in_array('unpack', $fails['calls'], true) && !in_array('downloadPackage', $fails['calls'], true));
        check('worker: the attempt was written down before the download, so a fatal would count too',
            [$fails['params']['attempts']['pkg_claudecowork']['version'] ?? null, $fails['params']['attempts']['pkg_claudecowork']['count'] ?? null], ['0.24.28', 1]);

        $updaterSrc = file_get_contents($upDir . 'ClaudeCoworkUpdate.php');
        checkTrue('source: Joomla\'s whole-body download is gone from the updater', strpos($updaterSrc, 'InstallerHelper::downloadPackage') === false);

        foreach (glob($tmp . '/*') as $f) @unlink($f);
        @unlink($tmp . '/administrator/components/com_claudecowork/lib/PackageDownload.php');
        foreach (['/administrator/components/com_claudecowork/lib', '/administrator/components/com_claudecowork', '/administrator/components', '/administrator', ''] as $d) @rmdir($tmp . $d);

        if (!empty($upStandalone)) { echo "\n{$passed} passed, {$failed} failed\n"; exit($failed ? 1 : 0); }
    }
}
