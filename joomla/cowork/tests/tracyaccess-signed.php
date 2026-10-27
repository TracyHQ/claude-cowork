<?php
// plg_system_tracyaccess signs a person in to Joomla from request headers, so what this package
// ships decides who can become an administrator of every site that installs it. What this holds:
//
// 1. The shipped plugin is the signed one Tracy's fleet installs (0.2.0 or later), byte for byte:
//    tests/fixtures/tracyaccess-source.json names the source and the hash of every file. Up to
//    0.24.65 the package carried 0.1.4, which logged in whoever sent bare x-tracy-email/tier/role
//    headers, and the self-updater put it back over the fleet's 0.2.0 on every update.
// 2. The shipped plugin itself, against Joomla stand-ins, each request set in its own PHP process:
//    bare headers log nobody in; on a site with no access key (a customer's own site, not hosted by
//    Tracy) even a correctly signed assertion logs nobody in and nothing is written; a forged,
//    replayed or keyless assertion is refused; and a valid one on a keyed site does log in, so the
//    refusals above are not a plugin that does nothing. No case may throw out of the plugin.
//
// Run alone (`php tests/tracyaccess-signed.php`) or from run.php.

namespace {
    if (isset($argv) && ($argv[1] ?? null) === '--tracyaccess-case' && isset($argv[2])
        && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        define('TA_CASE', json_decode($argv[2], true));
        define('_JEXEC', 1);
    }
}

namespace Joomla\Event {
    if (\defined('TA_CASE')) {
        interface SubscriberInterface { public static function getSubscribedEvents(): array; }
    }
}

namespace Joomla\Database {
    if (\defined('TA_CASE')) {
        interface DatabaseAwareInterface {}
        trait DatabaseAwareTrait
        {
            private $db;
            public function setDatabase($db): void { $this->db = $db; }
            public function getDatabase() { return $this->db; }
        }
    }
}

namespace Joomla\CMS\Log {
    if (\defined('TA_CASE')) {
        final class Log
        {
            public const WARNING = 16;
            public static function add($entry, int $priority = 64, string $category = ''): void { \TaRecord::$calls[] = 'log: ' . $entry; }
        }
    }
}

namespace Joomla\CMS\Plugin {
    if (\defined('TA_CASE')) {
        final class TaParams
        {
            private $values;
            public function __construct(array $values) { $this->values = $values; }
            public function get(string $key, $default = null) { return $this->values[$key] ?? $default; }
        }
        abstract class CMSPlugin
        {
            protected $params;
            private $app;
            public function __construct($subject = null, array $config = []) { $this->params = new TaParams($config['params'] ?? []); }
            public function setApplication($app): void { $this->app = $app; }
            public function getApplication() { return $this->app; }
        }
        final class PluginHelper
        {
            public static function importPlugin(string $type): void {}
        }
    }
}

namespace Joomla\CMS\Authentication {
    if (\defined('TA_CASE')) {
        final class Authentication { public const STATUS_SUCCESS = 1; }
        final class AuthenticationResponse
        {
            public $status; public $type; public $username; public $email; public $fullname;
        }
    }
}

namespace Joomla\CMS\User {
    if (\defined('TA_CASE')) {
        class User
        {
            public $id = 0; public $guest = 1; public $email = ''; public $username = ''; public $name = '';
            public $groups = []; private $params = [];
            public function __construct(int $id = 0) { if ($id > 0) { $this->id = $id; $this->guest = 0; } }
            public function getParam(string $key, $default = null) { return $this->params[$key] ?? $default; }
            public function bind(array &$data): bool
            {
                foreach (['name', 'username', 'email'] as $k) $this->$k = $data[$k];
                $this->groups = $data['groups'];
                $this->params = $data['params'];
                return true;
            }
            public function save(): bool { $this->id = 42; \TaRecord::$calls[] = 'user.save: ' . $this->email; return true; }
            public function getErrors(): array { return []; }
        }
        final class UserHelper
        {
            public static function hashPassword(string $p): string { return 'hash'; }
        }
    }
}

namespace Joomla\CMS {
    if (\defined('TA_CASE')) {
        final class Factory
        {
            public static $app;
            public static function getApplication() { return self::$app; }
        }
    }
}

