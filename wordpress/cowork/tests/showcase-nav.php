<?php
/**
 * Taking a quickstart's template showcase out of a bound site's navigation, and putting it back:
 * `showcaseNav.status|apply|revert` on the content-contract door, over the real released maps
 * (`lib/contracts/<id>/showcase-nav-map.json`) and the real navigation and template-part markup
 * they were generated from (`fixtures/showcase-nav/`, cut from each release's navigation-source.json).
 *
 * Loaded by run.php after contracts.php. Uses `check()` / `checkTrue()` and the `$WTOKEN` it defines.
 */
declare(strict_types=1);

echo "\nShowcase navigation\n";

$snavContracts = __DIR__ . '/../lib/contracts';

/**
 * A bound site holding one release's navigations and template parts as the release shipped them,
 * at their release ids, in the active theme. Bound by writing the store directly, as the
 * multilingual tests do: the rest of the release is not here, which inspect reports as drift.
 *
 * @return array{engine:Engine,log:FakeApplyLog,writer:Claude_Cowork_Site_Writer,source:array}
 */
function snavSite(string $contractId, string $contractsDir, array $options = []): array
{
    WP_Fake::reset();
    WP_Fake::$stylesheet = 'quickstart-theme';
    WP_Fake::$options = ['home' => $options['home'] ?? 'http://test.local'];
    $GLOBALS['wpdb']->beforeSwap = null;
    $fixture = __DIR__ . '/fixtures/showcase-nav/' . explode('/', $contractId)[0] . '.json';
    $source = is_file($fixture) ? json_decode((string) file_get_contents($fixture), true) : ['navigations' => [], 'templateParts' => []];
    if (isset($options['home'])) {
        // Provisioned: `wp search-replace <placeholderUrl> <site url>` ran before anything else.
        foreach (['navigations', 'templateParts'] as $group) {
            foreach ($source[$group] as $i => $row) {
                $source[$group][$i]['content'] = str_replace('https://quickstart.invalid', rtrim($options['home'], '/'), $row['content']);
            }
        }
    }
    foreach ($source['navigations'] as $nav) {
        WP_Fake::$posts[$nav['id']] = ['ID' => $nav['id'], 'post_type' => 'wp_navigation', 'post_name' => $nav['slug'], 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => $nav['slug'], 'post_content' => $nav['content']];
    }
    foreach ($source['templateParts'] as $part) {
        if (!empty($options['themeFileOnly'])) {
            continue;
        }
        WP_Fake::$posts[$part['id']] = ['ID' => $part['id'], 'post_type' => 'wp_template_part', 'post_name' => $part['slug'], 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => $part['slug'], 'post_content' => $part['content']];
        WP_Fake::$terms[$part['id'] . ':wp_theme'] = ['quickstart-theme'];
        WP_Fake::$terms[$part['id'] . ':wp_template_part_area'] = [$part['area']];
    }
    if (isset($options['themeDir'])) {
        WP_Fake::$themeDir = $options['themeDir'];
    }
    WP_Fake::$nextId = 5000;
    WP_Fake::$options[QuickstartContract::STORE_OPTION] = json_encode([
        'schemaVersion' => 1, 'contract' => $contractId, 'contractHash' => DemoTrimProfile::baseHash($contractsDir . '/' . $contractId),
        'revision' => 'r', 'ids' => [], 'demoTrim' => null, 'sourceLanguage' => null, 'boundAt' => 'now',
    ]);
    $writer = new Claude_Cowork_Site_Writer();
    $log = new FakeApplyLog();
    $contract = new QuickstartContract($writer, new Claude_Cowork_Contract_Store(), sys_get_temp_dir(), $contractsDir);
    $engine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $writer, new FakeMediaWriter(), $log, null, $contract);
    return ['engine' => $engine, 'log' => $log, 'writer' => $writer, 'source' => $source];
}

$sdoor = static function (Engine $engine, string $operation, array $params = []) use ($WTOKEN): array {
    return $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};
$sha = static fn(int $id): string => hash('sha256', (string) WP_Fake::$posts[$id]['post_content']);
$labels = static function (int $id): array {
    return array_map(static fn(array $item): string => $item['label'], ShowcaseNavMarkup::items(ShowcaseNavMarkup::parse((string) WP_Fake::$posts[$id]['post_content'])));
};
$finals = static function (string $contractId, ?string $contractsDir = null) use ($snavContracts): array {
    $out = [];
    foreach (json_decode((string) file_get_contents(($contractsDir ?? $snavContracts) . '/' . $contractId . '/showcase-nav-map.json'), true)['finals'] as $final) {
        $out[$final['key']] = $final;
    }
    return $out;
};
$drift = static function (Engine $engine) use ($sdoor): array {
    // inspect answers drift as warnings; the header is the one template part these maps edit.
    $messages = array_column($sdoor($engine, 'inspect')['warnings'] ?? [], 'message');
    return array_values(array_filter($messages, static fn(string $d): bool => strpos($d, 'Template part header') === 0));
};
$contents = static function (array $ids): array {
    return array_map(static fn(int $id): string => (string) (WP_Fake::$posts[$id]['post_content'] ?? ''), $ids);
};

