<?php
/**
 * A derived contract on WordPress: an imported site bound to the map its own rows make (DerivedMap
 * over WordPressDerivedRows), written through the same Apply door as a quickstart with the REAL site
 * writer over the fake WordPress, taken back the same way, read by the real content reader, and
 * checked on its rendered page after the write.
 *
 * Loaded by run.php after content-revisions.php and content-batch.php (uses WP_Fake_ContentDb,
 * parse_blocks, get_theme_root, `$FIXTURES`).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/LeafCodec.php';
require_once __DIR__ . '/../lib/VisibleText.php';
require_once __DIR__ . '/../lib/DerivedMap.php';
require_once __DIR__ . '/../lib/WordPressDerivedRows.php';
require_once __DIR__ . '/../lib/class-claude-cowork-content-source.php';

echo "\nDerived contract (WordPress)\n";

// The actions a purge after a derived write fires, answered by whatever a test hangs on them.
if (!function_exists('do_action')) {
    function do_action(string $tag, ...$args): void
    {
        if (isset(WP_Fake::$filters[$tag])) {
            (WP_Fake::$filters[$tag])(...$args);
        }
    }
}

/**
 * The queries WordPressDerivedRows asks, answered from the fake WordPress's rows (raw, as MySQL holds
 * them: an array option or meta serialized); every other query goes to the content reader's fake.
 */
final class DwDb
{
    public string $prefix = 'wp_';
    public string $dbname = 'wp';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $options = 'wp_options';
    public string $term_relationships = 'wp_term_relationships';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $terms = 'wp_terms';
    public string $users = 'wp_users';
    public string $last_error = '';
    public $dbh;
    public WP_Fake_ContentDb $inner;
    public int $rowQueries = 0;
    /** @var array<string,true> options the fake keeps out of autoload */
    public array $notAutoloaded = [];
    /** @var array<string,string> option name => the raw bytes the table holds when a filter answers get_option otherwise */
    public array $rawOptions = [];
    /** @var list<array{term_taxonomy_id:int,term_id:int,taxonomy:string,description:string}> a legacy shared term's other taxonomies */
    public array $sharedTerms = [];

    public function __construct()
    {
        $this->inner = new WP_Fake_ContentDb();
        $this->dbh = $this->inner->dbh;
    }

    public function prepare(string $query, ...$args): string
    {
        return $this->inner->prepare($query, ...$args);
    }

    public function query(string $sql)
    {
        return $this->inner->query($sql);
    }

    public function get_var(string $query)
    {
        return $this->inner->get_var($query);
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null)
    {
        return $this->inner->update($table, $data, $where, $format, $whereFormat);
    }

    private static function in(string $sql, string $column): array
    {
        if (!preg_match('/' . preg_quote($column, '/') . ' IN \(([^)]*)\)/', $sql, $m)) {
            return [];
        }
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|(\d+)/", $m[1], $values, PREG_SET_ORDER);
        return array_map(static fn($v) => isset($v[2]) && $v[2] !== '' ? $v[2] : stripslashes($v[1]), $values);
    }

    private static function stored($value): string
    {
        return is_array($value) ? serialize($value) : (string) $value;
    }

    public static function optionId(string $name): int
    {
        return (int) (crc32('option:' . $name) % 100000) + 1;
    }

    public static function metaId(int $post, string $key): int
    {
        return (int) (crc32('meta:' . $post . ':' . $key) % 100000) + 1;
    }

    public function get_results(string $sql, $output = null): array
    {
        if (strpos($sql, 'SELECT ID, post_type, post_name, post_title, post_excerpt, post_content FROM wp_posts') === 0) {
            $this->rowQueries++;
            $types = self::in($sql, 'post_type');
            $out = [];
            ksort(WP_Fake::$posts);
            foreach (WP_Fake::$posts as $id => $row) {
                if (($row['post_status'] ?? '') === 'publish' && in_array((string) ($row['post_type'] ?? ''), $types, true)) {
                    $out[] = ['ID' => (string) $id, 'post_type' => $row['post_type'], 'post_name' => (string) ($row['post_name'] ?? ''),
                        'post_title' => (string) ($row['post_title'] ?? ''), 'post_excerpt' => (string) ($row['post_excerpt'] ?? ''), 'post_content' => (string) ($row['post_content'] ?? '')];
                }
            }
            return $out;
        }
        if (strpos($sql, "SELECT ID, post_title FROM wp_posts WHERE post_type = 'nav_menu_item'") === 0
            || strpos($sql, "SELECT ID FROM wp_posts WHERE post_type = 'nav_menu_item'") === 0) {
            $out = [];
            foreach (WP_Fake::$posts as $id => $row) {
                if (($row['post_type'] ?? '') === 'nav_menu_item' && ($row['post_status'] ?? '') === 'publish') {
                    $out[] = ['ID' => (string) $id, 'post_title' => (string) ($row['post_title'] ?? '')];
                }
            }
            return $out;
        }
        if (preg_match('/^SELECT ID, post_title FROM wp_posts WHERE ID = (\d+)$/D', $sql, $m)) {
            $row = WP_Fake::$posts[(int) $m[1]] ?? null;
            return $row === null ? [] : [['ID' => $m[1], 'post_title' => (string) ($row['post_title'] ?? '')]];
        }
        if (preg_match('/^SELECT term_id, name FROM wp_terms WHERE term_id = (\d+)$/D', $sql, $m)) {
            $row = WP_Fake::$termRows[(int) $m[1]] ?? null;
            return $row === null ? [] : [['term_id' => $m[1], 'name' => (string) $row['name']]];
        }
        if (preg_match("/^SELECT ID FROM wp_posts WHERE post_status = 'publish' AND post_type = '([a-z_]+)' ORDER BY post_date DESC, ID DESC LIMIT (\d+)$/D", $sql, $m)) {
            $out = [];
            krsort(WP_Fake::$posts);
            foreach (WP_Fake::$posts as $id => $row) {
                if (($row['post_type'] ?? '') === $m[1] && ($row['post_status'] ?? '') === 'publish' && count($out) < (int) $m[2]) {
                    $out[] = ['ID' => (string) $id];
                }
            }
            ksort(WP_Fake::$posts);
            return $out;
        }
        if (strpos($sql, 'SELECT meta_id, post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN') === 0) {
            $ids = self::in($sql, 'post_id');
            $out = [];
            foreach (WP_Fake::$meta as $at => $value) {
                [$post, $key] = explode(':', $at, 2);
                if (in_array($post, $ids, true)) {
                    $out[] = ['meta_id' => (string) self::metaId((int) $post, $key), 'post_id' => $post, 'meta_key' => $key, 'meta_value' => self::stored($value)];
                }
            }
            return $out;
        }
        if (strpos($sql, 'SELECT tr.object_id, tt.taxonomy, t.slug FROM wp_term_relationships') === 0) {
            $ids = self::in($sql, 'tr.object_id');
            $taxonomies = self::in($sql, 'tt.taxonomy');
            $out = [];
            foreach ($ids as $id) {
                if (in_array('nav_menu', $taxonomies, true) && isset(WP_Fake::$menuOf[(int) $id])) {
                    $out[] = ['object_id' => $id, 'taxonomy' => 'nav_menu', 'slug' => (string) WP_Fake::$termRows[WP_Fake::$menuOf[(int) $id]]['slug']];
                }
                if (in_array('language', $taxonomies, true) && isset(WP_Fake::$postLanguage[(int) $id])) {
                    $out[] = ['object_id' => $id, 'taxonomy' => 'language', 'slug' => WP_Fake::$postLanguage[(int) $id]];
                }
            }
            return $out;
        }
        if (strpos($sql, 'SELECT option_id, option_name, option_value, autoload FROM wp_options') === 0) {
            $out = [];
            foreach (WP_Fake::$options as $name => $value) {
                $themed = strpos($name, 'theme_mods_') === 0 || strpos($name, 'widget_') === 0 || $name === 'sidebars_widgets';
                if ($themed || !isset($this->notAutoloaded[$name])) {
                    $out[] = ['option_id' => (string) self::optionId($name), 'option_name' => $name, 'option_value' => $this->rawOptions[$name] ?? self::stored($value), 'autoload' => 'on'];
                }
            }
            usort($out, static fn($a, $b) => (int) $a['option_id'] <=> (int) $b['option_id']);
            return $out;
        }
        if (strpos($sql, 'SELECT tt.term_taxonomy_id, t.term_id, t.name, t.slug, tt.taxonomy, tt.description FROM wp_terms t') === 0) {
            $taxonomies = self::in($sql, 'tt.taxonomy');
            $out = [];
            foreach (WP_Fake::$termRows as $id => $row) {
                if (in_array($row['taxonomy'], $taxonomies, true)) {
                    $out[] = ['term_taxonomy_id' => (string) ($id + 1000), 'term_id' => (string) $id, 'name' => $row['name'], 'slug' => $row['slug'],
                        'taxonomy' => $row['taxonomy'], 'description' => $row['description']];
                }
            }
            foreach ($this->sharedTerms as $shared) {
                $row = WP_Fake::$termRows[$shared['term_id']];
                $out[] = ['term_taxonomy_id' => (string) $shared['term_taxonomy_id'], 'term_id' => (string) $shared['term_id'], 'name' => $row['name'], 'slug' => $row['slug'],
                    'taxonomy' => $shared['taxonomy'], 'description' => $shared['description']];
            }
            usort($out, static fn($a, $b) => (int) $a['term_taxonomy_id'] <=> (int) $b['term_taxonomy_id']);
            return $out;
        }
        return $this->inner->get_results($sql, $output);
    }
}

