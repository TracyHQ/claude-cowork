<?php
// extension.install never holds the package in memory (0.24.29). Before it, the API door's
// JoomlaExtensions::installFromUrl fetched with InstallerHelper::downloadPackage, which takes the
// whole body as one string: installing pkg_claudecowork (~42 MB) or any large extension through
// Tracy was a "memory size exhausted" fatal at the default memory_limit 128M, which no catch sees,
// so the API answered a dead 500. The sibling of the updater fix in tests/updater-out-of-request.php.
//
// What this holds, through the API path (Engine::handle -> extension.install -> JoomlaExtensions,
// with Joomla's Factory, Installer and InstallerHelper as stand-ins), each case in its own PHP
// process under memory_limit 24M:
//
// 1. A 40 MB package pinned by sha256 and size installs, and the installer gets the whole file.
// 2. A sha256 or a size that differs is a clean install_failed, never handed to the installer, and
//    nothing is left in tmp/.
// 3. A URL that is not http(s) is refused before anything is opened.
//
// Run alone (`php tests/extension-install-streaming.php`) or from run.php.

namespace {
    if (isset($argv) && ($argv[1] ?? null) === '--install-case' && isset($argv[2])
        && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        define('IS_CASE', json_decode($argv[2], true));
        define('_JEXEC', 1);
        define('JPATH_ADMINISTRATOR', IS_CASE['root'] . '/administrator');

        final class IsRecord
        {
            /** @var array<int,mixed> */
            public static $calls = [];
        }

        // https://fixture.invalid/<name> answers with IS_CASE['source'], read in the blocks the caller
        // asks for: the network without a network, and without TLS.
        final class IsHttpsFixture
        {
            public $context;
            private $fh;
            public function stream_open($path, $mode, $options, &$opened): bool { $this->fh = fopen(IS_CASE['source'], 'rb'); return $this->fh !== false; }
            public function stream_read($count) { return fread($this->fh, $count); }
            public function stream_eof(): bool { return feof($this->fh); }
            public function stream_close(): void { fclose($this->fh); }
            public function stream_stat() { return fstat($this->fh); }
            public function stream_set_option($option, $arg1, $arg2): bool { return false; }
        }
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', 'IsHttpsFixture');
    }
}

namespace Joomla\CMS {
    if (\defined('IS_CASE')) {
        final class Factory
        {
            public static function getApplication()
            {
                return new class {
                    public function get($k, $d = null) { return IS_CASE['config'][$k] ?? $d; }
                };
            }
            public static function getContainer()
            {
                return new class {
                    public function get($id) { return new \stdClass(); }
                };
            }
        }
    }
}

namespace Joomla\CMS\Installer {
    if (\defined('IS_CASE')) {
        final class InstallerHelper
        {
            public static function unpack($path, $alwaysReturnArray = false)
            {
                \IsRecord::$calls[] = ['unpack', is_file($path) ? filesize($path) : null, is_file($path) ? hash_file('sha256', $path) : null];
                $dir = \dirname($path) . '/extracted';
                @mkdir($dir);
                return ['extractdir' => $dir, 'dir' => $dir, 'packagefile' => $path, 'type' => 'package'];
            }
            public static function cleanupInstall($file, $dir)
            {
                \IsRecord::$calls[] = ['cleanup'];
                if (is_file($file)) unlink($file);
                if ($dir && is_dir($dir)) rmdir($dir);
                return true;
            }
            public static function downloadPackage($url, $target = false)
            {
                \IsRecord::$calls[] = ['downloadPackage'];
                return false;
            }
        }

        final class Installer
        {
            public function setDatabase($db): void {}
            public function install($dir): bool { \IsRecord::$calls[] = ['install']; return true; }
            public function getError() { return ''; }
            public function getManifest() { return simplexml_load_string('<extension><name>Joomla Claude Cowork</name><version>0.24.29</version></extension>'); }
        }
    }
}

namespace {
    if (\defined('IS_CASE')) {
        require __DIR__ . '/../lib/Engine.php';
        require __DIR__ . '/../com_claudecowork/site/src/Controller/JoomlaExtensions.php';

        $before = memory_get_usage(true);
        $extensions = new Tracy\Component\ClaudeCowork\Site\Controller\JoomlaExtensions(false);
        if (isset(IS_CASE['direct'])) {
            $answer = $extensions->installFromUrl(IS_CASE['direct']);
        } else {
            $engine = new Engine('install-token-at-least-16', [], null, null, null, $extensions);
            $answer = $engine->handle(['token' => 'install-token-at-least-16', 'action' => 'extension.install', 'params' => IS_CASE['params']]);
        }

        echo json_encode([
            'answer' => $answer,
            'calls'  => IsRecord::$calls,
            'left'   => array_values(array_map('basename', glob(IS_CASE['config']['tmp_path'] . '/*'))),
            'peak'   => memory_get_peak_usage(true),
            'base'   => $before,
        ]);
        exit(0);
    }
}

