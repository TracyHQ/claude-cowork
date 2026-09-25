<?php
/**
 * Navigation links that carry a page id but no URL are given the page's permalink at render time.
 *
 * WHY: the Tracy Business archive ships one navigation per Polylang edition (`tracy-vi`,
 * `topbar-vi`, `footer-company-vi`, …) and every `core/navigation-link` block in the translated
 * twins was captured as `{"id": 1946, "url": ""}` — the editor keeps the id, the front end prints
 * the url, and an empty url renders an anchor that goes nowhere. Measured on the dev machine
 * 25/09/2026 (site wpbgfeoy6, `/vi/`: every menu item `href=""`). Filling the url from the id is
 * what the block editor would do on the next save; the receiver does it on every page view so a
 * site provisioned from the archive as released is right the moment it is handed over. Guarded on
 * every WordPress function it touches, so loading the file outside WordPress (activation, tests)
 * is safe.
 */
final class NavigationLinks
{
    public static function register(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('render_block_data', [self::class, 'fillUrl']);
    }

    /**
     * @param mixed $parsed a parsed block (WordPress passes an array; anything else is returned as is)
     * @return mixed
     */
    public static function fillUrl($parsed)
    {
        if (!is_array($parsed)) {
            return $parsed;
        }
        $name = (string) ($parsed['blockName'] ?? '');
        if ($name !== 'core/navigation-link' && $name !== 'core/navigation-submenu') {
            return $parsed;
        }
        $attrs = is_array($parsed['attrs'] ?? null) ? $parsed['attrs'] : [];
        $url = trim((string) ($attrs['url'] ?? ''));
        $id = (int) ($attrs['id'] ?? 0);
        if ($url !== '' || $id <= 0 || !function_exists('get_permalink')) {
            return $parsed;
        }
        $kind = (string) ($attrs['kind'] ?? 'post-type');
        $link = $kind === 'taxonomy' && function_exists('get_term_link') ? get_term_link($id) : get_permalink($id);
        if (!is_string($link) || $link === '') {
            return $parsed;
        }
        $parsed['attrs']['url'] = $link;
        return $parsed;
    }
}
