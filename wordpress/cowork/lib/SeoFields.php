<?php
/**
 * SeoFields — where a page's search title and meta description live, and who prints them.
 *
 * WordPress itself has neither: the `<title>` is built from the post title and the site name, and no
 * meta description is printed at all. An SEO plugin adds both, each in its own post meta. A site with
 * none (every Tracy quickstart site) had no place for either, so a write of `seo:{title}` landed
 * nowhere, silently, and the only way left to change what Google shows was to rename the page — which
 * also renames its breadcrumb and, on some pages, its heading (measured 05/10/2026 on a Tracy Business
 * wp7 site: the customer asked to change only the search title).
 *
 * So, in this order:
 *  - an SEO plugin whose post meta it reads runs (Yoast SEO, Rank Math): a write goes to its keys,
 *    whether or not this post held one before, and this plugin prints nothing of its own;
 *  - another SEO plugin runs (All in One SEO 4 keeps its fields in its own table and only copies them
 *    to `_aioseo_*` meta for other plugins; SEOPress, The SEO Framework, Slim SEO, Squirrly, SmartCrawl,
 *    SureRank, Jetpack with its SEO Tools module on): a write is refused by the plugin's name, because
 *    nothing this door can write would be printed, and this plugin prints nothing of its own;
 *  - none runs: the values are this plugin's own post meta, `_claude_cowork_seo_title` (the whole
 *    `<title>`, through `pre_get_document_title`) and `_claude_cowork_seo_description` (a
 *    `<meta name="description">` added to `wp_head` unless something there already printed one), both
 *    stored as plain one-line text. Page 2 and on of a listing keep WordPress's own title. Polylang is
 *    told not to copy either key into a new translation: they hold one language's words.
 *
 * No Tracy theme prints a meta description (checked 05/10/2026: wp-tracy-business, tracy, tracy-base
 * and the wp-ja-* themes print none; wp-ja-kinetic sets its own `<title>` from a meta of its own, which
 * a value here overrides). The head is still checked rather than trusted, for any other theme.
 *
 * Plain PHP with every WordPress function guarded, like StringOverrides: the front-end half is loaded
 * on every request and does work only on a page whose post holds one of the two values.
 */
declare(strict_types=1);

final class SeoFields
{
    /** The whole `<title>` of one post's page, on a site with no SEO plugin. Protected meta (underscore). */
    public const TITLE_KEY = '_claude_cowork_seo_title';
    /** That page's meta description, on a site with no SEO plugin. */
    public const DESCRIPTION_KEY = '_claude_cowork_seo_description';

    /**
     * The SEO plugins this file knows, in the order they are looked for: the constant each defines
     * when it loads, the plugin folders it is installed under, and the post meta it keeps a post's
     * title and description in. `writes` says whether that meta is what the plugin prints (so a write
     * there shows); `read` keys are still reported by a read when it does not.
     */
    private const PLUGINS = [
        'yoast' => [
            'name' => 'Yoast SEO',
            'constants' => ['WPSEO_VERSION'],
            'folders' => ['wordpress-seo', 'wordpress-seo-premium'],
            'title' => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'writes' => true,
        ],
        'rank-math' => [
            'name' => 'Rank Math',
            'constants' => ['RANK_MATH_VERSION'],
            'folders' => ['seo-by-rank-math', 'seo-by-rank-math-pro'],
            'title' => 'rank_math_title',
            'description' => 'rank_math_description',
            'writes' => true,
        ],
        'aioseo' => [
            'name' => 'All in One SEO',
            'constants' => ['AIOSEO_VERSION'],
            'folders' => ['all-in-one-seo-pack', 'all-in-one-seo-pack-pro'],
            'title' => '_aioseo_title',
            'description' => '_aioseo_description',
            'writes' => false,
        ],
        'seopress' => [
            'name' => 'SEOPress',
            'constants' => ['SEOPRESS_VERSION'],
            'folders' => ['wp-seopress', 'wp-seopress-pro'],
            'title' => null,
            'description' => null,
            'writes' => false,
        ],
        'the-seo-framework' => [
            'name' => 'The SEO Framework',
            'constants' => ['THE_SEO_FRAMEWORK_VERSION'],
            'folders' => ['autodescription'],
            'title' => null,
            'description' => null,
            'writes' => false,
        ],
        'slim-seo' => [
            'name' => 'Slim SEO',
            'constants' => ['SLIM_SEO_VER'],
            'folders' => ['slim-seo'],
            'title' => null,
            'description' => null,
            'writes' => false,
        ],
        'squirrly' => [
            'name' => 'Squirrly SEO',
            'constants' => ['SQ_VERSION'],
            'folders' => ['squirrly-seo'],
            'title' => null,
            'description' => null,
            'writes' => false,
        ],
        'smartcrawl' => [
            'name' => 'SmartCrawl',
            'constants' => ['SMARTCRAWL_VERSION'],
            'folders' => ['smartcrawl-seo', 'wpmu-dev-seo'],
            'title' => null,
            'description' => null,
            'writes' => false,
        ],
        'surerank' => [
            'name' => 'SureRank',
            'constants' => ['SURERANK_VERSION'],
            'folders' => ['surerank'],
            'title' => null,
            'description' => null,
            'writes' => false,
        ],
        // Jetpack prints a title and a description only while its SEO Tools module is on (`module`).
        'jetpack-seo' => [
            'name' => 'Jetpack SEO',
            'constants' => ['JETPACK__VERSION'],
            'folders' => ['jetpack'],
            'module' => 'seo-tools',
            'title' => null,
            'description' => null,
            'writes' => false,
        ],
    ];