$dwPrevDb = $GLOBALS['wpdb'];
$dwRoot = sys_get_temp_dir() . '/cc-derived-' . bin2hex(random_bytes(4));
mkdir($dwRoot . '/wp-content/uploads/2026/09', 0777, true);
file_put_contents($dwRoot . '/wp-content/uploads/2026/09/hero.png', 'png');
file_put_contents($dwRoot . '/wp-content/uploads/2026/09/new.png', 'png');

/** An imported site, as it lands: a page with a builder's JSON, a theme's serialized options, a classic menu, a category. */
$dwSeed = static function (): void {
    WP_Fake::reset();
    WP_Fake::$stylesheet = 'northwind';
    WP_Fake::$options = [
        'home' => 'http://northwind.test',
        'siteurl' => 'http://northwind.test',
        'blogname' => 'Northwind',
        'blogdescription' => '',
        'stylesheet' => 'northwind',
        'template' => 'northwind',
        'date_format' => 'F j, Y',
        'theme_mods_northwind' => ['footer_text' => 'Made in Oslo', 'header_color' => '#000000', 'hero_image' => 'http://northwind.test/wp-content/uploads/2026/09/hero.png', 'draft_note' => 'Draft only'],
        Claude_Cowork_Content_Source::SITE_OPTION => str_repeat('ab', 16),
    ];
    WP_Fake::$posts[12] = ['ID' => 12, 'post_type' => 'page', 'post_name' => 'about', 'post_status' => 'publish', 'post_parent' => 0,
        'post_title' => 'About Northwind', 'post_excerpt' => '', 'post_content' => '<!-- wp:paragraph --><p>Welcome aboard</p><!-- /wp:paragraph -->'];
    WP_Fake::$meta['12:_elementor_data'] = '[{"id":"a1","elType":"widget","settings":{"title":"Ships in 24h","title_color":"#fff"}}]';
    WP_Fake::$meta['12:_edit_last'] = '1';
    WP_Fake::$meta['12:' . Claude_Cowork_Content_Source::UID_META] = str_repeat('c', 32);
    WP_Fake::$termRows[40] = ['term_id' => 40, 'name' => 'Main', 'slug' => 'main', 'description' => '', 'parent' => 0, 'taxonomy' => 'nav_menu'];
    WP_Fake::$termRows[5] = ['term_id' => 5, 'name' => 'News', 'slug' => 'news', 'description' => 'Latest from Northwind', 'parent' => 0, 'taxonomy' => 'category'];
    // WordPress stores a term name escaped.
    WP_Fake::$termRows[6] = ['term_id' => 6, 'name' => 'Food &amp; Drink', 'slug' => 'food-drink', 'description' => '', 'parent' => 0, 'taxonomy' => 'category'];
    // An autoloaded plugin option with words no page shows, and a counter beside nothing.
    WP_Fake::$options['plugin_label'] = 'Book a table';
    WP_Fake::$options['visit_counter'] = '17';
    // A classic menu entry with no title of its own: it shows the page's.
    WP_Fake::$posts[30] = ['ID' => 30, 'post_type' => 'nav_menu_item', 'post_title' => '', 'post_status' => 'publish', 'menu_order' => 1];
    WP_Fake::$meta['30:_menu_item_type'] = 'post_type';
    WP_Fake::$meta['30:_menu_item_object'] = 'page';
    WP_Fake::$meta['30:_menu_item_object_id'] = 12;
    WP_Fake::$meta['30:_menu_item_url'] = '';
    WP_Fake::$meta['30:_menu_item_menu_item_parent'] = 0;
    WP_Fake::$menuOf[30] = 40;
    $GLOBALS['wpdb'] = new DwDb();
};
$dwPages = ['<html><body><h1>About Northwind</h1><p>Welcome aboard</p><div>Ships in 24h</div><footer>Made in Oslo</footer>'
    . '<nav><a href="/about/">About Northwind</a></nav><img src="/wp-content/uploads/2026/09/hero.png" alt=""><p>Words from a theme file</p></body></html>'];
