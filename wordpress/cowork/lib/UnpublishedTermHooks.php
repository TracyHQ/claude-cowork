<?php
/**
 * UnpublishedTermHooks — on a quickstart site, the archive of a category or tag that lists no post answers 404.
 *
 * Tracy drafts the posts of a page the customer unticks (TracyHQ/tch#1013, D4), and `multilingual.retire` drafts every
 * post of a language nobody chose. WordPress still serves the archive of each of their categories and tags: `WP::handle_404`
 * answers 200 for a term archive with no posts as long as the term exists, so /category/news/ and /fr/category/news-fr/
 * opened an empty listing (measured 09/10/2026 on a Tracy Business wp7 1.3.4 stand). The demo's own News category
 * held no post at all, so "every post taken off" is not the rule: a demo term nobody filed anything under is residue too.
 *
 * On a site bound to a quickstart contract (`_tracy_content_contract`), a term archive with no published post answers
 * 404, its feed too; a term that still lists a published post (one a kept page shows) is served as WordPress always
 * did. A site that is not bound (a customer's own, imported) is never touched. With term counts kept true
 * (`QuickstartContract::setPostStatus`), the core sitemap already leaves such a term out (`hide_empty`).
 * Loaded on every request; it reads the database only on a term archive that found no post.
 */
final class UnpublishedTermHooks
{
    /** The binding option, spelled here because `QuickstartContract` is not loaded on a page view. */
    private const STORE_OPTION = '_tracy_content_contract';

    /** Hook in; without WordPress nothing happens. */
    public static function register(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('pre_handle_404', [self::class, 'filter'], 10, 2);
    }

    /**
     * Whether the archive answers 404. Pure, so it is tested without WordPress.
     * @param bool $bound the site is bound to a quickstart contract
     * @param bool $termArchive a category, tag or custom taxonomy archive whose term exists
     * @param int $listed how many posts the archive found
     * @param string[] $statuses the statuses of every post filed under the term
     */
    public static function notFound(bool $bound, bool $termArchive, int $listed, array $statuses): bool
    {
        return $bound && $termArchive && $listed === 0 && !in_array('publish', $statuses, true);
    }

    /** Whether the site is bound to a quickstart contract: its binding option names one. */
    private static function bound(): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }
        $raw = get_option(self::STORE_OPTION, '');
        $binding = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($binding) && is_string($binding['contract'] ?? null) && $binding['contract'] !== '';
    }

    /** `pre_handle_404`: on a quickstart site a term archive with no published post is a 404, never an empty listing. */
    public static function filter($preempt, $query = null)
    {
        if ($preempt !== false || !is_object($query) || !method_exists($query, 'get_queried_object')) {
            return $preempt;
        }
        try {
            $archive = (method_exists($query, 'is_category') && $query->is_category())
                || (method_exists($query, 'is_tag') && $query->is_tag())
                || (method_exists($query, 'is_tax') && $query->is_tax());
            if (!$archive || !empty($query->posts) || !self::bound()) {
                return $preempt;
            }
            $term = $query->get_queried_object();
            $ttId = is_object($term) && isset($term->term_taxonomy_id) ? (int) $term->term_taxonomy_id : 0;
            global $wpdb;
            if ($ttId <= 0 || !is_object($wpdb)) {
                return $preempt;
            }
            $statuses = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT p.post_status FROM {$wpdb->term_relationships} tr"
                . " INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id = %d",
                $ttId
            ));
            if (!self::notFound(true, true, 0, is_array($statuses) ? array_map('strval', $statuses) : [])) {
                return $preempt;
            }
            $query->set_404();
            if (function_exists('status_header')) {
                status_header(404);
            }
            if (function_exists('nocache_headers')) {
                nocache_headers();
            }
            return true;
        } catch (\Throwable $e) {
            // An archive is still an archive when this cannot tell; never let it break the page.
            return $preempt;
        }
    }
}
