<?php
/**
 * BlockWords — the visitor words blocks keep in their ATTRIBUTES, in the site's language.
 *
 * A block theme writes some words into a block's attributes, not its text: `moreText` of a post excerpt, `content` of a
 * read-more link, the label, placeholder and button of a search, the "By " prefix of an author name. No text pass
 * reaches them (they sit inside the `<!-- wp:… {…} -->` comment), so a vi site still read "Read more" on 9 pages and
 * "Search" in its header (measured 10/10/2026 on dev g59-ess-wp-full, JA Essence WP 1.0.12; English `moreText` is in
 * the DB of every published WordPress quickstart). Dates are the same class: a date block's own `format` ("M d, Y",
 * 25 files of JA Essence) fixes the English order whatever the site's date format is.
 *
 * At render (`render_block_data`), for the reviewed block/attribute pairs only (PAIRS):
 *   1. the per-site map wins: option `claude_cowork_block_words`, JSON `{"<locale>":{"<source words>":"<words>"}}`,
 *      written by Tracy through `content.contract {operation:'blockWords.set'}` under an apply id (apply.revert takes
 *      it back; content.update cannot reach it);
 *   2. else WordPress's own translation of the words (`translate($words, 'default')`: "Read more" → "Xem thêm");
 *   3. else the words stay.
 * Surrounding spaces are kept ("By " → "Bởi "). On a site whose locale is not English, a date block whose own format
 * puts the month word before the day loses that format, so the site's own date format (DateFormats) applies.
 * Nothing else is touched: a navigation label or a social link's name is content, translated where content is.
 */
declare(strict_types=1);

require_once __DIR__ . '/DateFormats.php';

final class BlockWords
{
    public const OPTION = 'claude_cowork_block_words';

    /** 🔒 The reviewed pairs: block => the attributes that hold words a visitor reads. Nothing outside it is read or written. */
    public const PAIRS = [
        'core/read-more' => ['content'],
        'core/post-excerpt' => ['moreText'],
        'core/search' => ['label', 'placeholder', 'buttonText'],
        'core/post-author-name' => ['prefix', 'suffix'],
        'core/post-author' => ['byline'],
        'core/post-terms' => ['prefix', 'suffix'],
        'core/post-navigation-link' => ['label'],
        'core/query-pagination-next' => ['label'],
        'core/query-pagination-previous' => ['label'],
        'core/comments-pagination-next' => ['label'],
        'core/comments-pagination-previous' => ['label'],
    ];
    /** Blocks whose own `format` attribute is a date format. */
    public const DATE_BLOCKS = ['core/post-date', 'core/comment-date'];

    private const LOCALE = '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?$/D';
    private const MAX_WORDS = 200;
    private const MAX_ENTRIES = 500;

    /** @var array<string,array<string,string>>|null the option, read once per request */
    private static ?array $map = null;

    /** Hook in; without WordPress nothing happens. Cheap: a block outside PAIRS and DATE_BLOCKS costs two isset. */
    public static function register(): void
    {
        if (function_exists('add_filter')) {
            add_filter('render_block_data', [self::class, 'filterBlock'], 10, 1);
        }
    }

    /** `render_block_data`: the parsed block with its words in the site's language. */
    public static function filterBlock($block)
    {
        if (!is_array($block) || !is_string($block['blockName'] ?? null) || !is_array($block['attrs'] ?? null)) {
            return $block;
        }
        $name = $block['blockName'];
        if (!isset(self::PAIRS[$name]) && !in_array($name, self::DATE_BLOCKS, true)) {
            return $block;
        }
        if (self::$map === null) {
            self::$map = function_exists('get_option') ? self::decode(get_option(self::OPTION, '')) : [];
        }
        $locale = function_exists('determine_locale') ? (string) determine_locale() : (function_exists('get_locale') ? (string) get_locale() : '');
        $core = static fn(string $words): string => function_exists('translate') ? (string) translate($words, 'default') : $words;
        return self::translateBlock($block, $locale, self::$map[$locale] ?? [], $core);
    }

    /**
     * One parsed block with its reviewed attributes in `$locale`. Pure, so it is tested without WordPress.
     *
     * @param array<string,string> $words the site's map for this locale
     * @param callable(string):string $core WordPress's own translation of the words in this locale
     */
    public static function translateBlock(array $block, string $locale, array $words, callable $core): array
    {
        $name = (string) ($block['blockName'] ?? '');
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        foreach (self::PAIRS[$name] ?? [] as $attribute) {
            $value = $attrs[$attribute] ?? null;
            if (!is_string($value) || trim($value) === '' || !preg_match('/\p{L}/u', $value)) {
                continue;
            }
            $attrs[$attribute] = self::wordsFor($value, $words, $core);
        }
        if (in_array($name, self::DATE_BLOCKS, true) && is_string($attrs['format'] ?? null)
            && $locale !== '' && strpos($locale, 'en') !== 0 && DateFormats::monthBeforeDay($attrs['format'])) {
            // The site's date format applies instead, which the language step set to the locale's own.
            unset($attrs['format']);
        }
        $block['attrs'] = $attrs;
        return $block;
    }

    /** The words for `$value`: the site's map (exact, then trimmed), else WordPress's translation, spaces kept. */
    private static function wordsFor(string $value, array $words, callable $core): string
    {
        if (isset($words[$value]) && $words[$value] !== '') {
            return $words[$value];
        }
        $trimmed = trim($value);
        $lead = substr($value, 0, strlen($value) - strlen(ltrim($value)));
        $tail = substr($value, strlen(rtrim($value)));
        if (isset($words[$trimmed]) && $words[$trimmed] !== '') {
            return $lead . $words[$trimmed] . $tail;
        }
        $translated = (string) $core($trimmed);
        return $translated !== '' && $translated !== $trimmed ? $lead . $translated . $tail : $value;
    }

