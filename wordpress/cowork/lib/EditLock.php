<?php
/**
 * EditLock — whether a person has a post open in the WordPress editor right now, by WordPress's
 * own rule, so Tracy never writes under them.
 *
 * The editor stamps post meta `_edit_lock` = "<unix time>:<user id>" when a post is opened and
 * refreshes it on every heartbeat. Core's `wp_check_post_lock()` treats it as live only while the
 * stamp is younger than `apply_filters('wp_check_post_lock_window', 150)` seconds, and only when
 * the user still exists; a stamp without a user part falls back to `_edit_last`. This class is
 * that rule and nothing longer: a wider window would refuse writes WordPress itself would allow,
 * a narrower one would write under a person the admin screens still show as editing.
 *
 * One deliberate difference: core ignores the lock of the CURRENT user (you do not lock yourself
 * out). A Tracy request is not the person in the editor, so every holder counts.
 *
 * The decision is pure (meta values, now, window in; holder out) so it is tested without
 * WordPress. Reading the meta and the user's name is the caller's: the content reader does it
 * inside its consistent read, the site writer through WordPress's API.
 */

final class EditLock
{
    public const META = '_edit_lock';
    /** Core's fallback holder when `_edit_lock` carries no user part. */
    public const LAST_EDITOR_META = '_edit_last';
    public const FILTER = 'wp_check_post_lock_window';
    public const DEFAULT_WINDOW = 150;
    /** The refusal code both write paths answer, and the contract door's recoverable problem. */
    public const CODE = 'SLOT_LOCKED_BY_USER';

    /**
     * The live holder of one post's editor lock, or null. A malformed stamp, a zero time, no
     * user, or a stamp at or past `time + window` is no lock — exactly where core says so.
     *
     * @param mixed $lock       the `_edit_lock` meta value ('' / null when absent)
     * @param mixed $lastEditor the `_edit_last` meta value, used only when `$lock` names no user
     * @return array{user:int,since:int,until:int}|null
     */
    public static function holder($lock, $lastEditor, int $now, int $window): ?array
    {
        if (!is_string($lock) && !is_int($lock)) {
            return null;
        }
        $parts = explode(':', (string) $lock);
        if (!ctype_digit($parts[0])) {
            return null;
        }
        $time = (int) $parts[0];
        $user = isset($parts[1]) ? $parts[1] : (is_scalar($lastEditor) ? (string) $lastEditor : '');
        if ($time <= 0 || !ctype_digit($user) || (int) $user <= 0) {
            return null;
        }
        // Core: `$time > time() - $time_window`, i.e. live strictly before time + window.
        if ($time <= $now - $window) {
            return null;
        }
        return ['user' => (int) $user, 'since' => $time, 'until' => $time + $window];
    }

    /**
     * The wire shape `content.read` lists as `lockedBy` and both write refusals carry.
     * `$name` is the holder's display name; an empty one is null, never a made-up label.
     *
     * @param array{user:int,since:int,until:int} $holder
     * @return array{kind:string,name:?string,since:string,until:string}
     */
    public static function lockedBy(array $holder, ?string $name): array
    {
        return [
            'kind' => 'admin-user',
            'name' => $name === null || trim($name) === '' ? null : $name,
            'since' => gmdate('Y-m-d\TH:i:s\Z', $holder['since']),
            'until' => gmdate('Y-m-d\TH:i:s\Z', $holder['until']),
        ];
    }

    /** The refusal in words a person can act on: who to ask, and what to ask them. */
    public static function message(string $title, array $lockedBy): string
    {
        $title = trim($title) === '' ? '(no title)' : $title;
        $who = is_string($lockedBy['name'] ?? null) ? $lockedBy['name'] : 'another user';
        return '"' . $title . '" is open in the WordPress editor by ' . $who . ': ask them to save and close it, then try again.';
    }

    /** The window WordPress uses on this site: the core default through the core filter. */
    public static function window(): int
    {
        $window = function_exists('apply_filters') ? apply_filters(self::FILTER, self::DEFAULT_WINDOW) : self::DEFAULT_WINDOW;
        return is_numeric($window) ? (int) $window : self::DEFAULT_WINDOW;
    }
}
