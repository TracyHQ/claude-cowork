<?php
/**
 * MultilingualHooks — keeps a retired edition out of the front end.
 *
 * `multilingual.retire` (Engine) drafts every row of an edition the customer did not ask for,
 * so its pages stop being served. Polylang still knows the language, though: it stays in the
 * switcher (linking to a home page with nothing behind it) and in the `hreflang` list. These
 * hooks take it out of both while the binding holds a retired set, and do nothing otherwise.
 *
 * Loaded on every request, so it is deliberately small and loads nothing else: no engine, no
 * contract class. It reads the binding option once, and only when a Polylang filter actually
 * fires — a page with no switcher and no hreflang pays no query.
 *
 * What is NOT changed: `pll_languages_list()` and everything built on it. The language still
 * exists; it is not live. That is what lets `multilingual.restore` bring it back untouched.
 *
 * Four filters, because Polylang (3.8.9, read on a live site 25/09/2026) offers no single one:
 *
 *   pll_the_languages_args     the raw list (`raw => 1`, what the Tracy theme and Polylang's own
 *   pll_the_language_link      navigation block use) returns BEFORE any output filter, so it is
 *                              thinned through its arguments: hide untranslated languages, and
 *                              give a retired one no link. A live language that had no link
 *                              gets the home Polylang would have given it, so nothing changes
 *                              for it.
 *   pll_the_languages          the html list and dropdown: retired items removed from the markup.
 *   pll_rel_hreflang_attributes  the `<link rel="alternate" hreflang>` list in `wp_head`.
 */
final class MultilingualHooks
{
    /** The binding option, spelled here because `QuickstartContract` is not loaded on a page view. */
    public const STORE_OPTION = '_tracy_content_contract';

    /** @var string[]|null retired Polylang slugs, read once per request */
    private static ?array $retired = null;
    /** Whether the last `pll_the_languages_args` pass turned `hide_if_no_translation` on itself. */
    private static bool $forcedHide = false;

    /**
     * Hook in. Safe to call wherever the plugin file is included: without WordPress (a lint, a
     * fresh process at activation time) there is nothing to hook and nothing happens.
     */
    public static function register(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('pll_the_languages_args', [self::class, 'filterArgs']);
        add_filter('pll_the_language_link', [self::class, 'filterLink'], 10, 3);
        add_filter('pll_the_languages', [self::class, 'filterSwitcher'], 10, 2);
        add_filter('pll_rel_hreflang_attributes', [self::class, 'filterHreflang']);
    }

    /** Forget what was read, so the next filter reads the binding again (tests, and a retire in the same request). */
    public static function reset(): void
    {
        self::$retired = null;
        self::$forcedHide = false;
    }

    /** @return string[] Polylang slugs of the retired editions; [] when no retire is on record */
    public static function retired(): array
    {
        if (self::$retired !== null) {
            return self::$retired;
        }
        self::$retired = [];
        if (!function_exists('get_option')) {
            return self::$retired;
        }
        $raw = get_option(self::STORE_OPTION, '');
        $binding = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $record = is_array($binding) && isset($binding['multilingual']) && is_array($binding['multilingual']) ? $binding['multilingual'] : null;
        $retired = is_array($record['retired'] ?? null) ? $record['retired'] : [];
        foreach ($retired as $slug) {
            if (is_string($slug) && $slug !== '') {
                self::$retired[] = $slug;
            }
        }
        return self::$retired;
    }

    /** `pll_the_languages_args`: hide untranslated languages, remembering whether that was our doing. */
    public static function filterArgs($args)
    {
        self::$forcedHide = false;
        if (!is_array($args) || self::retired() === []) {
            return $args;
        }
        if (empty($args['hide_if_no_translation'])) {
            $args['hide_if_no_translation'] = 1;
            self::$forcedHide = true;
        }
        return $args;
    }

    /**
     * `pll_the_language_link`: no link for a retired language, which the switcher then drops. A
     * live language whose page has no translation keeps the home Polylang substitutes itself,
     * so forcing `hide_if_no_translation` above changes nothing for it.
     */
    public static function filterLink($url, $slug, $locale = '')
    {
        $retired = self::retired();
        if ($retired === []) {
            return $url;
        }
        if (in_array((string) $slug, $retired, true)) {
            return null;
        }
        if ((is_string($url) && $url !== '') || !self::$forcedHide) {
            return $url;
        }
        return self::homeOf((string) $slug, $url);
    }

