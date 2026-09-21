<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

/**
 * Is this request knocking on the API door?
 *
 * The door is `index.php?option=com_claudecowork&task=api.exec`, and it is answered twice: by the
 * component's own controller once Joomla has routed the request, and — earlier — by the system
 * plugin at `onAfterInitialise`, before any router or any other plugin has seen it. Both need the
 * same answer to the same question, so the question lives here, in plain PHP, where the test
 * runner can ask it without Joomla.
 *
 * `format` is deliberately not part of the question. The door always answers JSON; a caller that
 * forgot `format=json` gets JSON anyway rather than an HTML error page dressed as a reply.
 */
final class Door
{
    public const OPTION = 'com_claudecowork';
    public const TASK = 'api.exec';

    /**
     * @param array<string,mixed> $query The request's query parameters, as strings.
     */
    public static function wants(array $query): bool
    {
        $option = $query['option'] ?? null;
        $task = $query['task'] ?? null;
        if (!is_string($option) || !is_string($task)) {
            return false;
        }
        return $option === self::OPTION && strtolower($task) === self::TASK;
    }

    /**
     * The PHP memory the door reserves before the engine is built, in php.ini shorthand.
     *
     * A contract profile is decoded whole when the engine is wired — before any action runs — and
     * a quickstart with 43 content languages carries ~6,000 entities with ACL rules in an 8 MB
     * presentation lock (tracy-business/j6/1.1.0: 6,144 entityRules). Under PHP's default 128M
     * every bind and inspect of such a site died with "Allowed memory size exhausted" in
     * ContractAccess::snapshot; at 512M bind took about 10 s (measured 2026-09-21). Reserved here,
     * at the one point every action passes through, because php.ini on a customer's host is not
     * ours to edit.
     */
    public const MEMORY_LIMIT = '512M';

    /**
     * Raise `memory_limit` to at least MEMORY_LIMIT. Never lowers a limit already above it and
     * leaves `-1` (unlimited) alone. Returns the limit in effect afterwards.
     */
    public static function reserveMemory(): string
    {
        $wanted = self::memoryLimitFor((string) ini_get('memory_limit'));
        if ($wanted !== null) {
            ini_set('memory_limit', $wanted);
        }
        return (string) ini_get('memory_limit');
    }

    /**
     * The value to set for a configured `memory_limit`, or null when it is already enough. Pure,
     * so the test runner can ask it for every shape php.ini allows without touching its own limit.
     */
    public static function memoryLimitFor(string $configured): ?string
    {
        $configured = trim($configured);
        // A negative limit is PHP's "unlimited"; nothing this could set is more than that.
        if ($configured !== '' && $configured[0] === '-') {
            return null;
        }
        return self::bytes($configured) >= self::bytes(self::MEMORY_LIMIT) ? null : self::MEMORY_LIMIT;
    }

    /**
     * PHP's own ini shorthand: an integer with an optional K, M or G suffix, case-insensitive.
     * Anything unparseable counts as 0, which is what PHP itself makes of it — and 0 is raised.
     */
    private static function bytes(string $shorthand): int
    {
        if (!preg_match('/^\s*(\d+)\s*([kmgKMG]?)\s*$/', $shorthand, $m)) {
            return 0;
        }
        $factor = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2])];
        return ((int) $m[1]) * $factor;
    }
}
