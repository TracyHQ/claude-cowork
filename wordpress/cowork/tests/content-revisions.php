<?php
/**
 * Content API v2 on the write side: `content.contract apply` held by the revisions `content.read`
 * lists (`expected_content_revisions`), the receipt's `contentRevisions`, and structured
 * `errors[]` on every refusal.
 *
 * The revisions come from the REAL reader (`Claude_Cowork_Content_Source`), run over a `$wpdb`
 * that answers its handful of queries from the fake WordPress's rows — so a revision compared
 * here is the value `contents[].revision` would show, not a second formula. Loaded by run.php
 * after contracts.php (uses `contractSite()`, `$SITE`, `$FIXTURES`).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/class-claude-cowork-content-source.php';

echo "\nContent API v2: revisions and errors on content.contract\n";

if (!function_exists('get_theme_root')) {
    function get_theme_root($theme = ''): string
    {
        return sys_get_temp_dir() . '/cc-no-theme-root';
    }
}

/**
 * The reader's queries, answered from `WP_Fake`: options, posts (with MD5 of the content, as
 * MySQL computes it), the post meta and terms it asks for, and the writer's advisory lock. Any
 * other statement is an error, so a reader that starts asking something new fails here loudly.
 */
final class WP_Fake_ContentDb
{
    public string $prefix = 'wp_';
    public string $dbname = 'wp';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $options = 'wp_options';
    public string $term_relationships = 'wp_term_relationships';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $terms = 'wp_terms';
    public string $last_error = '';
    public $dbh;
    public int $lockAnswer = 1;
    /** @var string[] */
    public array $queries = [];

    public function __construct()
    {
        $this->dbh = mysqli_init();
    }

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . str_replace("'", "\\'", (string) $arg) . "'", $query, 1);
        }
        return $query;
    }

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        return true;
    }

    public function get_var(string $query)
    {
        $this->queries[] = $query;
        return strpos($query, 'GET_LOCK') !== false ? $this->lockAnswer : 1;
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null)
    {
        $id = (int) ($where['ID'] ?? 0);
        if ($table !== $this->posts || !isset(WP_Fake::$posts[$id])) {
            return false;
        }
        WP_Fake::$posts[$id] = array_merge(WP_Fake::$posts[$id], $data);
        return 1;
    }

    /** @return string[] the quoted values of the first `<column> IN (...)` */
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

    public function get_results(string $sql, $output = null): array
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'SELECT option_name, option_value FROM wp_options') === 0) {
            $out = [];
            foreach (self::in($sql, 'option_name') as $name) {
                if (array_key_exists($name, WP_Fake::$options)) {
                    $out[] = ['option_name' => $name, 'option_value' => self::stored(WP_Fake::$options[$name])];
                }
            }
            return $out;
        }
        if (strpos($sql, 'MD5(post_content) AS content_md5 FROM wp_posts WHERE (post_type') !== false) {
            $types = self::in($sql, 'post_type');
            $statuses = self::in($sql, 'post_status');
            $out = [];
            ksort(WP_Fake::$posts);
            foreach (WP_Fake::$posts as $id => $row) {
                $type = (string) ($row['post_type'] ?? 'post');
                $status = (string) ($row['post_status'] ?? 'publish');
                if (!(in_array($type, $types, true) && in_array($status, $statuses, true)) && !in_array($type, ['attachment', 'wp_template'], true)) {
                    continue;
                }
                $out[] = [
                    'ID' => (string) $id, 'post_type' => $type, 'post_status' => $status, 'post_password' => '',
                    'post_name' => (string) ($row['post_name'] ?? ''), 'post_title' => (string) ($row['post_title'] ?? ''),
                    'post_excerpt' => '', 'post_parent' => (string) ($row['post_parent'] ?? 0), 'menu_order' => '0',
                    'post_date_gmt' => '2026-09-01 00:00:00', 'post_modified_gmt' => '2026-09-01 00:00:00',
                    'content_md5' => md5((string) ($row['post_content'] ?? '')),
                ];
            }
            return $out;
        }
        if (strpos($sql, 'FROM wp_postmeta WHERE post_id IN') !== false) {
            $keys = self::in($sql, 'meta_key');
            $out = [];
            foreach (self::in($sql, 'post_id') as $id) {
                foreach ($keys as $key) {
                    if (array_key_exists($id . ':' . $key, WP_Fake::$meta)) {
                        $out[] = ['post_id' => $id, 'meta_key' => $key, 'meta_value' => self::stored(WP_Fake::$meta[$id . ':' . $key])];
                    }
                }
            }
            return $out;
        }
        if (strpos($sql, 'FROM wp_term_relationships tr') !== false) {
            $out = [];
            foreach (self::in($sql, 'tr.object_id') as $id) {
                foreach (WP_Fake::$terms as $at => $names) {
                    [$object, $taxonomy] = explode(':', $at, 2);
                    if ($object === $id) {
                        foreach ($names as $name) {
                            $out[] = ['object_id' => $id, 'taxonomy' => $taxonomy, 'term_taxonomy_id' => (string) crc32($taxonomy . $name), 'name' => $name, 'slug' => $name, 'description' => ''];
                        }
                    }
                }
            }
            return $out;
        }
        throw new RuntimeException('WP_Fake_ContentDb does not answer: ' . substr($sql, 0, 120));
    }
}

