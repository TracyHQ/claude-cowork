<?php
// EngineFactory's catches answer with an honest fallback (content.read's 503, a builder's null), and
// before it they write down the Throwable they caught — TCH #722 was a content.read 503 that nothing
// anywhere could explain. Each case runs EngineFactory in its own PHP process: the factory needs
// Joomla's Factory, ComponentHelper, Uri and Log, and other files of this suite define stand-ins for
// some of those that would collide. Scratch files live under sys_get_temp_dir() and are removed.
//
// Run alone (`php tests/engine-factory-logging.php`) or from run.php.

namespace {
    if (isset($argv) && ($argv[1] ?? null) === '--engine-factory-case' && isset($argv[2])
        && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        define('EF_CASE', json_decode($argv[2], true));
        define('_JEXEC', 1);
        define('JPATH_ROOT', EF_CASE['root'] . '/site');
        define('JPATH_ADMINISTRATOR', EF_CASE['root'] . '/administrator');
    }
}

namespace Joomla\CMS\Log {
    if (\defined('EF_CASE') && EF_CASE['log'] !== 'missing') {
        final class Log
        {
            public const ALL = 30719;
            public const ERROR = 8;
            public static array $entries = [];
            public static array $loggers = [];

            public static function addLogger(array $options, int $priorities = self::ALL, array $categories = []): void
            {
                self::$loggers[] = [$options, $priorities, $categories];
            }

            public static function add($entry, int $priority = 64, string $category = ''): void
            {
                if (EF_CASE['log'] === 'throwing') throw new \RuntimeException('log file is not writable');
                self::$entries[] = ['message' => $entry, 'priority' => $priority, 'category' => $category];
            }
        }
    }
}

namespace Joomla\CMS {
    if (\defined('EF_CASE')) {
        final class Factory
        {
            public static function getContainer(): object
            {
                return new class {
                    public function get(string $id): object
                    {
                        if (EF_CASE['db'] === 'container') throw new \RuntimeException('database driver could not connect');
                        return new \EfDb(EF_CASE['db']);
                    }
                };
            }
        }
    }
}

namespace Joomla\CMS\Component {
    if (\defined('EF_CASE')) {
        final class ComponentHelper
        {
            public static function getParams(string $option): object
            {
                return new class {
                    public function get(string $key, $default = null) { return EF_CASE['params'][$key] ?? $default; }
                };
            }
        }
    }
}

namespace Joomla\CMS\Uri {
    if (\defined('EF_CASE')) {
        final class Uri { public static function root(): string { return 'https://fixture.invalid/'; } }
    }
}

namespace Joomla\Database {
    if (\defined('EF_CASE') && !interface_exists(DatabaseInterface::class)) {
        interface DatabaseInterface {}
    }
}

namespace {
    use Tracy\Component\ClaudeCowork\Administrator\Service\EngineFactory;