$dwPermalink = static fn(int $id): ?string => $id === 12 ? 'http://northwind.test/about/' : null;
$dwRowsOf = static function (?callable $get = null) use ($dwPermalink): WordPressDerivedRows {
    return new WordPressDerivedRows($GLOBALS['wpdb'], ['post', 'page', 'attachment'], ['category', 'post_tag'], 'http://northwind.test/', $dwPermalink,
        $get ?? static fn(): array => ['code' => 0, 'body' => '']);
};
/** The contract the plugin wires: the real store over the fake options, rows read lazily. */
$dwContractOf = static function (SiteWriter $writer, string $configured = '', ?int &$builds = null) use ($dwRowsOf, $dwRoot, $FIXTURES): QuickstartContract {
    $builds = 0;
    return (new QuickstartContract($writer, new Claude_Cowork_Contract_Store(), $dwRoot, $FIXTURES, $configured))
        ->withDerivedRows(static function () use ($dwRowsOf, &$builds): array {
            $builds++;
            return $dwRowsOf()->rows();
        });
};
$dwEngineOf = static function (SiteWriter $writer, ApplyLog $log, QuickstartContract $contract, array $pages, ?callable $fetch = null, ?callable $purge = null, ?callable $ownerUrl = null) use ($dwRowsOf): Engine {
    return (new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $writer, new FakeMediaWriter(), $log, null, $contract))
        ->derivedSource(static function () use ($dwRowsOf, $pages): array {
            $unresolved = [];
            $rows = $dwRowsOf()->rows($unresolved);
            return ['rows' => $rows, 'pages' => $pages, 'unresolved' => $unresolved];
        }, $fetch, $purge, $ownerUrl);
};
$dwDerive = static fn(string $requestId, string $label = 'northwind-import') =>
    ['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'derive', 'label' => $label, 'requestId' => $requestId]];
$dwDoor = static fn(Engine $engine, string $operation, array $params = []) =>
    $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
$dwSlot = static function (array $inspect, string $current): array {
    foreach ($inspect['slotDetails'] ?? [] as $slot) {
        if (($slot['current'] ?? null) === $current) {
            return $slot;
        }
    }
    return ['key' => 'missing: ' . $current, 'entity' => null, 'column' => null, 'type' => null, 'leaf' => null];
};
$dwApply = static function (Engine $engine, array $changes, string $id) use ($dwDoor, $dwSlot): array {
    $state = $dwDoor($engine, 'inspect');
    $keys = [];
    foreach ($changes as $current => $value) {
        $keys[$dwSlot($state, $current)['key']] = $value;
    }
    return $dwDoor($engine, 'apply', ['apply_id' => 'contract-' . $id, 'request_id' => $id, 'expected_revision' => $state['revision'] ?? '', 'changes' => $keys]);
};

// ── the rows: what WordPressDerivedRows reads (A7) ────────────────────────────────────────────

$dwSeed();
$dwUnresolved = [];
$dwRows = $dwRowsOf()->rows($dwUnresolved);
$dwByKey = [];
foreach ($dwRows as $row) {
    $dwByKey[$row['kind'] . '-' . $row['id']] = $row;
}
check('rows: a published page, its title, excerpt and markup', [$dwByKey['post-12']['core'] ?? null, $dwByKey['post-12']['html'] ?? null, $dwByKey['post-12']['identity'] ?? null],
    [['post_title' => 'About Northwind', 'post_excerpt' => ''], ['post_content' => '<!-- wp:paragraph --><p>Welcome aboard</p><!-- /wp:paragraph -->'], ['postType' => 'page', 'slug' => 'about']]);
check('rows: a builder meta is one nested column, addressed by post and key',
    [$dwByKey['postmeta-' . DwDb::metaId(12, '_elementor_data')]['identity'] ?? null, isset($dwByKey['postmeta-' . DwDb::metaId(12, '_elementor_data')]['nested']['meta_value'])],
    [['postId' => 12, 'key' => '_elementor_data'], true]);
check('rows: bookkeeping meta is not read', [isset($dwByKey['postmeta-' . DwDb::metaId(12, '_edit_last')]), isset($dwByKey['postmeta-' . DwDb::metaId(12, Claude_Cowork_Content_Source::UID_META)])], [false, false]);
check('rows: a theme\'s options are read raw, serialized', $dwByKey['option-' . DwDb::optionId('theme_mods_northwind')]['nested']['option_value'] ?? null,
    serialize(WP_Fake::$options['theme_mods_northwind']));
check('rows: configuration options are not read', [isset($dwByKey['option-' . DwDb::optionId('home')]), isset($dwByKey['option-' . DwDb::optionId('date_format')]),
    isset($dwByKey['option-' . DwDb::optionId(Claude_Cowork_Content_Source::SITE_OPTION)]), isset($dwByKey['option-' . DwDb::optionId('blogname')])], [false, false, false, true]);
check('rows: a public term, keyed by its term_taxonomy_id, its name and description', [$dwByKey['term-1005']['core'] ?? null, $dwByKey['term-1005']['html'] ?? null, $dwByKey['term-1005']['identity'] ?? null],
    [['name' => 'News'], ['description' => 'Latest from Northwind'], ['taxonomy' => 'category', 'slug' => 'news', 'termId' => 5]]);
check('rows: a term name is read decoded', $dwByKey['term-1006']['core'] ?? null, ['name' => 'Food & Drink']);
check('rows: a menu (not a public taxonomy) is not a term', isset($dwByKey['term-1040']), false);
$GLOBALS['wpdb']->sharedTerms = [['term_taxonomy_id' => 2005, 'term_id' => 5, 'taxonomy' => 'post_tag', 'description' => 'Tagged news']];
$dwShared = [];
foreach ($dwRowsOf()->rows() as $row) {
    $dwShared[$row['kind'] . '-' . $row['id']] = $row;
}
check('rows: a legacy shared term is one row per taxonomy, its one name a slot of the first only',
    [$dwShared['term-1005']['core'] ?? null, $dwShared['term-2005']['core'] ?? null, $dwShared['term-2005']['html'] ?? null],
    [['name' => 'News'], [], ['description' => 'Tagged news']]);
$GLOBALS['wpdb']->sharedTerms = [];
check('rows: a classic menu entry without a title shows its page\'s title, and knows its menu',
    [$dwByKey['menuItem-30']['core'] ?? null, $dwByKey['menuItem-30']['identity'] ?? null], [['post_title' => 'About Northwind'], ['id' => 30, 'menu' => 'main']]);
check('rows: nothing was too large to scan', $dwUnresolved, []);

// Pages over loopback: 127.0.0.1 with the site's Host, the preview header, one deadline.
$dwAsked = [];
$dwGet = static function (string $url, array $headers, int $timeout, ?string $resolve) use (&$dwAsked): array {
    $dwAsked[] = [$url, $headers, $resolve];
    $body = strpos($url, '/about/') !== false ? '<p>About page</p>' : ($url === 'http://127.0.0.1/' ? '<p>Home page</p>' : null);
    return $body === null ? ['code' => 404, 'body' => 'Not found'] : ['code' => 200, 'body' => $body];
};
$dwFetched = $dwRowsOf($dwGet)->pages();
check('pages: home and the menu\'s page, each once, a page that failed left out', $dwFetched, ['<p>Home page</p>', '<p>About page</p>']);
check('pages: asked over loopback with the site\'s Host and the preview header',
    $dwAsked[0] ?? null, ['http://127.0.0.1/', ['Host' => 'northwind.test', 'X-Tracy-Preview' => 'pick'], null]);
$dwHttpsAsked = [];
$dwHttps = new WordPressDerivedRows($GLOBALS['wpdb'], ['page'], [], 'https://northwind.test/', $dwPermalink, static function (string $url, array $headers, int $timeout, ?string $resolve) use (&$dwHttpsAsked): array {
    $dwHttpsAsked[] = [$url, $headers['Host'], $resolve];
    // Plain http redirects to https, as a site forcing https does.
    return $resolve === null ? ['code' => 301, 'body' => ''] : ['code' => 200, 'body' => '<p>over https: ' . $url . '</p>'];
});
$dwHttpsPages = $dwHttps->fetch(['https://northwind.test/about/', 'https://northwind.test/contact/']);
check('pages: a post and its meta are checked on the post, everything else on the home page',
    [$dwRowsOf()->ownerUrl('post', 12, []), $dwRowsOf()->ownerUrl('postmeta', 99, ['postId' => 12, 'key' => '_elementor_data']), $dwRowsOf()->ownerUrl('option', 3, ['name' => 'blogname']),
        $dwRowsOf()->ownerUrl('post', 13, [])], ['http://northwind.test/about/', 'http://northwind.test/about/', 'http://northwind.test/', null]);
check('pages: an https site is asked over plain http first, then by its own name resolved to this machine; the next page starts on the route that answered',
    [$dwHttpsAsked, array_keys($dwHttpsPages)], [[['http://127.0.0.1/about/', 'northwind.test', null], ['https://northwind.test/about/', 'northwind.test', 'northwind.test:443:127.0.0.1'],
        ['https://northwind.test/contact/', 'northwind.test', 'northwind.test:443:127.0.0.1']], ['https://northwind.test/about/', 'https://northwind.test/contact/']]);
$dwNoCurl = [];
(new WordPressDerivedRows($GLOBALS['wpdb'], ['page'], [], 'https://northwind.test/', $dwPermalink, static function (string $url, array $headers, int $timeout, ?string $resolve) use (&$dwNoCurl): array {
    $dwNoCurl[] = $url;
    return ['code' => 301, 'body' => ''];
}, false))->fetch(['https://northwind.test/about/']);
check('pages: without curl there is no route: nothing is asked, nothing calibrates', $dwNoCurl, []);
$dwHttpsAsked = [];
$dwPlain = new WordPressDerivedRows($GLOBALS['wpdb'], ['page'], [], 'https://northwind.test/', $dwPermalink, static function (string $url, array $headers, int $timeout, ?string $resolve) use (&$dwHttpsAsked): array {
    $dwHttpsAsked[] = $url;
    return ['code' => 200, 'body' => '<p>served over http</p>'];
});
check('pages: when plain http answers, https is never tried', [$dwPlain->fetch(['https://northwind.test/about/']), $dwHttpsAsked],
    [['https://northwind.test/about/' => '<p>served over http</p>'], ['http://127.0.0.1/about/']]);

// The loopback never goes through WordPress's HTTP API: its transport, a `pre_http_request` answer or
// a proxy could send the https route to the customer's live site under the copy's real domain name.
if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = [])
    {
        $GLOBALS['dwRemoteGets'][] = $url;
        return new WP_Error('the loopback must not use wp_remote_get');
    }
}
$GLOBALS['dwRemoteGets'] = [];
// Port 1 on this machine answers nothing: both routes fail fast, through curl alone.
$dwPlainAnswer = WordPressDerivedRows::httpGet('http://127.0.0.1:1/about/', ['Host' => 'northwind.test', 'X-Tracy-Preview' => 'pick'], 2, null);
$dwTlsAnswer = WordPressDerivedRows::httpGet('https://northwind.test:1/about/', ['Host' => 'northwind.test:1', 'X-Tracy-Preview' => 'pick'], 2, 'northwind.test:1:127.0.0.1');
$dwRefused = WordPressDerivedRows::httpGet('https://northwind.test:1/about/', ['Host' => 'northwind.test:1'], 2, 'elsewhere.test:1:127.0.0.1');
$dwOffBox = WordPressDerivedRows::httpGet('http://northwind.test/about/', ['Host' => 'northwind.test'], 2, null);
check('loopback: nothing answering is code 0 on both routes', [$dwPlainAnswer['code'], $dwTlsAnswer['code']], [0, 0]);
check('loopback: a resolve entry that is not the URL\'s own host, or plain http off this machine, is never sent', [$dwRefused['code'], $dwOffBox['code']], [0, 0]);
$dwSiteRows = WordPressDerivedRows::forSite();
$dwSiteRows->fetch(['/about/'], 3);
check('loopback: wp_remote_get is never called, directly or through a wired fetch', $GLOBALS['dwRemoteGets'], []);