$previousDb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new WP_Fake_ContentDb();

/**
 * A bound test-design site whose rows have content identities, and an engine whose contract
 * reads revisions through the real reader.
 */
$revSite = static function (bool $polylang = false) use ($SITE, $FIXTURES): array {
    $s = contractSite($SITE, $FIXTURES, $polylang);
    WP_Fake::$options[Claude_Cowork_Content_Source::SITE_OPTION] = str_repeat('ab', 16);
    foreach (array_keys(WP_Fake::$posts) as $id) {
        WP_Fake::$meta[$id . ':' . Claude_Cowork_Content_Source::UID_META] = md5('uid-' . $id);
    }
    $contract = new QuickstartContract($s['writer'], new Claude_Cowork_Contract_Store(), $s['root'], $FIXTURES, '',
        new Claude_Cowork_Content_Revisions($FIXTURES, 'test'));
    $engine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $s['writer'], $s['media'], $s['log'], null, $contract);
    $bound = $engine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => 'content.contract', 'params' => ['operation' => 'bind', 'contract' => 'test-design/wp7/1.0.0']]);
    if (($bound['ok'] ?? false) !== true) {
        throw new RuntimeException('test site did not bind: ' . json_encode($bound));
    }
    return $s + ['E' => $engine, 'contractR' => $contract, 'bound' => $bound];
};
$rdoor = static function (Engine $engine, string $operation, array $params = []): array {
    return $engine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};
/** What content.read lists right now: content id → revision, plus the ids of the rows a test names. */
$listed = static function () use ($FIXTURES): array {
    $source = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test');
    $out = [];
    foreach ($source->summaries() as $summary) {
        $out[$summary['id']] = $summary['revision'];
    }
    $source->release();
    return $out;
};
$idOf = static function (string $kind, int $id = 0, string $key = '') use ($FIXTURES): string {
    $source = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test');
    $found = $source->revisionsOf([['kind' => $kind, 'id' => $id, 'key' => $key]])[0];
    $source->release();
    return (string) ($found['id'] ?? '');
};

// ── W1: a revision per content ──────────────────────────────────────────────────────────────

$r = $revSite();
$home = $idOf('post', 10);
$header = $idOf('templatePart', 70, 'header');
$site = $idOf('option', 0, 'blogname');
checkTrue('the reader names the home page, the header part and the site identity', $home !== '' && $header !== '' && $site !== '' && count(array_unique([$home, $header, $site])) === 3);
check('a translated option belongs to the site identity content too', $idOf('optionTranslation', 0, 'blogname'), $site);
$before = $listed();
checkTrue('every content has a 40-character revision', $before !== [] && !array_filter($before, static fn($rev) => strlen($rev) !== 40));
$one = $rdoor($r['E'], 'apply', ['expected_revision' => $r['bound']['revision'], 'apply_id' => 'contract-w1', 'request_id' => 'w1', 'changes' => ['home.hero.eyebrow' => 'Since 1999']]);
check('an apply of one page slot lands', $one['ok'], true);
$after = $listed();
check('only the page written has a new revision', array_keys(array_diff_assoc($after, $before)), [$home]);
check('the receipt carries it, as content.read lists it', $one['contentRevisions'], [$home => $after[$home]]);