// ── every released map is one this receiver takes ───────────────────────────────────────────

foreach (glob($snavContracts . '/*/wp7/*/showcase-nav-map.json') as $file) {
    $id = substr(dirname($file), strlen($snavContracts) + 1);
    $probe = snavSite($id, $snavContracts);
    $answer = $sdoor($probe['engine'], 'showcaseNav.status');
    check('the released map of ' . $id . ' is taken', [$answer['ok'], $answer['status'] ?? null], [true, 'none']);
}

$DINER = 'ja-diner/wp7/1.0.1';
$F = $finals($DINER);
$MORGAN = 'ja-morgan/wp7/1.1.16';
$MF = $finals($MORGAN);

// The released Diner and Morgan maps only take entries out of navigations. The receiver still
// applies the two template-part edits the builder shipped for them before (Diner's drawer repointed
// at the main menu, Morgan's drawer and its toggle removed): those maps, built against the same
// release bytes, are fixtures (`fixtures/showcase-nav/*-map.json`), each served from a copy of its
// contract in place of the released map.
$snavRemove = static function (string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $node) {
        $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname());
    }
    rmdir($dir);
};
$snavFixtures = sys_get_temp_dir() . '/snav-fixtures-' . bin2hex(random_bytes(4));
register_shutdown_function($snavRemove, $snavFixtures);
foreach ([$DINER => 'ja-diner-repoint-map.json', $MORGAN => 'ja-morgan-drawer-map.json'] as $id => $fixtureMap) {
    mkdir($snavFixtures . '/' . $id, 0777, true);
    foreach (['manifest.json', 'content-map.json', 'presentation-lock.json'] as $name) {
        copy($snavContracts . '/' . $id . '/' . $name, $snavFixtures . '/' . $id . '/' . $name);
    }
    copy(__DIR__ . '/fixtures/showcase-nav/' . $fixtureMap, $snavFixtures . '/' . $id . '/showcase-nav-map.json');
}
$P = $finals($DINER, $snavFixtures);
$dinerSource = json_decode((string) file_get_contents(__DIR__ . '/fixtures/showcase-nav/ja-diner.json'), true);
$dinerHeader = hash('sha256', $dinerSource['templateParts'][0]['content']);

// ── refusals ────────────────────────────────────────────────────────────────────────────────

