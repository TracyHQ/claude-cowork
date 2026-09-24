<?php
/**
 * Just enough WordPress to run the real writers outside WordPress.
 *
 * The engine is tested against fakes, which proves the apply/undo bookkeeping. This file exists to
 * test the other half — the rules that decide what a caller may write at all: which post fields
 * are accepted, which options are refused, what a meta needs. Those rules live in
 * Claude_Cowork_Site_Writer and are worth a test precisely because getting one wrong is a change
 * that lands on a customer's live site.
 *
 * Only what the tests call is defined here. This is a stub, not an emulator: nothing here should
 * grow into a second WordPress, and a test that needs more of one is a test that belongs on a real
 * install.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wp/');
define('ARRAY_A', 'ARRAY_A');

/** What the stubs remember, so a test can look at the site afterwards. */
final class WP_Fake
{
    /** @var array<int,array<string,mixed>> */
    public static array $posts = [];
    /** @var array<string,mixed> "postId:key" => value */
    public static array $meta = [];
    /** @var array<string,mixed> */
    public static array $options = [];
    /** @var array<int,int> */
    public static array $cleaned = [];
    public static int $nextId = 500;
    /** @var array<string,string[]> "postId:taxonomy" => term names */
    public static array $terms = [];
    /** Stands in for KSES: a callable applied to post_content on the way in, or null for verbatim. */
    public static $contentFilter = null;
    /** Where kses_remove_filters() parks the filter while one write goes through. */
    public static $ksesParked = null;
    /** @var array<int,array<string,mixed>> term_id => row */
    public static array $termRows = [];
    /** @var array<int,int> menu item id => menu term id */
    public static array $menuOf = [];
    /** @var int[] */
    public static array $trashed = [];
    public static int $nextTermId = 900;
    /** The active theme, which is what a template-part override has to be filed under. */
    public static string $stylesheet = 'tracy';
    /** Whether Polylang is "installed": PLL() answers null when it is not. */
    public static bool $polylang = false;
    /** @var array<string,array<string,mixed>> slug => Polylang language row */
    public static array $languages = [];
    /** @var array<int,string> post id => Polylang slug */
    public static array $postLanguage = [];
    /** @var array<int,array<string,int>> post id => [Polylang slug => id of its copy in that language] */
    public static array $translations = [];
    /** How many times the rewrite rules were flushed. */
    public static int $flushed = 0;

    public static function reset(): void
    {
        self::$polylang = false;
        self::$languages = [];
        self::$postLanguage = [];
        self::$translations = [];
        self::$flushed = 0;
        if (class_exists('WP_Fake_PLL_Languages')) {
            WP_Fake_PLL_Languages::$updates = [];
        }
        if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof WP_Fake_Db) {
            $GLOBALS['wpdb']->queries = [];
            $GLOBALS['wpdb']->lockAnswer = 1;
        }
        self::$posts = [];
        self::$meta = [];
        self::$options = [];
        self::$cleaned = [];
        self::$terms = [];
        self::$termRows = [];
        self::$menuOf = [];
        self::$trashed = [];
        self::$nextTermId = 900;
        self::$stylesheet = 'tracy';
        self::$nextId = 500;
        self::$contentFilter = null;
        self::$ksesParked = null;
    }
}

