<?php
/**
 * RetiredSitemaps — a retired edition leaves the sitemap.
 *
 * Polylang splits every sitemap of WordPress core per language and lists a language's sitemaps whatever it holds: its
 * users sitemap has no subtype, so it is listed for every language Polylang knows. After `multilingual.retire` took a
 * Tracy Business wp7 1.3.4 stand down to its source edition, `wp-sitemap.xml` still named 41 users sitemaps (and, until
 * term counts followed the raw moves, 41 category sitemaps), each under a language prefix nobody can visit any more
 * (measured 09/10/2026).
 *
 * While the binding holds a retired set (`MultilingualHooks::retired()`), every provider is wrapped
 * (`RetiredSitemapsProvider`): an index entry named for a retired language (`<subtype>---pll-sep---<slug>`) is left out,
 * and a sitemap page asked for in a retired language lists nothing, which WordPress answers with 404. With no retired
 * set nothing is wrapped. The language itself is not changed, so `multilingual.restore` brings its sitemaps back.
 */
final class RetiredSitemaps
{
    /** How Polylang names a language's sitemap (`PLL_Multilingual_Sitemaps_Provider::SEPARATOR`, 3.8.9). */
    public const SEPARATOR = '---pll-sep---';

    /** Hook in; without WordPress nothing happens. After Polylang (10), so its per-language provider is what is wrapped. */
    public static function register(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('wp_sitemaps_add_provider', [self::class, 'wrap'], 20, 2);
    }

    /** `wp_sitemaps_add_provider`: the provider, wrapped while a retired set is on record. */
    public static function wrap($provider, $name = '')
    {
        if (!class_exists('WP_Sitemaps_Provider') || !$provider instanceof WP_Sitemaps_Provider || MultilingualHooks::retired() === []) {
            return $provider;
        }
        require_once __DIR__ . '/RetiredSitemapsProvider.php';
        return $provider instanceof RetiredSitemapsProvider ? $provider : new RetiredSitemapsProvider($provider);
    }

    /**
     * The index entries without those of a retired language. Pure.
     * @param array<int,array<string,mixed>> $types `get_sitemap_type_data()`: [{name, pages}, …]
     * @param string[] $retired Polylang slugs
     * @return array<int,array<string,mixed>>
     */
    public static function keptTypes(array $types, array $retired): array
    {
        $out = [];
        foreach ($types as $type) {
            $name = is_array($type) ? (string) ($type['name'] ?? '') : '';
            if (self::retiredName($name, $retired)) {
                continue;
            }
            $out[] = $type;
        }
        return $out;
    }

    /** Whether a sitemap name is a retired language's (`page---pll-sep---af`, `---pll-sep---af`). Pure. */
    public static function retiredName(string $name, array $retired): bool
    {
        $at = strrpos($name, self::SEPARATOR);
        if ($at === false) {
            return false;
        }
        return in_array(substr($name, $at + strlen(self::SEPARATOR)), $retired, true);
    }

    /** Whether the request is in a retired language (Polylang's current language), so its sitemap page lists nothing. */
    public static function requestRetired(): bool
    {
        $current = function_exists('pll_current_language') ? pll_current_language('slug') : '';
        return is_string($current) && $current !== '' && in_array($current, MultilingualHooks::retired(), true);
    }
}