namespace {
    if (!\defined('IS_CASE')) {
        if (!function_exists('check')) {
            $passed = 0; $failed = 0;
            function check(string $name, $got, $want): void { global $passed, $failed; if ($got === $want) { $passed++; echo "  ok   {$name}\n"; } else { $failed++; echo "  FAIL {$name}\n       got : " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n"; } }
            function checkTrue(string $name, bool $c): void { check($name, $c, true); }
            $isStandalone = true;
        }

        echo "\nextension.install: the package goes to a file, never into memory\n";

        $isTmp = sys_get_temp_dir() . '/cowork-install-' . bin2hex(random_bytes(4));
        mkdir($isTmp . '/administrator/components/com_claudecowork/lib', 0777, true);
        mkdir($isTmp . '/tmp');
        // Where EngineFactory and the door require lib/ from on a site.
        copy(__DIR__ . '/../lib/PackageDownload.php', $isTmp . '/administrator/components/com_claudecowork/lib/PackageDownload.php');
        $isSource = $isTmp . '/pkg_claudecowork.zip';
        $fh = fopen($isSource, 'wb');
        $block = random_bytes(1024 * 1024);
        for ($i = 0; $i < 40; $i++) fwrite($fh, $block);
        fclose($fh);
        $isSha = hash_file('sha256', $isSource);
        $isBytes = 40 * 1024 * 1024;

        $isRun = static function (array $case) use ($isTmp, $isSource): array {
            $case += ['root' => $isTmp, 'source' => $isSource, 'config' => ['tmp_path' => $isTmp . '/tmp']];
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=24M ' . escapeshellarg(__FILE__) . ' --install-case ' . escapeshellarg(json_encode($case)) . ' 2>&1');
            $r = json_decode((string) $out, true);
            if (!is_array($r)) { echo "       case output: {$out}\n"; return ['answer' => [], 'calls' => [], 'left' => [], 'peak' => PHP_INT_MAX, 'base' => 0]; }
            return $r;
        };
        $url = 'https://fixture.invalid/pkg_claudecowork.zip';

        $ok = $isRun(['params' => ['url' => $url, 'sha256' => $isSha, 'bytes' => $isBytes]]);
        check('a 40 MB package pinned by sha256 installs through the API under memory_limit 24M',
            [$ok['answer']['ok'] ?? null, $ok['answer']['installed']['version'] ?? null], [true, '0.24.29']);
        check('the installer was handed the whole file, byte for byte', $ok['calls'][0] ?? null, ['unpack', $isBytes, $isSha]);
        check('then installed it and cleaned up', array_column(array_slice($ok['calls'], 1), 0), ['install', 'cleanup']);
        checkTrue('the download added under 8 MB to memory (the package is 40)', ($ok['peak'] - $ok['base']) < 8 * 1024 * 1024);
        check('nothing is left in tmp/ after a success', $ok['left'], []);

        $bad = $isRun(['params' => ['url' => $url, 'sha256' => str_repeat('0', 64), 'bytes' => $isBytes]]);
        check('a sha256 that differs is a clean install_failed', [$bad['answer']['ok'] ?? null, $bad['answer']['error'] ?? null], [false, 'install_failed']);
        checkTrue('and says why', strpos((string) ($bad['answer']['message'] ?? ''), 'checksum') !== false);
        check('and is never handed to the installer, nothing left in tmp/', [$bad['calls'], $bad['left']], [[], []]);

        $size = $isRun(['params' => ['url' => $url, 'sha256' => $isSha, 'bytes' => $isBytes - 1]]);
        check('a size that differs from the catalog is refused the same way', [$size['answer']['error'] ?? null, $size['calls'], $size['left']], ['install_failed', [], []]);

        $file = $isRun(['direct' => 'file://' . $isSource]);
        check('a URL that is not http(s) is refused before anything is opened',
            [$file['answer'], $file['calls']], [['ok' => false, 'error' => 'package URL must be http or https'], []]);

        $src = file_get_contents(__DIR__ . '/../com_claudecowork/site/src/Controller/JoomlaExtensions.php');
        checkTrue('source: Joomla\'s whole-body download is gone from the door', strpos($src, 'InstallerHelper::downloadPackage(') === false);

        @unlink($isSource);
        @unlink($isTmp . '/administrator/components/com_claudecowork/lib/PackageDownload.php');
        foreach (['/administrator/components/com_claudecowork/lib', '/administrator/components/com_claudecowork', '/administrator/components', '/administrator', '/tmp', ''] as $d) @rmdir($isTmp . $d);

        if (!empty($isStandalone)) { echo "\n{$passed} passed, {$failed} failed\n"; exit($failed ? 1 : 0); }
    }
}