// ── derive (A8) ────────────────────────────────────────────────────────────────────────────────

$dwSeed();
$dwWriter = new Claude_Cowork_Site_Writer();
$dwLog = new FakeApplyLog();
$dwEngine = $dwEngineOf($dwWriter, $dwLog, $dwContractOf($dwWriter), $dwPages);
$dwAnswer = $dwEngine->handle($dwDerive('derive-1'));
check('derive: answers ok on an unbound imported site', ($dwAnswer['ok'] ?? false) ? true : $dwAnswer, true);
$dwBinding = json_decode((string) (WP_Fake::$options[QuickstartContract::STORE_OPTION] ?? 'null'), true);
check('derive: the binding is derived and names its label and request',
    [$dwBinding['mode'] ?? null, $dwBinding['contract'] ?? null, $dwBinding['requestId'] ?? null, $dwBinding['calibrated'] ?? null, $dwBinding['schemaVersion'] ?? null],
    ['derived', 'derived/northwind-import', 'derive-1', true, 1]);
check('derive: the contract hash names the algorithm, never the rows', $dwBinding['contractHash'] ?? null, hash('sha256', (string) json_encode(DerivedMap::hashBasis(DerivedMap::ALGORITHM))));
check('derive: the answer counts entities and classes', [$dwAnswer['contract'] ?? null, $dwAnswer['entities'] ?? null, $dwAnswer['calibrated'] ?? null, $dwAnswer['replayed'] ?? null,
    $dwAnswer['byClass'] ?? null, $dwAnswer['slots'] ?? null, $dwAnswer['unresolved'] ?? null],
    ['derived/northwind-import', 7, true, false, ['db' => 6, 'nested' => 4, 'unmatched' => 1], 10, []]);
check('derive: the kept nested leaves are stored for later reads', count($dwBinding['keep'] ?? []), 4);

$dwReplay = $dwEngine->handle($dwDerive('derive-1'));
check('derive: the same request again answers what it bound', [$dwReplay['ok'] ?? null, $dwReplay['replayed'] ?? null, $dwReplay['contract'] ?? null], [true, true, 'derived/northwind-import']);
check('derive: and leaves the binding as it was', json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true), $dwBinding);
$dwBad = $dwEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'derive', 'label' => 'No', 'requestId' => 'derive-2']]);
check('derive: a malformed label is refused', [$dwBad['ok'], $dwBad['error']], [false, 'bad_params']);
$dwBad = $dwEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'derive', 'label' => 'northwind-import']]);
check('derive: a missing requestId is refused', [$dwBad['ok'], $dwBad['error']], [false, 'bad_params']);

// The hash holds whatever the rows become: a re-derive after an edit binds the same hash.
WP_Fake::$posts[12]['post_title'] = 'About the company';
$dwEngineOf($dwWriter, $dwLog, $dwContractOf($dwWriter), $dwPages)->handle($dwDerive('derive-1b'));
$dwRebound = json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true);
check('derive: a new request re-derives, with the same hash', [$dwRebound['requestId'] ?? null, $dwRebound['contractHash'] ?? null], ['derive-1b', $dwBinding['contractHash']]);
WP_Fake::$posts[12]['post_title'] = 'About Northwind';
$dwEngineOf($dwWriter, $dwLog, $dwContractOf($dwWriter), $dwPages)->handle($dwDerive('derive-1'));
$dwBinding = json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true);

// A db.* or info call on a derived site never scans its rows.
$dwLazyBuilds = 0;
$dwLazy = new Engine($WTOKEN, ['php' => PHP_VERSION], null, null, null, null, $dwWriter, new FakeMediaWriter(), $dwLog, null, $dwContractOf($dwWriter, '', $dwLazyBuilds));
$dwRowQueries = $GLOBALS['wpdb']->rowQueries;
$dwLazy->handle(['token' => $WTOKEN, 'action' => 'info']);
$dwLazy->handle(['token' => $WTOKEN, 'action' => 'db.tables']);
$dwLazy->handle(['token' => $WTOKEN, 'action' => 'db.purge', 'params' => ['tables' => []]]);
check('lazy: info and db.* on a derived site read no row', [$dwLazyBuilds, $GLOBALS['wpdb']->rowQueries - $dwRowQueries], [0, 0]);

// A site bound to a quickstart keeps its binding; a site a quickstart build is making stays unbound.
$dwSeed();
WP_Fake::$options[QuickstartContract::STORE_OPTION] = (string) json_encode(['schemaVersion' => 1, 'contract' => 'test-design/wp7/1.0.0', 'contractHash' => 'quickstart-hash', 'revision' => 'r', 'ids' => []]);
$dwQs = $dwEngineOf($dwWriter, new FakeApplyLog(), $dwContractOf($dwWriter), $dwPages)->handle($dwDerive('derive-qs'));
check('derive: a quickstart-bound site refuses', [$dwQs['ok'], $dwQs['error'], $dwQs['message']],
    [false, 'conflict', 'This site is bound to a quickstart contract; derive refuses to replace it']);
