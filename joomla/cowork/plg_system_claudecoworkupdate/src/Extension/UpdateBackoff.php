<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Plugin\System\ClaudeCoworkUpdate\Extension;

\defined('_JEXEC') or die;

/**
 * When a version that failed to install may be tried again.
 *
 * An attempt is written down BEFORE the download starts and crossed out only when the install
 * succeeds. So an attempt that dies in a fatal error (memory, time limit, a killed worker) still
 * counts, which is exactly the failure that used to retry every quarter hour forever: the
 * 14/10/2026 fleet demos ran out of memory on the same release 61 times in a day.
 *
 * The wait doubles from one hour and stops at a day: a release that cannot install on this site
 * is tried a handful of times a day, not ninety-six. A NEWER announced version is not held back
 * by an older one's failures: it may be the fix.
 *
 * Plain PHP with no CMS, so tests/updater-out-of-request.php runs it on its own.
 */
final class UpdateBackoff
{
    public const FIRST = 3600;
    public const MOST  = 86400;

    /**
     * Whether $version may be attempted now, given the attempt record for its element.
     *
     * @param array{version?:string,count?:int,at?:int}|null $attempt
     */
    public static function due(?array $attempt, string $version, int $now): bool
    {
        if (!$attempt || ($attempt['version'] ?? '') !== $version) {
            return true;
        }

        return $now - (int) ($attempt['at'] ?? 0) >= self::wait((int) ($attempt['count'] ?? 1));
    }

    /** Seconds to wait after the $count-th failed attempt: 1 h, 2 h, 4 h, … at most a day. */
    public static function wait(int $count): int
    {
        $count = max(1, min($count, 16));

        return (int) min(self::MOST, self::FIRST * (2 ** ($count - 1)));
    }

    /**
     * The record to write before attempting $version.
     *
     * @param array{version?:string,count?:int,at?:int}|null $attempt
     *
     * @return array{version:string,count:int,at:int}
     */
    public static function begin(?array $attempt, string $version, int $now): array
    {
        $count = ($attempt && ($attempt['version'] ?? '') === $version) ? (int) ($attempt['count'] ?? 0) : 0;

        return ['version' => $version, 'count' => $count + 1, 'at' => $now];
    }
}
