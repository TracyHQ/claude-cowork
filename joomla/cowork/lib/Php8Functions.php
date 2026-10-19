<?php
/**
 * The PHP 8 string and array functions the engine calls, defined only where PHP has none.
 *
 * The package declares PHP 7.4 as its floor (update.json `requires_php`), and Joomla 3 ships no
 * symfony polyfill — so on a 7.4 site the first `str_starts_with` was a fatal "call to undefined
 * function" mid-request. Each is guarded by `function_exists`, so PHP 8, or a polyfill Joomla
 * loaded first, keeps its own. Required by every lib file that calls one of them, because the
 * files are loaded one by one, in whatever order each caller needs.
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

if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $next = 0;
        foreach ($array as $key => $_) {
            if ($key !== $next++) return false;
        }
        return true;
    }
}