check('derive: and its binding stands', json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true)['contractHash'], 'quickstart-hash');
$dwSeed();
$dwBuild = $dwEngineOf($dwWriter, new FakeApplyLog(), $dwContractOf($dwWriter, 'test-design/wp7/1.0.0'), $dwPages)->handle($dwDerive('derive-build'));
check('derive: a site configured for a quickstart and not yet bound refuses', [$dwBuild['ok'], $dwBuild['error'], $dwBuild['message']],
    [false, 'conflict', 'This site is being built from a quickstart; derive refuses']);
check('derive: and stays unbound', isset(WP_Fake::$options[QuickstartContract::STORE_OPTION]), false);

// No page could be fetched (loopback blocked): bound all the same, uncalibrated, every nested leaf kept.
$dwSeed();
$dwBlind = $dwEngineOf($dwWriter, new FakeApplyLog(), $dwContractOf($dwWriter), [])->handle($dwDerive('derive-blind'));
$dwBlindBinding = json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true);
check('derive: without pages it still binds, uncalibrated', [$dwBlind['ok'] ?? null, $dwBlind['calibrated'] ?? null, $dwBlindBinding['calibrated'] ?? null], [true, false, false]);
check('derive: an uncalibrated binding keeps null', array_key_exists('keep', $dwBlindBinding) ? $dwBlindBinding['keep'] : 'absent', null);
$dwBlindState = $dwDoor(new Engine($WTOKEN, [], null, null, null, null, $dwWriter, new FakeMediaWriter(), new FakeApplyLog(), null, $dwContractOf($dwWriter)), 'inspect');
check('derive: a later read keeps every nested leaf of what it keeps, the one no page shows too', $dwSlot($dwBlindState, 'Draft only')['column'], 'option_value');
check('derive: uncalibrated, options are only the words ones (blogname, theme mods, widgets)',
    [$dwSlot($dwBlindState, 'Northwind')['column'], $dwSlot($dwBlindState, 'Book a table')['column'], $dwSlot($dwBlindState, 'About Northwind')['column']],
    ['option_value', null, 'post_title']);

// ── inspect, apply, revert (A8) ─────────────────────────────────────────────────────────────

$dwSeed();
$dwEngineOf($dwWriter, $dwLog, $dwContractOf($dwWriter), $dwPages)->handle($dwDerive('derive-1'));
$dwBuilds = 0;
$dwEngine = $dwEngineOf($dwWriter, $dwLog = new FakeApplyLog(), $dwContractOf($dwWriter, '', $dwBuilds), $dwPages);
$dwState = $dwDoor($dwEngine, 'inspect');
check('inspect: answers ok, bound to the derived contract', [$dwState['ok'] ?? $dwState, $dwState['bound'] ?? null, $dwState['contract'] ?? null], [true, true, 'derived/northwind-import']);
check('inspect: the map was built once', $dwBuilds, 1);
$dwTitle = $dwSlot($dwState, 'About Northwind');
$dwHtml = $dwSlot($dwState, 'Welcome aboard');
$dwJson = $dwSlot($dwState, 'Ships in 24h');
$dwSer = $dwSlot($dwState, 'Made in Oslo');
check('inspect: a title is a whole column', [$dwTitle['entity'], $dwTitle['column'], $dwTitle['leaf']], ['post-12', 'post_title', null]);
check('inspect: a leaf in post_content (HTML)', [$dwHtml['entity'], $dwHtml['column'], $dwHtml['leaf']], ['post-12', 'post_content', 'html:g0|text:']);
check('inspect: a leaf in _elementor_data (JSON)', [$dwJson['entity'], $dwJson['column'], $dwJson['leaf']],
    ['postmeta-' . DwDb::metaId(12, '_elementor_data'), 'meta_value', 'json:/0/settings/title|text:']);
check('inspect: a leaf in theme_mods (serialize)', [$dwSer['entity'], $dwSer['column'], $dwSer['leaf']],
    ['option-' . DwDb::optionId('theme_mods_northwind'), 'option_value', 'ser:/footer_text|text:']);
$dwMenu = array_values(array_filter($dwState['slotDetails'] ?? [], static fn($s) => $s['entity'] === 'menuItem-30'));
check('inspect: a classic menu label, shown as its page\'s title', [count($dwMenu), $dwMenu[0]['column'] ?? null, $dwMenu[0]['current'] ?? null], [1, 'post_title', 'About Northwind']);
check('inspect: no problem, no drift', [$dwState['problems'] ?? null, $dwState['warnings'] ?? null], [[], null]);

$dwContentBefore = WP_Fake::$posts[12]['post_content'];
$dwHtmlApply = $dwApply($dwEngine, ['Welcome aboard' => 'Welcome & enjoy'], 'dw-html');
check('apply: an HTML leaf is rewritten in place, escaped', [$dwHtmlApply['ok'] ?? $dwHtmlApply, WP_Fake::$posts[12]['post_content']],
    [true, '<!-- wp:paragraph --><p>Welcome &amp; enjoy</p><!-- /wp:paragraph -->']);
check('apply: the receipt names the row written', $dwHtmlApply['written'] ?? null, [['kind' => 'post', 'id' => 12, 'key' => null]]);
$dwReverted = $dwEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-dw-html']]);
check('revert: an HTML leaf is back to the byte', [$dwReverted['ok'] ?? $dwReverted, WP_Fake::$posts[12]['post_content']], [true, $dwContentBefore]);

$dwJsonBefore = WP_Fake::$meta['12:_elementor_data'];
$dwJsonApply = $dwApply($dwEngine, ['Ships in 24h' => 'Ships in 48h'], 'dw-json');
check('apply: a builder JSON leaf', [$dwJsonApply['ok'] ?? $dwJsonApply, json_decode((string) WP_Fake::$meta['12:_elementor_data'], true)[0]['settings']],
    [true, ['title' => 'Ships in 48h', 'title_color' => '#fff']]);
check('apply: the meta is still a JSON string, not an array', is_string(WP_Fake::$meta['12:_elementor_data']), true);
$dwEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-dw-json']]);
check('revert: the JSON is back to the byte', WP_Fake::$meta['12:_elementor_data'], $dwJsonBefore);

$dwModsBefore = WP_Fake::$options['theme_mods_northwind'];
$dwSerApply = $dwApply($dwEngine, ['Made in Oslo' => 'Made in Bergen'], 'dw-ser');
check('apply: a serialized option leaf', $dwSerApply['ok'] ?? $dwSerApply, true);
check('apply: get_option reads the option back as an ARRAY, the new leaf in it, the rest untouched', get_option('theme_mods_northwind'),
    ['footer_text' => 'Made in Bergen', 'header_color' => '#000000', 'hero_image' => 'http://northwind.test/wp-content/uploads/2026/09/hero.png', 'draft_note' => 'Draft only']);
$dwEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-dw-ser']]);
check('revert: the option is the same array again', get_option('theme_mods_northwind'), $dwModsBefore);

$dwMenuApply = $dwDoor($dwEngine, 'apply', ['apply_id' => 'contract-dw-menu', 'request_id' => 'dw-menu', 'expected_revision' => $dwDoor($dwEngine, 'inspect')['revision'],
    'changes' => [$dwMenu[0]['key'] => 'Our story']]);
check('apply: a classic menu label is written to the entry\'s own title', [$dwMenuApply['ok'] ?? $dwMenuApply, WP_Fake::$posts[30]['post_title'], WP_Fake::$posts[12]['post_title']],
    [true, 'Our story', 'About Northwind']);
check('apply: and the entry still points at its page', [WP_Fake::$meta['30:_menu_item_object_id'], WP_Fake::$menuOf[30]], [12, 40]);
$dwEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-dw-menu']]);
check('revert: the entry has no title of its own again', WP_Fake::$posts[30]['post_title'], '');

