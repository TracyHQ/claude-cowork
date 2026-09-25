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
        // Inner blocks of a navigation are rendered through WP_Block::render, which does not pass
        // through `render_block_data`; the rendered markup does pass through `render_block`.
        add_filter('render_block', [self::class, 'fillRenderedHref'], 10, 2);
    }

    /**
     * The rendered anchor of a navigation link whose block carried no url: `href=""` becomes the
     * permalink of the block's id.
     * @param mixed $content
     * @param mixed $block
     * @return mixed
     */
    public static function fillRenderedHref($content, $block)
    {
        if (!is_string($content) || !is_array($block)) {
            return $content;
        }
        $name = (string) ($block['blockName'] ?? '');
        if ($name !== 'core/navigation-link' && $name !== 'core/navigation-submenu') {
            return $content;
        }
        if (strpos($content, 'href=""') === false && strpos($content, "href=''") === false) {
            return $content;
        }
        $link = self::permalinkOf(is_array($block['attrs'] ?? null) ? $block['attrs'] : []);
        if ($link === null) {
            return $content;
        }
        $escaped = function_exists('esc_url') ? esc_url($link) : htmlspecialchars($link, ENT_QUOTES);
        return str_replace(['href=""', "href=''"], 'href="' . $escaped . '"', $content);
    }

    /** @param array<string, mixed> $attrs @return string|null */
    private static function permalinkOf(array $attrs): ?string
    {
        $id = (int) ($attrs['id'] ?? 0);
        if ($id <= 0 || !function_exists('get_permalink')) {
            return null;
        }
        $kind = (string) ($attrs['kind'] ?? 'post-type');
        $link = $kind === 'taxonomy' && function_exists('get_term_link') ? get_term_link($id) : get_permalink($id);
        return is_string($link) && $link !== '' ? $link : null;
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
        $link = self::permalinkOf($attrs);
        if ($link === null) {
            return $parsed;
        }
        $parsed['attrs']['url'] = $link;
        return $parsed;
    }
}
