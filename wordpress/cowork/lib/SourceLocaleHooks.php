<?php
/**
 * SourceLocaleHooks — a Polylang source edition written in another language speaks that language.
 *
 * Tracy writes a customer who chose ONE language that is not English into the archive's source edition at `/`
 * (TracyHQ/tch#1013, D10) and retires the other editions. Polylang still prints that edition's own locale — `en_US` —
 * in `<html lang>`, in the `hreflang` list and in the words WordPress loads for it, and setting `WPLANG` changes none
 * of that, because Polylang decides the front end's locale per edition (measured 08/10/2026 on a Tracy Business wp7
 * site written in Vietnamese: `<html lang="en-US">`).
 *
 * Option `tracy_source_locale` (a WordPress locale such as `vi` or `de_DE`, written through `content.update` and
 * taken back by its apply id) names the language the source edition is written in. While it is set, a front-end
 * request on the source edition reports that locale, and the language switcher (`pll_the_languages`: the standard
 * block, the widget, the template tag) names and flags that edition in the written language; every other edition, the
 * admin and a site without the option are untouched. Loaded on every request, so it is small and loads nothing else.
 */
final class SourceLocaleHooks
{
    public const OPTION = 'tracy_source_locale';

    /** WordPress locales: `vi`, `de_DE`, `pt_BR_formal`, `de_CH_informal`. */
    private const SHAPE = '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?$/';

