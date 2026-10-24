<?php
/**
 * The rendered-words tests alone: php tests/rendered-words-run.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Engine.php';
require_once __DIR__ . '/FakeWriters.php';
require_once __DIR__ . '/FakeWordPress.php';
require_once __DIR__ . '/../lib/class-claude-cowork-writers.php';

$passed = 0;
$failed = 0;

function check(string $name, $got, $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}\n       got : " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n";
}

$WTOKEN = 'write-token-at-least-16chars';
require __DIR__ . '/rendered-words.php';
require __DIR__ . '/rendered-words-receipts.php';
require __DIR__ . '/rendered-words-hooks.php';

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
