<?php
// Loaded by run.php, after contracts.php (ContractTestWriter, TestContractStore) and run.php's fakes.
//
// 🔒 EVERY SHIPPED MULTILINGUAL PROFILE CAN ADD A LANGUAGE, HIDE THE REST, AND TAKE A JOB BACK — ON ITS
// OWN ARCHIVE'S SHAPE, BEFORE A RELEASE. On 23/09/2026 the Business profile met three receiver
// assumptions one site retry at a time, each hidden behind the last: a switcher the archive already
// ships was held to the shape of a new one, a job taken back deleted that governed switcher, and an
// article copy dropped the note the archive puts on every article. Apple and Airbnb have none of those
// shapes, so every existing check was green. This gate builds a site from each profile's own
// presentation lock — the real rows, notes, switcher and assignments — and runs the whole road through
// Engine::handle, holding inspect to it after every step. A new quickstart is covered by being shipped.
//
// The lock's FILE list is left out: the template files are not in this repository, and file integrity
// has checks of its own. Everything inspect compares about ROWS is kept.

/** A contract directory copied without its lock's files, the profiles re-pinned to the copy. */
function gateContractCopy(string $source): string {
    $root = sys_get_temp_dir() . '/cowork-gate-' . bin2hex(random_bytes(6));
    $dir = $root . '/lib/contracts/' . basename(dirname($source, 2)) . '/' . basename(dirname($source)) . '/' . basename($source);
    mkdir($dir, 0777, true);
    // Every step may `continue` past the end of its loop body, so the copy is removed at exit.
    register_shutdown_function(static function () use ($root): void {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($root);
    });
    copy(__DIR__ . '/../lib/language-packs.json', $root . '/lib/language-packs.json');
    foreach (['manifest', 'content-map'] as $name) copy($source . '/' . $name . '.json', $dir . '/' . $name . '.json');
    $lock = json_decode(file_get_contents($source . '/presentation-lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $lock['fileRoots'] = [];
    $lock['files'] = [];
    file_put_contents($dir . '/presentation-lock.json', json_encode($lock));
    $base = hash('sha256', implode('', array_map(
        fn ($n) => $n . ':' . hash_file('sha256', $dir . '/' . $n) . "\n",
        ['manifest.json', 'content-map.json', 'presentation-lock.json']
    )));
    foreach (['multilingual-map', 'demo-trim-map'] as $name) {
        if (!is_file($source . '/' . $name . '.json')) continue;
        $profile = json_decode(file_get_contents($source . '/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $profile['baseHash'] = $base;
        file_put_contents($dir . '/' . $name . '.json', json_encode($profile));
    }
    return $dir;
}

/**
 * The ACL a live site would report: the lock's, plus one entry per copy the language job made, carrying
 * the chain of the row it was copied from — what Joomla gives a new row filed beside its source. A
 * fixed snapshot (TestContractStore's) cannot answer for rows that did not exist when it was taken.
 */
final class GateContractStore implements ContractStore {
    public ?array $binding = null;
    public ?array $job = null;
    public array $acl = [];
    public ?ContractTestWriter $site = null;
    /** @var array<string,array{kind:string,id:int,alias?:string,catid?:string}> */
    public array $base = [];
    public function load(): ?array { return $this->binding; }
    public function save(array $value): void {
        if ($this->binding !== null) { if ($this->binding != $value) throw new RuntimeException('Cannot replace a content-only baseline'); return; }
        $this->binding = $value;
    }
    public function replace(array $value): void { $this->binding = $value; }
    public function job(): ?array { return $this->job; }
    public function saveJob(?array $job): void { $this->job = $job; }
    public function access(): array {
        $acl = $this->acl;
        if (!isset($acl['entityRules']) || $this->site === null) return $acl;
        foreach ($this->site->store['module'] ?? [] as $id => $row) {
            if (isset($acl['entityRules']['modules.' . $id])) continue;
            // Any other new module (the switcher) gets what Joomla gives one: root, then com_modules.
            if (!preg_match('/^tracy-ml:(.+):[a-z]{2,3}-[A-Za-z]{2,4}$/', (string) ($row['note'] ?? ''), $m) || !isset($this->base[$m[1]])) {
                foreach ($acl['entityRules'] as $asset => $chain)
                    if (str_starts_with($asset, 'modules.') && array_column($chain, 'name') === ['root.1', 'com_modules']) {
                        $acl['entityRules']['modules.' . $id] = $chain;
                        break;
                    }
                continue;
            }
            $from = 'modules.' . $this->base[$m[1]]['id'];
            if (isset($acl['entityRules'][$from])) $acl['entityRules']['modules.' . $id] = $acl['entityRules'][$from];
        }
        foreach ($this->site->store['article'] ?? [] as $id => $row) {
            if (isset($acl['entityRules']['content.' . $id])) continue;
            foreach ($this->base as $b) {
                if ($b['kind'] !== 'article' || (string) ($b['catid'] ?? '') !== (string) ($row['catid'] ?? '')) continue;
                if (strpos((string) ($row['alias'] ?? ''), (string) ($b['alias'] ?? '') . '-') !== 0) continue;
                if (isset($acl['entityRules']['content.' . $b['id']])) $acl['entityRules']['content.' . $id] = $acl['entityRules']['content.' . $b['id']];
                break;
            }
        }
        ksort($acl['entityRules']);
        return $acl;
    }
}

/** The archive as a site: every governed row from the lock, padded to its counts with other editions. */
function gateSite(string $dir, GateContractStore $store, FakeApplyLog $log): ContractTestWriter {
    $lock = json_decode(file_get_contents($dir . '/presentation-lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $map = json_decode(file_get_contents($dir . '/content-map.json'), true, 512, JSON_THROW_ON_ERROR);
    $w = new ContractTestWriter($log, $store);
    $w->nestedPaths = true;
    $governed = [];
    foreach ($map['entities'] as $entity) {
        $row = $lock['entities'][$entity['key']] + $entity['identity'];
        $w->store[$entity['kind']][(int) $entity['sourceId']] = ['id' => (string) $entity['sourceId']] + $row;
        $governed[$entity['kind']] = ($governed[$entity['kind']] ?? 0) + 1;
        // #__extensions, as far as the lock can tell: each component a menu item links to, by its id.
        if ($entity['kind'] === 'menuItem' && preg_match('/option=([a-z0-9_]+)/i', (string) ($row['link'] ?? ''), $m) && (int) ($row['component_id'] ?? 0) > 0)
            $w->components[$m[1]] = (int) $row['component_id'];
        $store->base[$entity['key']] = ['kind' => $entity['kind'], 'id' => (int) $entity['sourceId'],
            'alias' => (string) ($row['alias'] ?? ''), 'catid' => (string) ($row['catid'] ?? '')];
    }
    // The tables' columns, as the lock records them; the defaults are Joomla's schema's.
    $schema = ['client_id' => '0', 'checked_out' => null, 'publish_up' => null, 'publish_down' => null];
    foreach ($map['entities'] as $entity)
        foreach ($lock['entities'][$entity['key']] as $column => $_)
            $w->columns[$entity['kind']][$column] = $schema[$column] ?? ($w->columns[$entity['kind']][$column] ?? null);
    foreach ($lock['assignments'] as $a) {
        $current = json_decode($w->store['moduleAssignment'][(int) $a['moduleid']]['menuids'] ?? '[]', true);
        $current[] = (int) $a['menuid'];
        $w->store['moduleAssignment'][(int) $a['moduleid']] = ['menuids' => json_encode($current)];
    }
    // Menu rows as the site holds them: `lft` in the archive's own order, and every ancestor the
    // contract does not govern (a megamenu heading) present — a copy's path is built from it.
    $lft = 0;
    foreach ($w->store['menuItem'] ?? [] as $mid => $row) $w->store['menuItem'][$mid]['lft'] = (string) (++$lft * 2);
    foreach ($w->store['menuItem'] ?? [] as $row) {
        for ($pid = (int) ($row['parent_id'] ?? 1), $path = (string) ($row['path'] ?? ''), $level = (int) ($row['level'] ?? 1);
             $pid > 1 && !isset($w->store['menuItem'][$pid]); $level--) {
            $path = dirname($path);
            $w->store['menuItem'][$pid] = ['id' => (string) $pid, 'title' => 'ancestor ' . $pid, 'alias' => basename($path), 'path' => $path,
                'level' => (string) ($level - 1), 'lft' => '1', 'parent_id' => '1', 'menutype' => $row['menutype'] ?? '',
                'published' => '1', 'client_id' => '0', 'home' => '0', 'language' => '*', 'note' => '', 'type' => 'heading'];
            $governed['menuItem']++;
            $pid = 1;
        }
    }
    // The archive's own other editions: rows the contract does not govern, published in a language
    // the customer will not ask for — what a retire must hide and a derivation must not collide with.
    // Rows the lock's ACL names exist on the archive whatever the contract governs; they pad first,
    // so no row the job creates is handed an id the site already holds.
    $named = ['module' => [], 'article' => []];
    foreach (array_keys($lock['access']['entityRules'] ?? []) as $asset)
        if (preg_match('/^(modules|content)\.(\d+)$/', $asset, $m) && !isset($w->store[$m[1] === 'modules' ? 'module' : 'article'][(int) $m[2]]))
            $named[$m[1] === 'modules' ? 'module' : 'article'][] = (int) $m[2];
    $next = 900000;
    // The archive's own editions in the languages the gate derives, top of the menu tree: Business
    // ships one per language with its source's aliases, and a copy must find its alias taken
    // (j-ee6vsk, `Duplicate entry '0-1-home-vi-VN'`). Only where the lock's inventory has room.
    $tops = [];
    foreach ($w->store['menuItem'] ?? [] as $row)
        if ((int) ($row['parent_id'] ?? 1) === 1 && (string) ($row['client_id'] ?? '0') === '0' && $row['title'] !== 'ancestor ' . $row['id']) $tops[] = $row;
    if (($lock['inventoryCounts']['menuItem'] ?? 0) - ($governed['menuItem'] ?? 0) >= 2 * count($tops))
        foreach (['vi-VN', 'fr-FR'] as $edition) foreach ($tops as $row) {
            $id = $next++;
            $w->store['menuItem'][$id] = ['id' => (string) $id, 'language' => $edition, 'note' => 'archive edition', 'home' => '0'] + $row;
            $governed['menuItem']++;
        }
    foreach ($lock['inventoryCounts'] as $kind => $count) {
        for ($n = $governed[$kind] ?? 0; $n < $count; $n++) {
            $id = ($named[$kind] ?? []) ? array_shift($named[$kind]) : $next++;
            $row = ['id' => (string) $id, 'title' => 'pad ' . $id, 'alias' => 'pad-' . $id, 'language' => 'de-DE', 'note' => ''];
            if ($kind === 'article') $row += ['state' => '1', 'catid' => '0'];
            elseif ($kind === 'module') $row += ['published' => '1', 'client_id' => '0', 'module' => 'mod_custom', 'position' => 'pad'];
            elseif ($kind === 'menuItem') $row += ['published' => '1', 'client_id' => '0', 'home' => '0', 'path' => 'pad-' . $id];
            elseif ($kind === 'category') $row += ['published' => '1', 'extension' => 'com_content', 'path' => 'pad-' . $id];
            $w->store[$kind][$id] = $row;
        }
    }
    $w->store['language'] = [
        1 => ['lang_id' => '1', 'lang_code' => 'en-GB', 'sef' => 'en', 'title' => 'English', 'published' => 1],
        2 => ['lang_id' => '2', 'lang_code' => 'de-DE', 'sef' => 'de', 'title' => 'Deutsch', 'published' => 1],
        3 => ['lang_id' => '3', 'lang_code' => 'vi-VN', 'sef' => 'vi', 'title' => 'Tiếng Việt', 'published' => 0],
    ];
    $w->store['languageFilter'] = [1 => ['id' => '1', 'enabled' => 0, 'params' => '{}']];
    $store->acl = $lock['access'];
    $store->site = $w;
    return $w;
}

$gateContracts = glob(__DIR__ . '/../lib/contracts/*/*/*/multilingual-map.json') ?: [];
checkTrue('the gate finds the shipped multilingual profiles', count($gateContracts) >= 3);
foreach ($gateContracts as $profileFile) {
    $source = dirname($profileFile);
    $id = basename(dirname($source, 2)) . '/' . basename(dirname($source)) . '/' . basename($source);
    $dir = gateContractCopy($source);
    $gs = new GateContractStore();
    $gl = new FakeApplyLog();
    $gw = gateSite($dir, $gs, $gl);
    $ext = new FakeExtensions();
    $ext->installed = [['type' => 'language', 'element' => 'vi-VN'], ['type' => 'language', 'element' => 'fr-FR']];
    $gc = new QuickstartContract($gw, $gs, $dir, $dir);
    $ge = new Engine($WTOKEN, ['joomla' => '6.1.1'], null, null, null, $ext, $gw, null, $gl, null, null, null, $gc);
    $call = fn (array $params) => $ge->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
    $step = function (string $what, array $answer) use ($id, $gc) {
        if (empty($answer['ok'])) { check("$id: $what", $answer['message'] ?? $answer['error'] ?? 'refused', 'ok'); return false; }
        try { $gc->inspect(); return true; }
        catch (Throwable $error) { check("$id: inspect after $what", $error->getMessage(), 'clean'); return false; }
    };

    if (!$step('bind', $call(['operation' => 'bind']))) continue;

    // Hide what the customer did not ask for, until the receiver says it is done.
    $retire = ['operation' => 'multilingual.retire', 'keep' => ['en-GB', 'vi-VN'], 'apply_id' => 'mlang-gate-retire'];
    for ($i = 0, $r = ['ok' => true, 'status' => 'running']; $i < 20 && ($r['status'] ?? '') === 'running'; $i++) $r = $call($retire);
    if (!$step('retire', $r)) continue;
    check("$id: retire hides the other editions", count(array_filter($gw->store['article'] ?? [], fn ($a) => ($a['language'] ?? '') === 'de-DE' && (string) ($a['state'] ?? '') === '1')), 0);

    // Derive vi-VN: the plan's own source words stand in for a translation.
    $plan = $call(['operation' => 'multilingual.plan', 'locale' => 'vi-VN']);
    if (!$step('plan vi-VN', $plan)) continue;
    $words = [];
    foreach ($plan['slots'] as $slot) $words[$slot['key']] = (string) ($slot['source'] ?? '');
    $apply = ['operation' => 'multilingual.apply', 'locale' => 'vi-VN', 'translations' => $words,
        'expected_revision' => $plan['revision'], 'apply_id' => 'mlang-gate-vi', 'request_id' => 'gate-vi'];
    for ($i = 0, $a = ['ok' => true, 'status' => 'running']; $i < 400 && ($a['status'] ?? '') === 'running'; $i++) {
        $a = $call($apply);
        // A new Build on the same site runs `style` (bind) again while a language is half made:
        // a site whose inspect is clean is already locked, not a baseline to replace (j-ee6vsk).
        if ($i === 2 && ($a['status'] ?? '') === 'running') {
            if (!$step('bind again with vi-VN in flight', $call(['operation' => 'bind']))) continue 2;
            $mid = $call(['operation' => 'apply', 'apply_id' => 'contract-gate-mid', 'request_id' => 'mid', 'changes' => [['key' => 'x', 'value' => 'y']]]);
            check("$id: a content apply under a half-made language names the job", preg_match('/in flight for vi-VN.*multilingual\.revert/', (string) ($mid['message'] ?? '')), 1);
        }
    }
    if (!$step('derive vi-VN', $a)) continue;
    check("$id: vi-VN completes", $a['status'] ?? null, 'completed');
    if (!$step('verify vi-VN', $call(['operation' => 'multilingual.verify', 'locale' => 'vi-VN']))) continue;
    $switchers = array_filter($gw->store['module'] ?? [], fn ($m) => ($m['module'] ?? '') === 'mod_languages' && (string) ($m['published'] ?? '1') === '1');
    check("$id: one language switcher", count($switchers), 1);

    // Start fr-FR, stop it half-way, and take it back: nothing governed may leave with it.
    $frPlan = $call(['operation' => 'multilingual.plan', 'locale' => 'fr-FR']);
    if (!$step('plan fr-FR', $frPlan)) continue;
    $fr = [];
    foreach ($frPlan['slots'] as $slot) $fr[$slot['key']] = (string) ($slot['source'] ?? '');
    $call(['operation' => 'multilingual.apply', 'locale' => 'fr-FR', 'translations' => $fr,
        'expected_revision' => $frPlan['revision'], 'apply_id' => 'mlang-gate-fr', 'request_id' => 'gate-fr']);
    if (!$step('take back fr-FR', $call(['operation' => 'multilingual.revert', 'locale' => 'fr-FR']))) continue;
    $lock = json_decode(file_get_contents($dir . '/presentation-lock.json'), true);
    $missing = [];
    foreach (json_decode(file_get_contents($dir . '/content-map.json'), true)['entities'] as $entity)
        if (!isset($gw->store[$entity['kind']][(int) $entity['sourceId']])) $missing[] = $entity['key'];
    check("$id: taking a job back leaves every governed row", $missing, []);
    $moved = array_filter($gw->store['menuItem'] ?? [], fn ($r) => ($r['note'] ?? '') === 'archive edition' && ($r['language'] ?? '') === 'vi-VN');
    $aside = count(array_filter($moved, fn ($r) => str_ends_with((string) $r['alias'], '-archive')));
    if (!$step('take back vi-VN', $call(['operation' => 'multilingual.revert', 'locale' => 'vi-VN']))) continue;
    $back = array_filter($gw->store['menuItem'] ?? [], fn ($r) => ($r['note'] ?? '') === 'archive edition' && ($r['language'] ?? '') === 'vi-VN'
        && str_ends_with((string) $r['alias'], '-archive'));
    checkTrue("$id: the archive's own vi-VN edition was moved aside and is back after the revert", $moved === [] || ($aside > 0 && $back === []));
    unset($lock);
}