final class WP_Error
{
    public function __construct(private string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

/** Identity: escaping is WordPress's business, and the writers only have to remember to ask. */
function wp_slash($value)
{
    return $value;
}

function get_post(int $id, string $output = '')
{
    if (!isset(WP_Fake::$posts[$id])) {
        return null;
    }
    return ARRAY_A === $output ? WP_Fake::$posts[$id] : (object) WP_Fake::$posts[$id];
}

/**
 * What real WordPress does to `post_content` on the way in, when the caller lacks
 * `unfiltered_html`: KSES removes any tag outside its allow-list. Set
 * `WP_Fake::$contentFilter` to a callable to stand in for it. Null means store verbatim.
 */
function wp_fake_apply_content_filter(array $data): array
{
    if (isset($data['post_content']) && is_callable(WP_Fake::$contentFilter)) {
        $data['post_content'] = call_user_func(WP_Fake::$contentFilter, (string) $data['post_content']);
    }
    return $data;
}

function wp_insert_post(array $data, bool $wpError = false)
{
    if (!isset($data['post_title']) && !isset($data['post_content'])) {
        return $wpError ? new WP_Error('empty post') : 0;
    }
    $data = wp_fake_apply_content_filter($data);
    $id = WP_Fake::$nextId++;
    WP_Fake::$posts[$id] = array_merge(['ID' => $id], $data);
    return $id;
}

function wp_update_post(array $data, bool $wpError = false)
{
    $id = (int) ($data['ID'] ?? 0);
    if (!isset(WP_Fake::$posts[$id])) {
        return $wpError ? new WP_Error("no such post: {$id}") : 0;
    }
    $data = wp_fake_apply_content_filter($data);
    WP_Fake::$posts[$id] = array_merge(WP_Fake::$posts[$id], $data);
    return $id;
}

function wp_delete_post(int $id, bool $force = false): void
{
    unset(WP_Fake::$posts[$id]);
}

function metadata_exists(string $type, int $id, string $key): bool
{
    return array_key_exists($id . ':' . $key, WP_Fake::$meta);
}

function get_post_meta(int $id, string $key, bool $single = false)
{
    return WP_Fake::$meta[$id . ':' . $key] ?? '';
}

function update_post_meta(int $id, string $key, $value): bool
{
    WP_Fake::$meta[$id . ':' . $key] = $value;
    return true;
}

function delete_post_meta(int $id, string $key): bool
{
    unset(WP_Fake::$meta[$id . ':' . $key]);
    return true;
}

function get_option(string $key, $default = false)
{
    return array_key_exists($key, WP_Fake::$options) ? WP_Fake::$options[$key] : $default;
}

function update_option(string $key, $value, $autoload = null): bool
{
    WP_Fake::$options[$key] = $value;
    return true;
}

function delete_option(string $key): bool
{
    unset(WP_Fake::$options[$key]);
    return true;
}

function clean_post_cache(int $id): void
{
    WP_Fake::$cleaned[$id] = $id;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

// ---- what a template-part override needs, and nothing this fake did not already owe ----------

/**
 * Enough of a post object for code that reads `->ID` and `->post_content`. WordPress's own class
 * carries fifty fields; the writer touches four, and inventing the rest would be a fake of a fake.
 */
class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_name = '';
    public string $post_status = '';
    public string $post_type = '';
}

function get_stylesheet(): string
{
    return WP_Fake::$stylesheet;
}

function wp_set_object_terms(int $id, $terms, string $taxonomy, bool $append = false): array
{
    $names = is_array($terms) ? $terms : [$terms];
    $key = $id . ':' . $taxonomy;
    WP_Fake::$terms[$key] = $append ? array_merge(WP_Fake::$terms[$key] ?? [], $names) : $names;
    return WP_Fake::$terms[$key];
}

function wp_get_object_terms(int $id, string $taxonomy, array $args = []): array
{
    return WP_Fake::$terms[$id . ':' . $taxonomy] ?? [];
}

/**
 * The one query this code makes: a post of a given type and slug, filed under a given term.
 * Only the arguments the writer actually sends are honoured — a fake that pretended to
 * understand the rest of WP_Query would be lying about what is covered.
 */
function get_posts(array $args = []): array
{
    $type = (string) ($args['post_type'] ?? 'post');
    $name = (string) ($args['name'] ?? '');
    $wantTerm = null;
    foreach ((array) ($args['tax_query'] ?? []) as $clause) {
        if (($clause['taxonomy'] ?? '') === 'wp_theme') {
            $wantTerm = (string) ($clause['terms'] ?? '');
        }
    }

    $out = [];
    foreach (WP_Fake::$posts as $id => $row) {
        if (($row['post_type'] ?? '') !== $type) {
            continue;
        }
        if ('' !== $name && ($row['post_name'] ?? '') !== $name) {
            continue;
        }
        if (null !== $wantTerm && !in_array($wantTerm, WP_Fake::$terms[$id . ':wp_theme'] ?? [], true)) {
            continue;
        }
        $post = new WP_Post();
        $post->ID = (int) $id;
        $post->post_title = (string) ($row['post_title'] ?? '');
        $post->post_content = (string) ($row['post_content'] ?? '');
        $post->post_name = (string) ($row['post_name'] ?? '');
        $post->post_status = (string) ($row['post_status'] ?? '');
        $post->post_type = (string) ($row['post_type'] ?? '');
        $out[] = $post;
    }
    return $out;
}

// ---- taxonomy terms and menu entries -------------------------------------------------------

function taxonomy_exists(string $taxonomy): bool
{
    return in_array($taxonomy, ['category', 'post_tag', 'nav_menu', 'wp_theme', 'wp_template_part_area'], true);
}

function get_term(int $id, string $taxonomy = '')
{
    $row = WP_Fake::$termRows[$id] ?? null;
    if ($row === null || ('' !== $taxonomy && $row['taxonomy'] !== $taxonomy)) {
        return null;
    }
    return (object) $row;
}

function get_term_by(string $field, $value, string $taxonomy = '')
{
    foreach (WP_Fake::$termRows as $row) {
        if ('' !== $taxonomy && $row['taxonomy'] !== $taxonomy) {
            continue;
        }
        if (($row[$field] ?? null) === $value) {
            return (object) $row;
        }
    }
    return false;
}

function wp_insert_term(string $name, string $taxonomy, array $args = [])
{
    $id = WP_Fake::$nextTermId++;
    WP_Fake::$termRows[$id] = [
        'term_id' => $id,
        'name' => $name,
        'slug' => (string) ($args['slug'] ?? strtolower(str_replace(' ', '-', $name))),
        'description' => (string) ($args['description'] ?? ''),
        'parent' => (int) ($args['parent'] ?? 0),
        'taxonomy' => $taxonomy,
    ];
    return ['term_id' => $id];
}

function wp_update_term(int $id, string $taxonomy, array $args = [])
{
    if (!isset(WP_Fake::$termRows[$id])) {
        return new WP_Error("no such term: {$id}");
    }
    foreach (['name', 'slug', 'description', 'parent'] as $f) {
        if (array_key_exists($f, $args)) {
            WP_Fake::$termRows[$id][$f] = $args[$f];
        }
    }
    return ['term_id' => $id];
}

function wp_delete_term(int $id, string $taxonomy): bool
{
    unset(WP_Fake::$termRows[$id]);
    return true;
}

function wp_trash_post(int $id)
{
    if (!isset(WP_Fake::$posts[$id])) {
        return false;
    }
    WP_Fake::$posts[$id]['post_status'] = 'trash';
    WP_Fake::$trashed[] = $id;
    return WP_Fake::$posts[$id];
}

/**
 * WordPress's own writer for a menu entry, faked down to what the caller can observe: a
 * `nav_menu_item` post plus the five meta keys that say where it points.
 */
function wp_update_nav_menu_item(int $menuId, int $itemId = 0, array $args = [])
{
    $id = $itemId > 0 ? $itemId : WP_Fake::$nextId++;
    WP_Fake::$posts[$id] = [
        'ID' => $id,
        'post_type' => 'nav_menu_item',
        'post_title' => (string) ($args['menu-item-title'] ?? ''),
        'post_status' => (string) ($args['menu-item-status'] ?? 'draft'),
        'menu_order' => (int) ($args['menu-item-position'] ?? 0),
    ];
    WP_Fake::$meta[$id . ':_menu_item_type'] = (string) ($args['menu-item-type'] ?? 'custom');
    WP_Fake::$meta[$id . ':_menu_item_object'] = (string) ($args['menu-item-object'] ?? '');
    WP_Fake::$meta[$id . ':_menu_item_object_id'] = (int) ($args['menu-item-object-id'] ?? 0);
    WP_Fake::$meta[$id . ':_menu_item_url'] = (string) ($args['menu-item-url'] ?? '');
    WP_Fake::$meta[$id . ':_menu_item_menu_item_parent'] = (int) ($args['menu-item-parent-id'] ?? 0);
    WP_Fake::$menuOf[$id] = $menuId;
    return $id;
}

/**
 * The KSES switch, as the writer sees it. Core's kses_remove_filters()/kses_init_filters() work by
 * calling remove_filter/add_filter; standing in for those is enough for a test to watch the writer
 * turn the filter off for one write and back on after.
 */
function has_filter($tag = '', $fn = '')
{
    return WP_Fake::$contentFilter !== null;
}

function kses_remove_filters(): void
{
    WP_Fake::$ksesParked = WP_Fake::$contentFilter;
    if (isset($GLOBALS['__kses_toggle'])) {
        ($GLOBALS['__kses_toggle'])(false);
    }
}

function kses_init_filters(): void
{
    if (isset($GLOBALS['__kses_toggle'])) {
        ($GLOBALS['__kses_toggle'])(true);
    }
}

// ---- what the content contract needs: a database handle, and Polylang ------------------------

/**
 * `$wpdb` down to the three calls the plugin makes outside the writers: the advisory lock the
 * site writer takes, and the raw status update a demo trim writes. `update()` edits the same
 * posts the rest of this fake serves, so a test can read a trimmed post back through `get_post`.
 */
final class WP_Fake_Db
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $dbname = 'wp';
    /** @var string[] every statement seen, so a test can see the lock taken and released */
    public array $queries = [];
    /** What GET_LOCK answers: 1 is the lock taken, 0 is another writer holding it. */
    public int $lockAnswer = 1;

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . (string) $arg . "'", $query, 1);
        }
        return $query;
    }

    public function get_var(string $query)
    {
        $this->queries[] = $query;
        if (strpos($query, 'GET_LOCK') !== false) {
            return $this->lockAnswer;
        }
        return 1;
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null)
    {
        $id = (int) ($where['ID'] ?? 0);
        if ($table !== $this->posts || !isset(WP_Fake::$posts[$id])) {
            return false;
        }
        foreach ($data as $column => $value) {
            WP_Fake::$posts[$id][$column] = $value;
        }
        return 1;
    }
}