// The binding's ids are part of every content's revision (they decide which field carries which
// slotKey), so a site bound again to other rows moves them ALL. An apply never changes the ids.
$binding = json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true);
$binding['ids']['page-home'] = 11;
WP_Fake::$options[QuickstartContract::STORE_OPTION] = json_encode($binding);
$rebound = $listed();
check('a binding with other ids moves every revision (bindingSlotsHash)', count(array_diff_assoc($rebound, $after)), count($after));
$binding['ids']['page-home'] = 10;
WP_Fake::$options[QuickstartContract::STORE_OPTION] = json_encode($binding);
check('and its own ids back restore them', $listed(), $after);

// ── W2: expected_content_revisions ──────────────────────────────────────────────────────────

$r = $revSite();
$E = $r['E'];
$revs = $listed();
$held = $rdoor($E, 'apply', ['expected_content_revisions' => [$home => $revs[$home], $site => $revs[$site]], 'apply_id' => 'contract-m1', 'request_id' => 'm1',
    'changes' => ['home.hero.heading' => 'Rock and Roll', 'site.name' => 'Acme']]);
check('held by content revisions alone, without expected_revision, an apply lands', $held['ok'], true);
check('the page and the option were written', array_column($held['written'], 'kind'), ['post', 'option']);
$now = $listed();
check('the receipt names both contents at their new revisions', $held['contentRevisions'], [$home => $now[$home], $site => $now[$site]]);
checkTrue('and keeps the site-wide revision', is_string($held['revision']) && strlen($held['revision']) === 64);
check('the header, not written, kept its revision', $now[$header], $revs[$header]);

$stale = $rdoor($E, 'apply', ['expected_content_revisions' => [$home => $revs[$home]], 'apply_id' => 'contract-m2', 'request_id' => 'm2', 'changes' => ['home.hero.eyebrow' => 'Since 2001']]);
check('a content revision that moved is refused', [$stale['ok'], $stale['error']], [false, 'contract_failed']);
check('as REVISION_STALE naming the slot, the content and its current revision', $stale['errors'], [[
    'code' => 'REVISION_STALE', 'message' => 'Content ' . $home . ' changed; read it again: home.hero.eyebrow',
    'field' => ['slotKey' => 'home.hero.eyebrow', 'contentId' => $home], 'severity' => 'recoverable', 'current' => $now[$home],
]]);
check('the old message is still the message', $stale['message'], $stale['errors'][0]['message']);

$other = $rdoor($E, 'apply', ['expected_content_revisions' => [$header => $now[$header]], 'apply_id' => 'contract-m3', 'request_id' => 'm3', 'changes' => ['home.hero.eyebrow' => 'Since 2001']]);
check('a content the map does not name is REVISION_REQUIRED', array_map(static fn($e) => [$e['code'], $e['field']['contentId'], $e['current'] ?? null], $other['errors']),
    [['REVISION_REQUIRED', $home, $now[$home]]]);

$both = $rdoor($E, 'apply', ['expected_revision' => str_repeat('0', 64), 'expected_content_revisions' => [$home => $now[$home]], 'apply_id' => 'contract-m4', 'request_id' => 'm4',
    'changes' => ['home.hero.eyebrow' => 'Since 2001']]);
check('a wrong expected_revision beside a right map is refused', array_column($both['errors'], 'code'), ['REVISION_STALE']);
check('as the site-wide revision, no slot', $both['errors'][0]['field'], ['slotKey' => null, 'contentId' => null]);
check('with the site revision it is at', $both['errors'][0]['current'], $rdoor($E, 'inspect')['revision']);
check('nothing was written by the refusals', QuickstartContract::getBlockValue(WP_Fake::$posts[10]['post_content'], 'hero.eyebrow', 'content'), 'Established 2004');