    /** The fields a write may carry, in the order a refusal names them. */
    private const FIELDS = ['title', 'description'];

    /** The description wp_head will print, while its buffer is open; null when none is pending. */
    private static ?string $pending = null;
    /** The output-buffer level openHead() opened, so closeHead() never takes a buffer that is not its own. */
    private static int $level = 0;

    /**
     * The SEO plugin this site runs, or null. Found by the constant it defines once loaded (a renamed
     * folder or a must-use copy still defines it) or by its folder in the active plugin list (and the
     * network's, on a multisite).
     *
     * @return array{id:string,name:string,title:?string,description:?string,writes:bool}|null
     */
    public static function running(): ?array
    {
        $active = [];
        if (function_exists('get_option')) {
            $list = get_option('active_plugins', []);
            if (is_array($list)) {
                $active = array_values(array_filter($list, 'is_string'));
            }
        }
        if (function_exists('is_multisite') && function_exists('get_site_option') && is_multisite()) {
            $network = get_site_option('active_sitewide_plugins', []);
            if (is_array($network)) {
                $active = array_merge($active, array_map('strval', array_keys($network)));
            }
        }
        $folders = [];
        foreach ($active as $basename) {
            $folders[strtok($basename, '/')] = true;
        }
        foreach (self::PLUGINS as $id => $plugin) {
            $found = false;
            foreach ($plugin['constants'] as $constant) {
                $found = $found || defined($constant);
            }
            foreach ($plugin['folders'] as $folder) {
                $found = $found || isset($folders[$folder]);
            }
            if ($found && isset($plugin['module']) && !self::jetpackModule($plugin['module'])) {
                $found = false;
            }
            if ($found) {
                return ['id' => $id, 'name' => $plugin['name'], 'title' => $plugin['title'],
                    'description' => $plugin['description'], 'writes' => $plugin['writes']];
            }
        }
        return null;
    }

    /**
     * Where each field of one `seo:{title, description}` write goes on this site: field => meta key.
     * Refused (a RuntimeException naming why, before anything is written) when the value is not that
     * shape, or when the SEO plugin that runs keeps its fields where this door cannot write them.
     *
     * @param mixed $seo
     * @return array<string,string>
     */
    public static function plan($seo): array
    {
        if (!is_array($seo) || ($seo !== [] && array_keys($seo) === range(0, count($seo) - 1))) {
            throw new RuntimeException('seo takes an object {title, description}; given ' . gettype($seo) . ', so nothing was written');
        }
        if ($seo === []) {
            throw new RuntimeException('seo names no field: give ' . implode(', ', self::FIELDS) . ' or both, or leave seo out; nothing was written');
        }
        $unknown = array_diff(array_map('strval', array_keys($seo)), self::FIELDS);
        if ($unknown !== []) {
            throw new RuntimeException('seo takes ' . implode(' and ', self::FIELDS) . '; given ' . implode(', ', $unknown) . ', which this site has no place for, so nothing was written');
        }
        foreach ($seo as $field => $value) {
            if (!is_string($value)) {
                throw new RuntimeException("seo.{$field} must be text; given " . gettype($value) . ', so nothing was written');
            }
        }
        $keys = self::keys();
        $plan = [];
        foreach (array_keys($seo) as $field) {
            $plan[(string) $field] = $keys[(string) $field];
        }
        return $plan;
    }

