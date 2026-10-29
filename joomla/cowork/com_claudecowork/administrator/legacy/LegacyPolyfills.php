<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

/*
 * PHP 8's string and array helpers the component's `src/` classes call. Joomla 4+ ships them for
 * PHP 7 (symfony/polyfill-php80, -php81); Joomla 3 does not, and its PHP 7.4 has none of them.
 * Defined only when PHP lacks them, with PHP 8's behaviour.
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return strncmp((string) $haystack, (string) $needle, strlen((string) $needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return (string) $needle === '' || strpos((string) $haystack, (string) $needle) !== false;
    }
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $array)
    {
        $next = 0;
        foreach ($array as $key => $unused) {
            if ($key !== $next++) {
                return false;
            }
        }
        return true;
    }
}