namespace {
    if (\defined('TA_CASE')) {
        final class TaRecord { public static $calls = []; }

        final class TaQuery
        {
            private $sql = [];
            public function __call(string $name, array $args) { $this->sql[] = $name . '(' . implode(',', array_map('strval', $args)) . ')'; return $this; }
            public function __toString(): string { return implode(' ', $this->sql); }
        }

        /** Enough of a database for the plugin: nothing is found, writes are recorded, a nonce is taken once. */
        final class TaDb
        {
            private $nonces = [];
            private $query = '';
            public function getQuery(bool $new = false) { return new TaQuery(); }
            public function quoteName($name) { return '`' . $name . '`'; }
            public function quote($v) { return "'" . $v . "'"; }
            public function setQuery($q) { $this->query = (string) $q; return $this; }
            public function loadResult() { return 0; }
            public function execute() { TaRecord::$calls[] = 'db.execute: ' . $this->query; return true; }
            public function insertObject(string $table, &$row)
            {
                if ($table === '#__tracyaccess_nonce') {
                    if (isset($this->nonces[$row->nonce])) throw new \RuntimeException('Duplicate entry');
                    $this->nonces[$row->nonce] = true;
                    return true;
                }
                TaRecord::$calls[] = 'db.insert: ' . $table;
                return true;
            }
        }

        final class TaApp
        {
            public $client;
            public function __construct(string $client) { $this->client = $client; }
            public function getIdentity() { return new \Joomla\CMS\User\User(); }
            public function isClient(string $c): bool { return $c === $this->client; }
            public function logout($id, array $o = []) { TaRecord::$calls[] = 'logout: ' . $id; return true; }
            public function triggerEvent(string $name, array $args = [])
            {
                TaRecord::$calls[] = 'event: ' . $name . ($name === 'onUserLogin' ? ' ' . $args[0]['email'] : '');
                return [true];
            }
            public function get(string $k, $d = null) { return $d; }
        }

        $plugin = TA_CASE['plugin'];
        require $plugin . '/src/Extension/TracyAccess.php';
        putenv('TRACY_ACCESS_KEY_FILE=' . TA_CASE['keyFile']);

        $db = new TaDb();
        $out = [];
        foreach (TA_CASE['requests'] as $request) {
            TaRecord::$calls = [];
            $_SERVER = $request['server'] + ['HTTP_HOST' => 'site-a.tracy.test', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/administrator/'];
            $app = new TaApp($request['client'] ?? 'administrator');
            \Joomla\CMS\Factory::$app = $app;
            $p = new \Tracy\Plugin\System\TracyAccess\Extension\TracyAccess(null, ['params' => []]);
            $p->setApplication($app);
            $p->setDatabase($db);
            $threw = null;
            try { $p->onAfterInitialise(); } catch (\Throwable $e) { $threw = get_class($e) . ': ' . $e->getMessage(); }
            $out[] = ['calls' => TaRecord::$calls, 'threw' => $threw];
        }
        echo json_encode($out);
        exit(0);
    }
}

namespace {
    if (!\defined('TA_CASE')) {
        if (!function_exists('check')) {
            $passed = 0; $failed = 0;
            function check(string $name, $got, $want): void { global $passed, $failed; if ($got === $want) { $passed++; echo "  ok   {$name}\n"; } else { $failed++; echo "  FAIL {$name}\n       got : " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n"; } }
            function checkTrue(string $name, bool $c): void { check($name, $c, true); }
            $taStandalone = true;
        }

        echo "\nTracy access plugin: the signed one, and it trusts no bare header\n";
        $taPlugin = __DIR__ . '/../plg_system_tracyaccess';

        // ── 1. the shipped bytes are the fleet's signed plugin ───────────────────────────────────
        $taSource = json_decode((string) file_get_contents(__DIR__ . '/fixtures/tracyaccess-source.json'), true);
        $taShipped = [];
        $taIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($taPlugin, FilesystemIterator::SKIP_DOTS));
        foreach ($taIt as $taFile) {
            if ($taFile->getFilename() === '.DS_Store') continue;
            $taShipped[substr($taFile->getPathname(), strlen($taPlugin) + 1)] = hash_file('sha256', $taFile->getPathname());
        }
        ksort($taShipped);
        $taWant = $taSource['files'];
        ksort($taWant);
        check('source: every shipped file is the fleet\'s own, byte for byte (fixtures/tracyaccess-source.json)', $taShipped, $taWant);
        $taXml = (string) file_get_contents($taPlugin . '/tracyaccess.xml');
        $taVersion = preg_match('#<version>([^<]+)</version>#', $taXml, $taM) ? $taM[1] : '0';
        checkTrue('source: the shipped plugin is 0.2.0 or later (0.1.4 trusted bare headers)', version_compare($taVersion, '0.2.0', '>='));
        check('source: the fixture names the version the manifest declares', $taSource['version'], $taVersion);

        // ── 2. the plugin, request by request ───────────────────────────────────────────────────
        $taTmp = sys_get_temp_dir() . '/cowork-tracyaccess-' . bin2hex(random_bytes(4));
        mkdir($taTmp);
        $taKeyBytes = random_bytes(32);
        $taB64 = static function (string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); };
        file_put_contents($taTmp . '/key.json', json_encode(['v' => 1, 'kid' => 'k1', 'site' => 'site-a', 'key' => $taB64($taKeyBytes)]));
        file_put_contents($taTmp . '/bad.json', '{"v":1,"kid":"k1","site":"site-a","key":"short"}');
        $taNoKey = $taTmp . '/absent.json';

        $taSign = static function (array $over = [], ?string $key = null) use ($taKeyBytes, $taB64): array {
            $claims = $over + ['v' => 1, 'kid' => 'k1', 'site' => 'site-a', 'email' => 'owner@example.test', 'role' => 'owner',
                'tier' => 'editor', 'host' => 'site-a.tracy.test', 'method' => 'GET', 'target' => '/administrator/',
                'nonce' => $taB64(random_bytes(16)), 'iat' => time(), 'exp' => time() + 30];
            $assert = $taB64(json_encode($claims));
            $sig = $taB64(hash_hmac('sha256', 'tracy-site-access.v1.' . $assert, $key ?? $taKeyBytes, true));
            return ['HTTP_X_TRACY_ASSERT' => $assert, 'HTTP_X_TRACY_SIG' => $sig];
        };
        $taBare = ['HTTP_X_TRACY_EMAIL' => 'attacker@example.test', 'HTTP_X_TRACY_TIER' => 'editor', 'HTTP_X_TRACY_ROLE' => 'owner'];

        $taRun = static function (string $keyFile, array $requests) use ($taPlugin): array {
            $case = ['plugin' => realpath($taPlugin), 'keyFile' => $keyFile, 'requests' => $requests];
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --tracyaccess-case ' . escapeshellarg(json_encode($case)) . ' 2>&1');
            $r = json_decode((string) $out, true);
            if (!is_array($r)) { echo "       case output: {$out}\n"; return []; }
            return $r;
        };
        // What a request did that matters: a login, a logout, or a write. Logs are left out.
        $taActs = static function (array $r): array {
            return array_values(array_filter($r['calls'] ?? ['(no result)'], static function ($c) { return strpos($c, 'log: ') !== 0; }));
        };
        $taLogs = static function (array $r): string { return implode("\n", $r['calls'] ?? []); };

        // A customer's own site: no key file.
        $taCustomer = $taRun($taNoKey, [
            ['server' => $taBare],
            ['server' => $taBare, 'client' => 'site'],
            ['server' => $taSign()],
            ['server' => $taBare + $taSign()],
        ]);
        check('no key: bare x-tracy-email/tier/role headers log nobody in and write nothing', $taActs($taCustomer[0] ?? []), []);
        check('no key: the same on the front end', $taActs($taCustomer[1] ?? []), []);
        check('no key: even a correctly signed assertion logs nobody in and writes nothing', $taActs($taCustomer[2] ?? []), []);
        checkTrue('no key: the refusal is logged as a missing key', strpos($taLogs($taCustomer[2] ?? []), 'this site has no access key') !== false);
        check('no key: signed and bare headers together log nobody in', $taActs($taCustomer[3] ?? []), []);
        check('no key: nothing throws out of the plugin', array_column($taCustomer, 'threw'), [null, null, null, null]);

        // A Tracy-hosted site: a key the host mounted.
        $taReplay = $taSign();
        $taFleet = $taRun($taTmp . '/key.json', [
            ['server' => $taBare],
            ['server' => $taSign([], random_bytes(32))],
            ['server' => $taSign(['site' => 'site-b'])],
            ['server' => $taSign(['target' => '/administrator/index.php?option=com_users'])],
            ['server' => $taReplay],
            ['server' => $taReplay],
        ]);
        check('key: bare headers still log nobody in', $taActs($taFleet[0] ?? []), []);
        check('key: an assertion signed with another key is refused', $taActs($taFleet[1] ?? []), []);
        check('key: an assertion for another site is refused', $taActs($taFleet[2] ?? []), []);
        check('key: an assertion for another target is refused', $taActs($taFleet[3] ?? []), []);
        check('key: a valid assertion logs its holder in (the refusals above are not a dead plugin)',
            array_values(array_filter($taActs($taFleet[4] ?? []), static function ($c) { return strpos($c, 'event: ') === 0; })),
            ['event: onUserLogin owner@example.test', 'event: onUserAfterLogin']);
        check('key: the same assertion a second time is refused (single-use nonce)',
            array_values(array_filter($taActs($taFleet[5] ?? []), static function ($c) { return strpos($c, 'event: ') === 0; })), []);
        check('key: nothing throws out of the plugin', array_column($taFleet, 'threw'), [null, null, null, null, null, null]);

        $taBadKey = $taRun($taTmp . '/bad.json', [['server' => $taSign()]]);
        check('a key file that is not a 32-byte key counts as no key', $taActs($taBadKey[0] ?? []), []);

        foreach (glob($taTmp . '/*') as $f) @unlink($f);
        @rmdir($taTmp);

        if (!empty($taStandalone)) { echo "\n{$passed} passed, {$failed} failed\n"; exit($failed ? 1 : 0); }
    }
}