// A term name: shown decoded, written back escaped as WordPress stores it, reverted to the byte.
$dwTermApply = $dwApply($dwEngine, ['Food & Drink' => 'Food & Wine'], 'dw-term');
check('apply: a term name is written escaped as WordPress stores it', [$dwTermApply['ok'] ?? $dwTermApply, WP_Fake::$termRows[6]['name']], [true, 'Food &amp; Wine']);
check('apply: and reads back decoded', $dwSlot($dwDoor($dwEngine, 'inspect'), 'Food & Wine')['entity'], 'term-1006');
$dwEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-dw-term']]);
check('revert: the term name is back to the byte', WP_Fake::$termRows[6]['name'], 'Food &amp; Drink');

// The revision holds the slots' words: a counter or a setting beside them does not make a read stale.
$dwRevisionBefore = $dwDoor($dwEngine, 'inspect')['revision'];
WP_Fake::$options['theme_mods_northwind']['header_color'] = '#111111';
WP_Fake::$options['visit_counter'] = '18';
check('revision: a non-slot key in a slot\'s option and an unmapped counter do not move it', $dwDoor($dwEngine, 'inspect')['revision'], $dwRevisionBefore);
WP_Fake::$options['theme_mods_northwind']['header_color'] = '#000000';

$dwImage = $dwSlot($dwState, 'http://northwind.test/wp-content/uploads/2026/09/hero.png');
$dwImageApply = $dwApply($dwEngine, ['http://northwind.test/wp-content/uploads/2026/09/hero.png' => 'wp-content/uploads/2026/09/new.png'], 'dw-image');
check('apply: a derived picture is written in the form its slot holds', [$dwImage['type'], $dwImageApply['ok'] ?? $dwImageApply, get_option('theme_mods_northwind')['hero_image']],
    ['image', true, 'http://northwind.test/wp-content/uploads/2026/09/new.png']);
$dwBadImage = $dwApply($dwEngine, ['http://northwind.test/wp-content/uploads/2026/09/new.png' => 'wp-content/uploads/2026/09/missing.png'], 'dw-image-bad');
check('apply: a picture that is not in the uploads is refused', [$dwBadImage['ok'], $dwBadImage['errors'][0]['code'] ?? null], [false, 'SLOT_IMAGE_INVALID']);
$dwEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-dw-image']]);
$dwMarkup = $dwApply($dwEngine, ['Welcome aboard' => 'Welcome <b>aboard</b>'], 'dw-markup');
check('apply: markup is not content on a derived slot either', [$dwMarkup['ok'], $dwMarkup['errors'][0]['code'] ?? null], [false, 'SLOT_NOT_CONTENT']);

// The option a string override lives in (A10) is the contract's too: no content.update or content.delete.
foreach (['content.update' => ['fields' => ['value' => '{}']], 'content.delete' => []] as $dwAction => $dwExtra) {
    $dwProtected = $dwEngine->handle(['token' => $WTOKEN, 'action' => $dwAction, 'params' => ['apply_id' => 'dw-protect', 'kind' => 'option', 'key' => 'claude_cowork_string_overrides'] + $dwExtra]);
    check($dwAction . ': the string override option is refused', [$dwProtected['ok'], $dwProtected['error'] ?? null], [false, 'bad_params']);
}

// A quickstart bind on a site a derive bound: refused, the derived binding stands.
$dwBound = WP_Fake::$options[QuickstartContract::STORE_OPTION];
$dwQsBind = $dwDoor($dwEngine, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
check('bind: a quickstart bind on a derived site is refused', [$dwQsBind['ok'], $dwQsBind['error'] ?? null], [false, 'conflict']);
check('bind: and the derived binding stands', WP_Fake::$options[QuickstartContract::STORE_OPTION], $dwBound);
$dwTrim = $dwDoor($dwEngine, 'demoTrim.plan');
check('a derived contract has no demo trim', [$dwTrim['ok'], $dwTrim['error'] ?? null], [false, 'unsupported']);

// ── content.read on a derived site (A8 step 5) ─────────────────────────────────────────────
// One row that does not read back as the table holds it (a filter answers get_option, as Polylang or
// WPML strings do) is left out, named, and blocks nothing else.
$dwSeed();
WP_Fake::$options['widget_pll'] = ['title' => 'Bonjour'];
$GLOBALS['wpdb']->rawOptions['widget_pll'] = serialize(['title' => 'Hello there']);
$dwFilteredPages = [$dwPages[0] . '<p>Hello there</p>'];
$dwFiltered = $dwEngineOf($dwWriter, new FakeApplyLog(), $dwContractOf($dwWriter), $dwFilteredPages)->handle($dwDerive('derive-filtered'));
check('filtered: derive binds all the same', [$dwFiltered['ok'] ?? $dwFiltered, $dwFiltered['calibrated'] ?? null], [true, true]);
checkTrue('filtered: and names the row it left out', in_array('option ' . DwDb::optionId('widget_pll') . '.option_value does not read back as stored (a filter or a plugin rewrites it), not offered',
    $dwFiltered['unresolved'] ?? [], true));
$dwFilteredEngine = $dwEngineOf($dwWriter, new FakeApplyLog(), $dwContractOf($dwWriter), $dwFilteredPages);
$dwFilteredState = $dwDoor($dwFilteredEngine, 'inspect');
check('filtered: no slot of it, no problem', [$dwSlot($dwFilteredState, 'Hello there')['key'], $dwSlot($dwFilteredState, 'Bonjour')['key'], $dwFilteredState['problems'] ?? null],
    ['missing: Hello there', 'missing: Bonjour', []]);
$dwGood = $dwApply($dwFilteredEngine, ['Made in Oslo' => 'Made in Bergen'], 'dw-filtered');
check('filtered: a good slot beside it applies', [$dwGood['ok'] ?? $dwGood, get_option('theme_mods_northwind')['footer_text']], [true, 'Made in Bergen']);

// A slot whose row moved under it since the map was built: drift for that slot, the others still apply.
$dwMoved = $dwContractOf($dwWriter);
$dwMovedEngine = $dwEngineOf($dwWriter, new FakeApplyLog(), $dwMoved, $dwFilteredPages);
$dwMovedState = $dwDoor($dwMovedEngine, 'inspect');
WP_Fake::$posts[12]['post_content'] = 'Plain words now';
$dwMovedState = $dwDoor($dwMovedEngine, 'inspect');
check('moved: an unreadable slot is a warning, never a problem', [$dwMovedState['ok'] ?? $dwMovedState, count($dwMovedState['warnings'] ?? []), $dwMovedState['problems'] ?? null], [true, 1, []]);
$dwMovedApply = $dwApply($dwMovedEngine, ['Made in Bergen' => 'Made in Oslo'], 'dw-moved');
check('moved: another slot still applies', $dwMovedApply['ok'] ?? $dwMovedApply, true);
WP_Fake::$posts[12]['post_content'] = $dwContentBefore;

// content.read: a markup leaf marks the field the markup already shows, and is listed once.
$dwMerged = Claude_Cowork_Content_Source::mergeDerived(
    ['id' => 'c1', 'blocks' => [['id' => 'b1', 'key' => 'intro', 'fields' => [['key' => 'intro', 'type' => 'text', 'value' => 'Welcome aboard', 'slotKey' => null, 'semanticKey' => null]], 'items' => []]]],
    [['slot' => ['key' => 'post-12.post_content.aaaa', 'column' => 'post_content', 'type' => 'text'], 'value' => 'Welcome aboard'],
     ['slot' => ['key' => 'postmeta-9.meta_value.bbbb', 'column' => 'meta_value', 'type' => 'text'], 'value' => 'Ships in 24h']],
    static fn(string $key): string => 'b-' . $key);
check('read: a post_content leaf appears once, on its markup field, with its slot key',
    [count($dwMerged['blocks']), $dwMerged['blocks'][0]['fields'][0]['slotKey'], $dwMerged['blocks'][1]['fields'][0]['slotKey'] ?? null],
    [2, 'post-12.post_content.aaaa', 'postmeta-9.meta_value.bbbb']);
// A widget option's slots, one block per widget instance, keyed as the widget id ProvenanceStamps
// prints (`widget:text-3` -> block `widget-text-3`), so a picked widget confirms its own slots.
$dwWidgetRaw = serialize([2 => ['title' => 'Opening hours', 'text' => 'Mon to Fri'], 3 => ['title' => 'Find us'], '_multiwidget' => 1]);
$dwWidgetSlots = [];
foreach (LeafCodec::leaves($dwWidgetRaw) as $dwLeaf) {
    $dwWidgetSlots[] = ['slot' => ['key' => 'option-77.option_value.' . substr(sha1($dwLeaf['path']), 0, 10), 'column' => 'option_value', 'type' => 'text', 'leaf' => $dwLeaf['path']],
        'value' => $dwLeaf['text'], 'block' => Claude_Cowork_Content_Source::widgetBlock('widget_text', $dwLeaf['path'])];
}
$dwWidgets = Claude_Cowork_Content_Source::mergeDerived(['id' => 'c9', 'blocks' => []], $dwWidgetSlots, static fn(string $key): string => 'b-' . $key);
check('read: a widget option\'s slots sit in one block per instance, named as the widget id',
    array_map(static fn($b) => [$b['key'], array_column($b['fields'], 'value')], $dwWidgets['blocks']),
    [['widget-text-2', ['Opening hours', 'Mon to Fri']], ['widget-text-3', ['Find us']]]);
check('read: each field keeps its own slot key', array_map(static fn($b) => array_column($b['fields'], 'slotKey'), $dwWidgets['blocks']),
    [[$dwWidgetSlots[0]['slot']['key'], $dwWidgetSlots[1]['slot']['key']], [$dwWidgetSlots[2]['slot']['key']]]);
check('read: no block name for a non-widget option or a leaf with no instance',
    [Claude_Cowork_Content_Source::widgetBlock('theme_mods_northwind', 'ser:/2/title|text:'), Claude_Cowork_Content_Source::widgetBlock('widget_text', 'text:'),
        Claude_Cowork_Content_Source::widgetBlock('widget_nav_menu', 'ser:/_multiwidget|text:'), Claude_Cowork_Content_Source::widgetBlock('widget_media_image', 'ser:/4/url|text:')],
    [null, null, null, 'widget-media_image-4']);

$dwSeed();
$dwEngineOf($dwWriter, new FakeApplyLog(), $dwContractOf($dwWriter), $dwPages)->handle($dwDerive('derive-1'));
$dwBinding = json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true);
$dwSource = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test', static fn(): array => $dwRowsOf()->rows());
$dwFingerprint = $dwSource->revision();
$dwSource->release();
WP_Fake::$options['visit_counter'] = '99';
$dwSource = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test', static fn(): array => $dwRowsOf()->rows());
check('read: an autoloaded counter no slot holds does not move the snapshot revision', $dwSource->revision(), $dwFingerprint);
$dwSource->release();


