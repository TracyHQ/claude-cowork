<?php
/**
 * The PHP 8 string functions the tests call, defined only where PHP has none.
 *
 * The plugin declares PHP 7.4 as its floor (update.json `requires_php`) and its lib calls none of
 * them; the tests do, so on 7.4 the runner stopped at the first `str_contains`. Each is guarded by
 * `function_exists`, so PHP 8 keeps its own. Test-only: nothing here ships in the plugin.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }
}