    /**
     * The refusal of a post write that carries `seo` and no column of the row: the row's undo would
     * not reach a meta, so the value is written as the meta it is, which `apply.revert` takes back on
     * its own. Names this site's key for each field asked for.
     *
     * @param mixed $seo
     */
    public static function aloneMessage(int $postId, $seo): string
    {
        $plan = self::plan($seo);
        $calls = [];
        foreach ($plan as $field => $key) {
            $calls[] = 'content.update {kind:"postmeta", id:' . ($postId > 0 ? $postId : '<post id>') . ', key:"' . $key . '", fields:{value:"<' . $field . '>"}}';
        }
        return 'seo alone is not a post write: the post row\'s undo would not take it back. Write '
            . ($calls === [] ? 'each field' : implode(' and ', $calls))
            . ' instead, each undone on its own; nothing was written';
    }

    /**
     * The keys one `seo` write would change on this post, field => key: `plan`, less every field whose
     * value (as it would be stored) is the one the post already holds. Empty when the write changes
     * nothing, so nothing is written and no undo step stands for it.
     *
     * @param mixed $seo
     * @return array<string,string>
     */
    public static function changes(int $postId, $seo): array
    {
        $changes = [];
        foreach (self::plan($seo) as $field => $key) {
            $now = $postId > 0 && function_exists('metadata_exists') && metadata_exists('post', $postId, $key)
                ? get_post_meta($postId, $key, true)
                : null;
            if ($now !== self::stored($key, (string) $seo[$field])) {
                $changes[$field] = $key;
            }
        }
        return $changes;
    }

    /** The value as it is stored under that key: this plugin's own keys hold plain one-line text. */
    public static function stored(string $key, string $value): string
    {
        return $key === self::TITLE_KEY || $key === self::DESCRIPTION_KEY ? self::plainText($value) : $value;
    }

    /**
     * The value a `content.update {kind:"postmeta"}` of this key stores. Any other key passes through
     * untouched. This plugin's own two keys take text only (stored as plain one-line text), and only on
     * a site where no SEO plugin runs: with one, nothing would print them, so the refusal names the key
     * that would be.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function ownKeyValue(string $key, $value)
    {
        if ($key !== self::TITLE_KEY && $key !== self::DESCRIPTION_KEY) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException("{$key} must be text; given " . gettype($value) . ', so nothing was written');
        }
        $running = self::running();
        if ($running !== null) {
            $field = $key === self::TITLE_KEY ? 'title' : 'description';
            $theirs = $running['writes'] ? $running[$field] : null;
            throw new RuntimeException("this site runs {$running['name']}, which prints the search {$field} instead of {$key}"
                . ($theirs !== null ? ": write {$theirs}" : ', from fields this door does not write')
                . '; nothing was written');
        }
        return self::plainText($value);
    }

    /**
     * `pll_copy_post_metas`: the meta keys Polylang copies into a new translation (and keeps in sync),
     * less this plugin's two. They hold one language's words; a translation that inherited them would
     * show the source language's search title under another language's page.
     *
     * @param mixed $keys
     * @return mixed
     */
    public static function notCopied($keys)
    {
        if (!is_array($keys)) {
            return $keys;
        }
        return array_values(array_diff($keys, [self::TITLE_KEY, self::DESCRIPTION_KEY]));
    }

    /**
     * One post's search title and description as the site holds them for its page: the running SEO
     * plugin's keys, or with none this plugin's own. Empty fields are left out.
     *
     * @return array<string,string>
     */
    public static function read(int $postId): array
    {
        $running = self::running();
        $keys = $running === null
            ? ['title' => self::TITLE_KEY, 'description' => self::DESCRIPTION_KEY]
            : ['title' => $running['title'], 'description' => $running['description']];
        $seo = [];
        foreach ($keys as $field => $key) {
            if ($key === null || !function_exists('get_post_meta')) {
                continue;
            }
            $value = (string) get_post_meta($postId, $key, true);
            if ($value !== '') {
                $seo[$field] = $value;
            }
        }
        return $seo;
    }