$s = snavSite($DINER, $snavContracts);
$E = $s['engine'];
check('apply needs an snav- apply_id', $sdoor($E, 'showcaseNav.apply', ['apply_id' => 'dtrim-1', 'request_id' => 'n1'])['error'], 'bad_params');
check('and a request_id', $sdoor($E, 'showcaseNav.apply', ['apply_id' => 'snav-1'])['error'], 'bad_params');
check('an unknown operation is refused', $sdoor($E, 'showcaseNav.hide', ['apply_id' => 'snav-1', 'request_id' => 'n1'])['error'], 'bad_params');
check('revert with nothing applied is refused', $sdoor($E, 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1'])['error'], 'contract_failed');
$status = $sdoor($E, 'showcaseNav.status');
check('status before an apply', [$status['ok'], $status['status'], $status['remaining'], $status['hides']],
    [true, 'none', 2, ['navigation.removeLink' => 7]]);

WP_Fake::$polylang = true;
WP_Fake::$languages = [
    'en' => ['term_id' => 5, 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'is_rtl' => 0, 'term_group' => 0, 'flag_code' => 'us'],
    'de' => ['term_id' => 6, 'name' => 'Deutsch', 'slug' => 'de', 'locale' => 'de_DE', 'is_rtl' => 0, 'term_group' => 1, 'flag_code' => 'de'],
];
$ml = $sdoor($E, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('a site with a second language is refused, both ways', [$ml['error'], $sdoor($E, 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1'])['error']], ['contract_failed', 'contract_failed']);
check('and says nothing was written', $ml['message'], 'This site already has a second language; taking its showcase entries out of the menus would leave the translated menus showing them. Nothing has been written.');
check('nothing moved, nothing on record', [$sha(93), $sha(278), $sha(96), $sdoor($E, 'inspect')['showcaseNav']], [$F['navigation-93']['before'], $F['navigation-278']['before'], $dinerHeader, null]);
WP_Fake::$polylang = false;
WP_Fake::$languages = [];

// ── Diner 1.0.1: the drawer keeps the account and search pages, without the template's screens ─

$d = snavSite($DINER, $snavContracts);
$D = $d['engine'];
$first = $sdoor($D, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('both navigations are one batch', [$first['ok'], $first['status'], $first['moved'], $first['remaining'], $first['skipped']], [true, 'completed', 2, 0, []]);
check('every owner holds exactly the bytes the map ends with', [$sha(93), $sha(278)], [$F['navigation-93']['after'], $F['navigation-278']['after']]);
check('the home variants are gone from 93; the real entries are kept', $labels(93), ['Home', 'About Us', 'Reservation', 'Offers', 'Our Blog', 'Contact Us']);
check('the drawer keeps login, registration, profile and search', $labels(278), ['J! Pages', 'Login', 'Registration', 'User Profile', 'Edit User Profile', 'Other Pages', 'Smart Search']);
check('and loses the error, offline and sample content screens', array_values(array_intersect(['Error Page', 'Offline Page', 'Content', 'Typography', 'Tagged Items'], $labels(278))), []);
check('the header is not touched: its drawer still opens 278', [$sha(96), strpos((string) WP_Fake::$posts[96]['post_content'], '"ref":278') !== false], [$dinerHeader, true]);
check('the header is clean to inspect', $drift($D), []);
check('status says complete', [$sdoor($D, 'showcaseNav.status')['status'], $sdoor($D, 'showcaseNav.status')['remaining']], ['complete', 0]);
$replay = $sdoor($D, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('the same request answers the same again', [$replay['status'], $replay['skipped'], $sha(278)], ['completed', [], $F['navigation-278']['after']]);
$again = $sdoor($D, 'showcaseNav.apply', ['apply_id' => 'snav-2', 'request_id' => 'n2']);
check('applying twice writes nothing and says so', [$again['ok'], $again['alreadyApplied'], $again['applyId']], [true, true, 'snav-1']);
check('each write is logged under the apply id, without the bytes', [count($d['log']->entries('snav-1')), array_key_exists('content', $d['log']->entries('snav-1')[0])], [2, false]);
$viaRevert = $D->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'snav-1']]);
check('apply.revert of a showcase receipt points at showcaseNav.revert', [$viaRevert['error'], strpos($viaRevert['message'], 'showcaseNav.revert') !== false], ['bad_params', true]);

$rev = $sdoor($D, 'showcaseNav.revert', ['apply_id' => 'snav-r1', 'request_id' => 'v1']);
check('revert puts both back in one batch', [$rev['status'], $rev['moved'], $rev['remaining'], $rev['conflicts']], ['reverted', 2, 0, []]);
check('every owner is back to the bytes it shipped with', $contents([93, 278, 96]), array_map(static fn($n) => $n['content'], array_merge($d['source']['navigations'], $d['source']['templateParts'])));
$lost = $sdoor($D, 'showcaseNav.revert', ['apply_id' => 'snav-r1', 'request_id' => 'v1']);
check('a revert retried after a lost answer gets it again', [$lost['ok'], $lost['alreadyReverted'], $lost['conflicts']], [true, true, []]);
check('the request that applied cannot apply again', $sdoor($D, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1'])['error'], 'conflict');
check('a new request applies again', [$sdoor($D, 'showcaseNav.apply', ['apply_id' => 'snav-3', 'request_id' => 'n3'])['status'], $sha(278)], ['completed', $F['navigation-278']['after']]);

// ── a map with a template part: the drawer opens the main menu, in batches ──────────────────

$p = snavSite($DINER, $snavFixtures);
$PE = $p['engine'];
check('status counts the repoint', $sdoor($PE, 'showcaseNav.status')['hides'], ['navigation.removeLink' => 7, 'templatePart.setNavigationRef' => 1]);
$first = $sdoor($PE, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('the first call edits a batch and says it is not done', [$first['ok'], $first['status'], $first['moved'], $first['remaining']], [true, 'running', 2, 1]);
$replay = $sdoor($PE, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('the same request resumes it', [$replay['status'], $replay['moved'], $replay['remaining'], $replay['skipped']], ['completed', 1, 0, []]);
check('the header drawer opens navigation 93, not 278', [strpos((string) WP_Fake::$posts[96]['post_content'], '"ref":93') !== false, strpos((string) WP_Fake::$posts[96]['post_content'], '"ref":278')], [true, false]);
check('every owner holds exactly the bytes the fixture map ends with', [$sha(93), $sha(278), $sha(96)],
    [$P['navigation-93']['after'], $P['navigation-278']['after'], $P['templatePart-96']['after']]);
check('the repointed header is clean to inspect', $drift($PE), []);
$rev = $sdoor($PE, 'showcaseNav.revert', ['apply_id' => 'snav-r1', 'request_id' => 'v1']);
$rev2 = $sdoor($PE, 'showcaseNav.revert', ['apply_id' => 'snav-r1', 'request_id' => 'v1']);
check('revert runs in batches too', [[$rev['status'], $rev['moved'], $rev['remaining']], [$rev2['status'], $rev2['moved'], $rev2['remaining'], $rev2['conflicts']]],
    [['running', 2, 1], ['reverted', 1, 0, []]]);
check('every owner, the header too, is back to the bytes it shipped with', $contents([93, 278, 96]), array_map(static fn($n) => $n['content'], array_merge($p['source']['navigations'], $p['source']['templateParts'])));
check('another request is refused while one is in flight', (static function () use ($sdoor, $DINER, $snavFixtures): string {
    $x = snavSite($DINER, $snavFixtures);
    $sdoor($x['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
    return $sdoor($x['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'other'])['error'];
})(), 'conflict');

// ── an apply that dies mid-write, and its retry ─────────────────────────────────────────────

$c = snavSite($DINER, $snavContracts);
$GLOBALS['wpdb']->beforeSwap = static function (int $id): void {
    if ($id === 93) {
        throw new RuntimeException('the connection dropped');
    }
};
$died = $sdoor($c['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$GLOBALS['wpdb']->beforeSwap = null;
check('a write that dies is a failure, not a success', [$died['ok'], $sha(93)], [false, $F['navigation-93']['before']]);
check('its receipt landed before the write', $sdoor($c['engine'], 'inspect')['showcaseNav']['receipts']['navigation-93']['outcome'], 'writing');
$resumed = $sdoor($c['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('the same request finishes it, exactly', [$resumed['status'], $resumed['skipped'], $sha(93), $sha(278)],
    ['completed', [], $F['navigation-93']['after'], $F['navigation-278']['after']]);

// ── the customer is never overwritten ───────────────────────────────────────────────────────

$r = snavSite($DINER, $snavContracts);
$customer = str_replace('Home', 'Start', $r['source']['navigations'][0]['content']);
$GLOBALS['wpdb']->beforeSwap = static function (int $id) use ($customer): void {
    if ($id === 93) {
        WP_Fake::$posts[93]['post_content'] = $customer; // saved in the editor between the read and the write
    }
};
$raced = $sdoor($r['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$GLOBALS['wpdb']->beforeSwap = null;
check('a customer save between the read and the write wins', [WP_Fake::$posts[93]['post_content'] === $customer, $raced['status'], $sha(278)], [true, 'completed', $F['navigation-278']['after']]);
check('and is reported', $raced['skipped'], ['navigation-93' => 'it changed while it was being written']);

$e = snavSite($DINER, $snavContracts);
WP_Fake::$posts[278]['post_content'] = str_replace('Smart Search', 'Search', (string) WP_Fake::$posts[278]['post_content']);
$edited = WP_Fake::$posts[278]['post_content'];
$done = $sdoor($e['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('a navigation the customer edited before the apply is left as it is and reported', [WP_Fake::$posts[278]['post_content'] === $edited, $done['status'], $done['skipped'], $sha(93)],
    [true, 'completed', ['navigation-278' => 'edited on this site since the quickstart shipped it; left as it is'], $F['navigation-93']['after']]);

$e = snavSite($DINER, $snavFixtures);
WP_Fake::$posts[96]['post_content'] .= "\n<!-- wp:paragraph --><p>mine</p><!-- /wp:paragraph -->";
$edited = WP_Fake::$posts[96]['post_content'];
check('a header the customer edited before the apply is drift', count($drift($e['engine'])), 1);
$sdoor($e['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$done = $sdoor($e['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('it is left as it is and reported', [WP_Fake::$posts[96]['post_content'] === $edited, isset($done['skipped']['templatePart-96'])], [true, true]);
check('and is still drift after the apply', $drift($e['engine']), ['Template part header was edited']);

// ── revert after the customer edits, and a later revert that recovers ───────────────────────

$v = snavSite($DINER, $snavContracts);
$V = $v['engine'];
$sdoor($V, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$applied = WP_Fake::$posts[278]['post_content'];
WP_Fake::$posts[278]['post_content'] = str_replace('Smart Search', 'Search', $applied);
$left = $sdoor($V, 'showcaseNav.revert', ['apply_id' => 'snav-r1', 'request_id' => 'v1']);
check('revert leaves the customer\'s navigation and says so', [$left['status'], array_keys($left['conflicts']), WP_Fake::$posts[278]['post_content'] === str_replace('Smart Search', 'Search', $applied)],
    ['reverted', ['navigation-278'], true]);
check('the other navigation went back', $sha(93), $F['navigation-93']['before']);
check('status lists the conflict', array_keys($sdoor($V, 'showcaseNav.status')['conflicts']), ['navigation-278']);
WP_Fake::$posts[278]['post_content'] = $applied; // the customer takes their edit back out
$recover = $sdoor($V, 'showcaseNav.revert', ['apply_id' => 'snav-r2', 'request_id' => 'v2']);
check('a later revert puts it back', [$recover['status'], $recover['moved'], $recover['conflicts'], $sha(278)], ['reverted', 1, [], $F['navigation-278']['before']]);
$after = $sdoor($V, 'showcaseNav.status');
check('status after a finished revert: reverted, nothing remaining, nothing in conflict', [$after['status'], $after['remaining'], $after['conflicts'], $after['owners'], $after['applyId']],
    ['reverted', 0, [], 2, 'snav-1']);

$v = snavSite($DINER, $snavFixtures);
$V = $v['engine'];
$sdoor($V, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$sdoor($V, 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$applied = WP_Fake::$posts[96]['post_content'];
WP_Fake::$posts[96]['post_content'] = $applied . "\n<!-- wp:separator /-->";
check('a header edited after the apply is drift again', $drift($V), ['Template part header was edited']);
$sdoor($V, 'showcaseNav.revert', ['apply_id' => 'snav-r1', 'request_id' => 'v1']);
$left = $sdoor($V, 'showcaseNav.revert', ['apply_id' => 'snav-r1', 'request_id' => 'v1']);
check('revert leaves the customer\'s header and says so', [$left['status'], array_keys($left['conflicts']), WP_Fake::$posts[96]['post_content'] === $applied . "\n<!-- wp:separator /-->"],
    ['reverted', ['templatePart-96'], true]);
check('the navigations went back', [$sha(93), $sha(278)], [$P['navigation-93']['before'], $P['navigation-278']['before']]);
check('status lists the header conflict', array_keys($sdoor($V, 'showcaseNav.status')['conflicts']), ['templatePart-96']);
WP_Fake::$posts[96]['post_content'] = $applied; // the customer takes their edit back out
check('the header as the apply left it is clean while the conflict is on record', $drift($V), []);
$recover = $sdoor($V, 'showcaseNav.revert', ['apply_id' => 'snav-r2', 'request_id' => 'v2']);
check('a later revert puts the header back', [$recover['status'], $recover['moved'], $recover['conflicts'], $sha(96)], ['reverted', 1, [], $P['templatePart-96']['before']]);
check('with every owner back', [$sdoor($V, 'showcaseNav.status')['owners'], $sdoor($V, 'showcaseNav.status')['conflicts']], [3, []]);

// ── an editor open on an owner refuses the call before anything moves ───────────────────────

$l = snavSite($DINER, $snavContracts);
WP_Fake::$users[3] = ['display_name' => 'Ada Editor'];
WP_Fake::$meta['278:' . EditLock::META] = (time() - 10) . ':3';
$locked = $sdoor($l['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('a navigation open in the editor refuses the apply', [$locked['ok'], array_column($locked['errors'] ?? [], 'code')], [false, ['SLOT_LOCKED_BY_USER']]);
check('nothing moved, nothing on record', [$sha(93), $sha(278), $sdoor($l['engine'], 'inspect')['showcaseNav']], [$F['navigation-93']['before'], $F['navigation-278']['before'], null]);

$l = snavSite($DINER, $snavFixtures);
WP_Fake::$users[3] = ['display_name' => 'Ada Editor'];
WP_Fake::$meta['96:' . EditLock::META] = (time() - 10) . ':3';
$locked = $sdoor($l['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('a header open in the editor refuses the apply', [$locked['ok'], array_column($locked['errors'] ?? [], 'code')], [false, ['SLOT_LOCKED_BY_USER']]);
check('nothing moved, nothing on record', [$sha(96), $sha(278), $sdoor($l['engine'], 'inspect')['showcaseNav']], [$P['templatePart-96']['before'], $P['navigation-278']['before'], null]);

// ── Morgan 1.1.16: entries leave the drawer, which stays; Restaurant 1.0.1: a branch inside it ─

$m = snavSite($MORGAN, $snavContracts);
$mHeader = (string) WP_Fake::$posts[166]['post_content'];
$mDone = $sdoor($m['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('Morgan: complete, every owner at the map\'s end', [$mDone['status'], $mDone['skipped'], $sha(158), $sha(160)],
    ['completed', [], $MF['navigation-158']['after'], $MF['navigation-160']['after']]);
check('Morgan: the header keeps its drawer and the button that opens it, byte for byte', [(string) WP_Fake::$posts[166]['post_content'] === $mHeader, strpos($mHeader, 'id="jm-off-canvas"') !== false, strpos($mHeader, 'aria-controls="jm-off-canvas"') !== false],
    [true, true, true]);
check('Morgan: the drawer keeps login, registration, profile and search', $labels(160), ['Site Pages', 'Login', 'Registration', 'Profile', 'Edit Profile', 'Other Pages', 'Smart Search', 'Content', 'Tagged Items']);
check('Morgan: and loses the template\'s own screens', array_values(array_intersect(['Offline Page', 'Error Page', 'Typography', 'Featured Articles', 'Single Article'], $labels(160))), []);
$mBack = $sdoor($m['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
check('Morgan: and the revert puts both navigations back, byte for byte', [$mBack['status'], $mBack['conflicts'], $contents([158, 160])],
    ['reverted', [], array_column($m['source']['navigations'], 'content')]);

$MP = $finals($MORGAN, $snavFixtures);
$mp = snavSite($MORGAN, $snavFixtures);
$mpBefore = (string) WP_Fake::$posts[166]['post_content'];
$sdoor($mp['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$mpDone = $sdoor($mp['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$header = (string) WP_Fake::$posts[166]['post_content'];
check('a map removing blocks: complete, every owner at the map\'s end', [$mpDone['status'], $sha(158), $sha(160), $sha(166)],
    ['completed', $MP['navigation-158']['after'], $MP['navigation-160']['after'], $MP['templatePart-166']['after']]);
check('the drawer and the button that opened it are gone, the main menu stays', [strpos($header, 'jm-off-canvas'), strpos($header, '"ref":158') !== false], [false, true]);
$sdoor($mp['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
$mpBack = $sdoor($mp['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
check('and the revert puts the drawer back, byte for byte', [$mpBack['status'], $mpBack['conflicts'], (string) WP_Fake::$posts[166]['post_content'] === $mpBefore], ['reverted', [], true]);

$RESTAURANT = 'ja-restaurant/wp7/1.0.1';
$RF = $finals($RESTAURANT);
$t = snavSite($RESTAURANT, $snavContracts);
$tDone = $sdoor($t['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('Restaurant: the Explore branch leaves the drawer', [$tDone['status'], $sha(133), in_array('Explore', $labels(133), true)], ['completed', $RF['navigation-133']['after'], false]);
$sdoor($t['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
check('Restaurant: and comes back', $sha(133), $RF['navigation-133']['before']);

// ── Kinetic 1.0.12: entries of the header's own (inline) navigation, found by path ──────────
// The map names each entry's block (`core/navigation-link`) beside its path; the entry is found by
// the path, as the builder's own replay finds it, and the sha256 chain proves every byte.

$KINETIC = 'ja-kinetic/wp7/1.0.12';
$KF = $finals($KINETIC);
$k = snavSite($KINETIC, $snavContracts);
$kHeader = static fn(): string => (string) WP_Fake::$posts[1777]['post_content'];
$kBefore = $kHeader();
$kDone = $sdoor($k['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('Kinetic: Blueprint and Signal leave the header, at the end bytes of the map', [$kDone['status'], $sha(1777), strpos($kHeader(), '"label":"Blueprint"'), strpos($kHeader(), '"label":"Signal"'), strpos($kHeader(), '"label":"Home"') !== false],
    ['completed', $KF['templatePart-1777']['after'], false, false, true]);
check('Kinetic: inspect finds the header where the job left it', $drift($k['engine']), []);
$kBack = $sdoor($k['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
check('Kinetic: and the revert puts the header back, byte for byte', [$kBack['status'], $kBack['conflicts'], $kHeader() === $kBefore], ['reverted', [], true]);

// ── no row for the template part: the theme file is in charge, and is never written ────────

$themeDir = sys_get_temp_dir() . '/snav-theme-' . bin2hex(random_bytes(4));
try {
    mkdir($themeDir . '/parts', 0777, true);
    file_put_contents($themeDir . '/parts/header.html', $dinerSource['templateParts'][0]['content']);
    $f = snavSite($DINER, $snavFixtures, ['themeFileOnly' => true, 'themeDir' => $themeDir]);
    $sdoor($f['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
    $fDone = $sdoor($f['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
    check('a header only the theme file holds is a conflict for that owner, and nothing is created', [$fDone['status'], $fDone['skipped'], $f['writer']->read('templatePart', 0, 'header')],
        ['completed', ['templatePart-96' => 'the header comes from the theme file; this receiver only edits a saved template part'], null]);
    check('the theme file is untouched, the navigations are edited', [hash('sha256', (string) file_get_contents($themeDir . '/parts/header.html')), $sha(93), $sha(278)],
        [$P['templatePart-96']['before'], $P['navigation-93']['after'], $P['navigation-278']['after']]);
    $sdoor($f['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
    $fRev = $sdoor($f['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
    check('revert puts back the navigations and creates nothing either', [$fRev['status'], $fRev['conflicts'], $sha(93), $sha(278), $f['writer']->read('templatePart', 0, 'header')],
        ['reverted', [], $P['navigation-93']['before'], $P['navigation-278']['before'], null]);
} finally {
    @unlink($themeDir . '/parts/header.html');
    @rmdir($themeDir . '/parts');
    @rmdir($themeDir);
}

// ── only this job's writes move the baseline ────────────────────────────────────────────────

$a = snavSite($DINER, $snavFixtures);
$sdoor($a['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$sdoor($a['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$afterBytes = [93 => WP_Fake::$posts[93]['post_content'], 96 => WP_Fake::$posts[96]['post_content']];

// The customer set the header and navigation 93 to the map's end bytes themselves, before any apply.
$s = snavSite($DINER, $snavFixtures);
WP_Fake::$posts[93]['post_content'] = $afterBytes[93];
WP_Fake::$posts[96]['post_content'] = $afterBytes[96];
check('a header the customer set to the map\'s end bytes is drift', $drift($s['engine']), ['Template part header was edited']);
$sdoor($s['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$sDone = $sdoor($s['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('an apply finds them already there and writes nothing to them', [$sDone['status'], $sDone['skipped']],
    ['completed', ['templatePart-96' => 'already as the map leaves it', 'navigation-93' => 'already as the map leaves it']]);
check('the customer\'s header is still drift after the apply', $drift($s['engine']), ['Template part header was edited']);
$sdoor($s['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
$sRev = $sdoor($s['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
check('revert puts back only what the apply wrote', [$sRev['status'], $sRev['conflicts'], $sha(278), WP_Fake::$posts[93]['post_content'] === $afterBytes[93], WP_Fake::$posts[96]['post_content'] === $afterBytes[96]],
    ['reverted', [], $P['navigation-278']['before'], true, true]);

// A write that landed but whose answer was lost: its `writing` receipt and the end bytes make it this job's.
$w = snavSite($DINER, $snavFixtures);
$GLOBALS['wpdb']->beforeSwap = static function (int $id) use ($afterBytes): void {
    if (isset($afterBytes[$id])) {
        WP_Fake::$posts[$id]['post_content'] = $afterBytes[$id]; // the UPDATE ran ...
        throw new RuntimeException('the answer was lost'); // ... and its answer never came back
    }
};
$lostHeader = $sdoor($w['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$GLOBALS['wpdb']->beforeSwap = null;
check('the lost write leaves a writing receipt', [$lostHeader['ok'], $sdoor($w['engine'], 'inspect')['showcaseNav']['receipts']['templatePart-96']['outcome']], [false, 'writing']);
check('and the header it wrote is not drift', $drift($w['engine']), []);
$GLOBALS['wpdb']->beforeSwap = static function (int $id) use ($afterBytes): void {
    if (isset($afterBytes[$id])) {
        WP_Fake::$posts[$id]['post_content'] = $afterBytes[$id];
        throw new RuntimeException('the answer was lost');
    }
};
$lostNav = $sdoor($w['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$GLOBALS['wpdb']->beforeSwap = null;
check('so does a lost navigation write', [$lostNav['ok'], $sdoor($w['engine'], 'inspect')['showcaseNav']['receipts']['navigation-93']['outcome']], [false, 'writing']);
$wDone = $sdoor($w['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$wDone = $wDone['status'] === 'running' ? $sdoor($w['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']) : $wDone;
$wReceipts = $sdoor($w['engine'], 'inspect')['showcaseNav']['receipts'];
check('the same request resumes and counts them as written', [$wDone['status'], $wDone['skipped'], $wReceipts['templatePart-96']['outcome'], $wReceipts['navigation-93']['outcome']],
    ['completed', [], 'written', 'written']);
check('the site inspects clean', $drift($w['engine']), []);
$sdoor($w['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
$wRev = $sdoor($w['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
check('and revert puts them back', [$wRev['status'], $sha(93), $sha(96), $sha(278)],
    ['reverted', $P['navigation-93']['before'], $P['templatePart-96']['before'], $P['navigation-278']['before']]);

// ── a provisioned site: the placeholder address was rewritten to the site's own ────────────

$HOME = 'https://diner.example.test';
$fold = static fn(string $bytes): string => str_replace($HOME, 'https://quickstart.invalid', $bytes);
$u = snavSite($DINER, $snavFixtures, ['home' => $HOME . '/']);
$uSource = $contents([93, 278, 96]);
check('the provisioned navigations hold the site address, not the placeholder', [strpos($uSource[0], $HOME . '/') !== false, strpos($uSource[0], 'quickstart.invalid')], [true, false]);
$uFirst = $sdoor($u['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$uFirst = $uFirst['status'] === 'running' ? $sdoor($u['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']) : $uFirst;
check('a rewritten site hides what the packed site hides', [$uFirst['status'], $uFirst['skipped'], $labels(93), strpos((string) WP_Fake::$posts[96]['post_content'], '"ref":93') !== false],
    ['completed', [], ['Home', 'About Us', 'Reservation', 'Offers', 'Our Blog', 'Contact Us'], true]);
check('with the site address kept: folded back, the bytes are the map\'s end', [hash('sha256', $fold($contents([93])[0])), hash('sha256', $fold($contents([278])[0])), $sha(96), strpos($contents([93])[0], 'quickstart.invalid')],
    [$P['navigation-93']['after'], $P['navigation-278']['after'], $P['templatePart-96']['after'], false]);
check('the rewritten site inspects clean', $drift($u['engine']), []);
$uAgain = $sdoor($u['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-2', 'request_id' => 'n2']);
check('applying again writes nothing', [$uAgain['ok'], $uAgain['alreadyApplied'] ?? null], [true, true]);
$sdoor($u['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
$uRev = $sdoor($u['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
check('revert puts the site\'s own bytes back, byte for byte', [$uRev['status'], $uRev['conflicts'], $contents([93, 278, 96]) === $uSource], ['reverted', [], true]);
$uNew = $sdoor($u['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-3', 'request_id' => 'n3']);
$uNew = $uNew['status'] === 'running' ? $sdoor($u['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-3', 'request_id' => 'n3']) : $uNew;
check('and a new request hides them again', [$uNew['status'], $uNew['skipped'], hash('sha256', $fold($contents([278])[0]))], ['completed', [], $P['navigation-278']['after']]);

// The customer edited one rewritten navigation: still theirs.
$ue = snavSite($DINER, $snavContracts, ['home' => $HOME]);
WP_Fake::$posts[278]['post_content'] = str_replace('Smart Search', 'Search', (string) WP_Fake::$posts[278]['post_content']);
$ueEdited = WP_Fake::$posts[278]['post_content'];
$ueDone = $sdoor($ue['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('a navigation the customer edited on a rewritten site is left alone', [$ueDone['status'], $ueDone['skipped'], WP_Fake::$posts[278]['post_content'] === $ueEdited, hash('sha256', $fold($contents([93])[0]))],
    ['completed', ['navigation-278' => 'edited on this site since the quickstart shipped it; left as it is'], true, $F['navigation-93']['after']]);

// The placeholder itself is still in the live bytes next to the site address: folding would be a guess.
$up = snavSite($DINER, $snavContracts, ['home' => $HOME]);
$upMixed = preg_replace('~' . preg_quote($HOME, '~') . '~', 'https://quickstart.invalid', $up['source']['navigations'][0]['content'], 1);
WP_Fake::$posts[93]['post_content'] = $upMixed;
check('(the mixed bytes fold to the map\'s start)', hash('sha256', $fold($upMixed)), $F['navigation-93']['before']);
$upDone = $sdoor($up['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
check('a navigation already holding the placeholder is compared exactly, and left alone', [$upDone['status'], $upDone['skipped'], WP_Fake::$posts[93]['post_content'] === $upMixed],
    ['completed', ['navigation-93' => 'edited on this site since the quickstart shipped it; left as it is'], true]);

// A write on a rewritten site that landed but whose answer was lost is still this job's.
$ua = snavSite($DINER, $snavContracts, ['home' => $HOME]);
$sdoor($ua['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$uaAfter = WP_Fake::$posts[93]['post_content'];
$ul = snavSite($DINER, $snavContracts, ['home' => $HOME]);
$GLOBALS['wpdb']->beforeSwap = static function (int $id) use ($uaAfter): void {
    if ($id === 93) {
        WP_Fake::$posts[93]['post_content'] = $uaAfter; // the UPDATE ran ...
        throw new RuntimeException('the answer was lost'); // ... and its answer never came back
    }
};
$ulLost = $sdoor($ul['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$GLOBALS['wpdb']->beforeSwap = null;
$ulReceipt = $sdoor($ul['engine'], 'inspect')['showcaseNav']['receipts']['navigation-93'];
check('(the write landed, with the site address)', [$ulLost['ok'], $ulReceipt['outcome'], hash('sha256', $fold($contents([93])[0]))], [false, 'writing', $F['navigation-93']['after']]);
$ulDone = $sdoor($ul['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
$ulReceipts = $sdoor($ul['engine'], 'inspect')['showcaseNav']['receipts'];
check('the same request counts it as written', [$ulDone['status'], $ulDone['skipped'], $ulReceipts['navigation-93']['outcome']], ['completed', [], 'written']);
$sdoor($ul['engine'], 'showcaseNav.revert', ['apply_id' => 'snav-r', 'request_id' => 'v1']);
check('and revert puts the site\'s own bytes back', $contents([93, 278]), array_column($ul['source']['navigations'], 'content'));

// ── maps that cannot be used ────────────────────────────────────────────────────────────────

$k = snavSite('tracy-business/wp7/1.1.0', $snavContracts);
$kStatus = $sdoor($k['engine'], 'showcaseNav.status');
check('a map with nothing to hide applies as nothing', [$kStatus['status'], $kStatus['hides']], ['none', []]);

$snavCopy = sys_get_temp_dir() . '/snav-contracts-' . bin2hex(random_bytes(4));
$copyContract = static function (string $id) use ($snavContracts, $snavCopy): string {
    $to = $snavCopy . '/' . $id;
    mkdir($to, 0777, true);
    foreach (['manifest.json', 'content-map.json', 'presentation-lock.json', 'showcase-nav-map.json'] as $name) {
        copy($snavContracts . '/' . $id . '/' . $name, $to . '/' . $name);
    }
    return $to;
};
try {
    $dir = $copyContract($DINER);
    $map = json_decode((string) file_get_contents($dir . '/showcase-nav-map.json'), true);
    $map['status'] = 'not-covered';
    $map['reason'] = 'the drawer is built by the theme script';
    $map['operations'] = [];
    $map['finals'] = [];
    file_put_contents($dir . '/showcase-nav-map.json', json_encode($map));
    $n = snavSite($DINER, $snavCopy);
    $nc = $sdoor($n['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
    check('a not-covered map answers unsupported, with its reason', [$nc['error'], strpos($nc['message'], 'the drawer is built by the theme script') !== false], ['unsupported', true]);
    check('and the contract itself still serves', $sdoor($n['engine'], 'inspect')['ok'] ?? null, true);

    $map = json_decode((string) file_get_contents($snavContracts . '/' . $DINER . '/showcase-nav-map.json'), true);
    $map['operations'][1]['after'] = str_repeat('0', 64);
    file_put_contents($dir . '/showcase-nav-map.json', json_encode($map));
    $b = snavSite($DINER, $snavCopy);
    $bad = $sdoor($b['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1']);
    check('a map that does not chain is refused as unsupported, nothing written', [$bad['error'], strpos($bad['message'], 'do not chain') !== false, $sha(93)], ['unsupported', true, $F['navigation-93']['before']]);
    check('status says so too', $sdoor($b['engine'], 'showcaseNav.status')['error'], 'unsupported');
    check('and the contract itself still serves', $sdoor($b['engine'], 'inspect')['ok'] ?? null, true);

    unlink($dir . '/showcase-nav-map.json');
    $none = snavSite($DINER, $snavCopy);
    check('no map at all is unsupported', $sdoor($none['engine'], 'showcaseNav.apply', ['apply_id' => 'snav-1', 'request_id' => 'n1'])['error'], 'unsupported');
} finally {
    $snavRemove($snavCopy);
    $snavRemove($snavFixtures);
}
