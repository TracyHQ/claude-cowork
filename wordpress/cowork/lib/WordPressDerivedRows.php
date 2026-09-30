<?php
/**
 * What a DERIVED contract is made of on WordPress: the public rows of an imported site, in the shape
 * `DerivedMap::build()` takes, and the rendered pages that calibrate which nested leaves a visitor
 * reads. Plain SQL through the site's own `$wpdb`; nothing here writes.
 *
 * Behind injected callables (the database object, the public post types and taxonomies, the
 * permalink of a post, the HTTP getter) so the tests run it over memory, like every other part of
 * this engine. `forSite()` wires the real ones.
 *
 * A derived entity is keyed by its native id on this copy (`post-12`, `postmeta-<meta_id>`,
 * `option-<option_id>`, `term-<term_taxonomy_id>`, `menuItem-<ID>`); `identity` is what the writer
 * addresses it by (a post by id, a meta by post id and key, an option by name, a term by termId and taxonomy, a
 * menu entry by id and the menu it belongs to).
 */
declare(strict_types=1);

require_once __DIR__ . '/LeafCodec.php';
require_once __DIR__ . '/LoopbackRoute.php';
require_once __DIR__ . '/DerivedMap.php';
require_once __DIR__ . '/DerivedCache.php';

final class WordPressDerivedRows
{
    /** One budget for every page a derive fetches: it runs under the writer's lock. */
    public const PAGES_SECONDS = 60;
    /** The render check after a derived apply: it runs under the same lock, and a write should not wait a minute on it. */
    public const CHECK_SECONDS = 15;
    /** Pages a derive may fetch to calibrate. */
    public const PAGES = 40;
    /** An autoloaded option larger than this is a cache or a log, not words. */
    private const AUTOLOAD_BYTES = 204800;
    /** Published posts one derive reads; the rest are named in `unresolved`, not scanned. */
    public const MAX_POSTS = 5000;
    /** Bytes of candidate values one derive scans; the rows past it are named in `unresolved`. */
    public const MAX_BYTES = 20971520;
    /** Posts read per query, and posts whose meta is read per query: each batch is rows before the next is fetched. */
    private const BATCH = 200;
    /** Values up to this many bytes are tested in SQL for holding no word at all (wordValue); longer ones are always read. */
    private const SHORT = 64;
    /** Where the built map is kept between requests (DerivedCache): not autoloaded, and a technical option, so never a row. */
    public const CACHE_OPTION = 'claude_cowork_derived_map';

    /**
     * Post types that are not public and still hold what a visitor reads: synced patterns, the block
     * theme's header and footer when the site stored its own, and navigation menus.
     */
    private const SHARED_TYPES = ['wp_block', 'wp_template_part', 'wp_navigation'];

    /** Meta keys that are WordPress's or a builder's bookkeeping: never words. */
    private const TECHNICAL_META = ['_edit_lock', '_edit_last', '_wp_attached_file', '_wp_attachment_metadata', '_thumbnail_id',
        '_wp_page_template', '_elementor_css', '_elementor_page_assets', '_tracy_content_uid', '_wp_old_slug', '_wp_old_date',
        '_wp_trash_meta_status', '_wp_trash_meta_time', '_encloseme', '_pingme', '_menu_item_type', '_menu_item_object',
        '_menu_item_object_id', '_menu_item_menu_item_parent', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn',
        '_menu_item_url', '_elementor_version', '_elementor_pro_version', '_elementor_edit_mode', '_elementor_template_type',
        '_elementor_controls_usage', '_elementor_screenshot'];
    private const TECHNICAL_META_PREFIXES = ['_oembed_', '_transient_'];

    /**
     * Options that configure the site rather than say anything on it. Written as a slot, a date
     * format, an address or an e-mail would change behaviour, not words; the calibration usually
     * drops them, but a derive whose pages could not be fetched keeps every nested leaf.
     */
    private const TECHNICAL_OPTIONS = ['siteurl', 'home', 'admin_email', 'new_admin_email', 'active_plugins', 'recently_activated',
        'uninstall_plugins', 'template', 'stylesheet', 'current_theme', 'cron', 'rewrite_rules', 'permalink_structure',
        'category_base', 'tag_base', 'date_format', 'time_format', 'links_updated_date_format', 'timezone_string', 'gmt_offset',
        'blog_charset', 'html_type', 'WPLANG', 'db_version', 'initial_db_version', 'db_upgraded', 'upload_path', 'upload_url_path',
        'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'default_role', 'users_can_register',
        'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt',
        'recovery_keys', 'finished_splitting_shared_terms', 'site_icon', 'show_on_front', 'page_on_front', 'page_for_posts',
        'wp_page_for_privacy_policy', 'widget_block_types', 'theme_switched', 'fresh_site', 'can_compress_scripts',
        'polylang', 'polylang_wpml_strings'];
    private const TECHNICAL_OPTION_PREFIXES = ['_transient_', '_site_transient_', 'claude_cowork', '_tracy_', 'tracy_content_'];

