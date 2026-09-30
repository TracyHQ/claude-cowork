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
    /** The active theme's folder, for a template part the site never stored (themeTemplatePart). */
    public static string $themeDir = '/nonexistent-theme';
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
    /** @var array<string,array<string,string>> Polylang slug => [original string => its translation], what each language's `polylang_mo` post holds */
    public static array $strings = [];
    /** @var array<int,array{display_name:string}> user id => the columns an editor lock's holder is named by */
    public static array $users = [];
    /** @var array<string,callable> hook => the one callback `apply_filters` runs for it */
    public static array $filters = [];
    /** @var array<string,array<string,mixed>> pattern name => what the block pattern registry holds for it */
    public static array $patterns = [];

    public static function reset(): void
    {
        self::$themeDir = '/nonexistent-theme';
        self::$patterns = [];
        self::$users = [];
        self::$filters = [];
        self::$polylang = false;
        self::$languages = [];
        self::$strings = [];
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

/**
 * The block pattern registry, as far as the writer asks it: one pattern by name, its content already
 * run through PHP (the real `get_registered` includes a theme file pattern and returns its output).
 */
final class WP_Block_Patterns_Registry
{
    private static ?self $instance = null;

    public static function get_instance(): self
    {
        return self::$instance ??= new self();
    }

    public function get_registered(string $name): ?array
    {
        return WP_Fake::$patterns[$name] ?? null;
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

/** False for a user that does not exist, as WordPress answers: a lock held by one is no lock. */
function get_userdata(int $id)
{
    return isset(WP_Fake::$users[$id]) ? (object) (['ID' => $id] + WP_Fake::$users[$id]) : false;
}

/** One callback per hook, enough for the core filters this plugin honours. */
function apply_filters(string $tag, $value, ...$args)
{
    return isset(WP_Fake::$filters[$tag]) ? (WP_Fake::$filters[$tag])($value, ...$args) : $value;
}

function get_option(string $key, $default = false)
{
    return array_key_exists($key, WP_Fake::$options) ? WP_Fake::$options[$key] : $default;
}

/** Whether the fake database is inside `START TRANSACTION ... READ ONLY`, where MySQL refuses every write (and $wpdb says false). */
function wp_fake_read_only(): bool
{
    $db = $GLOBALS['wpdb'] ?? null;
    return is_object($db) && (!empty($db->readOnly) || (isset($db->inner) && !empty($db->inner->readOnly)));
}

function update_option(string $key, $value, $autoload = null): bool
{
    if (wp_fake_read_only()) {
        return false;
    }
    WP_Fake::$options[$key] = $value;
    return true;
}

function delete_option(string $key): bool
{
    if (wp_fake_read_only()) {
        return false;
    }
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
    // What a post list describes each row by (`describe_post`); an unset column reads as WordPress
    // reads it, empty or zero.
    public string $post_excerpt = '';
    public int $post_parent = 0;
    public int $menu_order = 0;
    public string $comment_status = 'closed';
    public string $post_date_gmt = '0000-00-00 00:00:00';
    public string $post_modified_gmt = '0000-00-00 00:00:00';
    public int $post_author = 0;
}

function get_stylesheet(): string
{
    return WP_Fake::$stylesheet;
}

function get_stylesheet_directory(): string
{
    return WP_Fake::$themeDir;
}

function get_template_directory(): string
{
    return WP_Fake::$themeDir;
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

// ---- what a post list needs: WP_Query, and the reads that describe each row ------------------

/** A slug as WordPress spells it when asked to look one up: lowercase, a dot becomes a hyphen. */
function sanitize_title_for_query(string $title): string
{
    return strtolower(str_replace('.', '-', $title));
}

/**
 * `WP_Query`, as far as the post list asks it: the arguments `list_posts` and `search_posts` send,
 * answered from `WP_Fake::$posts` in id order. It refuses where a default would decide for the
 * caller, so a test cannot pass because this fake happened to guess what core does:
 *
 *  - an argument it does not know throws, instead of being ignored;
 *  - so does a query that does not ASK for `orderby` ID and `order` ASC (core, asked for nothing,
 *    orders by post_date, newest first: a page in the wrong order on a real site);
 *  - and one that does not ask for `suppress_filters` (core, not told to, runs every `posts_where`
 *    and `posts_clauses` filter a plugin added, which a statement written by hand never sees).
 *
 * What the LAST query was asked is kept in `$lastArgs`, for a test to compare with what it means the
 * writer to send: the arguments no answer shows (`ignore_sticky_posts`, `no_found_rows`) are only
 * ever visible there.
 *
 * Three behaviours of the real class are kept on purpose, because the writer has to survive them
 * (each read off class-wp-query.php): an empty `post__in` is no restriction at all, an empty page
 * size is the site's "posts per page" setting (ten unless changed) and not zero rows, and no
 * `post_type` means `post`. Together they make an unguarded empty `post__in` answer with the site's
 * first ten posts.
 */
class WP_Query
{
    /** @var WP_Post[] */
    public array $posts = [];
    /** @var array<string,mixed> the arguments of the query built last */
    public static array $lastArgs = [];

    private const UNDERSTOOD = ['name', 'post_type', 'post_status', 'orderby', 'order', 'offset', 'posts_per_page',
        'ignore_sticky_posts', 'no_found_rows', 'suppress_filters', 'post__in'];

    public function __construct(array $args = [])
    {
        foreach (array_keys($args) as $key) {
            if (!in_array($key, self::UNDERSTOOD, true)) {
                throw new LogicException("the WP_Query fake does not understand `{$key}`");
            }
        }
        if (($args['orderby'] ?? null) !== 'ID' || ($args['order'] ?? null) !== 'ASC') {
            throw new LogicException('the WP_Query fake orders by ID ascending only, and only when asked to: core would order by post_date, newest first');
        }
        if (($args['suppress_filters'] ?? false) !== true) {
            throw new LogicException('the WP_Query fake runs no query filters, so a caller has to suppress them: core would run every posts_where and posts_clauses filter');
        }
        self::$lastArgs = $args;

        $types = array_values(array_filter((array) ($args['post_type'] ?? 'post'), static fn ($t) => $t !== ''));
        $types = $types === [] ? ['post'] : $types;
        if ($types === ['any']) {
            $types = array_values(get_post_types(['exclude_from_search' => false]));
        }
        $statuses = (array) ($args['post_status'] ?? 'publish');
        $name = (string) ($args['name'] ?? '') === '' ? '' : sanitize_title_for_query((string) $args['name']);
        $only = array_map('intval', (array) ($args['post__in'] ?? []));
        // An empty size (absent, or zero) is the site's setting; only a setting of zero itself is one.
        $perPage = $args['posts_per_page'] ?? null;
        if (empty($perPage)) {
            $perPage = get_option('posts_per_page', 10);
        }
        $perPage = (int) $perPage;
        if ($perPage < -1) {
            $perPage = abs($perPage);
        } elseif ($perPage === 0) {
            $perPage = 1;
        }

        $rows = WP_Fake::$posts;
        ksort($rows);
        $matching = [];
        foreach ($rows as $id => $row) {
            if (!in_array((string) ($row['post_type'] ?? ''), $types, true)
                || !in_array((string) ($row['post_status'] ?? ''), $statuses, true)
                || ('' !== $name && strtolower((string) ($row['post_name'] ?? '')) !== $name)
                || ([] !== $only && !in_array((int) $id, $only, true))) {
                continue;
            }
            $post = new WP_Post();
            $post->ID = (int) $id;
            foreach (['post_title', 'post_content', 'post_name', 'post_status', 'post_type', 'post_excerpt', 'comment_status',
                'post_date_gmt', 'post_modified_gmt'] as $column) {
                if (isset($row[$column])) {
                    $post->$column = (string) $row[$column];
                }
            }
            foreach (['post_parent', 'menu_order', 'post_author'] as $column) {
                $post->$column = (int) ($row[$column] ?? 0);
            }
            $matching[] = $post;
        }
        $this->posts = array_slice($matching, max(0, (int) ($args['offset'] ?? 0)), $perPage < 0 ? null : $perPage);
    }
}

// What `describe_post` reads besides the row: a site with no terms, no featured image, no menus and
// no page template, which is what a new site is.
function get_the_terms($post, string $taxonomy)
{
    return false;
}

function get_post_thumbnail_id($post = null): int
{
    return 0;
}

function get_page_template_slug($post = null)
{
    return '';
}

function wp_get_nav_menus(array $args = []): array
{
    return [];
}

function wp_get_nav_menu_items($menu, array $args = [])
{
    return [];
}

function has_term($term = '', $taxonomy = '', $post = null): bool
{
    return false;
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

/**
 * Polylang's string translations of one language, as `PLL_MO` (a pomo `MO` with a database
 * home) exposes them: `entries` keyed by the original string, each with `translations[0]`,
 * loaded from and saved to WP_Fake::$strings per language. Only what the engine calls.
 */
final class PLL_MO
{
    /** @var array<string,object> original => entry with `singular` and `translations` */
    public array $entries = [];

    /** Polylang 3.8 reads `$lang->slug` / `$lang->term_id`: a slug string here is the bug the real site had (25/09/2026). */
    public function import_from_db(object $lang): void
    {
        $this->entries = [];
        foreach (WP_Fake::$strings[(string) $lang->slug] ?? [] as $original => $translation) {
            $this->add_entry($this->make_entry((string) $original, (string) $translation));
        }
    }

    public function make_entry(string $original, string $translation): object
    {
        return (object) ['singular' => $original, 'translations' => [$translation]];
    }

    public function add_entry(object $entry): void
    {
        $this->entries[(string) $entry->singular] = $entry;
    }

    public function export_to_db(object $lang): void
    {
        $strings = [];
        foreach ($this->entries as $original => $entry) {
            $strings[(string) $original] = (string) ($entry->translations[0] ?? '');
        }
        WP_Fake::$strings[(string) $lang->slug] = $strings;
    }
}

/** The home of one language, as the front-end hooks ask for it when a switcher entry has no translation. */
function pll_home_url(string $lang = ''): string
{
    return WP_Fake::$polylang ? 'http://test.local/' . $lang . '/' : '';
}
