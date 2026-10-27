<?php
/**
 * PageLiterals — the English words a front-end layout prints as a literal, with no language key, said in the site's language.
 *
 * Measured 10/10/2026 on dev g60-ess-j-full (JA Essence j6, vi-VN; TracyHQ/tch#1013): the search form of Joomla's own
 * mod_finder carries `aria-label="search"` written into `modules/mod_finder/tmpl/default.php` (Joomla 6.1 core), and the JA
 * Essence video layout (`html/layouts/joomla/content/video_play.php`) prints `<span class="sr-only">Watch the video</span>`.
 * Neither goes through Text::_, so no language pack and no override key could reach them, on any template.
 *
 * Two halves, one rule for every template and every extension:
 *   - `inCode()`: the literals a visitor reads in a PHP layout — an `aria-label`, `title`, `placeholder` or `alt` attribute
 *     written as plain words, the words of a screen-reader span (`sr-only`, `visually-hidden`), and an element's own text of
 *     two words or more. `LanguageOverrides` offers each under `keyOf()` (`TRACY_LITERAL_WATCH_THE_VIDEO`, reason `literal`),
 *     so Tracy translates it like any other string and writes it into the language's override file (one revert takes it back).
 *   - `translate()`: the system plugin's output filter (onAfterRender, non-English site pages): an attribute
 *     of those four, or a text node, whose words are exactly a literal the override file holds a translation for, takes that
 *     translation, escaped. Nothing else of the page changes; script and style bodies are never read.
 * English editing uses `english()`: only source-provenance pairs from the native door are applied, site-wide to the
 * exact source text after entity decoding and whitespace normalization; case and punctuation remain significant.
 * No library dependency, like the rest of `lib/`.
 */
final class PageLiterals
{
    /** The prefix of every literal's override key. */
    public const PREFIX = 'TRACY_LITERAL_';
    /** Plain words: letters, digits, spaces and light punctuation, a letter first. */
    private const WORDS = "[A-Za-z][A-Za-z0-9 ,.:!?'-]{0,58}[A-Za-z0-9.!?:]|[A-Za-z]{2,}";
    /** The attributes a visitor (or a screen reader) reads. */
    private const ATTRIBUTES = 'aria-label|title|placeholder|alt';

    /** The override key of a literal, or null when it is not plain words. */
    public static function keyOf(string $literal): ?string
    {
        $words = trim((string) preg_replace('/\s+/', ' ', $literal));
        if (preg_match('/^(?:' . self::WORDS . ')$/D', $words) !== 1 || preg_match('/[A-Za-z]{2}/', $words) !== 1) return null;
        $key = trim((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper($words)), '_');
        return $key === '' ? null : substr(self::PREFIX . $key, 0, 120);
    }

    /** Stable exact-source metadata, separate from replacement keys. */
    public static function sourceKey(string $key): string
    {
        return 'TRACY_SOURCE_' . strtoupper(hash('sha256', $key));
    }

    /** Decode HTML entities and collapse whitespace, preserving case and punctuation. */
    public static function sourceText(string $raw): string
    {
        return trim((string) preg_replace('/\\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** English rules need server-recorded source provenance; missing or mismatched pairs are inert. */
    public static function englishRules(array $overrides): array
    {
        $out = [];
        foreach (self::fromOverrides($overrides) as $key => $value) {
            $source = $overrides[self::sourceKey($key)] ?? null;
            if (!is_string($source) || self::keyOf($source) !== $key || preg_match('/[<>\\r\\n\\x00]/', $source)) continue;
            $out[$key] = ['source' => self::sourceText($source), 'value' => $value];
        }
        return $out;
    }

    /** Site-wide exact-source English replacements, without reading PHP inventories at render time. */
    public static function english(string $html, array $overrides): string
    {
        $rules = self::englishRules($overrides);
        $words = [];
        foreach ($rules as $key => $rule) $words[$key] = $rule['value'];
        return self::translate($html, $words, $rules);
    }

    /**
     * The literals a visitor reads in one PHP layout's bytes, each once.
     *
     * @return list<string>
     */
    public static function inCode(string $bytes): array
    {
        $out = [];
        if (preg_match_all('/\b(?:' . self::ATTRIBUTES . ')="(' . self::WORDS . ')"/', $bytes, $m)) foreach ($m[1] as $w) $out[$w] = true;
        if (preg_match_all('~<span\b[^>]*\bclass="[^"<>]*\b(?:sr-only|visually-hidden)\b[^"<>]*"[^>]*>\s*(' . self::WORDS . ')\s*</span>~', $bytes, $m))
            foreach ($m[1] as $w) $out[$w] = true;
        // An element's own text of two words or more, right after an HTML tag (never the end of a PHP block or an arrow).
        if (preg_match_all('~(?<=["\'a-z0-9/])>\s*([A-Za-z][A-Za-z0-9,.:!?\'-]*(?: [A-Za-z0-9,.:!?\'-]+)+)\s*<~', $bytes, $m))
            foreach ($m[1] as $w) $out[$w] = true;
        return array_values(array_filter(array_keys($out), static fn(string $w): bool => self::keyOf($w) !== null));
    }

    /**
     * The literal translations an override file holds: key => words, only the keys `keyOf()` makes.
     *
     * @param array<string,string> $overrides a language's override file, parsed
     * @return array<string,string>
     */
    public static function fromOverrides(array $overrides): array
    {
        $out = [];
        foreach ($overrides as $key => $value)
            if (strpos((string) $key, self::PREFIX) === 0 && is_string($value) && trim($value) !== '' && preg_match('/[<>]/', $value) !== 1)
                $out[(string) $key] = trim($value);
        return $out;
    }

    /**
     * A page with every literal `$words` translates said in the site's language (see the class comment). Pure.
     *
     * @param array<string,string> $words override key => translation (`fromOverrides()`)
     */
    public static function translate(string $html, array $words, ?array $exact = null): string
    {
        if ($words === [] || $html === '') return $html;
        $said = static function (string $raw) use ($words, $exact): ?string {
            $key = self::keyOf(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($exact !== null && ($key === null || !isset($exact[$key]) || self::sourceText($raw) !== $exact[$key]['source'])) return null;
            return $key !== null && isset($words[$key]) ? htmlspecialchars($words[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        };
        // Script and style bodies (and comments) are carried through as they are.
        $parts = preg_split('~(<script\b.*?</script\s*>|<style\b.*?</style\s*>|<!--.*?-->)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return $html;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) continue;
            $part = (string) preg_replace_callback('/(\b(?:' . self::ATTRIBUTES . ')=")([^"<>]{1,80})(")/', static function (array $m) use ($said): string {
                $to = $said($m[2]);
                return $to === null ? $m[0] : $m[1] . $to . $m[3];
            }, $part);
            $part = (string) preg_replace_callback('/>(\s*)([^<>]{1,80}?)(\s*)</', static function (array $m) use ($said): string {
                $to = $said($m[2]);
                return $to === null ? $m[0] : '>' . $m[1] . $to . $m[3] . '<';
            }, $part);
            $parts[$i] = $part;
        }
        return implode('', $parts);
    }
}
