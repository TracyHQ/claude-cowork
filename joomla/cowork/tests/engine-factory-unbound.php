<?php
// EngineFactory's contract selection, and what content.read answers on the result (TCH #722).
//
// A site with no `contract` param is UNBOUND: it is never held to a profile merely because that
// profile ships in lib/contracts/ (Apple's, once the default). Run by run.php, or alone:
// php tests/engine-factory-unbound.php
//
// Every case runs the real EngineFactory and JoomlaContentReader in a subprocess (`--efu-child`):
// they need Joomla's ComponentHelper/Factory and fixed JPATH_* constants, which the other tests in
// run.php stub incompatibly. The subprocess sees an installed layout built under sys_temp_dir:
// the component's src/Service files copied in (JoomlaContentReader requires its lib/ relative to its
// own path, and PHP resolves symlinks in __DIR__), and lib/ as links to the real engine and profiles.

namespace {
    if (!defined('EFU_CHILD')) define('EFU_CHILD', PHP_SAPI === 'cli' && ($_SERVER['argv'][1] ?? null) === '--efu-child'
        && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__);
}

namespace Joomla\Database {
    if (EFU_CHILD) {
        interface DatabaseInterface {}
    }
}

namespace Joomla\CMS\Component {
    if (EFU_CHILD) {
        final class ComponentHelper
        {
            public static function getParams(string $name): object
            {
                $values = $GLOBALS['efuParams'][$name] ?? [];
                return new class($values) {
                    private array $values;
                    public function __construct(array $values) { $this->values = $values; }
                    public function get(string $key, $default = null) { return array_key_exists($key, $this->values) ? $this->values[$key] : $default; }
                };
            }
        }
    }
}

namespace Joomla\CMS {
    if (EFU_CHILD) {
        final class Factory
        {
            public static function getContainer(): object
            {
                return new class { public function get(string $id) { return $GLOBALS['efuDb']; } };
            }
        }
    }
}

namespace Joomla\CMS\Uri {
    if (EFU_CHILD) {
        final class Uri { public static function root(): string { return 'https://site.example/'; } }
    }
}

