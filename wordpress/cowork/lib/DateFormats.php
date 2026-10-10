<?php
/**
 * DateFormats — which PHP date formats read in English order, shared by the language step (Engine: `date_format`
 * follows WPLANG) and the block filter (BlockWords: a date block's own `format`).
 *
 * Akemi 10/10/2026 (TracyHQ/tch#1013): dates are visitor words; a vi site prints "9 tháng 6, 2023", the locale's own
 * order, as WordPress core vi installs it (`j F, Y`). The English install's `F j, Y` and a theme's `M d, Y` put the
 * month word before the day, which no translation of the month names can fix.
 */
declare(strict_types=1);

final class DateFormats
{
    /** WordPress's own date and time format when it is installed in English (wp-admin/includes/schema.php). */
    public const ENGLISH_DATE = 'F j, Y';
    public const ENGLISH_TIME = 'g:i a';

    /**
     * Whether a format names the month in words before the day, with a year: "M d, Y", "F j, Y", "D, M j Y". A
     * day-first, numeric, month-and-year or year-less format is the site's own choice and is left alone.
     */
    public static function monthBeforeDay(string $format): bool
    {
        $plain = (string) preg_replace('/\\\\./s', '', $format);
        $month = strcspn($plain, 'MF');
        $day = strcspn($plain, 'dj');
        return $month < strlen($plain) && $day < strlen($plain) && $month < $day && strcspn($plain, 'Yy') < strlen($plain);
    }

    /**
     * The date and time formats WordPress itself installs under `$locale` (`__('F j, Y')`, `__('g:i a')` in the core
     * translation, read under switch_to_locale), or null when WordPress is not loaded, the locale is not installed, or
     * its translation keeps the English date format.
     *
     * @return array{date:string,time:string}|null
     */
    public static function ofLocale(string $locale): ?array
    {
        if (!function_exists('switch_to_locale') || !function_exists('translate') || !function_exists('restore_previous_locale')) {
            return null;
        }
        $current = function_exists('determine_locale') ? (string) determine_locale() : (function_exists('get_locale') ? (string) get_locale() : '');
        $switched = false;
        if ($current !== $locale) {
            $switched = (bool) switch_to_locale($locale);
            if (!$switched) {
                return null;
            }
        }
        try {
            $date = (string) translate(self::ENGLISH_DATE, 'default');
            $time = (string) translate(self::ENGLISH_TIME, 'default');
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
        return $date === '' || $date === self::ENGLISH_DATE ? null : ['date' => $date, 'time' => $time === '' ? self::ENGLISH_TIME : $time];
    }
}
