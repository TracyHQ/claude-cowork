<?php
/**
 * AuthorMeta — which of a user's meta a visitor reads on the site's author pages, the only ones `usermeta` lists and writes.
 *
 * Measured 10/10/2026 on dev g60-ess-wp-full (JA Essence WordPress, a bakery, vi; TracyHQ/tch#1013): the author listing and every
 * author block printed "Collaborator & Editor" (theme meta `job_title`) and an English bio (core `description`) on 17 pages.
 * They are no post, no option and no contract slot, so nothing Tracy wrote reached them.
 *
 * A whitelist, never a user's other meta: a person's account (capabilities, sessions, passwords, preferences) stays theirs.
 *   - `description`, the bio WordPress core keeps and every author template prints;
 *   - a theme's job title or tagline: a key ending in `job_title`, `jobtitle`, `tagline` or `position`, with or without the
 *     theme's prefix (`job_title`, `wp_ja_nova_job_title`, `wp_ja_kinetic_user_tagline`).
 * A theme's social links (`je_social_*`) are NOT here: the template keeps them as they are (K6).
 */
final class AuthorMeta
{
    /** A theme's job title or tagline key. */
    private const THEME = '/^(?:[a-z0-9]+_)*(?:job_?title|jobtitle|tagline|position)$/D';

    /** Whether `$key` is a field of a user a visitor reads. */
    public static function visitorKey(string $key): bool
    {
        return $key === 'description' || preg_match(self::THEME, $key) === 1;
    }

    /**
     * The visitor-facing fields of one user's meta that hold words, as `get_user_meta($id)` answers it (key => list of values).
     *
     * @param array<string,mixed> $meta
     * @return array<string,string>
     */
    public static function fields(array $meta): array
    {
        $out = [];
        foreach ($meta as $key => $values) {
            $value = is_array($values) ? ($values[0] ?? null) : $values;
            if (is_string($key) && self::visitorKey($key) && is_string($value) && trim($value) !== '') $out[$key] = $value;
        }
        ksort($out);
        return $out;
    }
}
