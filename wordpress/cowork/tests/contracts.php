<?php
/**
 * The content-only seal, exercised end to end against the REAL site writer over the fake
 * WordPress: bind, inspect, apply, revert, the blocked list, demo trim and source relabel.
 *
 * Loaded by run.php. Uses `check()` / `checkTrue()` and the `$WTOKEN` it defines.
 */
declare(strict_types=1);

echo "\nContent contract\n";

$FIXTURES = __DIR__ . '/fixtures/contracts';
$SITE = json_decode((string) file_get_contents($FIXTURES . '/site.json'), true);

/** A webroot holding exactly the theme files the fixture lock pins. */
function contractRoot(array $site): string
{
    $root = sys_get_temp_dir() . '/cc-contract-' . bin2hex(random_bytes(4));
    mkdir($root . '/wp-content/themes/test-theme', 0777, true);
    file_put_contents($root . '/wp-content/themes/test-theme/style.css', $site['style.css']);
    file_put_contents($root . '/wp-content/themes/test-theme/theme.json', $site['theme.json']);
    return $root;
}

/**
 * A site as the test-design quickstart ships it: two options, one page, two template parts,
 * three demo posts. With Polylang on, a German copy of the page and of one demo post sit beside
 * the English ones.
 *
 * @return array{engine:Engine,writer:Claude_Cowork_Site_Writer,log:FakeApplyLog,media:FakeMediaWriter,root:string,contract:QuickstartContract}
 */