$GLOBALS['wpdb'] = new WP_Fake_Db();

/**
 * Polylang, as the contract sees it: which language a post is in, which languages exist, and
 * one language's locale being changed. `WP_Fake::$polylang` false is a site without the plugin,
 * and then `PLL()` answers null exactly as `function_exists('PLL')` would have answered false.
 * `pll_set_post_language` is deliberately NOT defined: the engine's `content.language` decides
 * whether the plugin exists by that name, and a test above relies on it being absent.
 */
final class WP_Fake_PLL_Languages
{
    /** @var array<int,array<string,mixed>> every update() call, as received */
    public static array $updates = [];

    public function get(string $slug)
    {
        return isset(WP_Fake::$languages[$slug]) ? (object) WP_Fake::$languages[$slug] : false;
    }

    public function update(array $args)
    {
        self::$updates[] = $args;
        foreach (WP_Fake::$languages as $slug => $row) {
            if ((int) $row['term_id'] === (int) ($args['lang_id'] ?? 0)) {
                WP_Fake::$languages[$slug]['locale'] = (string) $args['locale'];
                WP_Fake::$languages[$slug]['name'] = (string) $args['name'];
                return true;
            }
        }
        return new WP_Error('no such language');
    }

    public function clean_cache(): void
    {
    }
}