namespace {
    if (EFU_CHILD) {
        /**
         * The driver calls the factory and the reader make, answered from fixtures that satisfy every
         * prerequisite of content.read before its contract (opt-in row, identity triggers, InnoDB
         * tables, an empty snapshot). Any other statement is logged and refused, so a case cannot pass
         * by failing somewhere unrelated.
         */
        final class EfuDb implements \Joomla\Database\DatabaseInterface
        {
            public array $log = [];
            private string $sql = '';
            private ?string $derived;
            public function __construct(?string $derived) { $this->derived = $derived; }
            public function getPrefix(): string { return 'jos_'; }
            public function quote($value): string { return "'" . addslashes((string) $value) . "'"; }
            public function quoteName($name): string { return '`' . $name . '`'; }
            public function setQuery($sql): self { $this->sql = (string) $sql; $this->log[] = $this->sql; return $this; }
            public function execute(): bool
            {
                if (strpos($this->sql, 'SET TRANSACTION ISOLATION LEVEL') === 0) return true;
                throw new \RuntimeException('unexpected write');
            }
            public function transactionStart(): void { $this->log[] = 'BEGIN'; }
            public function transactionCommit(): void { $this->log[] = 'COMMIT'; }
            public function transactionRollback(): void { $this->log[] = 'ROLLBACK'; }
            public function loadResult()
            {
                if (strpos($this->sql, 'FROM #__claudecowork_content_contract WHERE id=1 AND LOCATE(') !== false) return $this->derived;
                if (strpos($this->sql, 'FROM #__claudecowork_content_contract WHERE id=') !== false) return null;
                if (strpos($this->sql, 'SELECT (SELECT COUNT(*)') === 0) return 0;
                throw new \RuntimeException('unexpected query');
            }
            public function loadAssoc(): ?array
            {
                if (strpos($this->sql, 'FROM #__claudecowork_content_reader WHERE id=1') !== false)
                    return ['id' => 1, 'site_id' => str_repeat('a', 32), 'secret' => str_repeat('b', 64), 'enabled' => 1];
                throw new \RuntimeException('unexpected query');
            }
            public function loadColumn(): array
            {
                if (strpos($this->sql, 'information_schema.TRIGGERS') !== false) return \ContentIdentity::requiredTriggerNames('jos_');
                throw new \RuntimeException('unexpected query');
            }
            public function loadAssocList($key = null, $column = null): array
            {
                if (strpos($this->sql, 'information_schema.TABLES') !== false) {
                    $engines = [];
                    foreach (['content', 'menu', 'modules', 'modules_menu', 'categories', 'viewlevels', 'usergroups', 'assets', 'associations', 'languages',
                        'claudecowork_content_contract', 'claudecowork_content_identity', 'fields', 'fields_values', 'tags', 'contentitem_tag_map'] as $table)
                        $engines['jos_' . $table] = 'InnoDB';
                    return $engines;
                }
                if (preg_match('/^SELECT \* FROM #__[a-z_]+ ORDER BY /', $this->sql)) return [];
                throw new \RuntimeException('unexpected query');
            }
        }

        final class EfuApp
        {
            public array $headers = [];
            public bool $closed = false;
            public function setHeader(string $name, string $value, bool $replace = false): void { $this->headers[$name] = $value; }
            public function sendHeaders(): void {}
            public function close(): void { $this->closed = true; }
        }

        $efuCase = json_decode(base64_decode($_SERVER['argv'][3]), true);
        define('_JEXEC', 1);
        define('JPATH_ROOT', $_SERVER['argv'][2] . '/site');
        define('JPATH_ADMINISTRATOR', $_SERVER['argv'][2] . '/administrator');
        spl_autoload_register(static function (string $class): void {
            foreach (['Tracy\\Component\\ClaudeCowork\\Administrator\\' => JPATH_ADMINISTRATOR . '/components/com_claudecowork/src/',
                'Tracy\\Component\\ClaudeCowork\\Site\\' => __DIR__ . '/../com_claudecowork/site/src/'] as $prefix => $dir)
                if (strpos($class, $prefix) === 0 && is_file($file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) require $file;
        });
        $GLOBALS['efuParams'] = ['com_claudecowork' => $efuCase['params']];
        $GLOBALS['efuDb'] = $efuDb = new EfuDb($efuCase['derived'] ?? null);
        $efuFactory = \Tracy\Component\ClaudeCowork\Administrator\Service\EngineFactory::class;
        $efuFactory::loadEngine();
        $efuOut = ['appleOnDisk' => is_file(JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib/contracts/tracy-apple/j6/1.1.0/manifest.json')];
        if ($efuCase['mode'] === 'build') {
            try {
                $efuContract = (new ReflectionMethod($efuFactory, 'buildContract'))->invoke(null);
                $efuProperty = static function (object $object, string $name) {
                    $property = new ReflectionProperty($object, $name);
                    return $property->isInitialized($object) ? $property->getValue($object) : null;
                };
                $efuOut['contract'] = $efuContract === null ? null : [
                    'class' => get_class($efuContract),
                    'id' => $efuProperty($efuContract, 'manifest')['id'] ?? null,
                    'refuses' => $efuProperty($efuContract, 'unavailable') !== null,
                    'derived' => $efuProperty($efuContract, 'derivedMode'),
                    'fileProofs' => $efuProperty($efuContract, 'proofs') !== null,
                ];
            } catch (Throwable $e) {
                $efuOut['threw'] = get_class($e);
            }
        } else {
            $efuApp = new EfuApp();
            ob_start();
            $efuFactory::answerContent($efuApp, $efuCase['request']);
            $efuOut['raw'] = ob_get_clean();
            $efuOut['status'] = http_response_code();
            $efuOut['body'] = json_decode($efuOut['raw'], true);
            $efuOut['closed'] = $efuApp->closed;
        }
        $efuOut['queries'] = $efuDb->log;
        echo "\n@@EFU@@" . json_encode($efuOut);
        exit(0);
    }
}

namespace {
    if (!EFU_CHILD) {
        $efuStandalone = !function_exists('check');
        if ($efuStandalone) {
            $passed = 0;
            $failed = 0;
            function check(string $name, $got, $want): void
            {
                global $passed, $failed;
                if ($got === $want) { $passed++; echo "  ok   {$name}\n"; return; }
                $failed++;
                echo "  FAIL {$name}\n       got : " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n";
            }
        }

        echo "\nEngineFactory: unbound sites are not held to a bundled profile\n";

        (static function (): void {
            $source = realpath(__DIR__ . '/..');
            $root = sys_get_temp_dir() . '/efu-' . bin2hex(random_bytes(6));
            $component = $root . '/administrator/components/com_claudecowork';
            $remove = static function (string $path) use (&$remove): void {
                if (is_link($path) || is_file($path)) { unlink($path); return; }
                if (!is_dir($path)) return;
                foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
                rmdir($path);
            };
            try {
                // The installed layout: lib/ links to every engine file and published profile, plus one
                // profile whose manifest is not JSON; src/Service the component's own files, copied.
                mkdir($root . '/site', 0700, true);
                mkdir($component . '/lib/contracts/efu-corrupt/j6/1.0.0', 0700, true);
                mkdir($component . '/src/Service', 0700, true);
                foreach (scandir($source . '/lib') as $entry)
                    if ($entry !== '.' && $entry !== '..' && $entry !== 'contracts') symlink($source . '/lib/' . $entry, $component . '/lib/' . $entry);
                foreach (scandir($source . '/lib/contracts') as $entry)
                    if ($entry !== '.' && $entry !== '..') symlink($source . '/lib/contracts/' . $entry, $component . '/lib/contracts/' . $entry);
                foreach (['manifest' => '{"id":', 'content-map' => '{}', 'presentation-lock' => '{}'] as $name => $json)
                    file_put_contents($component . '/lib/contracts/efu-corrupt/j6/1.0.0/' . $name . '.json', $json);
                foreach (glob($source . '/com_claudecowork/administrator/src/Service/*.php') as $file)
                    copy($file, $component . '/src/Service/' . basename($file));

                // One subprocess per case: bounded in time, and its answer must be there to count.
                $run = static function (array $case) use ($root): array {
                    $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', 'sys_temp_dir=' . sys_get_temp_dir(),
                        __FILE__, '--efu-child', $root, base64_encode(json_encode($case))];
                    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    if (!is_resource($process)) return ['failed' => 'could not start'];
                    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
                    $out = ''; $err = ''; $deadline = microtime(true) + 60;
                    while (true) {
                        $out .= (string) stream_get_contents($pipes[1]); $err .= (string) stream_get_contents($pipes[2]);
                        $status = proc_get_status($process);
                        if (!$status['running']) break;
                        if (microtime(true) > $deadline) { proc_terminate($process, 9); $err .= ' [timed out]'; break; }
                        usleep(10000);
                    }
                    $out .= (string) stream_get_contents($pipes[1]); $err .= (string) stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
                    $at = strrpos($out, "\n@@EFU@@");
                    $answer = $at === false ? null : json_decode(substr($out, $at + 8), true);
                    return is_array($answer) ? $answer : ['failed' => substr(trim($out . "\n" . $err), 0, 2000)];
                };
                $contract = static fn(array $params, ?string $derived = null): array => $run(['mode' => 'build', 'params' => $params, 'derived' => $derived]);
                $token = 'efu-service-token-' . str_repeat('7', 32);
                $read = static fn(array $params, array $request = []): array => $run(['mode' => 'answer', 'params' => $params + ['token' => $token],
                    'request' => $request + ['action' => 'content.read', 'token' => $token, 'params' => []]]);
                $store = static fn(array $answer): bool => in_array('SELECT binding FROM #__claudecowork_content_contract WHERE id=1', $answer['queries'] ?? [], true);
                $unbound = ['status' => 501, 'body' => ['error' => ['code' => 'CONTENT_ADAPTER_UNSUPPORTED', 'message' => 'Content mapping is unavailable']]];
                $masked = ['status' => 503, 'body' => ['error' => ['code' => 'CONTENT_SOURCE_UNAVAILABLE', 'message' => 'Content source or binding could not be read']]];
                $shape = static fn(array $answer): array => ['status' => $answer['status'] ?? null, 'body' => $answer['body'] ?? $answer];
                // What the factory built: null is an answer here, so a missing key (a failed case) is shown whole.
                $built = static fn(array $answer) => array_key_exists('contract', $answer) ? $answer['contract'] : $answer;

                // Selection: no param, an empty one or only whitespace is unbound, with Apple's profile on disk.
                foreach (['no' => [], 'an empty' => ['contract' => ''], 'a whitespace-only' => ['contract' => " \t "]] as $what => $params) {
                    $answer = $contract($params);
                    check("unbound: {$what} contract param builds no contract, though Apple's profile is on disk", [$answer['appleOnDisk'] ?? null, $built($answer)], [true, null]);
                }
                check('unbound: a construction baseline still builds no contract', $built($contract(['tracy_build_baseline' => 'tracy-base/j6/1.0.1'])), null);

                // An explicit profile keeps its own contract, with the file-proof lock wired.
                foreach (['tracy-apple/j6/1.1.0', 'tracy-business/j6/1.2.0', 'tracy-base/j6/1.0.1'] as $id) {
                    $answer = $contract(['contract' => $id]);
                    check("bound: {$id} is held to its own profile, file proofs on", $built($answer),
                        ['class' => 'QuickstartContract', 'id' => $id, 'refuses' => false, 'derived' => false, 'fileProofs' => true]);
                }
                check('bound: surrounding whitespace is trimmed from an explicit profile', $contract(['contract' => " tracy-business/j6/1.2.0\n"])['contract']['id'] ?? null, 'tracy-business/j6/1.2.0');

                // Set but not usable: a contract that refuses, never null and never Apple.
                foreach (['that is malformed' => 'Tracy-Apple', 'shaped like a traversal' => '../../../etc/j6/1.0.0', 'of two segments' => 'tracy-apple/1.1.0',
                    'this receiver does not carry' => 'tracy-apple/j6/9.9.9'] as $what => $id) {
                    $answer = $contract(['contract' => $id]);
                    check("refusing: a profile {$what} builds a contract that refuses", $built($answer),
                        ['class' => 'QuickstartContract', 'id' => null, 'refuses' => true, 'derived' => false, 'fileProofs' => true]);
                }
                check('refusing: a profile that is not JSON still throws, as before', $contract(['contract' => 'efu-corrupt/j6/1.0.0'])['threw'] ?? null, 'JsonException');

                // A derived binding wins over any param, the empty one included.
                $derived = json_encode(['mode' => 'derived', 'contract' => 'derived/northwind-import']);
                foreach (['empty' => [], 'Apple' => ['contract' => 'tracy-apple/j6/1.1.0']] as $what => $params) {
                    $answer = $contract($params, $derived);
                    check("derived: a derived binding is the contract whatever the params name ({$what})", $built($answer),
                        ['class' => 'QuickstartContract', 'id' => null, 'refuses' => false, 'derived' => true, 'fileProofs' => false]);
                }
                check('derived: a binding that only mentions the mark is not derived', $built($contract([], json_encode(['mode' => 'quickstart', 'note' => '"mode":"derived"']))), null);

                // content.read, authenticated, through the real reader: unbound answers the honest 501.
                foreach (['empty' => [], 'whitespace-only' => ['contract' => '  '], 'under construction' => ['tracy_build_baseline' => 'tracy-base/j6/1.0.1']] as $what => $params) {
                    $answer = $read($params);
                    check("content.read: an unbound site ({$what}) answers 501 mapping unavailable, not a masked 503", $shape($answer), $unbound);
                    check("content.read: ({$what}) no binding is read and the snapshot is rolled back",
                        [$store($answer), array_slice($answer['queries'] ?? [], -1), $answer['closed'] ?? null], [false, ['ROLLBACK'], true]);
                }
                // The same fixture reaches the contract on a bound site: the 501 above is the missing contract, nothing else.
                $answer = $read(['contract' => 'tracy-apple/j6/1.1.0']);
                check('content.read: an explicit profile reaches its own contract (the binding is read)', [$store($answer), $shape($answer)], [true, $masked]);
                foreach (['this receiver does not carry' => 'tracy-apple/j6/9.9.9', 'that is malformed' => '../x/1.0.0', 'that is not JSON' => 'efu-corrupt/j6/1.0.0'] as $what => $id) {
                    $answer = $read(['contract' => $id]);
                    check("content.read: a profile {$what} refuses, sanitized, and is not read as unbound", $shape($answer), $masked);
                }

                // Authentication and arguments are checked first, and nothing secret is echoed.
                $answer = $read([], ['token' => 'not-the-token']);
                check('content.read: a wrong token answers 401', $shape($answer), ['status' => 401, 'body' => ['error' => ['code' => 'CONTENT_UNAUTHENTICATED', 'message' => 'Authentication required']]]);
                check('content.read: the 401 neither echoes a token nor touches the database', [strpos($answer['raw'] ?? '', 'token') === false, $answer['queries'] ?? null], [true, []]);
                $answer = $read([], ['params' => ['a', 'b']]);
                check('content.read: a list for params answers 400', [$answer['status'] ?? null, $answer['body']['error']['code'] ?? $answer], [400, 'CONTENT_BAD_QUERY']);
            } finally {
                $remove($root);
            }
        })();

        if ($efuStandalone) {
            echo "\n{$passed} passed, {$failed} failed\n";
            exit($failed ? 1 : 0);
        }
    }
}