    if (\defined('EF_CASE')) {
        /** The database handed to the reader, failing the way the case names. */
        final class EfDb
        {
            private string $mode;
            public function __construct(string $mode) { $this->mode = $mode; }
            public function setQuery(string $sql): object
            {
                if ($this->mode === 'runtime') throw new RuntimeException('reader exploded on purpose');
                if ($this->mode === 'empty-message') throw new RuntimeException('');
                if ($this->mode === 'missing-table') throw new RuntimeException("Table 'j_claudecowork_content_reader' doesn't exist", 1146);
                if ($this->mode === 'error') return new stdClass(); // ->loadAssoc() is a PHP Error
                return $this;
            }
            public function loadAssoc(): ?array { return null; } // 'disabled': no opt-in row
        }

        /** The application: records headers and close() instead of ending the process. */
        final class EfApp
        {
            public array $headers = [];
            public int $sent = 0;
            public bool $closed = false;
            public function setHeader(string $name, string $value, bool $replace = false): void { $this->headers[$name] = $value; }
            public function sendHeaders(): void { $this->sent++; }
            public function close(): void { $this->closed = true; }
        }

        // Sends PHP's own warnings to stderr so stdout carries only the report.
        ini_set('display_errors', 'stderr');
        ini_set('log_errors', '0');
        ini_set('error_log', EF_CASE['errorLog']);
        $efReport = [];
        $efRoot = EF_CASE['root'] . '/administrator/components/com_claudecowork';
        require_once $efRoot . '/src/Service/EngineFactory.php';
        require_once $efRoot . '/src/Service/JoomlaContentReader.php';
        if (EF_CASE['action'] === 'build') {
            // The site controllers resolve the way Joomla's PSR-4 map resolves them in production.
            spl_autoload_register(static function (string $class): void {
                $prefix = 'Tracy\\Component\\ClaudeCowork\\Site\\Controller\\';
                if (strpos($class, $prefix) === 0) require EF_CASE['repo'] . '/com_claudecowork/site/src/Controller/' . substr($class, strlen($prefix)) . '.php';
            });
        }

        if (EF_CASE['action'] === 'content') {
            $efApp = new EfApp();
            ob_start();
            EngineFactory::answerContent($efApp, EF_CASE['request']);
            $efReport['body'] = ob_get_clean();
            $efReport['status'] = http_response_code();
            $efReport['headers'] = $efApp->headers;
            $efReport['sent'] = $efApp->sent;
            $efReport['closed'] = $efApp->closed;
        } elseif (EF_CASE['action'] === 'siblings') {
            EngineFactory::loadEngine();
            $efReport['results'] = [];
            foreach (['derivedBinding' => [], 'buildDumper' => [], 'buildWalker' => [\Joomla\CMS\Component\ComponentHelper::getParams('com_claudecowork')], 'buildWriter' => [], 'buildMedia' => [], 'buildLog' => []] as $method => $args) {
                $efMethod = new ReflectionMethod(EngineFactory::class, $method);
                if (PHP_VERSION_ID < 80100) $efMethod->setAccessible(true);
                $efReport['results'][$method] = $efMethod->invokeArgs(null, $args);
            }
        } elseif (EF_CASE['action'] === 'build') {
            EngineFactory::loadEngine();
            $efReport['engine'] = get_class(EngineFactory::build());
        } elseif (EF_CASE['action'] === 'secret-argument') {
            // A frame whose argument is a credential: the diagnostic names the frame, never the value.
            $efLog = new ReflectionMethod(EngineFactory::class, 'logFailure');
            if (PHP_VERSION_ID < 80100) $efLog->setAccessible(true);
            $efThrow = static function (string $token): void { throw new RuntimeException('authentication backend failed'); };
            try { $efThrow(EF_CASE['secret']); } catch (Throwable $e) { $efLog->invoke(null, 'test', $e); }
        }
        $efReport['logs'] = class_exists(\Joomla\CMS\Log\Log::class, false) ? \Joomla\CMS\Log\Log::$entries : null;
        $efReport['loggers'] = class_exists(\Joomla\CMS\Log\Log::class, false) ? \Joomla\CMS\Log\Log::$loggers : null;
        echo json_encode($efReport);
        exit(0);
    }

    $efStandalone = !function_exists('check');
    if ($efStandalone) {
        $passed = 0;
        $failed = 0;
        function check(string $name, $got, $want): void
        {
            global $passed, $failed;
            if ($got === $want) { $passed++; echo "  ok   {$name}\n"; return; }
            $failed++;
            echo "  FAIL {$name}\n       got : " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n";
        }
        function checkTrue(string $name, bool $cond): void { check($name, $cond, true); }
    }

    echo "\nEngineFactory logs what its catches answer for (Joomla)\n";

    // The layout production has: lib/ beside src/ under administrator/components/com_claudecowork.
    $efRepo = dirname(__DIR__);
    $efScratch = sys_get_temp_dir() . '/engine-factory-logging-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $efComponent = $efScratch . '/administrator/components/com_claudecowork';
    mkdir($efComponent . '/src/Service', 0700, true);
    mkdir($efScratch . '/site', 0700, true);
    symlink($efRepo . '/lib', $efComponent . '/lib');
    foreach (['EngineFactory.php', 'JoomlaContentReader.php'] as $efFile) {
        copy($efRepo . '/com_claudecowork/administrator/src/Service/' . $efFile, $efComponent . '/src/Service/' . $efFile);
    }