$dwSource = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test', static fn(): array => $dwRowsOf()->rows());
$dwProvenance = $dwSource->provenance();
check('read: the envelope names the derived contract', [$dwProvenance['quickstartTag'] ?? null, $dwProvenance['quickstartVersion'] ?? null, $dwProvenance['contractId'] ?? null, $dwProvenance['contractHash'] ?? null],
    ['derived', (string) DerivedMap::ALGORITHM, 'derived/northwind-import', $dwBinding['contractHash']]);
$dwSummaries = [];
foreach ($dwSource->summaries() as $summary) {
    $dwSummaries[json_encode($summary['native'])] = $summary;
}
$dwPage = $dwSummaries[json_encode([['kind' => 'post', 'id' => 12]])] ?? null;
checkTrue('read: the page is listed as a page', ($dwPage['type'] ?? null) === 'page');
$dwDetail = $dwPage === null ? [] : $dwSource->detail($dwPage['id']);
$dwFields = [];
foreach ($dwDetail['blocks'] ?? [] as $block) {
    foreach ($block['fields'] as $field) {
        if ($field['slotKey'] !== null) {
            $dwFields[$field['slotKey']] = [$field['value'], $field['semanticKey']];
        }
    }
}
check('read: the page carries its derived slots, the builder meta\'s among them', [$dwFields[$dwTitle['key']] ?? null, $dwFields[$dwHtml['key']] ?? null, $dwFields[$dwJson['key']] ?? null],
    [['About Northwind', 'post_title'], ['Welcome aboard', 'post_content'], ['Ships in 24h', 'meta_value']]);
$dwOption = $dwSummaries[json_encode([['kind' => 'option', 'key' => 'theme_mods_northwind']])] ?? null;
check('read: a theme option is a shared content of its own, named by the writer\'s address', $dwOption['type'] ?? null, 'shared');
$dwOptionFields = [];
foreach (($dwOption === null ? [] : $dwSource->detail($dwOption['id']))['blocks'] ?? [] as $block) {
    foreach ($block['fields'] as $field) {
        $dwOptionFields[] = [$field['value'], $field['slotKey']];
    }
}
checkTrue('read: its leaf is a field with its slot key', in_array(['Made in Oslo', $dwSer['key']], $dwOptionFields, true));
check('read: a term and a menu entry are shared contents too', [($dwSummaries[json_encode([['kind' => 'term', 'id' => 5, 'key' => 'category']])]['type'] ?? null),
    ($dwSummaries[json_encode([['kind' => 'menuItem', 'id' => 30, 'key' => 'main']])]['type'] ?? null)], ['shared', 'shared']);
$dwRevision = $dwSource->revisionsOf(['o' => ['kind' => 'option', 'id' => 0, 'key' => 'theme_mods_northwind'], 'm' => ['kind' => 'postmeta', 'id' => 12, 'key' => '_elementor_data']]);
check('read: a write to an option or a meta is held by the revision of the content that shows it',
    [$dwRevision['o']['id'] ?? null, $dwRevision['m']['id'] ?? null], [$dwOption['id'] ?? 'x', $dwPage['id'] ?? 'y']);
$dwSource->release();
$dwSource = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test', static fn(): array => $dwRowsOf()->rows());
$dwBeforeRevision = $dwSource->revisionsOf(['m' => ['kind' => 'postmeta', 'id' => 12, 'key' => '_elementor_data']])['m']['revision'] ?? null;
$dwSource->release();
WP_Fake::$meta['12:_elementor_data'] = str_replace('Ships in 24h', 'Ships today', (string) WP_Fake::$meta['12:_elementor_data']);
$dwSource = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test', static fn(): array => $dwRowsOf()->rows());
checkTrue('read: the page\'s revision moves when its builder meta does', ($dwSource->revisionsOf(['m' => ['kind' => 'postmeta', 'id' => 12, 'key' => '_elementor_data']])['m']['revision'] ?? null) !== $dwBeforeRevision);
$dwSource->release();
WP_Fake::$meta['12:_elementor_data'] = $dwJsonBefore;

// A link inside a shortcode: LeafCodec writes a shortcode's attribute as it is, so a value carrying
// markup would land on the page. Refused like any other value, before the link rules.
$dwSeed();
WP_Fake::$posts[14] = ['ID' => 14, 'post_type' => 'page', 'post_name' => 'offer', 'post_status' => 'publish', 'post_parent' => 0,
    'post_title' => 'Offer', 'post_excerpt' => '', 'post_content' => '[button url="https://x.test/buy" label="Buy now"]'];
