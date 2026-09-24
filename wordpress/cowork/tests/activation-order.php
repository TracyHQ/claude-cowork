<?php
/**
 * The files activation loads, in the order it loads them, in a fresh process: SiteWriter, then
 * the writers file — and nothing else. Every class the writers file declares must resolve.
 */
declare(strict_types=1);
$code = 'define("ABSPATH", "/tmp/"); require ' . var_export(__DIR__ . '/../lib/SiteWriter.php', true) . '; require ' . var_export(__DIR__ . '/../lib/class-claude-cowork-writers.php', true) . '; echo class_exists("Claude_Cowork_Contract_Store") ? "ok" : "missing";';
$out = shell_exec(PHP_BINARY . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
check('activation can load the writers file on its own (Contract_Store resolves)', trim((string) $out), 'ok');