final class WP_Fake_PLL_Model
{
    public WP_Fake_PLL_Languages $languages;

    public function __construct()
    {
        $this->languages = new WP_Fake_PLL_Languages();
    }
}

final class WP_Fake_PLL
{
    public WP_Fake_PLL_Model $model;

    public function __construct()
    {
        $this->model = new WP_Fake_PLL_Model();
    }
}

function PLL()
{
    return WP_Fake::$polylang ? new WP_Fake_PLL() : null;
}

function pll_get_post_language(int $id)
{
    return WP_Fake::$polylang ? (WP_Fake::$postLanguage[$id] ?? false) : false;
}

function pll_languages_list(array $args = []): array
{
    return WP_Fake::$polylang ? array_keys(WP_Fake::$languages) : [];
}

/** A post's copy in one language, from Polylang's translation group: the id, or false when the group has none. */
function pll_get_post(int $id, string $lang = '')
{
    return WP_Fake::$polylang ? (WP_Fake::$translations[$id][$lang] ?? false) : false;
}

function flush_rewrite_rules(bool $hard = true): void
{
    WP_Fake::$flushed++;
}

/** The home of one language, as the front-end hooks ask for it when a switcher entry has no translation. */
function pll_home_url(string $lang = ''): string
{
    return WP_Fake::$polylang ? 'http://test.local/' . $lang . '/' : '';
}