    /**
     * The words of the reviewed pairs a site's blocks hold, from block markup: per source words, where (`pairs`,
     * "core/read-more content") and how often.
     *
     * @param iterable<string> $markups post contents and theme files
     * @return array<string,array{source:string,pairs:list<string>,count:int}>
     */
    public static function found(iterable $markups): array
    {
        $out = [];
        foreach ($markups as $markup) {
            if (!is_string($markup) || strpos($markup, '<!-- wp:') === false) {
                continue;
            }
            preg_match_all('~<!--\s+wp:([a-z0-9-]+(?:/[a-z0-9-]+)?)\s+(\{.*?\})\s+/?-->~s', $markup, $m, PREG_SET_ORDER);
            foreach ($m as [, $name, $json]) {
                $name = strpos($name, '/') === false ? 'core/' . $name : $name;
                if (!isset(self::PAIRS[$name])) {
                    continue;
                }
                $attrs = json_decode($json, true);
                if (!is_array($attrs)) {
                    continue;
                }
                foreach (self::PAIRS[$name] as $attribute) {
                    $value = $attrs[$attribute] ?? null;
                    if (!is_string($value) || trim($value) === '' || !preg_match('/\p{L}/u', $value)) {
                        continue;
                    }
                    $source = trim($value);
                    $out[$source] ??= ['source' => $source, 'pairs' => [], 'count' => 0];
                    $out[$source]['count']++;
                    $pair = $name . ' ' . $attribute;
                    if (!in_array($pair, $out[$source]['pairs'], true)) {
                        $out[$source]['pairs'][] = $pair;
                    }
                }
            }
        }
        uasort($out, static fn(array $a, array $b): int => [$b['count'], $a['source']] <=> [$a['count'], $b['source']]);
        return $out;
    }

    /**
     * Why a `blockWords.set` cannot be written, or null: a WordPress locale, and `words` an object of at most
     * MAX_ENTRIES source words to plain words ('' removes one), each at most MAX_WORDS characters, no markup.
     */
    public static function refusal(array $p): ?string
    {
        if (!is_string($p['locale'] ?? null) || !preg_match(self::LOCALE, $p['locale'])) {
            return 'locale required: a WordPress locale such as vi or de_DE';
        }
        $words = $p['words'] ?? null;
        if (!is_array($words) || $words === [] || array_keys($words) === range(0, count($words) - 1)) {
            return 'words required: an object of the source words to their words in the locale ("" removes one)';
        }
        if (count($words) > self::MAX_ENTRIES) {
            return 'at most ' . self::MAX_ENTRIES . ' words in one set';
        }
        foreach ($words as $source => $value) {
            $source = (string) $source;
            if (trim($source) === '' || mb_strlen($source) > self::MAX_WORDS || !preg_match('/\p{L}/u', $source)) {
                return 'a source is the words a block holds, 1 to ' . self::MAX_WORDS . ' characters: ' . substr($source, 0, 60);
            }
            if (!is_string($value) || mb_strlen($value) > self::MAX_WORDS || preg_match('/[<>\r\n\x00]/', $value)) {
                return 'the words for "' . substr($source, 0, 60) . '" are one line of plain text of at most ' . self::MAX_WORDS . ' characters';
            }
        }
        return null;
    }

    /** `$map` with `$words` set for `$locale`: '' removes an entry, an empty locale goes. */
    public static function with(array $map, string $locale, array $words): array
    {
        $mine = $map[$locale] ?? [];
        foreach ($words as $source => $value) {
            if ($value === '') {
                unset($mine[(string) $source]);
            } else {
                $mine[(string) $source] = $value;
            }
        }
        if ($mine === []) {
            unset($map[$locale]);
        } else {
            ksort($mine);
            $map[$locale] = $mine;
        }
        ksort($map);
        return $map;
    }

    /** The map an option value holds; `[]` for none or anything that is not the shape. */
    public static function decode($raw): array
    {
        $map = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $locale => $words) {
            if (!is_string($locale) || !preg_match(self::LOCALE, $locale) || !is_array($words)) {
                continue;
            }
            foreach ($words as $source => $value) {
                if (is_string($value) && $value !== '' && !preg_match('/[<>]/', $value)) {
                    $out[$locale][(string) $source] = $value;
                }
            }
        }
        return $out;
    }

    public static function encode(array $map): string
    {
        return (string) json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Forget the map read this request (a set, or a test). */
    public static function forget(): void
    {
        self::$map = null;
    }

    /**
     * WordPress's own translation of `$words` in `$locale` (the core translation, read under switch_to_locale), or null
     * when WordPress is not loaded, the locale is not installed, or it has none.
     */
    public static function core(string $locale, string $words): ?string
    {
        if (!function_exists('switch_to_locale') || !function_exists('translate') || !function_exists('restore_previous_locale')) {
            return null;
        }
        $current = function_exists('determine_locale') ? (string) determine_locale() : '';
        $switched = $current !== $locale && (bool) switch_to_locale($locale);
        if ($current !== $locale && !$switched) {
            return null;
        }
        try {
            $translated = (string) translate($words, 'default');
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
        return $translated !== '' && $translated !== $words ? $translated : null;
    }
}