    /** @var object `$wpdb`, or anything answering `get_results($sql, ARRAY_A)` with its table names */
    private $db;
    /** @var list<string> */
    private array $postTypes;
    /** @var list<string> */
    private array $taxonomies;
    private string $home;
    /** @var callable(int):?string */
    private $permalink;
    /** @var callable(string,array<string,string>,int,?string):array{code:int,body:string} one GET; code 0 when nothing answered */
    private $get;
    private bool $curl;
    private bool $tls;

    /**
     * @param list<string> $postTypes  public post types (attachments are never content)
     * @param list<string> $taxonomies public taxonomies
     * @param string $home the site's home URL (`home_url('/')`)
     * @param callable(int):?string $permalink a published post's address
     * @param callable(string,array<string,string>,int,?string):array{code:int,body:string} $get one loopback GET: url, headers,
     *        timeout, and the curl resolve entry (`name:port:127.0.0.1`) or null
     * @param bool $curl whether curl is there: without it there is no route and nothing is fetched
     *        (LoopbackRoute::routes); for a curl without TLS, routes() leaves the https route out (LoopbackRoute::tls() is false)
     * @param bool $tls whether that curl speaks TLS (LoopbackRoute::tls): without it an https site is asked over plain http only
     */
    public function __construct($db, array $postTypes, array $taxonomies, string $home, callable $permalink, callable $get, bool $curl = true, bool $tls = true)
    {
        $this->curl = $curl;
        $this->tls = $tls;
        $this->db = $db;
        $this->postTypes = array_values(array_diff(array_map('strval', $postTypes), ['attachment', 'nav_menu_item']));
        $this->taxonomies = array_values(array_diff(array_map('strval', $taxonomies), ['post_format', 'nav_menu', 'language', 'post_translations']));
        $this->home = $home;
        $this->permalink = $permalink;
        $this->get = $get;
    }

    /** The real site: `$wpdb`, its public types and taxonomies, WordPress's permalinks, and a direct curl loopback (httpGet). */
    public static function forSite(): self
    {
        $types = function_exists('get_post_types') ? array_values((array) get_post_types(['public' => true])) : ['post', 'page'];
        $taxonomies = function_exists('get_taxonomies') ? array_values((array) get_taxonomies(['public' => true])) : ['category', 'post_tag'];
        return new self($GLOBALS['wpdb'], $types, $taxonomies, function_exists('home_url') ? (string) home_url('/') : '/',
            static function (int $id): ?string {
                $url = function_exists('get_permalink') ? get_permalink($id) : false;
                return is_string($url) && $url !== '' ? $url : null;
            },
            [self::class, 'httpGet'], function_exists('curl_init') && defined('CURLOPT_RESOLVE'), LoopbackRoute::tls());
    }

    // ---- rows -------------------------------------------------------------------------------

    /**
     * Every row at once: batches() gathered. For a test or a small site; a derive and a map build
     * take the batches, so a large import is never held whole.
     *
     * @param list<string> $unresolved gains one line per value too large to scan
     * @return list<array{kind:string,id:int,identity:array,core:array<string,string>,html:array<string,string>,nested:array<string,string>}>
     */
    public function rows(?array &$unresolved = null): array
    {
        $unresolved = $unresolved ?? [];
        $out = [];
        $batches = $this->batches();
        foreach ($batches as $batch) {
            foreach ($batch as $row) {
                $out[] = $row;
            }
        }
        array_push($unresolved, ...$batches->getReturn());
        return $out;
    }