$neither = $rdoor($E, 'apply', ['apply_id' => 'contract-m5', 'request_id' => 'm5', 'changes' => ['home.hero.eyebrow' => 'Since 2001']]);
check('with neither, the refusal reads as before', [$neither['error'], $neither['message']], ['contract_failed', 'Content changed; inspect again']);
check('coded REVISION_REQUIRED', $neither['errors'][0]['code'], 'REVISION_REQUIRED');
$legacy = $rdoor($E, 'apply', ['expected_revision' => $rdoor($E, 'inspect')['revision'], 'apply_id' => 'contract-m6', 'request_id' => 'm6', 'changes' => ['home.hero.eyebrow' => 'Since 2001']]);
check('expected_revision alone still lands, as before', $legacy['ok'], true);
$pair = $listed();
$twice = $rdoor($E, 'apply', ['expected_revision' => $rdoor($E, 'inspect')['revision'], 'expected_content_revisions' => [$home => $pair[$home]],
    'apply_id' => 'contract-m7', 'request_id' => 'm7', 'changes' => ['home.hero.eyebrow' => 'Since 2002']]);
check('both right: it lands', $twice['ok'], true);
check('a map that is not ids to revisions is refused as CHANGES_INVALID',
    $rdoor($E, 'apply', ['expected_content_revisions' => ['x' => 5], 'apply_id' => 'contract-m8', 'request_id' => 'm8', 'changes' => ['site.name' => 'x']])['errors'][0]['code'], 'CHANGES_INVALID');

file_put_contents($r['root'] . '/wp-content/themes/test-theme/style.css', '/* edited */');
$fresh = $listed();
$drift = $rdoor($E, 'apply', ['expected_content_revisions' => [$home => $fresh[$home]], 'apply_id' => 'contract-m9', 'request_id' => 'm9', 'changes' => ['home.hero.eyebrow' => 'Since 2003']]);
// Tracy ADR 0022: a theme changed through WordPress is a warning on the apply and on the inspect.
check('a theme that drifted is a warning on an apply held by content revisions', [$drift['ok'], array_column($drift['warnings'], 'code')], [true, ['PRESENTATION_DRIFT']]);
check('named, with the warning severity', [$drift['warnings'][0]['severity'], $drift['warnings'][0]['message']], ['warning', 'Theme file changed: wp-content/themes/test-theme/style.css']);
check('an inspect of a drifted site answers, with the same warning', [$rdoor($E, 'inspect')['ok'], array_column($rdoor($E, 'inspect')['warnings'], 'code')], [true, ['PRESENTATION_DRIFT']]);

// An edition's key is held by the content of THAT edition.
$p = $revSite(true);
$de = $idOf('post', 60);
$prevs = $listed();
checkTrue('the German copy is its own content', $de !== '' && $de !== $idOf('post', 10));
$wrong = $rdoor($p['E'], 'apply', ['expected_content_revisions' => [$idOf('post', 10) => $prevs[$idOf('post', 10)]], 'apply_id' => 'contract-d1', 'request_id' => 'd1',
    'changes' => ['de::home.hero.eyebrow' => 'Seit 1999']]);
check('a locale key held by the source page is refused for the edition', [$wrong['errors'][0]['code'], $wrong['errors'][0]['field']], ['REVISION_REQUIRED', ['slotKey' => 'de::home.hero.eyebrow', 'contentId' => $de]]);
$right = $rdoor($p['E'], 'apply', ['expected_content_revisions' => [$de => $prevs[$de]], 'apply_id' => 'contract-d2', 'request_id' => 'd2', 'changes' => ['de::home.hero.eyebrow' => 'Seit 1999']]);
check('held by the edition content, it lands on the copy', [$right['ok'], array_column($right['written'], 'id')], [true, [60]]);
check('and the receipt names the edition content', array_keys($right['contentRevisions']), [$de]);
check('the source page kept its revision', $listed()[$idOf('post', 10)], $prevs[$idOf('post', 10)]);