    $efSecret = 'SENTINEL-TOKEN-7f3a9c2e5b81d4a6';
    $efRequestSecret = 'SENTINEL-BODY-c0ffee11d00d';
    $efCase = static function (array $case) use ($efRepo, $efScratch, $efSecret, $efRequestSecret): array {
        static $n = 0;
        $errorLog = $efScratch . '/php-error-' . (++$n) . '.log';
        $case += [
            'root' => $efScratch, 'repo' => $efRepo, 'action' => 'content', 'log' => 'joomla', 'db' => 'runtime',
            'errorLog' => $errorLog, 'secret' => $efSecret,
            'params' => ['token' => $efSecret],
            'request' => ['action' => 'content.read', 'token' => $efSecret, 'params' => [], 'note' => $efRequestSecret],
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'sys_temp_dir=' . sys_get_temp_dir(), '-d', 'zend.exception_ignore_args=0', __FILE__, '--engine-factory-case', json_encode($case)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $report = json_decode((string) $out, true);
        if (!is_array($report)) $report = ['broken' => "exit {$exit}: {$out}{$err}"];
        $report['exit'] = $exit;
        $report['stderr'] = $err;
        $report['errorLogText'] = is_file($case['errorLog']) ? (string) file_get_contents($case['errorLog']) : null;
        return $report;
    };
    // Everything a case wrote down, wherever it went.
    $efWritten = static fn(array $r): string => implode("\n", array_column($r['logs'] ?? [], 'message')) . "\n" . ($r['errorLogText'] ?? '');
    $efMasked = '{"error":{"code":"CONTENT_SOURCE_UNAVAILABLE","message":"Content source or binding could not be read"}}';
    $efHeaders = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store'];
    $efLeaks = static fn(string $text): bool => strpos($text, $efSecret) !== false || strpos($text, substr($efSecret, 0, 15)) !== false
        || strpos($text, $efRequestSecret) !== false || strpos($text, substr($efRequestSecret, 0, 15)) !== false;

    // 1. The reader throws a RuntimeException: the client answer is byte for byte the old one, and Joomla's log has the cause.
    $r = $efCase(['db' => 'runtime']);
    check('content.read runtime failure: child ran', $r['broken'] ?? null, null);
    check('content.read runtime failure: status stays 503', $r['status'] ?? null, 503);
    check('content.read runtime failure: body is byte-identical', $r['body'] ?? null, $efMasked);
    check('content.read runtime failure: headers unchanged', $r['headers'] ?? null, $efHeaders);
    check('content.read runtime failure: headers sent once, application closed', [$r['sent'] ?? null, $r['closed'] ?? null], [1, true]);
    check('content.read runtime failure: one Joomla log entry', count($r['logs'] ?? []), 1);
    $entry = $r['logs'][0] ?? ['message' => '', 'priority' => null, 'category' => null];
    check('content.read runtime failure: logged at ERROR in com_claudecowork', [$entry['priority'], $entry['category']], [8, 'com_claudecowork']);
    checkTrue('content.read runtime failure: log names the message', strpos($entry['message'], 'reader exploded on purpose') !== false);
    checkTrue('content.read runtime failure: log names the class', strpos($entry['message'], 'RuntimeException') !== false);
    checkTrue('content.read runtime failure: log names file:line of the throw', (bool) preg_match('~engine-factory-logging\.php:\d+~', $entry['message']));
    checkTrue('content.read runtime failure: log carries the stack', strpos($entry['message'], 'JoomlaContentReader->read()') !== false && strpos($entry['message'], 'EngineFactory::answerContent()') !== false);
    checkTrue('content.read runtime failure: log names the catch', strpos($entry['message'], 'content.read') !== false);
    check('content.read runtime failure: a dedicated logger is registered for the category', $r['loggers'] ?? null, [[['text_file' => 'com_claudecowork.php'], 30719, ['com_claudecowork']]]);
    check('content.read runtime failure: logged through Joomla, not error_log', $r['errorLogText'], null);
    check('content.read runtime failure: no secret written', $efLeaks($efWritten($r)), false);
    check('content.read runtime failure: nothing on stderr', $r['stderr'], '');

    // 2. A PHP Error (not an Exception) in the reader is caught and logged the same way.
    $r = $efCase(['db' => 'error']);
    check('content.read PHP Error: body is byte-identical', [$r['status'] ?? null, $r['body'] ?? null], [503, $efMasked]);
    checkTrue('content.read PHP Error: logged with its class and message',
        count($r['logs'] ?? []) === 1 && strpos($r['logs'][0]['message'], 'Error: Call to undefined method stdClass::loadAssoc()') !== false);
    check('content.read PHP Error: no secret written', $efLeaks($efWritten($r)), false);

    // 3. An exception with no message still has a location and a stack, and no invented cause.
    $r = $efCase(['db' => 'empty-message']);
    check('content.read empty message: body is byte-identical', [$r['status'] ?? null, $r['body'] ?? null], [503, $efMasked]);
    $entry = (string) ($r['logs'][0]['message'] ?? '');
    checkTrue('content.read empty message: location and stack kept', (bool) preg_match('~RuntimeException: \(no message\) at .+engine-factory-logging\.php:\d+~', $entry) && strpos($entry, '#0 ') !== false);

    // 4. No Joomla Log class: the same diagnostic goes to PHP's error_log, the answer unchanged.
    $r = $efCase(['db' => 'runtime', 'log' => 'missing']);
    check('content.read without Joomla Log: body is byte-identical', [$r['status'] ?? null, $r['body'] ?? null, $r['closed'] ?? null], [503, $efMasked, true]);
    checkTrue('content.read without Joomla Log: error_log has message and stack', strpos((string) $r['errorLogText'], 'reader exploded on purpose') !== false && strpos((string) $r['errorLogText'], 'JoomlaContentReader->read()') !== false);
    check('content.read without Joomla Log: no secret written', $efLeaks($efWritten($r)), false);

    // 5. Joomla Log throws (an unwritable log file): error_log instead, and the logger's failure never reaches the answer.
    $r = $efCase(['db' => 'runtime', 'log' => 'throwing']);
    check('content.read with a failing Joomla Log: body is byte-identical', [$r['status'] ?? null, $r['body'] ?? null, $r['headers'] ?? null], [503, $efMasked, $efHeaders]);
    checkTrue('content.read with a failing Joomla Log: error_log has the diagnostic', strpos((string) $r['errorLogText'], 'reader exploded on purpose') !== false);
    checkTrue('content.read with a failing Joomla Log: the logger failure stays out of the body', strpos((string) ($r['body'] ?? ''), 'writable') === false);

    // 6. Both destinations fail: nothing is written, and the answer is still exactly the old one.
    $r = $efCase(['db' => 'runtime', 'log' => 'throwing', 'errorLog' => $efScratch . '/no-such-dir/php-error.log']);
    check('content.read with no writable log at all: still answers', [$r['exit'] ?? null, $r['status'] ?? null, $r['body'] ?? null, $r['closed'] ?? null], [0, 503, $efMasked, true]);

    // 7. Coded errors are already honest: their answers are unchanged and nothing new is logged.
    $r = $efCase(['db' => 'disabled']);
    check('content.read not enabled: 501 body unchanged, nothing logged', [$r['status'] ?? null, $r['body'] ?? null, $r['logs'] ?? null, $r['errorLogText']],
        [501, '{"error":{"code":"CONTENT_ADAPTER_UNSUPPORTED","message":"Content reader is not enabled"}}', [], null]);
    $r = $efCase(['db' => 'missing-table']);
    check('content.read missing reader table: 501 body unchanged, nothing logged', [$r['status'] ?? null, $r['body'] ?? null, $r['logs'] ?? null],
        [501, '{"error":{"code":"CONTENT_ADAPTER_UNSUPPORTED","message":"Content reader is not enabled"}}', []]);
    $r = $efCase(['request' => ['action' => 'content.read', 'token' => 'wrong-token-wrong-token', 'params' => []]]);
    check('content.read bad token: 401 body unchanged, nothing logged', [$r['status'] ?? null, $r['body'] ?? null, $r['logs'] ?? null],
        [401, '{"error":{"code":"CONTENT_UNAUTHENTICATED","message":"Authentication required"}}', []]);
    $r = $efCase(['request' => ['action' => 'content.read', 'token' => $efSecret, 'params' => ['a', 'b']]]);
    check('content.read malformed query: 400 body unchanged, nothing logged', [$r['status'] ?? null, $r['body'] ?? null, $r['logs'] ?? null],
        [400, '{"error":{"code":"CONTENT_BAD_QUERY","message":"Invalid content query"}}', []]);

    // 8. The builders' catches keep their null fallback and log what they caught.
    $r = $efCase(['action' => 'siblings', 'db' => 'container', 'params' => ['token' => $efSecret, 'webroot' => $efScratch . '/no-such-webroot']]);
    check('builders: child ran', $r['broken'] ?? null, null);
    check('builders: every fallback is still null', $r['results'] ?? null,
        ['derivedBinding' => null, 'buildDumper' => null, 'buildWalker' => null, 'buildWriter' => null, 'buildMedia' => null, 'buildLog' => null]);
    $efMessages = array_column($r['logs'] ?? [], 'message');
    check('builders: one ERROR entry per caught failure', [count($efMessages), array_unique(array_column($r['logs'] ?? [], 'category')), array_unique(array_column($r['logs'] ?? [], 'priority'))],
        [6, ['com_claudecowork'], [8]]);
    foreach (['derivedBinding' => 'database driver could not connect', 'buildDumper' => 'database driver could not connect', 'buildWalker' => 'webroot not a dir',
        'buildWriter' => 'JoomlaSiteWriter', 'buildMedia' => 'JoomlaMediaWriter', 'buildLog' => 'JoomlaApplyLog'] as $efWhere => $efCause) {
        $efHit = array_values(array_filter($efMessages, static fn(string $m): bool => strpos($m, $efWhere . ':') !== false));
        checkTrue("builders: {$efWhere} logs its cause", count($efHit) === 1 && strpos($efHit[0], $efCause) !== false && strpos($efHit[0], '#0 ') !== false);
    }
    check('builders: a dedicated logger is registered once', count($r['loggers'] ?? []), 1);
    check('builders: no secret written', $efLeaks($efWritten($r)), false);

    // 9. build() keeps answering with an engine when the database is gone; derive's catch logs it too.
    $r = $efCase(['action' => 'build', 'db' => 'container']);
    check('build without a database: still returns the engine', [$r['broken'] ?? null, $r['engine'] ?? null], [null, 'Engine']);
    $efMessages = array_column($r['logs'] ?? [], 'message');
    checkTrue('build without a database: derive source failure is logged',
        count(array_filter($efMessages, static fn(string $m): bool => strpos($m, 'derivedSource:') !== false && strpos($m, 'database driver could not connect') !== false)) === 1);
    check('build without a database: no secret written', $efLeaks($efWritten($r)), false);

    // 10. A stack frame whose argument is a credential: the frame is named, the value never written.
    $r = $efCase(['action' => 'secret-argument']);
    $entry = (string) ($r['logs'][0]['message'] ?? '');
    checkTrue('secret argument: the frame is in the stack', (bool) preg_match('~\{closure[^\n]*\(\)~', $entry) && strpos($entry, 'authentication backend failed') !== false);
    check('secret argument: its value is not', $efLeaks($entry), false);

    // Only what this file made: the scratch tree, its lib symlink and the per-case error logs.
    $efRemove = static function (string $path) use (&$efRemove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) as $child) if ($child !== '.' && $child !== '..') $efRemove($path . '/' . $child);
        rmdir($path);
    };
    $efRemove($efScratch);

    if ($efStandalone) {
        echo "\n{$passed} passed, {$failed} failed\n";
        exit($failed ? 1 : 0);
    }
}