    /**
     * The rows in DerivedMap's shape, one batch at a time, in the order rows() lists them: the
     * published posts BATCH at a time by id, their meta BATCH posts at a time, the options, the
     * terms, the menu entries. Each database result is gone before the next query; what cannot be
     * words stays in the database (metaKeys, optionScope, wordValue). An imported shop measured
     * 30/09/2026 held 121,925 meta rows for 2,482 posts, nearly all prices, stock counts, ids and
     * switches: read whole into PHP they exhausted 128 MB, and each row costs about 1.2 KB there.
     *
     * @return Generator<int,list<array>,mixed,list<string>> returns the `unresolved` lines: values too large to
     *         scan, posts past MAX_POSTS, rows past MAX_BYTES
     */
    public function batches(): Generator
    {
        self::raiseMemory();
        $unresolved = [];
        $db = $this->db;
        // The rows whose candidate values fit in MAX_BYTES, in order; once one does not, none after it.
        $bytes = 0;
        $dropped = 0;
        $budget = static function (array $rows) use (&$bytes, &$dropped): array {
            $kept = [];
            foreach ($rows as $row) {
                $size = 0;
                foreach (['core', 'html', 'nested'] as $class) {
                    foreach ($row[$class] as $value) {
                        $size += strlen((string) $value);
                    }
                }
                if ($dropped > 0 || $bytes + $size > self::MAX_BYTES) {
                    $dropped++;
                    continue;
                }
                $bytes += $size;
                $kept[] = $row;
            }
            return $kept;
        };

        $types = $this->inList(array_merge($this->postTypes, self::SHARED_TYPES));
        $ids = [];
        $titles = [];
        for ($last = 0, $full = false; !$full;) {
            $batch = $this->query('SELECT ID, post_type, post_name, post_title, post_excerpt, post_content FROM ' . $db->posts
                . " WHERE post_status = 'publish' AND post_type IN (" . $types . ') AND ID > ' . $last . ' ORDER BY ID LIMIT ' . self::BATCH);
            if ($batch === []) {
                break;
            }
            $languages = $this->postTerms(array_map(static fn($r) => (int) $r['ID'], $batch), ['language']);
            $out = [];
            foreach ($batch as $r) {
                $id = $last = (int) $r['ID'];
                if (count($ids) === self::MAX_POSTS) {
                    // A site this large is read in part; the count past the ceiling is unknown without another scan.
                    $unresolved[] = 'more than ' . self::MAX_POSTS . ' published posts: only the first ' . self::MAX_POSTS . ' (by id) are scanned';
                    $full = true;
                    break;
                }
                $ids[] = $id;
                $titles[$id] = (string) $r['post_title'];
                $identity = ['postType' => (string) $r['post_type'], 'slug' => (string) $r['post_name']];
                if (isset($languages[$id]['language'])) {
                    $identity['language'] = $languages[$id]['language'];
                }
                $out[] = $this->row('post', $id, $identity, ['post_title' => $r['post_title'], 'post_excerpt' => $r['post_excerpt']],
                    ['post_content' => $r['post_content']], [], $unresolved);
            }
            unset($batch);
            yield $budget($out);
        }
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $in = implode(',', $chunk);
            // A key stored twice on one post has no single value a slot could stand for, whatever the
            // other copy holds: counted over every row, before the values are filtered.
            $twice = [];
            foreach ($this->query('SELECT post_id, meta_key FROM ' . $db->postmeta . ' WHERE post_id IN (' . $in . ') AND ' . $this->metaKeys('meta_key')
                . ' GROUP BY post_id, meta_key HAVING COUNT(*) > 1') as $r) {
                $twice[$r['post_id'] . ':' . $r['meta_key']] = true;
            }
            $batch = $this->query('SELECT meta_id, post_id, meta_key, meta_value FROM ' . $db->postmeta . ' WHERE post_id IN (' . $in . ') AND '
                . $this->metaKeys('meta_key') . ' AND ' . self::wordValue('meta_value') . ' ORDER BY post_id, meta_id');
            $out = [];
            foreach ($batch as $r) {
                $key = (string) $r['meta_key'];
                if (isset($twice[$r['post_id'] . ':' . $key]) || self::technicalMeta($key)) {
                    continue;
                }
                $out[] = $this->row('postmeta', (int) $r['meta_id'], ['postId' => (int) $r['post_id'], 'key' => $key], [], [],
                    ['meta_value' => $r['meta_value']], $unresolved);
            }
            unset($batch);
            yield $budget($out);
        }

        $out = [];
        foreach ($this->query('SELECT option_id, option_name, option_value, autoload FROM ' . $db->options . ' WHERE ' . $this->optionScope() . ' ORDER BY option_id') as $r) {
            $name = (string) $r['option_name'];
            if (self::technicalOption($name)) {
                continue;
            }
            $out[] = $this->row('option', (int) $r['option_id'], ['name' => $name], [], [], ['option_value' => $r['option_value']], $unresolved);
        }

