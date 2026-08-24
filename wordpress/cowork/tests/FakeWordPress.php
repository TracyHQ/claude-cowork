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
    /** The active theme, which is what a template-part override has to be filed under. */
    public static string $stylesheet = 'tracy';

    public static function reset(): void
    {
        self::$posts = [];
        self::$meta = [];
        self::$options = [];
        self::$cleaned = [];
        self::$terms = [];
        self::$stylesheet = 'tracy';
        self::$nextId = 500;
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

function wp_insert_post(array $data, bool $wpError = false)
{
    if (!isset($data['post_title']) && !isset($data['post_content'])) {
        return $wpError ? new WP_Error('empty post') : 0;
    }
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
