<?php
/**
 * Retiring editions on a sealed multilingual site, and bringing them back: `multilingual.retire`
 * and `multilingual.restore` on the content-contract door, plus the front-end hooks that keep a
 * retired language out of the switcher and the hreflang list.
 *
 * Loaded by run.php after contracts.php. Uses `check()` / `checkTrue()`, the `$WTOKEN` it
 * defines, and `contractSite()` / `contractRoot()` from contracts.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/MultilingualHooks.php';

echo "\nMultilingual retire\n";

$REAL_ID = 'tracy-business/wp7/1.1.0';
$REAL_DIR = __DIR__ . '/../lib/contracts/' . $REAL_ID;
$EDITIONS = json_decode((string) file_get_contents($REAL_DIR . '/editions.json'), true);

/**
 * A site restored from the real 41-edition Tracy Business archive, as far as this operation can
 * see it: Polylang with one language per edition, and per edition five pages, two posts and one
 * navigation twin (`tracy-<slug>`; the source edition's is `tracy`, navigation rows carry no
 * Polylang language — measured on a real site, 25/09/2026). Plus one post the customer added
 * without a language. Bound by writing the store directly: the real lock pins theme files this
 * fake cannot reproduce, and the retire only needs the seal, never a clean inspect.
 *
 * @return array{engine:Engine,log:FakeApplyLog,rows:array<string,int[]>}
 */