    /** The home URL of one language, through whatever this Polylang offers; the given value when nothing does. */
    private static function homeOf(string $slug, $fallback)
    {
        $pll = function_exists('PLL') ? PLL() : null;
        if (is_object($pll) && isset($pll->links, $pll->model) && is_object($pll->links) && method_exists($pll->links, 'get_home_url')) {
            $language = self::language($pll->model, $slug);
            if (is_object($language)) {
                $home = $pll->links->get_home_url($language);
                if (is_string($home) && $home !== '') {
                    return $home;
                }
            }
        }
        if (function_exists('pll_home_url')) {
            $home = pll_home_url($slug);
            if (is_string($home) && $home !== '') {
                return $home;
            }
        }
        return $fallback;
    }

    /** `pll_the_languages`: the html list or dropdown without the retired items (and the raw list, should one pass through). */
    public static function filterSwitcher($out, $args = [])
    {
        $retired = self::retired();
        if ($retired === []) {
            return $out;
        }
        if (is_array($out)) {
            foreach ($retired as $slug) {
                unset($out[$slug]);
            }
            return $out;
        }
        if (!is_string($out)) {
            return $out;
        }
        foreach ($retired as $slug) {
            $quoted = preg_quote($slug, '/');
            // `lang-item-<slug>` followed by a space or the closing quote: `lang-item-pt` must not
            // take `lang-item-pt-br` with it.
            $out = (string) preg_replace('/<li\b[^>]*\blang-item-' . $quoted . '(?=[\s"])[^>]*>.*?<\/li>\s*/s', '', $out);
            $out = (string) preg_replace('/<option\b[^>]*\bdata-lang="' . $quoted . '"[^>]*>.*?<\/option>\s*/s', '', $out);
        }
        return $out;
    }

    /**
     * `pll_rel_hreflang_attributes`: `{code: url}` without the retired languages. Polylang keys
     * the list by the W3C locale (`de-DE`) and shortens it to the language alone (`de`) when no
     * other language shares it, so a retired language is matched both ways — and a bare code
     * is dropped only when no live language answers to it.
     */
    public static function filterHreflang($hreflangs)
    {
        $retired = self::retired();
        if ($retired === [] || !is_array($hreflangs)) {
            return $hreflangs;
        }
        $model = function_exists('PLL') && is_object(PLL()) && isset(PLL()->model) ? PLL()->model : null;
        $exact = [];
        $retiredPrimary = [];
        foreach ($retired as $slug) {
            foreach (self::codesOf($model, $slug) as $code) {
                $exact[$code] = true;
                $retiredPrimary[explode('-', $code, 2)[0]] = true;
            }
        }
        $livePrimary = [];
        $all = function_exists('pll_languages_list') ? (array) pll_languages_list() : [];
        foreach ($all as $slug) {
            if (in_array((string) $slug, $retired, true)) {
                continue;
            }
            foreach (self::codesOf($model, (string) $slug) as $code) {
                $livePrimary[explode('-', $code, 2)[0]] = true;
            }
        }
        foreach (array_keys($hreflangs) as $code) {
            $key = strtolower((string) $code);
            if ($key === 'x-default') {
                continue;
            }
            $bare = strpos($key, '-') === false;
            if (isset($exact[$key]) || ($bare && isset($retiredPrimary[$key]) && !isset($livePrimary[$key]))) {
                unset($hreflangs[$code]);
            }
        }
        return $hreflangs;
    }

    /** @return string[] lower-case codes one language answers to: its slug, W3C locale and WordPress locale */
    private static function codesOf($model, string $slug): array
    {
        $codes = [strtolower($slug)];
        $language = self::language($model, $slug);
        if (is_object($language)) {
            foreach (['w3c', 'locale'] as $field) {
                $value = isset($language->$field) && is_string($language->$field) ? $language->$field : '';
                if ($value !== '') {
                    $codes[] = strtolower(str_replace('_', '-', $value));
                }
            }
        }
        return array_values(array_unique($codes));
    }

    /** One Polylang language object by slug, through either shape of the model; null when not found. */
    private static function language($model, string $slug)
    {
        if (!is_object($model)) {
            return null;
        }
        $row = null;
        if (isset($model->languages) && is_object($model->languages) && method_exists($model->languages, 'get')) {
            $row = $model->languages->get($slug);
        } elseif (method_exists($model, 'get_language')) {
            $row = $model->get_language($slug);
        }
        return is_object($row) ? $row : null;
    }
}
