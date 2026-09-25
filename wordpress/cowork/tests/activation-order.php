<?php
/**
 * The files activation loads, in the order it loads them, in a fresh process: SiteWriter, then
 * the writers file — and nothing else. Every class the writers file declares must resolve.
 */
declare(strict_types=1);
$code = 'define("ABSPATH", "/tmp/"); require ' . var_export(__DIR__ . '/../lib/SiteWriter.php', true) . '; require ' . var_export(__DIR__ . '/../lib/class-claude-cowork-writers.php', true) . '; echo class_exists("Claude_Cowork_Contract_Store") ? "ok" : "missing";';
$out = shell_exec(PHP_BINARY . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
check('activation can load the writers file on its own (Contract_Store resolves)', trim((string) $out), 'ok');

// The front-end hooks file is included by the plugin file on every request, before anything
// else of this plugin: it must load and register on its own, with no WordPress function defined.
$code = 'require ' . var_export(__DIR__ . '/../lib/MultilingualHooks.php', true) . '; MultilingualHooks::register(); echo MultilingualHooks::retired() === [] ? "ok" : "leaked";';
$out = shell_exec(PHP_BINARY . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
check('the multilingual hooks load and register without WordPress', trim((string) $out), 'ok');

$code = 'define("ABSPATH", "/tmp/"); require ' . var_export(__DIR__ . '/../lib/NavigationLinks.php', true) . '; NavigationLinks::register(); echo class_exists("NavigationLinks") ? "ok" : "missing";';
$out = shell_exec(PHP_BINARY . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
check('the navigation-link hook file loads and registers outside WordPress', trim((string) $out), 'ok');