    /** Hook in; without WordPress nothing happens. */
    public static function register(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }
        // Last, so Polylang's own choice of the edition's locale is what this replaces.
        add_filter('locale', [self::class, 'filterLocale'], PHP_INT_MAX);
        add_filter('pll_rel_hreflang_attributes', [self::class, 'filterHreflang'], PHP_INT_MAX);
        add_filter('pll_the_languages', [self::class, 'filterSwitcher'], PHP_INT_MAX);
    }

    /** The option, or '' when unset or not a locale. */
    private static function written(): string
    {
        if (!function_exists('get_option')) {
            return '';
        }
        $value = get_option(self::OPTION, '');
        return is_string($value) && preg_match(self::SHAPE, $value) ? $value : '';
    }

    /**
     * The locale a request speaks. Pure, so it is tested without WordPress.
     * @param string $locale what WordPress and Polylang chose
     * @param string $written the option
     * @param string|null $current Polylang's current edition (slug), null when Polylang is absent or has not chosen yet
     * @param string|null $source Polylang's default edition (slug), the archive's source edition
     * @param bool $admin an admin, ajax or REST request: the user's own locale
     */
    public static function localeFor(string $locale, string $written, ?string $current, ?string $source, bool $admin): string
    {
        if ($admin || $written === '' || !preg_match(self::SHAPE, $written)) {
            return $locale;
        }
        if ($current === null || $source === null || $current !== $source) {
            return $locale;
        }
        return $written;
    }

    /** `locale`: the source edition's front end speaks the language it is written in. */
    public static function filterLocale($locale)
    {
        if (!is_string($locale)) {
            return $locale;
        }
        $written = self::written();
        if ($written === '') {
            return $locale;
        }
        $admin = (function_exists('is_admin') && is_admin())
            || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('REST_REQUEST') && REST_REQUEST);
        $current = function_exists('pll_current_language') ? pll_current_language('slug') : null;
        $source = function_exists('pll_default_language') ? pll_default_language('slug') : null;
        return self::localeFor(
            $locale,
            $written,
            is_string($current) && $current !== '' ? $current : null,
            is_string($source) && $source !== '' ? $source : null,
            $admin
        );
    }

    /**
     * The hreflang list with the source edition's code replaced by the written language's. Pure.
     * @param mixed $hreflangs Polylang's `hreflang => url` list
     * @param string $written the option
     * @param string $sourceCode the source edition's hreflang code (`en-US`)
     */
    public static function hreflangFor($hreflangs, string $written, string $sourceCode)
    {
        if (!is_array($hreflangs) || $written === '' || $sourceCode === '' || !isset($hreflangs[$sourceCode])) {
            return $hreflangs;
        }
        // `de_DE_formal` is announced as `de-DE`: a variant has no hreflang code of its own.
        $code = self::codeOf($written);
        $out = [];
        foreach ($hreflangs as $key => $url) {
            $out[$key === $sourceCode ? $code : $key] = $url;
        }
        return $out;
    }

    /** `pll_rel_hreflang_attributes`: the source edition is announced in its written language. */
    public static function filterHreflang($hreflangs)
    {
        $written = self::written();
        if ($written === '' || !function_exists('PLL') || !is_object(PLL()) || !isset(PLL()->model)) {
            return $hreflangs;
        }
        $source = function_exists('pll_default_language') ? pll_default_language('slug') : null;
        $language = is_string($source) && method_exists(PLL()->model, 'get_language') ? PLL()->model->get_language($source) : null;
        $code = is_object($language) && isset($language->locale) ? str_replace('_', '-', (string) $language->locale) : '';
        return self::hreflangFor($hreflangs, $written, $code);
    }

    /** A locale's hreflang code: `de_DE_formal` is `de-DE`, a variant has no code of its own. Pure. */
    public static function codeOf(string $written): string
    {
        return implode('-', array_slice(explode('_', $written), 0, 2));
    }

    /**
     * The switcher's markup with the source edition named, coded and flagged in the language it is written in. Pure.
     *
     * A site written in Vietnamese showed "English" and a US flag in its switcher (measured 08/10/2026 on dev,
     * tamq-8w-bizwp): Polylang names the edition by its own language. Only the source edition's entry changes — its
     * `<li class="… lang-item-{slug} …">` of a list, or its `<option lang="{code}">` of a dropdown.
     * @param string $html Polylang's switcher markup (`pll_the_languages`)
     * @param string $sourceSlug the source edition's slug (`en`)
     * @param string $sourceName its name as Polylang prints it (`English`)
     * @param string $sourceCode its code in `lang` / `hreflang` (`en-US`)
     * @param string $name the written language's own name (`Tiếng Việt`); '' leaves the markup as it is
     * @param string $code the written language's code (`vi`)
     * @param string $flagSrc the written language's flag image source; '' takes the source edition's flag off instead
     */
    public static function switcherFor(
        string $html,
        string $sourceSlug,
        string $sourceName,
        string $sourceCode,
        string $name,
        string $code,
        string $flagSrc
    ): string {
        if ($name === '' || $code === '' || $sourceSlug === '' || $sourceName === '' || $sourceCode === '') {
            return $html;
        }
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $label = '>' . $esc($sourceName) . '<';
        $attrs = static fn(string $markup): string => str_replace(
            ['lang="' . $sourceCode . '"', 'hreflang="' . $sourceCode . '"'],
            ['lang="' . $esc($code) . '"', 'hreflang="' . $esc($code) . '"'],
            $markup
        );
        $item = '/<li class="[^"]*\blang-item-' . preg_quote($sourceSlug, '/') . '(?=[\s"])[^"]*">.*?<\/li>/s';
        $html = (string) preg_replace_callback($item, static function (array $m) use ($attrs, $label, $name, $esc, $flagSrc): string {
            $out = $attrs($m[0]);
            $out = $flagSrc === ''
                ? (string) preg_replace('/<img\b[^>]*>/', '', $out, 1)
                : (string) preg_replace('/(<img\b[^>]*\bsrc=")[^"]*(")/', '${1}' . str_replace(['\\', '$'], ['\\\\', '\\$'], $esc($flagSrc)) . '${2}', $out, 1);
            $at = strrpos($out, $label);
            return $at === false ? $out : substr_replace($out, '>' . $esc($name) . '<', $at, strlen($label));
        }, $html);
        $option = '/<option\b[^>]*\blang="' . preg_quote($sourceCode, '/') . '"[^>]*>' . preg_quote($esc($sourceName), '/') . '<\/option>/';
        return (string) preg_replace_callback($option, static function (array $m) use ($attrs, $label, $name, $esc): string {
            return str_replace($label, '>' . $esc($name) . '<', $attrs($m[0]));
        }, $html);
    }

    /**
     * The written locale's own name and flag code, from Polylang's list of the languages it knows (`vi` → Tiếng Việt, `vn`).
     * @return array{0: string, 1: string}
     */
    private static function known(string $written): array
    {
        if (!defined('POLYLANG_DIR')) {
            return ['', ''];
        }
        foreach (['/src/settings/languages.php', '/settings/languages.php'] as $file) {
            $path = POLYLANG_DIR . $file;
            if (is_readable($path)) {
                $list = include $path;
                $entry = is_array($list) && isset($list[$written]) && is_array($list[$written]) ? $list[$written] : [];
                return [(string) ($entry['name'] ?? ''), (string) ($entry['flag'] ?? '')];
            }
        }
        return ['', ''];
    }

    /** `pll_the_languages`: the switcher names the source edition in the language it is written in. */
    public static function filterSwitcher($html)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }
        $written = self::written();
        if ($written === '' || !function_exists('PLL') || !is_object(PLL()) || !isset(PLL()->model)) {
            return $html;
        }
        $source = function_exists('pll_default_language') ? pll_default_language('slug') : null;
        $language = is_string($source) && method_exists(PLL()->model, 'get_language') ? PLL()->model->get_language($source) : null;
        if (!is_object($language) || !isset($language->locale, $language->name) || $language->locale === $written) {
            return $html;
        }
        [$name, $flag] = self::known($written);
        $flagSrc = '';
        if ($flag !== '' && class_exists('PLL_Language') && method_exists('PLL_Language', 'get_flag_information')) {
            $info = PLL_Language::get_flag_information($flag);
            $flagSrc = is_array($info) ? (string) ($info['src'] ?? ($info['url'] ?? '')) : '';
        }
        return self::switcherFor(
            $html,
            (string) $source,
            (string) $language->name,
            str_replace('_', '-', (string) $language->locale),
            $name,
            self::codeOf($written),
            $flagSrc
        );
    }
}