    /** Hook the front end: the title filter and the two ends of wp_head. Cheap until a page holds a value. */
    public static function register(): void
    {
        if (!function_exists('add_filter') || !function_exists('add_action')) {
            return;
        }
        // Late, so a value written for this page wins over a theme's own title (wp-ja-kinetic sets one
        // at the default priority); SEO plugins are left alone because none runs when this prints.
        add_filter('pre_get_document_title', [self::class, 'documentTitle'], 20);
        add_filter('pll_copy_post_metas', [self::class, 'notCopied']);
        add_action('wp_head', [self::class, 'openHead'], PHP_INT_MIN);
        add_action('wp_head', [self::class, 'closeHead'], PHP_INT_MAX);
    }

    /**
     * `pre_get_document_title`: the page's own search title as the whole `<title>`, escaped (WordPress
     * prints this filter's answer as it is). Anything else is passed on untouched.
     *
     * @param mixed $title
     * @return mixed
     */
    public static function documentTitle($title)
    {
        $own = self::ownValue(self::TITLE_KEY);
        return $own === null ? $title : self::html($own);
    }

    /** First on wp_head: when this page has a description to print, hold the head to see what is printed. */
    public static function openHead(): void
    {
        self::$pending = self::ownValue(self::DESCRIPTION_KEY);
        if (self::$pending === null) {
            return;
        }
        ob_start();
        self::$level = ob_get_level();
    }

    /**
     * Last on wp_head: the head as printed, plus the description unless something printed one. A buffer
     * someone opened inside wp_head and left open is not ours to close: the tag is printed into it.
     */
    public static function closeHead(): void
    {
        $description = self::$pending;
        self::$pending = null;
        if ($description === null) {
            return;
        }
        if (ob_get_level() === self::$level) {
            echo self::withDescription((string) ob_get_clean(), $description);
            return;
        }
        echo self::tag($description);
    }

    /** The head with a meta description added, unless it already has one. */
    public static function withDescription(string $head, string $description): string
    {
        if (preg_match('/<meta\s[^>]*\bname\s*=\s*(["\']?)description\1[\s\/>]/i', $head) === 1) {
            return $head;
        }
        return $head . self::tag($description);
    }

    /**
     * Where a write goes on this site, field => key: the running plugin's keys, or this plugin's own.
     * Refused when the running plugin's printed values are nowhere this door can write.
     *
     * @return array<string,string>
     */
    private static function keys(): array
    {
        $running = self::running();
        if ($running === null) {
            return ['title' => self::TITLE_KEY, 'description' => self::DESCRIPTION_KEY];
        }
        if (!$running['writes'] || $running['title'] === null || $running['description'] === null) {
            throw new RuntimeException("this site runs {$running['name']}, which keeps a page's search title and description where this door cannot write them, so nothing was written; "
                . "they are changed in {$running['name']}'s own settings for the page");
        }
        return ['title' => $running['title'], 'description' => $running['description']];
    }

    /**
     * This plugin's own value of the page being rendered, trimmed; null with an SEO plugin, no post, none,
     * or on page 2 and on of a listing (a posts page's own title would name every page of it alike).
     */
    private static function ownValue(string $key): ?string
    {
        if (!function_exists('get_queried_object') || !function_exists('get_post_meta')) {
            return null;
        }
        if (function_exists('is_paged') && is_paged()) {
            return null;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post || (int) $post->ID <= 0) {
            return null;
        }
        $value = trim((string) get_post_meta((int) $post->ID, $key, true));
        if ($value === '' || self::running() !== null) {
            return null;
        }
        return $value;
    }

    /** Whether Jetpack has this module on: its own answer when loaded, else the option it keeps them in. */
    private static function jetpackModule(string $module): bool
    {
        if (class_exists('Jetpack') && method_exists('Jetpack', 'is_module_active')) {
            return (bool) call_user_func(['Jetpack', 'is_module_active'], $module);
        }
        $active = function_exists('get_option') ? get_option('jetpack_active_modules', []) : [];
        return is_array($active) && in_array($module, $active, true);
    }

    /**
     * Plain one-line text, as `sanitize_text_field` stores it: no tags (a script or style with its
     * words), no line breaks or runs of spaces. Done by hand only where WordPress is absent (a test).
     */
    private static function plainText(string $text): string
    {
        if (function_exists('sanitize_text_field')) {
            return (string) sanitize_text_field($text);
        }
        $text = strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text));
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private static function tag(string $description): string
    {
        return '<meta name="description" content="' . self::attr($description) . '" />' . "\n";
    }

    private static function html(string $text): string
    {
        return function_exists('esc_html') ? (string) esc_html($text) : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private static function attr(string $text): string
    {
        return function_exists('esc_attr') ? (string) esc_attr($text) : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
