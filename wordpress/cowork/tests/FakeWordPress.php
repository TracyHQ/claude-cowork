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
    /** @var array<int,array<string,mixed>> term_id => row */
    public static array $termRows = [];
    /** @var array<int,int> menu item id => menu term id */
    public static array $menuOf = [];
    /** @var int[] */
    public static array $trashed = [];
    public static int $nextTermId = 900;
    /** The active theme, which is what a template-part override has to be filed under. */
    public static string $stylesheet = 'tracy';

    public static function reset(): void
    {
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