$dwScWriter = new Claude_Cowork_Site_Writer();
$dwEngineOf($dwScWriter, new FakeApplyLog(), $dwContractOf($dwScWriter), [])->handle($dwDerive('derive-sc'));
$dwSc = $dwEngineOf($dwScWriter, new FakeApplyLog(), $dwContractOf($dwScWriter), []);
check('shortcode: its link attribute is a url slot', $dwSlot($dwDoor($dwSc, 'inspect'), 'https://x.test/buy')['type'], 'url');
$dwScBad = $dwApply($dwSc, ['https://x.test/buy' => 'https://x.test/"><script>alert(1)</script>'], 'dw-sc-bad');
check('shortcode: a url slot refuses markup', [$dwScBad['ok'], $dwScBad['errors'][0]['code'] ?? null, WP_Fake::$posts[14]['post_content']],
    [false, 'SLOT_NOT_CONTENT', '[button url="https://x.test/buy" label="Buy now"]']);
$dwScGood = $dwApply($dwSc, ['https://x.test/buy' => 'https://x.test/order'], 'dw-sc-good');
check('shortcode: a plain link is written in place', [$dwScGood['ok'] ?? $dwScGood, WP_Fake::$posts[14]['post_content']], [true, '[button url="https://x.test/order" label="Buy now"]']);
// An SVG the site already holds in its uploads is a derived picture, as on Joomla.
file_put_contents($dwRoot . '/wp-content/uploads/2026/09/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
$dwSvg = $dwApply($dwSc, ['http://northwind.test/wp-content/uploads/2026/09/hero.png' => 'wp-content/uploads/2026/09/logo.svg'], 'dw-svg');
check('image: an svg already in the uploads is accepted', [$dwSvg['ok'] ?? $dwSvg, get_option('theme_mods_northwind')['hero_image']], [true, 'http://northwind.test/wp-content/uploads/2026/09/logo.svg']);
// The purge after a derived write drops the entries of what was written, never the object cache whole.
WP_Fake::$cleaned = [];
Claude_Cowork_Site_Writer::purgePageCaches([['kind' => 'post', 'id' => 14, 'key' => null], ['kind' => 'postmeta', 'id' => 12, 'key' => '_elementor_data'], ['kind' => 'option', 'id' => 0, 'key' => 'theme_mods_northwind']]);
check('purge: the posts written are cleaned, one by one', array_values(WP_Fake::$cleaned), [14, 12]);

// ── after a derived write: purge and render check (A9) ─────────────────────────────────────

$dwSeed();
$dwEngineOf($dwWriter, new FakeApplyLog(), $dwContractOf($dwWriter), $dwPages)->handle($dwDerive('derive-render'));
$dwPurged = [];
WP_Fake::$filters['litespeed_purge_all'] = static function () use (&$dwPurged): void {
    $dwPurged[] = 'litespeed';
};
if (!function_exists('rocket_clean_domain')) {
    function rocket_clean_domain(): void
    {
        $GLOBALS['dwRocketCleaned'] = ($GLOBALS['dwRocketCleaned'] ?? 0) + 1;
    }
}
$GLOBALS['dwRocketCleaned'] = 0;
Claude_Cowork_Site_Writer::purgePageCaches();
check('purge: the page caches that exist are purged, the rest skipped', [$dwPurged, $GLOBALS['dwRocketCleaned']], [['litespeed'], 1]);

$dwFetchedUrls = [];
$dwServed = [];
$dwThrow = false;
$dwFetch = static function (array $urls) use (&$dwFetchedUrls, &$dwServed, &$dwThrow): array {
    $dwFetchedUrls[] = $urls;
    if ($dwThrow) {
        throw new RuntimeException('loopback refused');
    }
    return array_intersect_key($dwServed, array_flip($urls));
};
$dwPurges = 0;
$dwOwner = static fn(string $kind, int $id, array $identity): ?string =>
    in_array($kind, ['post', 'postmeta'], true) ? 'http://northwind.test/about/' : 'http://northwind.test/';
$dwRvLog = new FakeApplyLog();
$dwRv = $dwEngineOf($dwWriter, $dwRvLog, $dwContractOf($dwWriter), $dwPages, $dwFetch, static function () use (&$dwPurges): void {
    $dwPurges++;
}, $dwOwner);
$dwServed = ['http://northwind.test/about/' => '<h1>About Northwind</h1><p>Welcome aboard</p>'];
$dwResult = $dwApply($dwRv, ['Welcome aboard' => 'Welcome home'], 'rv-1');
check('render: a write the page does not show still answers ok', $dwResult['ok'] ?? $dwResult, true);
check('render: and warns which slot on which page', $dwResult['warnings'] ?? null,
    [['code' => 'WRITTEN_NOT_VISIBLE', 'message' => 'Written, but http://northwind.test/about/ does not show it', 'severity' => 'warning',
        'slotKey' => $dwHtml['key'], 'url' => 'http://northwind.test/about/']]);
check('render: the page caches were purged once', $dwPurges, 1);
$dwRevert = $dwRv->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-rv-1']]);
check('render: a warned write is taken back by its apply_id', [$dwRevert['ok'] ?? $dwRevert, WP_Fake::$posts[12]['post_content']], [true, $dwContentBefore]);
$dwServed = ['http://northwind.test/' => '<footer>Made in Bergen</footer>'];
$dwResult = $dwApply($dwRv, ['Made in Oslo' => 'Made in Bergen'], 'rv-2');
check('render: words the home page shows raise no warning', [$dwResult['ok'] ?? $dwResult, $dwResult['warnings'] ?? null, end($dwFetchedUrls)], [true, null, ['http://northwind.test/']]);
$dwThrow = true;
$dwResult = $dwApply($dwRv, ['Made in Bergen' => 'Made in Tromso'], 'rv-3');
check('render: a page that cannot be fetched raises no warning', [$dwResult['ok'] ?? $dwResult, $dwResult['warnings'] ?? null], [true, null]);
$dwThrow = false;
$dwServed = [];
$dwCount = count($dwFetchedUrls);
$dwResult = $dwApply($dwRv, ['About Northwind' => 'About us', 'Ships in 24h' => 'Ships in 48h', 'Made in Tromso' => 'Made in Oslo', 'News' => 'Stories'], 'rv-4');
check('render: one fetch per write, each owner page once, at most three', [count($dwFetchedUrls) - $dwCount, end($dwFetchedUrls)],
    [1, ['http://northwind.test/about/', 'http://northwind.test/']]);
// A quickstart site is wired the same way in the plugin, and its apply stays exactly as it was:
// no derived purge, no render check.
$dwQs = contractSite($SITE, $FIXTURES, false, 'test-design/wp7/1.0.0');
$dwQsPurges = 0;
$dwQsFetches = count($dwFetchedUrls);
$dwQs['engine']->derivedSource(static fn(): array => ['rows' => [], 'pages' => [], 'unresolved' => []], $dwFetch, static function () use (&$dwQsPurges): void {
    $dwQsPurges++;
}, $dwOwner);
$dwDoor($dwQs['engine'], 'bind');
$dwQsState = $dwDoor($dwQs['engine'], 'inspect');
$dwQsApply = $dwDoor($dwQs['engine'], 'apply', ['apply_id' => 'contract-dw-qs', 'request_id' => 'dw-qs', 'expected_revision' => $dwQsState['revision'] ?? '',
    'changes' => ['home.hero.heading' => 'Building better']]);
check('render: a quickstart apply is neither purged this way nor checked', [$dwQsApply['ok'] ?? $dwQsApply, $dwQsPurges, count($dwFetchedUrls) - $dwQsFetches, $dwQsApply['warnings'] ?? null],
    [true, 0, 0, null]);

WP_Fake::$filters = [];
$GLOBALS['wpdb'] = $dwPrevDb;