function multilingualSite(array $editions, string $contractId, string $contractsDir, bool $bind = true, bool $polylang = true): array
{
    WP_Fake::reset();
    WP_Fake::$stylesheet = 'tracy';
    WP_Fake::$options = ['home' => 'http://test.local'];
    WP_Fake::$polylang = $polylang;
    $rows = [];
    $id = 1000;
    $term = 100;
    $content = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
    foreach ($editions['locales'] as $slug => $edition) {
        WP_Fake::$languages[$slug] = ['term_id' => $term++, 'name' => $slug, 'slug' => $slug, 'locale' => $edition['locale'], 'is_rtl' => 0, 'term_group' => 0, 'flag_code' => $slug];
        $rows[$slug] = [];
        foreach (['page', 'page', 'page', 'page', 'page', 'post', 'post'] as $type) {
            $id++;
            WP_Fake::$posts[$id] = ['ID' => $id, 'post_type' => $type, 'post_name' => $type . '-' . $id, 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => $type . ' ' . $slug, 'post_content' => $content];
            WP_Fake::$postLanguage[$id] = $slug;
            $rows[$slug][] = $id;
        }
        $id++;
        $navSlug = $slug === $editions['source']['language'] ? 'tracy' : 'tracy-' . $slug;
        WP_Fake::$posts[$id] = ['ID' => $id, 'post_type' => 'wp_navigation', 'post_name' => $navSlug, 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Header', 'post_content' => $content];
        $rows[$slug][] = $id;
    }
    WP_Fake::$posts[9000] = ['ID' => 9000, 'post_type' => 'post', 'post_name' => 'mine', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Customer post', 'post_content' => $content];
    if ($bind) {
        WP_Fake::$options[QuickstartContract::STORE_OPTION] = json_encode([
            'schemaVersion' => 1, 'contract' => $contractId, 'contractHash' => DemoTrimProfile::baseHash($contractsDir . '/' . $contractId),
            'revision' => 'r', 'ids' => [], 'demoTrim' => null, 'sourceLanguage' => null, 'boundAt' => 'now',
        ]);
    }
    $writer = new Claude_Cowork_Site_Writer();
    $log = new FakeApplyLog();
    $contract = new QuickstartContract($writer, new Claude_Cowork_Contract_Store(), sys_get_temp_dir(), $contractsDir);
    $engine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $writer, new FakeMediaWriter(), $log, null, $contract);
    MultilingualHooks::reset();
    return ['engine' => $engine, 'log' => $log, 'rows' => $rows];
}

$mdoor = static function (Engine $engine, string $operation, array $params = []) use ($WTOKEN): array {
    return $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};
$statuses = static function (array $ids): array {
    $out = [];
    foreach ($ids as $id) {
        $out[] = WP_Fake::$posts[$id]['post_status'];
    }
    return array_values(array_unique($out));
};
$contractsDir = __DIR__ . '/../lib/contracts';

// ── refusals ────────────────────────────────────────────────────────────────────────────────

$u = multilingualSite($EDITIONS, $REAL_ID, $contractsDir, false);
check('an unbound site cannot retire an edition', $mdoor($u['engine'], 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm1', 'keep' => ['vi'], 'contract' => $REAL_ID])['error'], 'contract_failed');
check('nor restore one', $mdoor($u['engine'], 'multilingual.restore', ['apply_id' => 'mlang-1', 'contract' => $REAL_ID])['error'], 'contract_failed');
$np = multilingualSite($EDITIONS, $REAL_ID, $contractsDir, true, false);
check('without Polylang there is no edition to retire', $mdoor($np['engine'], 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm1', 'keep' => ['vi']])['error'], 'unavailable');

$m = multilingualSite($EDITIONS, $REAL_ID, $contractsDir);
$M = $m['engine'];
check('retire needs an mlang- apply_id', $mdoor($M, 'multilingual.retire', ['apply_id' => 'dtrim-1', 'request_id' => 'm1', 'keep' => ['vi']])['error'], 'bad_params');
check('and a request_id', $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'keep' => ['vi']])['error'], 'bad_params');
check('and a keep list', $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm1', 'keep' => 'vi'])['error'], 'bad_params');
$unknown = $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm1', 'keep' => ['vi', 'sw']]);
check('a language the archive ships no edition of cannot be kept', $unknown['error'], 'bad_params');
checkTrue('and is named', strpos($unknown['message'], 'sw') !== false);
check('nothing moved on any refusal', $statuses(array_merge(...array_values($m['rows']))), ['publish']);
check('and nothing was logged', $m['log']->log, []);

// ── retire: keep en-us and vi on the 41-edition profile ─────────────────────────────────────

$first = $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm1', 'keep' => ['en-us', 'vi']]);
check('the first call moves a batch and says it is not done', [$first['ok'], $first['status'], $first['moved'], $first['restored'], $first['remaining']], [true, 'running', 300, 0, 12]);
check('39 editions are retired', count($first['retired']), 39);
check('the source and vi are live', $first['live'], ['en', 'vi']);
check('under the apply id', $first['applyId'], 'mlang-1');
$second = $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm1', 'keep' => ['en-us', 'vi']]);
check('the second call finishes', [$second['status'], $second['moved'], $second['remaining']], ['completed', 12, 0]);
check('the source edition is untouched', $statuses($m['rows']['en']), ['publish']);
check('vi is untouched', $statuses($m['rows']['vi']), ['publish']);
$others = [];
foreach ($m['rows'] as $slug => $ids) {
    if ($slug !== 'en' && $slug !== 'vi') {
        $others = array_merge($others, $ids);
    }
}
check('every row of the 39 other editions is a draft', $statuses($others), ['draft']);
check('312 rows in all', count($others), 312);
check('the navigation twin of a retired edition is a draft', WP_Fake::$posts[end($m['rows']['de'])]['post_status'], 'draft');
check('the source navigation is not', WP_Fake::$posts[end($m['rows']['en'])]['post_status'], 'publish');
check('the customer-added post without a language is untouched', WP_Fake::$posts[9000]['post_status'], 'publish');
check('written raw, and the cache cleaned', count(array_intersect_key(WP_Fake::$cleaned, array_flip($others))), 312);
$entries = $m['log']->entries('mlang-1');
check('every move is logged as a visibility step with its before', count($entries), 312);
check('in the demo-trim shape plus the edition', array_intersect_key($entries[0], array_flip(['op', 'kind', 'column', 'before', 'language'])), ['op' => 'visibility', 'kind' => 'page', 'column' => 'post_status', 'before' => 'publish', 'language' => 'af']);
$record = json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true)['multilingual'];
check('the binding records the live set', [$record['applyId'], $record['requestId'], $record['live'], count($record['retired']), $record['status']], ['mlang-1', 'm1', ['en', 'vi'], 39, 'complete']);
checkTrue('and when', isset($record['at']));

// ── the same set again: nothing to do ───────────────────────────────────────────────────────

$again = $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm2', 'keep' => ['vi', 'en-us']]);
check('the same keep list is a no-op', [$again['status'], $again['moved'], $again['restored'], $again['remaining']], ['completed', 0, 0, 0]);
check('the log did not grow', count($m['log']->entries('mlang-1')), 312);
check('a keep list without the source keeps it anyway', $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm3', 'keep' => ['vi']])['live'], ['en', 'vi']);

// ── a wider set under the same receipt: de comes back from its before ───────────────────────

WP_Fake::$cleaned = [];
$wider = $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm4', 'keep' => ['en-us', 'vi', 'de-de']]);
check('adding de restores its rows', [$wider['status'], $wider['moved'], $wider['restored'], $wider['remaining']], ['completed', 0, 8, 0]);
check('de is live', $wider['live'], ['de', 'en', 'vi']);
check('38 retired', count($wider['retired']), 38);
check('the de rows are published again', $statuses($m['rows']['de']), ['publish']);
check('the navigation twin too', WP_Fake::$posts[end($m['rows']['de'])]['post_status'], 'publish');
check('the log shrank by the rows brought back', count($m['log']->entries('mlang-1')), 304);
check('and no longer names de', in_array('de', array_column($m['log']->entries('mlang-1'), 'language'), true), false);
check('fr is still a draft', $statuses($m['rows']['fr']), ['draft']);
check('a primary subtag names its one edition', $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm5', 'keep' => ['en-us', 'vi', 'de']])['live'], ['de', 'en', 'vi']);
check('and a region the archive does not spell falls back to the primary subtag', $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-1', 'request_id' => 'm6', 'keep' => ['en-us', 'vi', 'de-at']])['live'], ['de', 'en', 'vi']);

// ── another receipt while one is on record ──────────────────────────────────────────────────

check('a second apply id must take the first back', $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-2', 'request_id' => 'x1', 'keep' => ['vi']])['error'], 'conflict');
check('restore needs the id on record', $mdoor($M, 'multilingual.restore', ['apply_id' => 'mlang-2'])['error'], 'conflict');
check('and an mlang- id', $mdoor($M, 'multilingual.restore', ['apply_id' => 'dtrim-1'])['error'], 'bad_params');

// ── the hooks, while the retired set is on record ───────────────────────────────────────────

MultilingualHooks::reset();
check('the hooks read the retired set from the binding', count(MultilingualHooks::retired()), 38);
$html = '<ul><li class="lang-item lang-item-100 lang-item-en current-lang"><a lang="en-US" hreflang="en-US" href="/">English</a></li>'
    . "\n" . '<li class="lang-item lang-item-107 lang-item-de"><a lang="de-DE" hreflang="de-DE" href="/de/">Deutsch</a></li>'
    . "\n" . '<li class="lang-item lang-item-113 lang-item-fr"><a lang="fr-FR" hreflang="fr-FR" href="/fr/">Français</a></li>'
    . "\n" . '<li class="lang-item lang-item-126 lang-item-pt-br"><a lang="pt-BR" href="/pt-br/">Português</a></li>'
    . "\n" . '<li class="lang-item lang-item-127 lang-item-pt-pt"><a lang="pt-PT" href="/pt-pt/">Português</a></li>'
    . "\n" . '<li class="lang-item lang-item-138 lang-item-vi"><a lang="vi" href="/vi/">Tiếng Việt</a></li></ul>';
$filtered = MultilingualHooks::filterSwitcher($html, ['raw' => 0]);
check('the html switcher drops retired items', preg_match_all('/<li /', $filtered), 3);
checkTrue('keeping en, de and vi', strpos($filtered, 'lang-item-en') !== false && strpos($filtered, 'lang-item-de') !== false && strpos($filtered, 'lang-item-vi') !== false);
checkTrue('and dropping fr and both pt', strpos($filtered, 'lang-item-fr') === false && strpos($filtered, 'lang-item-pt') === false);
$select = '<select><option value="/" lang="en-US">English</option><option value="/fr/" lang="fr-FR" data-lang="fr">Français</option><option value="/vi/" lang="vi" data-lang="vi">Tiếng Việt</option></select>';
check('a dropdown switcher too', MultilingualHooks::filterSwitcher($select, ['dropdown' => 1]), '<select><option value="/" lang="en-US">English</option><option value="/vi/" lang="vi" data-lang="vi">Tiếng Việt</option></select>');
$raw = ['en' => ['slug' => 'en'], 'fr' => ['slug' => 'fr'], 'vi' => ['slug' => 'vi'], 'de' => ['slug' => 'de']];
check('and the raw list, should a Polylang pass it through the filter', array_keys(MultilingualHooks::filterSwitcher($raw, ['raw' => 1])), ['en', 'vi', 'de']);
$args = MultilingualHooks::filterArgs(['raw' => 1, 'hide_if_no_translation' => 0]);
check('the raw list is thinned through the switcher arguments instead', $args['hide_if_no_translation'], 1);
check('a retired language gets no link, so the switcher drops it', MultilingualHooks::filterLink('/fr/', 'fr', 'fr_FR'), null);
check('a live language keeps its link', MultilingualHooks::filterLink('/vi/', 'vi', 'vi'), '/vi/');
check('and a live language with no translation keeps its home, as Polylang would have given it', MultilingualHooks::filterLink(null, 'vi', 'vi'), 'http://test.local/vi/');
MultilingualHooks::filterArgs(['raw' => 1, 'hide_if_no_translation' => 1]);
check('a caller that hid untranslated languages itself gets Polylang\'s own answer', MultilingualHooks::filterLink(null, 'vi', 'vi'), null);
$hreflang = ['en-US' => '/', 'en-GB' => '/gb/', 'de' => '/de/', 'fr' => '/fr/', 'pt-BR' => '/pt-br/', 'pt-PT' => '/pt-pt/', 'vi' => '/vi/', 'x-default' => '/'];
check('hreflang drops the retired locales', array_keys(MultilingualHooks::filterHreflang($hreflang)), ['en-US', 'en-GB', 'de', 'vi', 'x-default']);

// ── restore: everything back, the binding clean ─────────────────────────────────────────────

$restored = $mdoor($M, 'multilingual.restore', ['apply_id' => 'mlang-1']);
check('restore puts every retired row back', [$restored['ok'], $restored['restored'], $restored['applyId']], [true, 304, 'mlang-1']);
check('every edition is published again', $statuses(array_merge(...array_values($m['rows']))), ['publish']);
check('the log is cleared', $m['log']->entries('mlang-1'), []);
check('and the binding carries no retired set', json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true)['multilingual'], null);
check('restoring again has nothing to take back', $mdoor($M, 'multilingual.restore', ['apply_id' => 'mlang-1'])['error'], 'contract_failed');
MultilingualHooks::reset();
check('the hooks now have nothing to hide', MultilingualHooks::retired(), []);
check('and pass the switcher through', MultilingualHooks::filterSwitcher($html, ['raw' => 0]), $html);
check('and the hreflang list', MultilingualHooks::filterHreflang($hreflang), $hreflang);
check('and the link', MultilingualHooks::filterLink(null, 'fr', 'fr_FR'), null);
check('and the arguments', MultilingualHooks::filterArgs(['hide_if_no_translation' => 0])['hide_if_no_translation'], 0);

// ── keeping everything again under the same receipt is a restore ────────────────────────────

$mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-3', 'request_id' => 'k1', 'keep' => ['en-us']]);
$narrow = $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-3', 'request_id' => 'k1', 'keep' => ['en-us']]);
check('a narrow set retires 40 editions in two calls', [$narrow['status'], $narrow['moved'], count($narrow['retired'])], ['completed', 20, 40]);
$all = array_map(static fn (array $e): string => $e['tag'], $EDITIONS['locales']);
$back = $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-3', 'request_id' => 'k2', 'keep' => array_values($all)]);
check('keeping every edition brings each back (the first 300)', [$back['status'], $back['restored'], $back['remaining']], ['running', 300, 20]);
$back = $mdoor($M, 'multilingual.retire', ['apply_id' => 'mlang-3', 'request_id' => 'k2', 'keep' => array_values($all)]);
check('and the rest', [$back['status'], $back['restored'], $back['retired']], ['completed', 20, []]);
check('leaving no record, as a restore would', json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true)['multilingual'], null);
check('and no log', $m['log']->entries('mlang-3'), []);

// ── on a properly bound site: inspect, demo trim and apply.revert beside a retired set ──────

$FIXTURES = __DIR__ . '/fixtures/contracts';
$SITE = json_decode((string) file_get_contents($FIXTURES . '/site.json'), true);
$b = contractSite($SITE, $FIXTURES, true);
$B = $b['engine'];
$call = static function (string $action, array $params = []) use ($B, $WTOKEN): array {
    return $B->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
};
$mdoor($B, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
check('a bound site starts with no retired set', $mdoor($B, 'inspect')['multilingual'], null);
$ret = $mdoor($B, 'multilingual.retire', ['apply_id' => 'mlang-b', 'request_id' => 'b1', 'keep' => ['en-us']]);
check('retiring de on the fixture site', [$ret['status'], $ret['moved'], $ret['retired'], $ret['live']], ['completed', 2, ['de'], ['en']]);
check('drafts the German page and the German demo post', [WP_Fake::$posts[60]['post_status'], WP_Fake::$posts[29]['post_status']], ['draft', 'draft']);
$insp = $mdoor($B, 'inspect');
check('inspect stays clean', $insp['problems'], []);
check('and reports the retired set', $insp['multilingual'], ['retired' => ['de'], 'live' => ['en'], 'applyId' => 'mlang-b']);
check('apply.revert is not the way back', $call('apply.revert', ['apply_id' => 'mlang-b'])['error'], 'content_only');
checkTrue('and says which is', strpos($call('apply.revert', ['apply_id' => 'mlang-b'])['message'], 'multilingual.restore') !== false);
$trim = $mdoor($B, 'demoTrim.apply', ['apply_id' => 'dtrim-b', 'request_id' => 't1']);
check('a demo trim beside a retired set is not refused', $trim['status'], 'completed');
check('it hides the source demo rows', $trim['moved'], 3);
check('and reports the retired edition\'s row as skipped, not moved', $trim['skipped'], [['key' => 'post-29', 'id' => 29, 'status' => 'draft', 'retired' => true]]);
check('inspect is still clean', $mdoor($B, 'inspect')['problems'], []);
$untrim = $mdoor($B, 'demoTrim.revert', ['apply_id' => 'dtrim-b2', 'request_id' => 't2']);
check('a demo trim revert leaves the retired row hidden', [$untrim['status'], WP_Fake::$posts[29]['post_status'], WP_Fake::$posts[26]['post_status']], ['reverted', 'draft', 'publish']);
$res = $mdoor($B, 'multilingual.restore', ['apply_id' => 'mlang-b']);
check('restore brings de back', [$res['restored'], WP_Fake::$posts[60]['post_status'], WP_Fake::$posts[29]['post_status']], [2, 'publish', 'publish']);
check('inspect is clean after the restore', $mdoor($B, 'inspect')['problems'], []);
check('with nothing on record', $mdoor($B, 'inspect')['multilingual'], null);

// Leave the fake as the next file expects it.
WP_Fake::reset();
MultilingualHooks::reset();
