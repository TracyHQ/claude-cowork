<?php
/**
 * StringOverrides — words a theme or plugin prints through gettext (`__('Read More', 'astra')`),
 * replaced per locale. They are in no database row a content slot could name (a language file or
 * the code itself), so the one place to change them without touching somebody else's files is the
 * translation on its way out.
 *
 * One option, `claude_cowork_string_overrides`, JSON `{"<locale>":{"<domain>":{"<key>":"value"}}}`
 * where the key is the msgid, or `<context>\u0004<msgid>` for a string with a context (gettext's own
 * spelling). Written only through `content.contract {operation:'string'}`, under an apply_id, so
 * `apply.revert` takes it back; `content.update` and `content.delete` cannot reach it (Engine).
 *
 * A site with no override adds no filter at all: every page stays exactly what it was.
 */
declare(strict_types=1);

final class StringOverrides
{
    public const OPTION = 'claude_cowork_string_overrides';
    private const LOCALE = '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/D';
    private const DOMAIN = '/^[A-Za-z0-9_.-]{1,100}$/D';
    private const MAX_TEXT = 2000;
    /** A printf conversion, as PHP's sprintf reads one (`%%` included). */
    private const PLACEHOLDER = "/%(?:\\d+\\$)?[-+ 0#']*\\d*(?:\\.\\d+)?[bcdeEfFgGosuxX%]/";
    /** One tag, opening or closing, with its attributes. */
    private const TAG = '~<(/?)([a-zA-Z][a-zA-Z0-9]*)((?:\\s+[a-zA-Z_:][\\w:.-]*(?:\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s"\'>]+))?)*)\\s*(/?)>~';
    /** Attributes a browser follows as an address: only an allowlisted scheme or the original's own placeholder. */
    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'xlink:href', 'poster'];
    private const ATTR = '~([a-zA-Z_:][\\w:.-]*)(?:\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s"\'>]+)))?~';

    /** @var array<string,array<string,array<string,string>>> */
    private array $map;
    /** @var callable(): string */
    private $locale;
    /** @var array<string,true> every domain some locale overrides: other domains never ask the locale */
    private array $domains = [];

    private function __construct(array $map, callable $locale)
    {
        $this->map = $map;
        $this->locale = $locale;
        foreach ($map as $domains) {
            foreach (array_keys($domains) as $domain) {
                $this->domains[(string) $domain] = true;
            }
        }
    }

    /**
     * Hook in when the option holds at least one override, never otherwise. Late (priority 20), so
     * the override is what a visitor reads after the site's own translation ran.
     *
     * @param callable|null $locale the locale a string is asked in; WordPress's own when null
     */
    public static function register(?callable $locale = null): void
    {
        if (!function_exists('add_filter') || !function_exists('get_option')) {
            return;
        }
        $map = self::decode(get_option(self::OPTION, ''));
        if ($map === []) {
            return;
        }
        $overrides = new self($map, $locale ?? static function (): string {
            if (function_exists('determine_locale')) {
                return (string) determine_locale();
            }
            return function_exists('get_locale') ? (string) get_locale() : '';
        });
        add_filter('gettext', [$overrides, 'gettext'], 20, 3);
        add_filter('gettext_with_context', [$overrides, 'gettextWithContext'], 20, 4);
        // A plural string: each form is its own msgid, so the number picks which one is looked up.
        add_filter('ngettext', [$overrides, 'ngettext'], 20, 5);
        add_filter('ngettext_with_context', [$overrides, 'ngettextWithContext'], 20, 6);
    }

    /** `gettext`: the override of `$text` in `$domain` for the current locale, else the translation as it was. */
    public function gettext($translation, $text, $domain)
    {
        return $this->lookup($translation, (string) $text, (string) $domain);
    }

    /** `gettext_with_context`: the same, keyed `<context>\u0004<msgid>`. */
    public function gettextWithContext($translation, $text, $context, $domain)
    {
        return $this->lookup($translation, (string) $context . "\x04" . (string) $text, (string) $domain);
    }

    /** `ngettext`: the singular's override for one, the plural's otherwise. */
    public function ngettext($translation, $single, $plural, $number, $domain)
    {
        return $this->lookup($translation, (string) ((int) $number === 1 ? $single : $plural), (string) $domain);
    }

    /** `ngettext_with_context`: the same, keyed `<context>\u0004<form>`. */
    public function ngettextWithContext($translation, $single, $plural, $number, $context, $domain)
    {
        return $this->lookup($translation, (string) $context . "\x04" . (string) ((int) $number === 1 ? $single : $plural), (string) $domain);
    }

    private function lookup($translation, string $key, string $domain)
    {
        // Every string of every domain passes here: a domain nobody overrides costs one isset.
        if (!isset($this->domains[$domain])) {
            return $translation;
        }
        // Asked every time, not kept: switch_to_locale() moves it mid-request (an e-mail in the
        // reader's language). The domain check above already bounds how often.
        $value = $this->map[(string) ($this->locale)()][$domain][$key] ?? null;
        return is_string($value) ? $value : $translation;
    }

    /** The overrides an option value holds, `[]` for none or anything that is not the shape above. */
    public static function decode($raw): array
    {
        $map = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $locale => $domains) {
            foreach (is_array($domains) ? $domains : [] as $domain => $strings) {
                foreach (is_array($strings) ? $strings : [] as $key => $value) {
                    if (is_string($value)) {
                        $out[(string) $locale][(string) $domain][(string) $key] = $value;
                    }
                }
            }
        }
        return $out;
    }

    /** The option value for a map: '' when it holds nothing, so register() adds no filter. */
    public static function encode(array $map): string
    {
        return $map === [] ? '' : (string) json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The key a string is stored under: its msgid, or `<context>\u0004<msgid>`. */
    public static function key(string $msgid, ?string $context): string
    {
        return $context === null || $context === '' ? $msgid : $context . "\x04" . $msgid;
    }

    /**
     * The map with one override set, or removed when `$value` is ''; emptied levels are dropped.
     */
    public static function with(array $map, string $locale, string $domain, string $key, string $value): array
    {
        if ($value === '') {
            unset($map[$locale][$domain][$key]);
            if (($map[$locale][$domain] ?? null) === []) {
                unset($map[$locale][$domain]);
            }
            if (($map[$locale] ?? null) === []) {
                unset($map[$locale]);
            }
            return $map;
        }
        $map[$locale][$domain][$key] = $value;
        return $map;
    }

    /**
     * Why a request is not an override, or null when it is one: a locale and a text domain in their
     * shapes, a msgid, a context without gettext's separator, and a value without control characters
     * or markup the original string does not carry itself.
     */
    public static function refusal(array $p): ?string
    {
        foreach (['domain', 'msgid', 'locale', 'value'] as $field) {
            if (!isset($p[$field]) || !is_string($p[$field])) {
                return 'domain, msgid, locale and value are required strings (an empty value removes the override)';
            }
        }
        $context = $p['context'] ?? null;
        if ($context !== null && (!is_string($context) || strlen($context) > 200 || strpos($context, "\x04") !== false)) {
            return 'context must be a string of at most 200 characters';
        }
        if (!preg_match(self::LOCALE, $p['locale'])) {
            return 'locale must be a WordPress locale such as en_US';
        }
        if (!preg_match(self::DOMAIN, $p['domain'])) {
            return 'domain must be a text domain such as astra';
        }
        if ($p['msgid'] === '' || strlen($p['msgid']) > self::MAX_TEXT || strpos($p['msgid'], "\x04") !== false) {
            return 'msgid must be the original string, at most ' . self::MAX_TEXT . ' bytes';
        }
        if (strlen($p['value']) > self::MAX_TEXT || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $p['value'])) {
            return 'value must be text of at most ' . self::MAX_TEXT . ' bytes';
        }
        // Themes pass a string through printf/sprintf: a placeholder dropped, added or misspelled
        // (`Save 50% off` reads `% o` as one) throws on every page that prints it.
        if (self::placeholders($p['value']) !== self::placeholders($p['msgid']) || self::strayPercent($p['value'])) {
            return 'value must keep the placeholders of the original string (reordered %1$s/%2$s is fine), and write a literal percent as %%';
        }
        if (!self::markupAllowed($p['value'], $p['msgid'])) {
            return 'value may carry only the tags and attributes the original string itself uses, and no script link';
        }
        return null;
    }

    /** The printf conversions of a string, `%%` left out, sorted: what a translation must keep. */
    private static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $m);
        $out = array_values(array_filter($m[0], static fn(string $p): bool => $p !== '%%'));
        // A positional `%2$s` is the same argument whichever order a translation puts it in.
        sort($out);
        return $out;
    }

    /** Whether a `%` is left that no conversion accounts for (a lone `100%`). */
    private static function strayPercent(string $text): bool
    {
        return strpos((string) preg_replace(self::PLACEHOLDER, '', $text), '%') !== false;
    }

    /**
     * Whether every tag of `$value` is a tag `$msgid` uses, with only the attributes the msgid gives
     * that tag, no attribute value naming a script, and no angle bracket outside a tag.
     */
    private static function markupAllowed(string $value, string $msgid): bool
    {
        $allowed = [];
        preg_match_all(self::TAG, $msgid, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            $name = strtolower($tag[2]);
            $allowed[$name] = $allowed[$name] ?? [];
            preg_match_all(self::ATTR, $tag[3], $attrs, PREG_SET_ORDER);
            foreach ($attrs as $attr) {
                $allowed[$name][strtolower($attr[1])] = true;
            }
        }
        preg_match_all(self::PLACEHOLDER, $msgid, $own);
        preg_match_all(self::TAG, $value, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            $name = strtolower($tag[2]);
            if (!isset($allowed[$name])) {
                return false;
            }
            preg_match_all(self::ATTR, $tag[3], $attrs, PREG_SET_ORDER);
            foreach ($attrs as $attr) {
                $attrName = strtolower($attr[1]);
                $given = ($attr[2] ?? '') . ($attr[3] ?? '') . ($attr[4] ?? '');
                // No entity at all: a browser decodes `&#106avascript:` (no semicolon) and friends in
                // ways no check here should try to follow. The original's attributes are plain.
                if (!isset($allowed[$name][$attrName]) || strpos($given, '&') !== false) {
                    return false;
                }
                $given = (string) preg_replace('/[\x00-\x20]+/', '', $given);
                if (in_array($attrName, self::URL_ATTRS, true) && !in_array($given, $own[0], true)
                    && !preg_match('~^(https?:|mailto:|tel:|/(?![/\\\\])|#|\?)~i', $given)) {
                    return false;
                }
            }
        }
        return !preg_match('/[<>]/', (string) preg_replace(self::TAG, '', $value));
    }
}