        if ($this->taxonomies !== []) {
            // Keyed by term_taxonomy_id: a legacy SHARED term (one wp_terms row in two taxonomies) is two
            // rows here, and its name — one column of one row — is a slot of the first only.
            $named = [];
            foreach ($this->query('SELECT tt.term_taxonomy_id, t.term_id, t.name, t.slug, tt.taxonomy, tt.description FROM ' . $db->terms . ' t JOIN ' . $db->term_taxonomy
                . ' tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN (' . $this->inList($this->taxonomies) . ') ORDER BY tt.term_taxonomy_id') as $r) {
                $termId = (int) $r['term_id'];
                // WordPress stores a term name escaped (`A &amp; B`); the words are the decoded text.
                $core = isset($named[$termId]) ? [] : ['name' => html_entity_decode((string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
                $named[$termId] = true;
                $out[] = $this->row('term', (int) $r['term_taxonomy_id'], ['taxonomy' => (string) $r['taxonomy'], 'slug' => (string) $r['slug'], 'termId' => $termId],
                    $core, ['description' => $r['description']], [], $unresolved);
            }
        }

        // Menu entries: few, and their meta is read whole (the `_menu_item_*` keys are the entry).
        $menuItems = $this->query('SELECT ID, post_title FROM ' . $db->posts . " WHERE post_type = 'nav_menu_item' AND post_status = 'publish' ORDER BY ID");
        $itemIds = array_map(static fn($r) => (int) $r['ID'], $menuItems);
        $meta = $this->meta($itemIds);
        $menus = $this->postTerms($itemIds, ['nav_menu']);
        foreach ($menuItems as $r) {
            $id = (int) $r['ID'];
            $menu = $menus[$id]['nav_menu'] ?? null;
            if ($menu === null) {
                continue; // an entry outside a menu renders nowhere, and the writer cannot address it
            }
            // An entry with no title of its own shows the title of what it points at. That title is
            // the slot's words; a write still lands on the entry's own title (QuickstartContract).
            $title = (string) $r['post_title'];
            if ($title === '') {
                $title = $this->pointedTitle($meta[$id] ?? [], $titles);
            }
            $out[] = $this->row('menuItem', $id, ['id' => $id, 'menu' => $menu], ['post_title' => $title], [],
                ['_menu_item_url' => (string) ($meta[$id]['_menu_item_url'][0]['meta_value'] ?? '')], $unresolved);
        }
        yield $budget($out);
        if ($dropped > 0) {
            $unresolved[] = $dropped . ' rows past the ' . self::MAX_BYTES . '-byte scan budget, not scanned';
        }
        return $unresolved;
    }

    /**
     * What the rows are read from, cheaply, in one query: counts, the highest ids and a checksum of
     * every column rows() reads, over the same scope and the same filters. Any write to those rows
     * changes it (a price or a stock count, which no slot holds, does not); DerivedCache keys a
     * built map by it. The receiver's own options are outside the scope, so storing the map does
     * not change what it is keyed by.
     */
    public function fingerprint(): string
    {
        $db = $this->db;
        $crc = static fn(string $columns): string => 'COALESCE(BIT_XOR(CRC32(CONCAT_WS(\'|\', ' . $columns . '))), 0)';
        $scope = "p.post_status = 'publish' AND p.post_type IN (" . $this->inList(array_merge($this->postTypes, self::SHARED_TYPES, ['nav_menu_item'])) . ')';
        $taxonomies = $this->inList(array_merge($this->taxonomies, ['nav_menu', 'language']));
        $row = $this->query('SELECT'
            . " (SELECT CONCAT_WS(':', COUNT(*), COALESCE(MAX(p.ID), 0), " . $crc('p.ID, p.post_type, p.post_name, p.post_title, p.post_excerpt, p.post_content, p.post_modified_gmt')
            . ') FROM ' . $db->posts . ' p WHERE ' . $scope . ') AS posts,'
            . " (SELECT CONCAT_WS(':', COUNT(*), COALESCE(MAX(m.meta_id), 0)) FROM " . $db->postmeta . ' m JOIN ' . $db->posts . ' p ON p.ID = m.post_id WHERE ' . $scope . ') AS meta,'
            . ' (SELECT ' . $crc('m.meta_id, m.post_id, m.meta_key, m.meta_value') . ' FROM ' . $db->postmeta . ' m JOIN ' . $db->posts . ' p ON p.ID = m.post_id WHERE ' . $scope
            . " AND ((" . $this->metaKeys('m.meta_key') . ' AND ' . self::wordValue('m.meta_value') . ") OR p.post_type = 'nav_menu_item')) AS words,"
            . " (SELECT CONCAT_WS(':', COUNT(*), " . $crc('option_id, option_name, option_value') . ') FROM ' . $db->options . ' WHERE (' . $this->optionScope()
            . ") OR option_name IN ('active_plugins', 'stylesheet', 'template')) AS options,"
            // One checksum per table: an imported site often has wp_terms and wp_term_taxonomy in different
            // collations, and one CONCAT_WS across both fails with "Illegal mix of collations".
            . " (SELECT CONCAT_WS(':', COUNT(*), " . $crc('t.term_id, t.name, t.slug') . ', ' . $crc('tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description') . ') FROM ' . $db->terms . ' t JOIN '
            . $db->term_taxonomy . ' tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN (' . $taxonomies . ')) AS terms,'
            . " (SELECT CONCAT_WS(':', COUNT(*), " . $crc('tr.object_id, tr.term_taxonomy_id') . ') FROM ' . $db->term_relationships . ' tr JOIN ' . $db->term_taxonomy
            . " tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy IN ('language', 'nav_menu')) AS links");
        return hash('sha256', (string) json_encode([$row[0] ?? null, $this->postTypes, $this->taxonomies, $this->home]));
    }

    /** @var array<string,DerivedCache> this request's one kept map per receiver version (siteCache) */
    private static array $siteCaches = [];

    /**
     * The site's own kept map, one object per request: the contract and every content reader built in
     * the request share it, so the tables are fingerprinted and the map read once.
     */
    public static function siteCache(string $version): DerivedCache
    {
        return self::$siteCaches[$version] ??= self::forSite()->cache($version);
    }

    /** The derived map kept between requests in a non-autoloaded option, keyed by fingerprint(). */
    public function cache(string $version): DerivedCache
    {
        return new DerivedCache([$this, 'fingerprint'], static function (): ?string {
            // Unpacking a kept map of thousands of slots is tens of MB too; measured 30/09/2026 on an
            // imported shop whose WordPress alone took 94 MB, a read outside wp-admin died at 128 MB.
            self::raiseMemory();
            $stored = function_exists('get_option') ? get_option(self::CACHE_OPTION, null) : null;
            return is_string($stored) ? $stored : null;
        }, static function (?string $value): void {
            if ($value === null) {
                delete_option(self::CACHE_OPTION);
            } else {
                update_option(self::CACHE_OPTION, $value, false);
            }
        }, $version);
    }

    /** As wp-admin does before its heavy screens: at least WP_MAX_MEMORY_LIMIT (256 MB by default), never less than the site has. */
    public static function raiseMemory(): void
    {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
            return;
        }
        DerivedCache::raiseMemory();
    }

    /** SQL: the meta keys that may hold words; the technical ones stay in the database. */
    private function metaKeys(string $column): string
    {
        $out = $column . ' NOT IN (' . $this->inList(self::TECHNICAL_META) . ')';
        foreach (self::TECHNICAL_META_PREFIXES as $prefix) {
            $out .= ' AND ' . $column . ' NOT LIKE ' . self::likePrefix($prefix) . " ESCAPE '!'";
        }
        return $out;
    }

    /** SQL: the options rows() reads, the technical ones left in the database. */
    private function optionScope(): string
    {
        $out = "(option_name LIKE 'theme!_mods!_%' ESCAPE '!' OR option_name LIKE 'widget!_%' ESCAPE '!' OR option_name = 'sidebars_widgets'"
            . " OR (autoload IN ('yes', 'on', 'auto-on', 'auto') AND LENGTH(option_value) <= " . self::AUTOLOAD_BYTES . '))'
            . ' AND option_name NOT IN (' . $this->inList(self::TECHNICAL_OPTIONS) . ") AND option_name NOT LIKE '%user!_roles' ESCAPE '!'";
        foreach (self::TECHNICAL_OPTION_PREFIXES as $prefix) {
            $out .= ' AND option_name NOT LIKE ' . self::likePrefix($prefix) . " ESCAPE '!'";
        }
        return $out . ' AND ' . self::wordValue('option_value');
    }

    /**
     * SQL: a value that may hold a word or a link. Left out are the short values LeafCodec::typeOf
     * finds nothing in (empty, only digits, spaces and `.,:;+-` as a price, a count or a date is, a
     * bare hex id of 8 or more, one character that is not `/`), and a switch stored as a word
     * (`yes`, `no`, `on`, `off`, `true`, `false`), which a plugin reads and a visitor never does.
     * Anything longer than SHORT is read. ASCII classes only, so MySQL 5.7's byte-wise REGEXP and
     * 8.0's ICU one agree.
     */
    private static function wordValue(string $column): string
    {
        return '(LENGTH(' . $column . ') > ' . self::SHORT . ' OR NOT (' . $column . " REGEXP '^[-0-9[:space:].,:;+]*\$' OR " . $column
            . " REGEXP '^[0-9a-fA-F]{8,}\$' OR (CHAR_LENGTH(" . $column . ') = 1 AND ' . $column . " <> '/') OR " . $column
            . " IN ('yes', 'no', 'on', 'off', 'true', 'false')))";
    }

    /** A LIKE pattern for names starting with `$prefix`, escaped with `!` (a backslash means two things across MySQL modes). */
    private static function likePrefix(string $prefix): string
    {
        return "'" . strtr($prefix, ['!' => '!!', '_' => '!_', '%' => '!%', "'" => "''"]) . "%'";
    }

    /** Every meta row of the given posts (menu entries: a few, read whole). @return array<int,array<string,list<array{meta_id:string,meta_value:string}>>> post id => key => rows */
    private function meta(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->query('SELECT meta_id, post_id, meta_key, meta_value FROM ' . $this->db->postmeta . ' WHERE post_id IN ('
                . implode(',', array_map('intval', $chunk)) . ') ORDER BY meta_id') as $r) {
                $out[(int) $r['post_id']][(string) $r['meta_key']][] = ['meta_id' => (string) $r['meta_id'], 'meta_value' => (string) $r['meta_value']];
            }
        }
        return $out;
    }

    /** @return array<int,array<string,string>> post id => taxonomy => the slug of its (first) term */
    private function postTerms(array $ids, array $taxonomies): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->query('SELECT tr.object_id, tt.taxonomy, t.slug FROM ' . $this->db->term_relationships . ' tr JOIN ' . $this->db->term_taxonomy
                . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN ' . $this->db->terms . ' t ON t.term_id = tt.term_id WHERE tr.object_id IN ('
                . implode(',', array_map('intval', $chunk)) . ') AND tt.taxonomy IN (' . $this->inList($taxonomies) . ') ORDER BY tr.object_id, t.term_id') as $r) {
                $out[(int) $r['object_id']][(string) $r['taxonomy']] ??= (string) $r['slug'];
            }
        }
        return $out;
    }

    /**
     * The title a menu entry without one shows: its post's, or its term's.
     *
     * @param array<string,list<array{meta_id:string,meta_value:string}>> $meta the entry's meta
     * @param array<int,string> $titles the titles of the published posts already read, by id
     */
    private function pointedTitle(array $meta, array $titles): string
    {
        $type = (string) ($meta['_menu_item_type'][0]['meta_value'] ?? '');
        $object = (int) ($meta['_menu_item_object_id'][0]['meta_value'] ?? 0);
        if ($object <= 0) {
            return '';
        }
        if ($type === 'post_type') {
            if (isset($titles[$object])) {
                return $titles[$object];
            }
            $found = $this->query('SELECT ID, post_title FROM ' . $this->db->posts . ' WHERE ID = ' . $object);
            return (string) ($found[0]['post_title'] ?? '');
        }
        if ($type === 'taxonomy') {
            $found = $this->query('SELECT term_id, name FROM ' . $this->db->terms . ' WHERE term_id = ' . $object);
            return html_entity_decode((string) ($found[0]['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /** One row in DerivedMap's shape; a value past LeafCodec's ceiling is named, not scanned. */
    private function row(string $kind, int $id, array $identity, array $core, array $html, array $nested, array &$unresolved): array
    {
        $out = ['kind' => $kind, 'id' => $id, 'identity' => $identity, 'core' => [], 'html' => [], 'nested' => []];
        foreach (['core' => $core, 'html' => $html, 'nested' => $nested] as $class => $columns) {
            foreach ($columns as $column => $value) {
                $value = (string) $value;
                if (strlen($value) > LeafCodec::MAX_BYTES) {
                    $unresolved[] = $kind . ' ' . $id . '.' . $column . ' is ' . strlen($value) . ' bytes, not scanned';
                    continue;
                }
                $out[$class][$column] = $value;
            }
        }
        return $out;
    }

    public static function technicalMeta(string $key): bool
    {
        if (in_array($key, self::TECHNICAL_META, true)) {
            return true;
        }
        foreach (self::TECHNICAL_META_PREFIXES as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function technicalOption(string $name): bool
    {
        if (in_array($name, self::TECHNICAL_OPTIONS, true) || substr($name, -strlen('user_roles')) === 'user_roles') {
            return true;
        }
        foreach (self::TECHNICAL_OPTION_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private function query(string $sql): array
    {
        $rows = $this->db->get_results($sql, 'ARRAY_A');
        if (!is_array($rows) || (string) ($this->db->last_error ?? '') !== '') {
            throw new RuntimeException('A derived row query failed');
        }
        return $rows;
    }

    private function inList(array $values): string
    {
        return implode(',', array_map(fn($v) => $this->db->prepare('%s', (string) $v), $values));
    }

    // ---- pages ------------------------------------------------------------------------------

    /**
     * The rendered pages a derive calibrates with, at most `$limit`: the home page, what the menus
     * link to on this site, the newest published row of each public post type, then the newest
     * pages. Fetched over loopback ({@see fetch()}); a page that fails is left out, none is `[]`
     * and the derive records `calibrated: false`.
     *
     * @return list<string>
     */
    public function pages(int $limit = self::PAGES): array
    {
        $db = $this->db;
        $urls = [$this->home];
        $items = $this->query('SELECT ID FROM ' . $db->posts . " WHERE post_type = 'nav_menu_item' AND post_status = 'publish' ORDER BY menu_order, ID");
        $meta = $this->meta(array_map(static fn($r) => (int) $r['ID'], $items));
        foreach ($items as $r) {
            $m = $meta[(int) $r['ID']] ?? [];
            $type = (string) ($m['_menu_item_type'][0]['meta_value'] ?? '');
            if ($type === 'custom') {
                $urls[] = (string) ($m['_menu_item_url'][0]['meta_value'] ?? '');
            } elseif ($type === 'post_type') {
                $urls[] = (string) (($this->permalink)((int) ($m['_menu_item_object_id'][0]['meta_value'] ?? 0)) ?? '');
            }
        }
        foreach ($this->postTypes as $type) {
            foreach ($this->query('SELECT ID FROM ' . $db->posts . " WHERE post_status = 'publish' AND post_type = " . $db->prepare('%s', $type)
                . ' ORDER BY post_date DESC, ID DESC LIMIT 1') as $r) {
                $urls[] = (string) (($this->permalink)((int) $r['ID']) ?? '');
            }
        }
        foreach ($this->query('SELECT ID FROM ' . $db->posts . " WHERE post_status = 'publish' AND post_type = 'page' ORDER BY post_date DESC, ID DESC LIMIT "
            . max(0, $limit)) as $r) {
            $urls[] = (string) (($this->permalink)((int) $r['ID']) ?? '');
        }
        $own = [];
        foreach ($urls as $url) {
            if ($url !== '' && $this->onSite($url) && !isset($own[$url]) && count($own) < $limit) {
                $own[$url] = true;
            }
        }
        return array_values($this->fetch(array_keys($own), self::PAGES_SECONDS));
    }

    /** The home page, where every option, term and menu entry is checked. */
    public function home(): string
    {
        return $this->home;
    }

    /** A published post's address, or null. */
    public function permalink(int $id): ?string
    {
        return ($this->permalink)($id);
    }

    /**
     * The page a derived row's words show on, for the render check after a write: a post (and its
     * meta) on its own permalink, an option, a term or a menu entry on the home page. Null when a
     * post has no public address: an unchecked write is better than a false warning.
     */
    public function ownerUrl(string $kind, int $id, array $identity): ?string
    {
        if ($kind === 'post') {
            return $this->permalink($id);
        }
        if ($kind === 'postmeta') {
            return $this->permalink((int) ($identity['postId'] ?? 0));
        }
        return $this->home;
    }

    /**
     * Pages of this site by their address, over loopback, all under one deadline, no redirect
     * followed, in the route order LoopbackRoute gives (shared with the Joomla engine): plain http to
     * 127.0.0.1 with the site's own Host first — a fleet copy serves plain http inside its container
     * whatever its public scheme — then, for an https site that fails or redirects there, https with
     * curl resolving the site's own name to 127.0.0.1. Every request carries `X-Tracy-Preview: pick`
     * (ProvenanceStamps: the page skips the page cache). Once a route answers, the next pages start
     * there: a site that redirects http does so for every page.
     *
     * @param list<string> $urls absolute addresses on this site
     * @return array<string,string> the pages that loaded, by the address asked
     */
    public function fetch(array $urls, int $seconds = self::CHECK_SECONDS): array
    {
        $deadline = microtime(true) + $seconds;
        $routes = LoopbackRoute::routes($this->home, $this->curl, $this->tls);
        $base = rtrim((string) parse_url($this->home, PHP_URL_PATH), '/') . '/';
        $first = 0;
        $pages = [];
        foreach ($urls as $url) {
            $parts = parse_url($url) ?: [];
            $path = (string) ($parts['path'] ?? '/');
            // Relative to the site's root, as LoopbackRoute's base URLs end in it.
            $page = (strpos($path, $base) === 0 ? substr($path, strlen($base)) : ltrim($path, '/')) . (isset($parts['query']) ? '?' . $parts['query'] : '');
            for ($i = $first; $i < count($routes); $i++) {
                $left = (int) floor($deadline - microtime(true));
                if ($left < 1) {
                    return $pages;
                }
                $route = $routes[$i];
                try {
                    $answer = ($this->get)($route['url'] . $page, ['Host' => $route['host'], 'X-Tracy-Preview' => 'pick'], min(10, $left), $route['resolve']);
                    $code = (int) ($answer['code'] ?? 0);
                    $body = (string) ($answer['body'] ?? '');
                } catch (Throwable $e) {
                    $code = 0; // nothing answered: the next route may
                    $body = '';
                }
                $outcome = LoopbackRoute::outcome($code);
                if ($outcome === 'next') {
                    continue;
                }
                $first = $i;
                if ($outcome === 'page' && $body !== '') {
                    $pages[$url] = $body;
                }
                break;
            }
        }
        return $pages;
    }

    private function onSite(string $url): bool
    {
        $mine = strtolower((string) parse_url($this->home, PHP_URL_HOST));
        $theirs = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $mine !== '' && $theirs === $mine;
    }

    /**
     * One loopback GET, through curl alone and never WordPress's HTTP API: its transport choice
     * (streams, a filtered `use_curl_transport`), a `pre_http_request` answer or a proxy setting
     * could send the https route to the public address of the site's domain, and on an imported
     * copy that domain is often the customer's live site. So:
     *
     * - the plain http route is sent only to 127.0.0.1, the site's name riding in the Host header;
     * - the https route only with curl resolving exactly the URL's own host and port to 127.0.0.1;
     * - no proxy, and for https the resolve and the certificate checks off for this loopback only
     *   (LoopbackRoute::curlOptions); no redirect followed, one protocol per route;
     * - without a usable curl (or, for https, a curl without TLS) nothing is sent: code 0, and the
     *   derive calibrates nothing.
     *
     * @param array<string,string> $headers
     * @param string|null $resolve `name:port:127.0.0.1` for the https route, null for plain http
     * @return array{code:int,body:string} code 0 when nothing answered or nothing was sent
     */
    public static function httpGet(string $url, array $headers, int $timeout, ?string $resolve): array
    {
        $none = ['code' => 0, 'body' => ''];
        if (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE')) {
            return $none;
        }
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($resolve === null) {
            if ($scheme !== 'http' || $host !== '127.0.0.1') {
                return $none;
            }
            $protocol = CURLPROTO_HTTP;
        } else {
            $port = (int) ($parts['port'] ?? 443);
            $version = function_exists('curl_version') ? curl_version() : [];
            if ($scheme !== 'https' || strtolower($resolve) !== $host . ':' . $port . ':127.0.0.1'
                || !defined('CURL_VERSION_SSL') || !((int) ($version['features'] ?? 0) & CURL_VERSION_SSL)) {
                return $none;
            }
            $protocol = CURLPROTO_HTTPS;
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $handle = curl_init();
        if ($handle === false) {
            return $none;
        }
        // No proxy on any route, and for https the resolve to 127.0.0.1 without the certificate check:
        // the helper both engines share. What only this request needs is added beside it.
        $options = LoopbackRoute::curlOptions(['resolve' => $resolve]) + [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $protocol,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => max(1, $timeout),
            CURLOPT_TIMEOUT => max(1, $timeout),
        ];
        try {
            if (!curl_setopt_array($handle, $options)) {
                return $none;
            }
            $body = curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
        return is_string($body) ? ['code' => $code, 'body' => $body] : $none;
    }
}