// ── W3: every bad slot at once ──────────────────────────────────────────────────────────────

$t = $revSite();
$pageBefore = WP_Fake::$posts[10]['post_content'];
$bad = $rdoor($t['E'], 'apply', ['expected_revision' => $t['bound']['revision'], 'apply_id' => 'contract-e1', 'request_id' => 'e1', 'changes' => [
    'home.hero.eyebrow' => str_repeat('a', 41),
    'home.hero.heading' => 'a <b>',
    'home.hero.nope' => 'x',
    'home.hero.cta.url' => 'javascript:alert(1)',
]]);
check('four bad slots are four errors', array_column($bad['errors'], 'code'), ['SLOT_TOO_LONG', 'SLOT_NOT_CONTENT', 'SLOT_UNKNOWN', 'SLOT_LINK_UNSUPPORTED']);
check('each naming its slot', array_map(static fn($e) => $e['field']['slotKey'], $bad['errors']), ['home.hero.eyebrow', 'home.hero.heading', 'home.hero.nope', 'home.hero.cta.url']);
check('too long says by how much', [$bad['errors'][0]['limit'], $bad['errors'][0]['actual']], [40, 41]);
check('severity follows the code', array_column($bad['errors'], 'severity'), ['recoverable', 'recoverable', 'unrecoverable', 'recoverable']);
check('the old messages, joined', $bad['message'], 'Content too long: home.hero.eyebrow (41 > 40); Markup and control characters are not content: home.hero.heading; Unknown content slot: home.hero.nope; Unsupported link: home.hero.cta.url');
check('and the relay fields stay', [$bad['ok'], $bad['error']], [false, 'contract_failed']);
check('nothing was written', WP_Fake::$posts[10]['post_content'], $pageBefore);
check('no log left behind', $t['log']->entries('contract-e1'), []);
$single = $rdoor($t['E'], 'apply', ['expected_revision' => $t['bound']['revision'], 'apply_id' => 'contract-e2', 'request_id' => 'e2', 'changes' => ['home.hero.eyebrow' => str_repeat('a', 41)]]);
check('one bad slot reads exactly as it always did', $single['message'], 'Content too long: home.hero.eyebrow (41 > 40)');
check('a missing edition block is SLOT_EDITION_MISSING', $rdoor($p['E'], 'apply', ['expected_revision' => $rdoor($p['E'], 'inspect')['revision'], 'apply_id' => 'contract-e3', 'request_id' => 'e3',
    'changes' => ['de::home.hero.cta.text' => 'Anrufen']])['errors'][0]['code'], 'SLOT_EDITION_MISSING');
check('a value that is not a string is CHANGES_INVALID', $rdoor($t['E'], 'apply', ['expected_revision' => $t['bound']['revision'], 'apply_id' => 'contract-e4', 'request_id' => 'e4',
    'changes' => ['site.name' => 5]])['errors'][0]['code'], 'CHANGES_INVALID');
$noId = $rdoor($t['E'], 'apply', ['expected_revision' => $t['bound']['revision'], 'apply_id' => 'e5', 'request_id' => 'e5', 'changes' => ['site.name' => 'x']]);
check('any other refusal is one CONTRACT_FAILED', [$noId['errors'][0]['code'], $noId['errors'][0]['severity'], $noId['errors'][0]['message']], ['CONTRACT_FAILED', 'unrecoverable', $noId['message']]);
$GLOBALS['wpdb']->lockAnswer = 0;
$busy = $rdoor($t['E'], 'inspect');
check('a site another writer holds is WRITER_BUSY, recoverable', [$busy['error'], $busy['errors'][0]['code'], $busy['errors'][0]['severity']], ['writer_busy', 'WRITER_BUSY', 'recoverable']);
$GLOBALS['wpdb']->lockAnswer = 1;
check('a content.read query naming protocolVersions is not refused', (new ContentReader(new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test'), str_repeat('k', 32), 'site', 'editorial', time()))->read(['protocolVersions' => 'tracy-content/v1'])['schemaVersion'] ?? null, 'tracy-content/v1');

$GLOBALS['wpdb'] = $previousDb;
