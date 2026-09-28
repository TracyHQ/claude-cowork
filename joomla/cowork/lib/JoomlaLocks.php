<?php
/**
 * Who holds a record open in the Joomla editor, decided from rows alone: no Joomla globals, no
 * database. The host reads the rows (`JoomlaContentReader::locks`); this decides what they mean.
 *
 * Opening an article, a menu item or a module in the back end CHECKS IT OUT (`checked_out` = user
 * id, `checked_out_time`), and saving or closing checks it back in. A write under that check-out is
 * lost the moment the editor saves: Joomla's form posts every field it loaded, so the admin's Save
 * silently puts the old words back over ours. So a write refuses a record that is open.
 *
 * 🔒 A CHECK-OUT IS ONLY LIVE WHILE ITS USER IS STILL SIGNED IN. Joomla leaves a check-out behind
 * whenever someone closes the tab instead of pressing Close, and nothing clears it until an admin
 * runs Global Check-in. Honouring every check-out would lock a customer's site against the agent
 * for days over a browser closed last week. So a check-out counts only while its user has an
 * administrator session (`#__session`, `client_id = 1`) seen within the session lifetime — the
 * same window after which Joomla itself would sign that user out.
 */
final class JoomlaLocks
{
    /**
     * A check-out older than this is left behind, not an editor at work. Joomla stamps
     * `checked_out_time` when the form opens and never again, and leaves the row checked out when
     * a tab is closed; with only the session test, a check-out from a demo build days earlier read
     * as locked the moment the same admin signed in (dev capiv2j, 26/09/2026: Home since 13/09).
     * Twelve hours is longer than anyone keeps one form open, and short enough that a leftover
     * clears itself within the day.
     */
    public const MAX_CHECKOUT_AGE = 12 * 3600;

    /**
     * The tables whose rows Joomla checks out, by the writer's kind. A kind missing here has no
     * check-out column (a user, a template style, a language) and is never locked.
     */
    public const TABLES = ['article' => 'content', 'menuItem' => 'menu', 'module' => 'modules', 'category' => 'categories',
        'tag' => 'tags', 'field' => 'fields', 'contact' => 'contact_details', 'newsfeed' => 'newsfeeds',
        'banner' => 'banners', 'bannerClient' => 'banner_clients'];

    public static function key(string $kind, int $id): string
    {
        return $kind . ':' . $id;
    }

    /**
     * The live locks among some checked-out rows.
     *
     * @param array<string,array{checked_out:mixed,checked_out_time:mixed}> $checkouts "kind:id" => its row's two columns
     * @param list<array{userid:mixed,client_id:mixed,time:mixed}> $sessions `#__session` rows of those users
     * @param array<string|int,string> $names user id => display name
     * @param int $lifetime the session lifetime in minutes (Joomla's `lifetime`)
     * @return array<string,array{kind:string,name:?string,since:?string,until:null}> "kind:id" => lockedBy, live ones only
     */
    public static function live(array $checkouts, array $sessions, array $names, int $now, int $lifetime): array
    {
        // The freshest administrator session per user: two browsers are one editor.
        $seen = [];
        foreach ($sessions as $session) {
            if ((int) ($session['client_id'] ?? -1) !== 1) continue;
            $user = (int) ($session['userid'] ?? 0);
            if ($user > 0) $seen[$user] = max($seen[$user] ?? PHP_INT_MIN, (int) ($session['time'] ?? 0));
        }
        // Inclusive: Joomla's session garbage collector drops a row only once it is OLDER than the lifetime.
        $oldest = $now - $lifetime * 60;
        $out = [];
        foreach ($checkouts as $key => $row) {
            $user = (int) ($row['checked_out'] ?? 0);
            if ($user <= 0 || !isset($seen[$user]) || $seen[$user] < $oldest) continue;
            // An unknown time (NULL, a zero date) is kept: nothing says the form is not open.
            $at = self::at($row['checked_out_time'] ?? null);
            if ($at !== null && $now - $at > self::MAX_CHECKOUT_AGE) continue;
            $name = $names[$user] ?? $names[(string) $user] ?? null;
            $out[$key] = ['kind' => 'admin-user', 'name' => is_string($name) && $name !== '' ? $name : null,
                'since' => self::since($row['checked_out_time'] ?? null), 'until' => null];
        }
        return $out;
    }

    /** `checked_out_time` is stored in UTC; a zero date (Joomla 3) or NULL (Joomla 4+) is unknown. */
    private static function at($value): ?int
    {
        if (!is_string($value) || $value === '' || substr($value, 0, 4) === '0000') return null;
        $at = strtotime($value . ' UTC');
        return $at === false ? null : $at;
    }

    private static function since($value): ?string
    {
        $at = self::at($value);
        return $at === null ? null : gmdate('Y-m-d\TH:i:s\Z', $at);
    }

    /**
     * The refusal an agent relays to its customer word for word: who has it open, since when, and
     * the one thing that clears it. There is deliberately no "force" to offer instead.
     */
    public static function message(?string $title, array $lockedBy): string
    {
        $what = is_string($title) && $title !== '' ? '"' . $title . '"' : 'This item';
        $who = is_string($lockedBy['name'] ?? null) ? $lockedBy['name'] : 'another administrator';
        $since = is_string($lockedBy['since'] ?? null) ? ' since ' . $lockedBy['since'] : '';
        return $what . ' is open in the Joomla editor by ' . $who . $since . ': ask them to save and close it, then try again.';
    }
}