function contractSite(array $site, string $fixtures, bool $polylang = false, string $configured = ''): array
{
    WP_Fake::reset();
    WP_Fake::$stylesheet = 'test-theme';
    WP_Fake::$options = [
        'blogname' => 'Test Co',
        'blogdescription' => 'A test',
        'tracy_identity' => ['email' => 'hi@test.local', 'phone' => '+1 555'],
        'stylesheet' => 'test-theme',
        'template' => 'test-theme',
        'home' => 'http://test.local',
    ];
    WP_Fake::$posts[10] = ['ID' => 10, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Home', 'post_content' => $site['home']];
    // 26-28 are the source edition's demo posts; 29 is the German edition's copy of one.
    foreach ([26, 27, 28, 29] as $id) {
        WP_Fake::$posts[$id] = ['ID' => $id, 'post_type' => 'post', 'post_name' => 'demo-' . $id, 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Demo ' . $id, 'post_content' => '<!-- wp:paragraph --><p>demo</p><!-- /wp:paragraph -->'];
    }
    WP_Fake::$posts[70] = ['ID' => 70, 'post_type' => 'wp_template_part', 'post_name' => 'header', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'header', 'post_content' => $site['header']];
    WP_Fake::$posts[71] = ['ID' => 71, 'post_type' => 'wp_template_part', 'post_name' => 'footer', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'footer', 'post_content' => $site['footer']];
    foreach ([70 => 'header', 71 => 'footer'] as $id => $area) {
        WP_Fake::$terms[$id . ':wp_theme'] = ['test-theme'];
        WP_Fake::$terms[$id . ':wp_template_part_area'] = [$area];
    }
    if ($polylang) {
        WP_Fake::$polylang = true;
        WP_Fake::$languages = [
            'en' => ['term_id' => 5, 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'is_rtl' => 0, 'term_group' => 0, 'flag_code' => 'us'],
            'de' => ['term_id' => 6, 'name' => 'Deutsch', 'slug' => 'de', 'locale' => 'de_DE', 'is_rtl' => 0, 'term_group' => 1, 'flag_code' => 'de'],
        ];
        WP_Fake::$posts[60] = ['ID' => 60, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Start', 'post_content' => str_replace('Established 2004', 'Gegründet 2004', $site['home'])];
        foreach ([10, 26, 27, 28, 70, 71] as $id) {
            WP_Fake::$postLanguage[$id] = 'en';
        }
        WP_Fake::$postLanguage[60] = 'de';
        WP_Fake::$postLanguage[29] = 'de';
    }
    $root = contractRoot($site);
    $writer = new Claude_Cowork_Site_Writer();
    $log = new FakeApplyLog();
    $media = new FakeMediaWriter();
    $contract = new QuickstartContract($writer, new Claude_Cowork_Contract_Store(), $root, $fixtures, $configured);
    $engine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $writer, $media, $log, null, $contract);
    return ['engine' => $engine, 'writer' => $writer, 'log' => $log, 'media' => $media, 'root' => $root, 'contract' => $contract];
}

$call = static function (Engine $engine, string $action, array $params = []) use ($WTOKEN): array {
    return $engine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
};
$door = static function (Engine $engine, string $operation, array $params = []) use ($call): array {
    return $call($engine, 'content.contract', ['operation' => $operation] + $params);
};

// ── block slots: the seeder's algorithm, ported ─────────────────────────────────────────────

$home = $SITE['home'];
check('a paragraph slot reads its text', QuickstartContract::getBlockValue($home, 'hero.eyebrow', 'content'), 'Established 2004');
check('a heading slot reads its text', QuickstartContract::getBlockValue($home, 'hero.heading', 'content'), 'Building things');
check('a button slot reads the link text', QuickstartContract::getBlockValue($home, 'hero.cta', 'text'), 'Talk to us');
check('and its url from the block attributes', QuickstartContract::getBlockValue($home, 'hero.cta', 'url'), '/contact');

$edited = QuickstartContract::setBlockValue($home, 'hero.heading', 'content', 'Rock & Roll <b>');
checkTrue('a written value is escaped into the markup', strpos($edited, '<h1 class="wp-block-heading">Rock &amp; Roll &lt;b&gt;</h1>') !== false);
check('and reads back unescaped', QuickstartContract::getBlockValue($edited, 'hero.heading', 'content'), 'Rock & Roll <b>');
$linked = QuickstartContract::setBlockValue($home, 'hero.cta', 'url', '/about?x=1');
checkTrue('a url lands in the href', strpos($linked, 'href="/about?x=1"') !== false);
checkTrue('and in the block attributes, slashes unescaped', strpos($linked, '<!-- wp:button {"metadata":{"name":"hero.cta"},"url":"/about?x=1"} -->') !== false);
checkTrue('everything outside the slot is untouched',
    str_replace('href="/contact"', 'href="/about?x=1"', str_replace('"url":"/contact"', '"url":"/about?x=1"', $home)) === $linked);
$missing = null;
try {
    QuickstartContract::setBlockValue($home, 'hero.nope', 'content', 'x');
} catch (RuntimeException $e) {
    $missing = $e->getMessage();
}
check('a block that is not there is named', $missing, 'block hero.nope is not in this pattern');
checkTrue('masking every slot is what the lock hashed',
    hash('sha256', QuickstartContract::setBlockValue(QuickstartContract::setBlockValue(QuickstartContract::setBlockValue(QuickstartContract::setBlockValue(
        $home, 'hero.eyebrow', 'content', '{{slot}}'), 'hero.heading', 'content', '{{slot}}'), 'hero.cta', 'text', '{{slot}}'), 'hero.cta', 'url', '{{slot}}'))
    === json_decode((string) file_get_contents($FIXTURES . '/test-design/wp7/1.0.0/presentation-lock.json'), true)['entities']['page-home']['skeleton']);

// ── the real profile the plugin ships ───────────────────────────────────────────────────────

$realDir = __DIR__ . '/../lib/contracts/tracy-business/wp7/1.1.0';
$real = new QuickstartContract(new FakeSiteWriter(), new Claude_Cowork_Contract_Store(), sys_get_temp_dir(), __DIR__ . '/../lib/contracts');
$real->preview('tracy-business/wp7/1.1.0');
check('the shipped Tracy Business profile has its ten entities', count($real->entities()), 10);
check('and its 303 slots', count($real->slots()), 303);
check('25 of them pictures, each with the demo it replaces', count(array_filter($real->slots(),
    static fn(array $slot): bool => ($slot['type'] ?? '') === 'image' && is_string($slot['sample'] ?? null) && is_int($slot['sampleId'] ?? null))), 25);
check('and 205 demo rows to hide', count($real->demoTrim()->rows()), 205);
check('and 41 editions', count($real->editionLanguages()), 41);
$realBase = DemoTrimProfile::baseHash($realDir);
check('its demo-trim map is pinned to the three base files', json_decode((string) file_get_contents($realDir . '/demo-trim-map.json'), true)['baseHash'], $realBase);
check('and so is its editions profile', json_decode((string) file_get_contents($realDir . '/editions.json'), true)['baseHash'], $realBase);

// A site built from the Tracy Base quickstart names this profile; without it that site cannot bind.
$base = new QuickstartContract(new FakeSiteWriter(), new Claude_Cowork_Contract_Store(), sys_get_temp_dir(), __DIR__ . '/../lib/contracts');
$base->preview('tracy-base/wp7/1.1.0');
check('the shipped Tracy Base profile loads', $base->id(), 'tracy-base/wp7/1.1.0');
check('with its seven entities', count($base->entities()), 7);
check('and its ten slots', count($base->slots()), 10);

// ── unbound: nothing changes ────────────────────────────────────────────────────────────────

$s = contractSite($SITE, $FIXTURES);
$E = $s['engine'];
check('an unbound site takes a structural write as before',
    $call($E, 'content.update', ['apply_id' => 'u1', 'kind' => 'option', 'key' => 'blogdescription', 'fields' => ['value' => 'Changed']])['ok'], true);
check('and every other write door too',
    $call($E, 'content.delete', ['apply_id' => 'u2', 'kind' => 'post', 'id' => 28])['ok'], true);
$call($E, 'apply.revert', ['apply_id' => 'u2']);
$call($E, 'apply.revert', ['apply_id' => 'u1']);
check('the door with no contract named says what it needs', $door($E, 'inspect')['error'], 'contract_unavailable');
check('a contract id that is not one is refused before any file is opened', $door($E, 'inspect', ['contract' => '../../etc'])['error'], 'contract_unavailable');
check('a contract this plugin does not carry is refused', $door($E, 'inspect', ['contract' => 'nope/wp7/1.0.0'])['error'], 'contract_unavailable');

$plan = $door($E, 'inspect', ['contract' => 'test-design/wp7/1.0.0']);
check('a planning inspect on an unbound site answers', $plan['ok'], true);
check('unbound', $plan['bound'], false);
check('the contract it would seal to', $plan['contract'], 'test-design/wp7/1.0.0');
check('every entity resolved', array_column($plan['entities'], 'id', 'key'), ['option-blogname' => null, 'option-identity' => null, 'page-home' => 10, 'part-header' => 70]);
check('every slot with its current value', $plan['slots'], [
    'site.name' => 'Test Co', 'identity.email' => 'hi@test.local',
    'home.hero.eyebrow' => 'Established 2004', 'home.hero.heading' => 'Building things',
    'home.hero.cta.text' => 'Talk to us', 'home.hero.cta.url' => '/contact', 'header.tagline' => 'Quality since 2004',
]);
check('no problems', $plan['problems'], []);
checkTrue('a revision is a sha256', (bool) preg_match('/^[a-f0-9]{64}$/', $plan['revision']));
$revision0 = $plan['revision'];

// ── bind ───────────────────────────────────────────────────────────────────────────────────

$bound = $door($E, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
check('bind seals the site', $bound['ok'], true);
check('at the revision the inspect saw', $bound['revision'], $revision0);
$stored = json_decode((string) WP_Fake::$options['_tracy_content_contract'], true);
check('the binding is one JSON option', $stored['schemaVersion'], 1);
check('naming the contract', $stored['contract'], 'test-design/wp7/1.0.0');
check('pinned to its three base files', $stored['contractHash'], DemoTrimProfile::baseHash($FIXTURES . '/test-design/wp7/1.0.0'));
check('binding twice is refused', $door($E, 'bind')['error'], 'conflict');
$insp = $door($E, 'inspect');
check('inspect on a bound site reads bound', $insp['bound'], true);
check('and needs no contract parameter any more', $insp['contract'], 'test-design/wp7/1.0.0');
check('another contract named on a bound site is refused', $door($E, 'inspect', ['contract' => 'bad-trim/wp7/1.0.0'])['error'], 'contract_unavailable');

// ── nothing is blocked (Tracy ADR 0022), and the reads stay open ───────────────────────────
check('bound: info still answers', $call($E, 'info')['ok'], true);
check('bound: content.get still answers', $call($E, 'content.get', ['id' => 10])['ok'], true);
check('bound: apply.list still answers', $call($E, 'apply.list', ['apply_id' => 'none'])['ok'], true);
check('bound: site.stats still answers', $call($E, 'site.stats')['ok'], true);
check("bound: the contract's own record is not writable through content.update",
    $call($E, 'content.update', ['apply_id' => 'x', 'kind' => 'option', 'key' => '_tracy_content_contract', 'fields' => ['value' => '']])['error'], 'bad_params');

// The reads that need a dumper, walker or package manager: an engine on fakes, sealed by its store.
WP_Fake::$options['_tracy_content_contract'] = json_encode(['schemaVersion' => 1, 'contract' => 'test-design/wp7/1.0.0', 'contractHash' => 'x', 'revision' => 'y', 'ids' => [], 'demoTrim' => null, 'sourceLanguage' => null, 'boundAt' => 'now']);
$fakeWriter = new FakeSiteWriter();
$fakeWriter->posts = [['id' => 1, 'slug' => 'a', 'type' => 'post']];
$fakeWriter->store['post'][1] = ['post_title' => 'A'];
$readSource = new FakeRowSource(['wp_posts' => ['create' => 'CREATE TABLE `wp_posts` (`ID` int)', 'rows' => [['1']]]]);
$readRoot = contractRoot($SITE);
$R = new Engine($WTOKEN, [], new DbDumper($readSource), new FileWalker($readRoot), null, new FakePackages(), $fakeWriter, new FakeMediaWriter(), new FakeApplyLog(), null,
    new QuickstartContract($fakeWriter, new Claude_Cowork_Contract_Store(), $readRoot, $FIXTURES));
foreach (['db.tables' => [], 'db.dump' => ['table' => 'wp_posts'], 'files.list' => [], 'file.read' => ['path' => 'wp-content/themes/test-theme/style.css'],
    'plugin.list' => [], 'theme.list' => [], 'core.manifest' => [], 'content.list' => [], 'content.get' => ['id' => 1]] as $read => $params) {
    check("bound: {$read} still answers", $call($R, $read, $params)['ok'], true);
}
// Tracy ADR 0022: every write an unbound site takes, a bound one takes — on the fakes engine, so the
// real site the tests below write through is left as it was.
foreach (['content.update', 'content.delete', 'content.language', 'language.install', 'plugin.install', 'plugin.activate',
    'plugin.selfUpdate', 'theme.install', 'theme.activate', 'theme.style', 'theme.palette', 'db.cleanup', 'db.restore', 'db.purge'] as $open) {
    $answer = $call($R, $open, ['apply_id' => 'open-' . $open, 'id' => 1, 'kind' => 'post', 'fields' => ['post_title' => 'x'], 'tables' => ['wp_x']]);
    checkTrue("bound: {$open} is not refused by the contract", ($answer['error'] ?? '') !== 'content_only' && ($answer['error'] ?? '') !== 'contract_unavailable');
}

// A corrupt store locks, it never opens.
WP_Fake::$options['_tracy_content_contract'] = '{"not":"a binding"}';
check('a corrupt store refuses every write', $call($R, 'content.update', ['apply_id' => 'x', 'kind' => 'post', 'id' => 1, 'fields' => ['post_title' => 'x']])['error'], 'contract_unavailable');
check('and the contract door', $call($R, 'content.contract')['error'], 'contract_unavailable');
check('while reads still answer', $call($R, 'info')['ok'], true);
WP_Fake::$options['_tracy_content_contract'] = json_encode($stored);

// ── apply ──────────────────────────────────────────────────────────────────────────────────

$applyParams = static function (array $changes, string $request = 'r1', string $apply = 'contract-1', array $extra = []) use ($revision0): array {
    return ['expected_revision' => $revision0, 'apply_id' => $apply, 'request_id' => $request, 'changes' => $changes] + $extra;
};
check('apply needs a contract- apply_id', $door($E, 'apply', $applyParams(['site.name' => 'x'], 'r', 'a1'))['error'], 'contract_failed');
check('apply needs a request_id', $door($E, 'apply', ['expected_revision' => $revision0, 'apply_id' => 'contract-1', 'changes' => ['site.name' => 'x']])['error'], 'contract_failed');
$stale = $door($E, 'apply', array_replace($applyParams(['site.name' => 'x']), ['expected_revision' => str_repeat('0', 64)]));
check('a stale revision is refused', $stale['error'], 'contract_failed');
checkTrue('and told to inspect again', strpos($stale['message'], 'inspect again') !== false);
check('an unknown slot is refused', $door($E, 'apply', $applyParams(['home.hero.nope' => 'x']))['error'], 'contract_failed');
check('a value over its slot length is refused', $door($E, 'apply', $applyParams(['home.hero.eyebrow' => str_repeat('a', 41)]))['error'], 'contract_failed');
checkTrue('naming the length', strpos($door($E, 'apply', $applyParams(['home.hero.eyebrow' => str_repeat('a', 41)]))['message'], '41 > 40') !== false);
check('markup is not content', $door($E, 'apply', $applyParams(['home.hero.eyebrow' => 'a <b>']))['error'], 'contract_failed');
check('a directive is not content', $door($E, 'apply', $applyParams(['home.hero.eyebrow' => 'a {foo}']))['error'], 'contract_failed');
check('a link that is not on the allow-list is refused', $door($E, 'apply', $applyParams(['home.hero.cta.url' => 'javascript:alert(1)']))['error'], 'contract_failed');
check('nor an http link to another host', $door($E, 'apply', $applyParams(['home.hero.cta.url' => 'http://other.local/x']))['error'], 'contract_failed');
check('nothing was written by any refusal', WP_Fake::$posts[10]['post_content'], $SITE['home']);
check('and no log was left behind', $s['log']->log, []);

$applied = $door($E, 'apply', $applyParams([
    'site.name' => 'Acme {site.name}',
    'identity.email' => 'hello@acme.test',
    'home.hero.eyebrow' => 'Since 1999',
    'home.hero.heading' => 'Rock & Roll',
    'home.hero.cta.text' => 'Call us',
    'home.hero.cta.url' => 'http://test.local/contact-us',
    'header.tagline' => 'Loud since 1999',
]));
check('an apply of every slot lands', $applied['ok'], true);
check('one write per row: two options, one page, one part', array_column($applied['written'], 'kind'), ['option', 'option', 'post', 'templatePart']);
checkTrue('at a new revision', $applied['revision'] !== $revision0);
check('the option changed', WP_Fake::$options['blogname'], 'Acme {site.name}');
check('one key of the array option changed, the other kept', WP_Fake::$options['tracy_identity'], ['email' => 'hello@acme.test', 'phone' => '+1 555']);
checkTrue('the page holds the escaped heading', strpos(WP_Fake::$posts[10]['post_content'], '<h1 class="wp-block-heading">Rock &amp; Roll</h1>') !== false);
checkTrue('and the same-host link', strpos(WP_Fake::$posts[10]['post_content'], '"url":"http://test.local/contact-us"') !== false);
checkTrue('the template part changed', strpos(WP_Fake::$posts[70]['post_content'], '<p>Loud since 1999</p>') !== false);
check('the log holds four content steps and one receipt', array_column($s['log']->entries('contract-1'), 'op'), ['content', 'content', 'content', 'content', 'contract']);
$after = $door($E, 'inspect');
check('inspect is clean after the apply', $after['problems'], []);
check('at the revision the apply reported', $after['revision'], $applied['revision']);
check('and reads the new values', $after['slots']['home.hero.heading'], 'Rock & Roll');
$listed = $call($E, 'apply.list', ['apply_id' => 'contract-1']);
check('apply.list shows the receipt', $listed['steps'][4]['op'], 'contract');
check('with the revision it left', $listed['steps'][4]['afterRevision'], $applied['revision']);
check('a replay of the same request answers the stored result', $door($E, 'apply', $applyParams(['site.name' => 'Acme {site.name}', 'identity.email' => 'hello@acme.test', 'home.hero.eyebrow' => 'Since 1999', 'home.hero.heading' => 'Rock & Roll', 'home.hero.cta.text' => 'Call us', 'home.hero.cta.url' => 'http://test.local/contact-us', 'header.tagline' => 'Loud since 1999'])), $applied);
check('the same request with other content is refused', $door($E, 'apply', $applyParams(['site.name' => 'Other']))['error'], 'contract_failed');
check('a new request under a used apply_id is refused', $door($E, 'apply', $applyParams(['site.name' => 'Other'], 'r2'))['error'], 'contract_failed');
$revision1 = $applied['revision'];

// ── apply.revert under the seal ────────────────────────────────────────────────────────────

$png = "\x89PNG\r\n\x1a\nfake";
$mediaPath = 'wp-content/uploads/tracy-content/' . hash('sha256', $png) . '.png';
check('bound: media.upload outside tracy-content is an ordinary upload', $call($E, 'media.upload', ['apply_id' => 'm0', 'path' => 'wp-content/uploads/logo.png', 'content_b64' => base64_encode($png)])['ok'], true);
check('bound: which the ordinary revert takes back', $call($E, 'apply.revert', ['apply_id' => 'm0'])['ok'], true);
check('bound: and a name that is not the sha256 of the bytes', $call($E, 'media.upload', ['apply_id' => 'm1', 'path' => 'wp-content/uploads/tracy-content/' . str_repeat('a', 64) . '.png', 'content_b64' => base64_encode($png)])['error'], 'bad_params');
check('bound: and a contract- apply_id', $call($E, 'media.upload', ['apply_id' => 'contract-9', 'path' => $mediaPath, 'content_b64' => base64_encode($png)])['error'], 'bad_params');
check('bound: a content-addressed image under its own apply lands', $call($E, 'media.upload', ['apply_id' => 'm1', 'path' => $mediaPath, 'content_b64' => base64_encode($png)])['ok'], true);
check('bound: a non-contract apply takes the ordinary revert', $call($E, 'apply.revert', ['apply_id' => 'm1'])['ok'], true);

$second = $door($E, 'apply', ['expected_revision' => $revision1, 'apply_id' => 'contract-2', 'request_id' => 'r3', 'changes' => ['home.hero.eyebrow' => 'Since 2000']]);
check('a second apply at the new revision lands', $second['ok'], true);
$lifo = $call($E, 'apply.revert', ['apply_id' => 'contract-1']);
check('the earlier apply cannot be reverted over a later one', $lifo['error'], 'conflict');
checkTrue('and says so', strpos($lifo['message'], 'Later content') !== false);
$rev2 = $call($E, 'apply.revert', ['apply_id' => 'contract-2']);
check('the latest apply reverts', $rev2['reverted'], 2);
check('to the revision before it', $rev2['revision'], $revision1);
$rev1 = $call($E, 'apply.revert', ['apply_id' => 'contract-1']);
check('and then the one before', $rev1['ok'], true);
check('back to the page as shipped', WP_Fake::$posts[10]['post_content'], $SITE['home']);
check('the array option too', WP_Fake::$options['tracy_identity'], ['email' => 'hi@test.local', 'phone' => '+1 555']);
$clean = $door($E, 'inspect');
check('inspect is clean after the reverts', $clean['problems'], []);
check('at the original revision', $clean['revision'], $revision0);
check('a reverted apply is forgotten', $s['log']->entries('contract-1'), []);

// ── bind refusals: the baseline is the released lock, never a snapshot ─────────────────────

$refused = static function (string $name, callable $break, string $expect) use ($SITE, $FIXTURES, $door): void {
    $t = contractSite($SITE, $FIXTURES);
    $break($t);
    $answer = $door($t['engine'], 'bind', ['contract' => 'test-design/wp7/1.0.0']);
    check("bind refuses {$name}", $answer['error'] ?? 'ok', 'contract_failed');
    $found = false;
    foreach ($answer['problems'] ?? [] as $problem) {
        if (strpos($problem, $expect) !== false) {
            $found = true;
        }
    }
    checkTrue("and names it: {$expect}", $found);
    check('leaving the site unbound', WP_Fake::$options['_tracy_content_contract'] ?? null, null);
};
// Tracy ADR 0022: a site that differs from the released design is bound all the same — the
// difference is a warning that names it. What makes a slot unsafe (below: a page that is not there,
// a slug twice) is still refused.
$drifted = static function (string $name, callable $break, string $expect) use ($SITE, $FIXTURES, $door): void {
    $t = contractSite($SITE, $FIXTURES);
    $break($t);
    $answer = $door($t['engine'], 'bind', ['contract' => 'test-design/wp7/1.0.0']);
    check("bind takes {$name}", $answer['ok'] ?? false, true);
    $found = false;
    foreach ($answer['warnings'] ?? [] as $warning) {
        if (strpos((string) $warning['message'], $expect) !== false && $warning['severity'] === 'warning') {
            $found = true;
        }
    }
    checkTrue("and warns about it: {$expect}", $found);
};
$drifted('a drifted theme file', static function (array $t): void {
    file_put_contents($t['root'] . '/wp-content/themes/test-theme/style.css', '/* edited */');
}, 'Theme file changed');
$drifted('an unexpected theme file', static function (array $t): void {
    file_put_contents($t['root'] . '/wp-content/themes/test-theme/extra.php', '<?php');
}, 'Unexpected theme file');
$drifted('an edited template part', static function (array $t): void {
    WP_Fake::$posts[71]['post_content'] = '<!-- wp:paragraph --><p>Edited footer</p><!-- /wp:paragraph -->';
}, 'Template part footer was edited');
$drifted('a page changed outside its slots', static function (array $t): void {
    WP_Fake::$posts[10]['post_content'] = str_replace('class="eyebrow"', 'class="eyebrow big"', WP_Fake::$posts[10]['post_content']);
}, 'differs from the lock outside its slots');
$drifted('a governed part changed outside its slots', static function (array $t): void {
    WP_Fake::$posts[70]['post_content'] = str_replace('<!-- wp:site-title /-->', '', WP_Fake::$posts[70]['post_content']);
}, 'differs from the lock outside its slots');
$drifted('a pinned option that differs', static function (array $t): void {
    WP_Fake::$options['stylesheet'] = 'other';
}, 'Option stylesheet differs');
// Tracy ADR 0022, measured 26/09 on the local stand: an agent renamed a bound page's slug through
// WordPress, and every apply on the site refused "Missing or ambiguous entity (0 rows)". A bound
// page is found by the id it was bound with; one gone from the site closes its own slots only.
$boundSite = static function () use ($SITE, $FIXTURES, $door): array {
    $t = contractSite($SITE, $FIXTURES);
    check('a site to rename and delete from binds', $door($t['engine'], 'bind', ['contract' => 'test-design/wp7/1.0.0'])['ok'], true);
    return $t;
};
$t = $boundSite();
WP_Fake::$posts[10]['post_name'] = 'start';
$seen = $door($t['engine'], 'inspect');
check('a bound page with a new slug still inspects', $seen['ok'], true);
checkTrue('and the new slug is a warning', in_array('Entity page-home has another post_name', array_column($seen['warnings'] ?? [], 'message'), true));
$moved = $door($t['engine'], 'apply', ['expected_revision' => $seen['revision'], 'apply_id' => 'contract-slug', 'request_id' => 'slug', 'changes' => ['home.hero.eyebrow' => 'Since 1999']]);
check('its slots are written through the id it was bound with', [$moved['ok'], strpos(WP_Fake::$posts[10]['post_content'], 'Since 1999') !== false], [true, true]);
$t = $boundSite();
unset(WP_Fake::$posts[10]);
$seen = $door($t['engine'], 'inspect');
check('a bound page gone from the site leaves the door open', $seen['ok'], true);
checkTrue('and is named in the warnings', in_array('Missing or ambiguous entity (0 rows): page-home', array_column($seen['warnings'] ?? [], 'message'), true));
$gone = $door($t['engine'], 'apply', ['expected_revision' => $seen['revision'], 'apply_id' => 'contract-gone', 'request_id' => 'gone', 'changes' => ['home.hero.eyebrow' => 'x']]);
check('its own slots are refused, one by one', array_column($gone['errors'] ?? [], 'code'), ['SLOT_UNKNOWN']);
$other = $door($t['engine'], 'apply', ['expected_revision' => $seen['revision'], 'apply_id' => 'contract-other', 'request_id' => 'other', 'changes' => ['site.name' => 'Acme']]);
check('every other slot is still written', $other['ok'], true);
$refused('a page that is not there', static function (array $t): void {
    unset(WP_Fake::$posts[10]);
}, 'Missing or ambiguous entity (0 rows): page-home');
$refused('a slug that is there twice without a language to tell them apart', static function (array $t): void {
    WP_Fake::$posts[11] = WP_Fake::$posts[10] + [];
    WP_Fake::$posts[11]['ID'] = 11;
}, 'Missing or ambiguous entity (2 rows)');
$drifted('a page whose status moved', static function (array $t): void {
    WP_Fake::$posts[10]['post_status'] = 'draft';
}, 'another status');
$refused('a slot whose block is gone', static function (array $t): void {
    WP_Fake::$posts[10]['post_content'] = str_replace('"name":"hero.eyebrow"', '"name":"hero.kicker"', WP_Fake::$posts[10]['post_content']);
}, 'block hero.eyebrow is not in this pattern');

// A demo-trim map that does not belong to its contract makes the whole profile unusable.
$bad = contractSite($SITE, $FIXTURES);
check('a profile whose demo-trim map is not its own is unavailable', $door($bad['engine'], 'inspect', ['contract' => 'bad-trim/wp7/1.0.0'])['error'], 'contract_unavailable');

// With Polylang, the same slug in two languages is two entities, and an edition can be written.
$p = contractSite($SITE, $FIXTURES, true);
$P = $p['engine'];
$pb = $door($P, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
check('with Polylang the English page is the one that binds', $pb['ids']['page-home'], 10);
$de = $door($P, 'apply', ['expected_revision' => $pb['revision'], 'apply_id' => 'contract-de', 'request_id' => 'd1', 'changes' => ['de::home.hero.eyebrow' => 'Seit 1999']]);
check('an edition slot writes into the copy', $de['ok'], true);
check('the German page', array_column($de['written'], 'id'), [60]);
checkTrue('holds the value', strpos(WP_Fake::$posts[60]['post_content'], '<p class="eyebrow">Seit 1999</p>') !== false);
check('the English page is untouched', WP_Fake::$posts[10]['post_content'], $SITE['home']);
check('a block the edition ships without is refused by name', $door($P, 'apply', ['expected_revision' => $pb['revision'], 'apply_id' => 'contract-de2', 'request_id' => 'd2', 'changes' => ['de::home.hero.cta.text' => 'Anrufen']])['message'], 'The de edition has no block for de::home.hero.cta.text');
check('a locale with no edition is refused', $door($P, 'apply', ['expected_revision' => $pb['revision'], 'apply_id' => 'contract-fr', 'request_id' => 'f1', 'changes' => ['fr::home.hero.eyebrow' => 'x']])['error'], 'contract_failed');

// ── the writer lock ────────────────────────────────────────────────────────────────────────

$GLOBALS['wpdb']->queries = [];
$door($E, 'inspect');
checkTrue('a contract call takes the advisory lock', (bool) array_filter($GLOBALS['wpdb']->queries, static fn (string $q) => strpos($q, 'GET_LOCK') !== false));
checkTrue('and releases it', (bool) array_filter($GLOBALS['wpdb']->queries, static fn (string $q) => strpos($q, 'RELEASE_LOCK') !== false));
$GLOBALS['wpdb']->lockAnswer = 0;
check('a site another writer holds answers writer_busy', $door($E, 'inspect')['error'], 'writer_busy');
$GLOBALS['wpdb']->lockAnswer = 1;

// ── demo trim ──────────────────────────────────────────────────────────────────────────────

$d = contractSite($SITE, $FIXTURES);
$D = $d['engine'];
check('a demo trim needs a bound site', $door($D, 'demoTrim.plan')['error'], 'contract_failed');
$door($D, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
$tplan = $door($D, 'demoTrim.plan');
check('the plan counts what it would hide', $tplan['hides'], ['post' => 4]);
check('and reads every row as pending', array_column($tplan['rows'], 'state'), ['pending', 'pending', 'pending', 'pending']);
check('no trim on record yet', $tplan['status'], 'none');
check('apply needs a dtrim- apply_id', $door($D, 'demoTrim.apply', ['apply_id' => 'contract-1', 'request_id' => 't1'])['error'], 'bad_params');
WP_Fake::$posts[27]['post_status'] = 'private'; // the customer already moved this one
$trimmed = $door($D, 'demoTrim.apply', ['apply_id' => 'dtrim-1', 'request_id' => 't1']);
check('apply hides the rows that stand where the profile left them', $trimmed['status'], 'completed');
check('three moved', $trimmed['moved'], 3);
check('the customer-edited one is skipped and named', $trimmed['skipped'], [['key' => 'post-27', 'id' => 27, 'status' => 'private']]);
check('the posts are drafts now', [WP_Fake::$posts[26]['post_status'], WP_Fake::$posts[28]['post_status'], WP_Fake::$posts[29]['post_status']], ['draft', 'draft', 'draft']);
check('written raw, and the cache cleaned', isset(WP_Fake::$cleaned[26], WP_Fake::$cleaned[28]), true);
check('each move logged with its before', array_column($d['log']->entries('dtrim-1'), 'before'), ['publish', 'publish', 'publish']);
$trimState = $door($D, 'inspect');
check('the binding records the trim', $trimState['demoTrim']['status'], 'complete');
check('and how many it hid', $trimState['demoTrim']['hidden'], 3);
check('inspect stays clean', $trimState['problems'], []);
check('a rerun is idempotent', $door($D, 'demoTrim.apply', ['apply_id' => 'dtrim-2', 'request_id' => 't2'])['alreadyTrimmed'], true);
check('apply.revert of a trim is not the way back', $call($D, 'apply.revert', ['apply_id' => 'dtrim-1'])['error'], 'bad_params');
$untrimmed = $door($D, 'demoTrim.revert', ['apply_id' => 'dtrim-3', 'request_id' => 't3']);
check('revert shows them again', $untrimmed['status'], 'reverted');
check('restoring from', [WP_Fake::$posts[26]['post_status'], WP_Fake::$posts[28]['post_status']], ['publish', 'publish']);
check('and leaving the customer-edited one alone', WP_Fake::$posts[27]['post_status'], 'private');
check('no trim on record any more', $door($D, 'inspect')['demoTrim'], null);
check('reverting twice has nothing to do', $door($D, 'demoTrim.revert', ['apply_id' => 'dtrim-4', 'request_id' => 't4'])['error'], 'contract_failed');

$f = contractSite($SITE, $FIXTURES, true);
WP_Fake::$languages['fr'] = ['term_id' => 7, 'name' => 'Français', 'slug' => 'fr', 'locale' => 'fr_FR', 'is_rtl' => 0, 'term_group' => 2, 'flag_code' => 'fr'];
$door($f['engine'], 'bind', ['contract' => 'test-design/wp7/1.0.0']);
$foreign = $door($f['engine'], 'demoTrim.apply', ['apply_id' => 'dtrim-1', 'request_id' => 't1']);
check('a language the contract has no edition for refuses the trim', $foreign['error'], 'contract_failed');
checkTrue('naming the language', strpos($foreign['message'], 'fr') !== false);
check('and nothing moved', WP_Fake::$posts[26]['post_status'], 'publish');

// ── source language ────────────────────────────────────────────────────────────────────────

$n = contractSite($SITE, $FIXTURES);
$door($n['engine'], 'bind', ['contract' => 'test-design/wp7/1.0.0']);
check('without Polylang there is no locale to respell', $door($n['engine'], 'sourceLanguage.plan')['error'], 'unavailable');

$l = contractSite($SITE, $FIXTURES, true);
$L = $l['engine'];
$door($L, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
$lplan = $door($L, 'sourceLanguage.plan', ['locale' => 'en_GB']);
check('the plan says what the source is now', $lplan['current'], 'en_US');
check('and what it may become', $lplan['variants'], ['en_US', 'en_GB', 'en_AU', 'en_CA', 'en_NZ']);
check('a locale that is another language is refused', $door($L, 'sourceLanguage.set', ['apply_id' => 'srclang-1', 'request_id' => 's1', 'locale' => 'fr_FR'])['error'], 'bad_params');
check('set needs a srclang- apply_id', $door($L, 'sourceLanguage.set', ['apply_id' => 'x-1', 'request_id' => 's1', 'locale' => 'en_GB'])['error'], 'bad_params');
$noop = $door($L, 'sourceLanguage.set', ['apply_id' => 'srclang-1', 'request_id' => 's1', 'locale' => 'en_US']);
check('setting the locale it already has is a no-op that says so', $noop['alreadySet'], true);
$set = $door($L, 'sourceLanguage.set', ['apply_id' => 'srclang-2', 'request_id' => 's2', 'locale' => 'en_GB']);
check('set respells the source', $set['status'], 'completed');
check('Polylang was told', WP_Fake_PLL_Languages::$updates[0]['locale'] ?? null, 'en_GB');
check('for the source language', WP_Fake_PLL_Languages::$updates[0]['lang_id'] ?? null, 5);
check('and its slug kept', WP_Fake_PLL_Languages::$updates[0]['slug'] ?? null, 'en');
check('WordPress too', WP_Fake::$options['WPLANG'], 'en_GB');
check('the binding records it', $door($L, 'inspect')['sourceLanguage']['to'], 'en_GB');
check('inspect stays clean', $door($L, 'inspect')['problems'], []);
check('a second respell must take the first back', $door($L, 'sourceLanguage.set', ['apply_id' => 'srclang-3', 'request_id' => 's3', 'locale' => 'en_AU'])['error'], 'conflict');
$back = $door($L, 'sourceLanguage.revert', ['apply_id' => 'srclang-4', 'request_id' => 's4']);
check('revert puts en_US back', $back['locale'], 'en_US');
check('in Polylang', WP_Fake::$languages['en']['locale'], 'en_US');
check('and WPLANG as it was: absent', array_key_exists('WPLANG', WP_Fake::$options), false);
check('nothing on record', $door($L, 'inspect')['sourceLanguage'], null);
check('reverting again has nothing to take back', $door($L, 'sourceLanguage.revert', ['apply_id' => 'srclang-5', 'request_id' => 's5'])['error'], 'contract_failed');

// Leave the fake as the next file expects it.
WP_Fake::reset();
