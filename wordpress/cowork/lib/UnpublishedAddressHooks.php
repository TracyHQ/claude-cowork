<?php
/**
 * UnpublishedAddressHooks — the address of a page that is not published answers 404, never another page.
 *
 * Tracy drafts the pages a customer unticks (TracyHQ/tch#1013). On a 404 WordPress core guesses a published post whose
 * slug STARTS WITH the one asked for (`redirect_guess_404_permalink`, a LIKE 'news%' query) and answers 301 to it, so
 * an anonymous /news/ went to /newsletter/ (measured 08/10/2026 on dev, Tracy Business WP, plugin 0.18.3): the page the
 * customer took off stayed one click from a page they never chose.
 *
 * While the name asked for belongs to a page or post that exists but is not published (draft, pending, private,
 * scheduled), the guess is skipped and the 404 stands. A name no row holds, or one a published row holds, is guessed as
 * WordPress always did. Loaded on every request; it reads the database only on a 404 that WordPress is about to guess.
 */
final class UnpublishedAddressHooks
{
    /** Statuses of a row that still owns its address while nobody can see it. */
    private const HELD = ['draft', 'pending', 'private', 'future'];

    /** Hook in; without WordPress nothing happens. */
    public static function register(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('do_redirect_guess_404_permalink', [self::class, 'filter']);
    }

    /**
     * Whether WordPress may guess. Pure, so it is tested without WordPress.
     * @param string $name the post name asked for (`name` query var)
     * @param string[] $statuses the statuses of the viewable-type rows whose post_name is exactly $name
     */
    public static function shouldGuess(string $name, array $statuses): bool
    {
        if ($name === '' || in_array('publish', $statuses, true)) {
            return true;
        }
        return array_intersect($statuses, self::HELD) === [];
    }

    /** `do_redirect_guess_404_permalink`: false while the address belongs to an unpublished page. */
    public static function filter($doGuess)
    {
        if ($doGuess === false || !function_exists('get_query_var')) {
            return $doGuess;
        }
        $name = get_query_var('name');
        if (!is_string($name) || $name === '') {
            return $doGuess;
        }
        try {
            global $wpdb;
            if (!is_object($wpdb)) {
                return $doGuess;
            }
            // The same post types core's guess may land on.
            $types = array_values(array_filter(
                get_post_types(['exclude_from_search' => false]),
                'is_post_type_viewable'
            ));
            if ($types === []) {
                return $doGuess;
            }
            $in = implode(',', array_fill(0, count($types), '%s'));
            $statuses = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_status FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ({$in})",
                array_merge([$name], $types)
            ));
            return self::shouldGuess($name, is_array($statuses) ? array_map('strval', $statuses) : []) ? $doGuess : false;
        } catch (\Throwable $e) {
            // A guess is a convenience; never let it break a 404.
            return $doGuess;
        }
    }
}
