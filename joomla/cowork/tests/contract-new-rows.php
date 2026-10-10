<?php
// Loaded by run.php. A row the quickstart never shipped is held, in the contract's ACL check, to what
// it inherits — not reported as an ACL change for being new.
//
// Tracy's design-system slot creates one module through content.update. The next contract call
// warned "Access-level or ACL definition changed: entityRules(modules.255)" (10/10/2026, dskeepjo,
// tracy-base j6), although the module's chain was com_modules' own: the check compared the site's
// whole entityRules against the lock's, so any row the lock did not name was a difference. Its
// newness is "Quickstart inventory changed", which stays for every added row but one: Tracy's own
// design-system module, marked `<style id="tracy-ds-slot"`, is not counted (David, 10/10/2026), and a
// baseline saved while it still counted is moved by the next content apply, not refused.

/** The access half of a contract store, read through the real ContractAccess::snapshot(). */
final class NewRowsContractStore implements ContractStore {
    public ?array $binding = null;
    public ?array $job = null;
    /** `#__viewlevels`, `#__usergroups`, `#__assets`, `#__modules`, `#__content`, `#__categories` as rows. */
    public array $inventory = [];
    public function load(): ?array { return $this->binding; }
    /** As JoomlaContractStore::save(): a stored baseline is only ever re-saved identical. */
    public function save(array $value): void {
        if ($this->binding !== null) { if ($this->binding != $value) throw new RuntimeException('Cannot replace a bound baseline'); return; }
        $this->binding = $value;
    }
    public function replace(array $value): void { $this->binding = $value; }
    public function job(): ?array { return $this->job; }
    public function saveJob(?array $job): void { $this->job = $job; }
    public function access(): array { return ContractAccess::snapshot($this->inventory); }
}

$nrDir = sys_get_temp_dir() . '/cowork-new-rows-' . bin2hex(random_bytes(6));
mkdir($nrDir);
mkdir($nrDir . '/assets');
file_put_contents($nrDir . '/assets/site.css', '.hero { color: teal }');
$nrModule = ['title' => 'Home hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'publish_up' => null,
    'publish_down' => null, 'ordering' => '1', 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0',
    'content' => '<h1>Demo title</h1>', 'params' => '{"module_tag":"div"}'];
$nrStore = new NewRowsContractStore();
// root.1, the two components with rules of their own, and the shipped module's empty asset under com_modules.
$nrStore->inventory = [
    'viewlevels' => [['id' => '1', 'rules' => '[1]']],
    'usergroups' => [['id' => '1', 'parent_id' => '0']],
    'assets' => [
        ['id' => '1', 'parent_id' => '0', 'name' => 'root.1', 'rules' => '{"core.login.site":{"1":1},"core.admin":{"8":1}}'],
        ['id' => '8', 'parent_id' => '1', 'name' => 'com_content', 'rules' => '{"core.edit":{"4":1}}'],
        ['id' => '18', 'parent_id' => '1', 'name' => 'com_modules', 'rules' => '{"core.admin":{"7":1}}'],
        ['id' => '40', 'parent_id' => '18', 'name' => 'com_modules.module.110', 'rules' => '{}'],
    ],
    'modules' => [['id' => '110', 'asset_id' => '40']],
    'content' => [],
    'categories' => [],
];
$nrWriter = new FakeSiteWriter();
$nrWriter->store['module'][110] = ['id' => '110'] + $nrModule;
$nrWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$nrSlots = [];
foreach (ContentSlots::htmlSlots($nrModule['content']) as $n => $s) $nrSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80] + $s;
foreach ([
    'manifest' => ['id' => 'new-rows/v1'],
    'content-map' => ['entities' => [['key' => 'hero', 'kind' => 'module', 'sourceId' => 110, 'identity' => ['title' => 'Home hero']]], 'slots' => $nrSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $nrModule], 'assignments' => [['moduleid' => 110, 'menuid' => 0]],
        'fileRoots' => ['assets'], 'files' => ['assets/site.css' => hash_file('sha256', $nrDir . '/assets/site.css')],
        'inventoryCounts' => ['module' => 1], 'access' => $nrStore->access()],
] as $nrName => $nrBody) file_put_contents($nrDir . '/' . $nrName . '.json', json_encode($nrBody));

/** The warnings one inspect gives, on a fresh contract (no request, so nothing is refused). */
$nrWarnings = function () use ($nrWriter, $nrStore, $nrDir): array {
    $contract = new QuickstartContract($nrWriter, $nrStore, $nrDir, $nrDir);
    $contract->inspect();
    return $contract->driftWarnings();
};
$nrAcl = fn(array $warnings): array => array_values(array_filter($warnings, fn($w) => strpos($w, 'Access-level or ACL') === 0));
check('new rows: the quickstart as shipped inspects clean', $nrWarnings(), []);

// What content.update leaves behind: the module row, and the asset Table::store() mints under com_modules.
$nrWriter->store['module'][255] = ['id' => '255', 'title' => 'Tracy design system', 'position' => 'header-r', 'ordering' => '0'] + $nrModule;
$nrWriter->store['moduleAssignment'][255] = ['menuids' => '[0]'];
$nrStore->inventory['assets'][] = ['id' => '313', 'parent_id' => '18', 'name' => 'com_modules.module.255', 'rules' => '{}'];
$nrStore->inventory['modules'][] = ['id' => '255', 'asset_id' => '313'];
$nrGot = $nrWarnings();
check('new rows: a module Tracy creates is not an ACL change', $nrAcl($nrGot), []);
check('new rows: it is still a row the quickstart did not ship', in_array('Quickstart inventory changed', $nrGot, true), true);

// The same module with no asset at all reads com_modules' chain the same way.
$nrStore->inventory['modules'][1]['asset_id'] = '0';
check('new rows: a new module with no asset is not an ACL change either', $nrAcl($nrWarnings()), []);

// A page added to the site, its asset minted under com_content with no rules of its own.
$nrStore->inventory['assets'][] = ['id' => '315', 'parent_id' => '8', 'name' => 'com_content.article.85', 'rules' => '{}'];
$nrStore->inventory['content'][] = ['id' => '85', 'asset_id' => '315'];
check('new rows: a new article that inherits com_content is not an ACL change', $nrAcl($nrWarnings()), []);

// A new row whose rules DO differ is still named.
$nrStore->inventory['modules'][1]['asset_id'] = '313';
$nrStore->inventory['assets'][4]['rules'] = '{"core.edit":{"2":1}}';
check('new rows: a new module with rules of its own is an ACL change', $nrAcl($nrWarnings()),
    ['Access-level or ACL definition changed: entityRules(modules.255)']);
$nrStore->inventory['assets'][4]['rules'] = '{}';
$nrStore->inventory['assets'][4]['parent_id'] = '1';
check('new rows: a new module hung under the root, skipping com_modules, is an ACL change', $nrAcl($nrWarnings()),
    ['Access-level or ACL definition changed: entityRules(modules.255)']);
$nrStore->inventory['assets'][4]['parent_id'] = '18';
$nrStore->inventory['assets'][5]['parent_id'] = '1';
check('new rows: a new article hung under the root, skipping com_content, is an ACL change', $nrAcl($nrWarnings()),
    ['Access-level or ACL definition changed: entityRules(content.85)']);
$nrStore->inventory['assets'][5]['parent_id'] = '8';
check('new rows: back to inheriting, no ACL change', $nrAcl($nrWarnings()), []);

// A shipped row is still held to its captured chain, new rows beside it or not.
$nrStore->inventory['assets'][3]['rules'] = '{"core.edit":{"2":1}}';
check('new rows: a shipped module whose rules change is still an ACL change', $nrAcl($nrWarnings()),
    ['Access-level or ACL definition changed: entityRules(modules.110)']);
$nrStore->inventory['assets'][3]['rules'] = '{}';
// And a component whose rules change is reported as that, not hidden by the rows that inherit from it.
$nrStore->inventory['assets'][2]['rules'] = '{"core.admin":{"6":1}}';
$nrGot = $nrAcl($nrWarnings());
checkTrue('new rows: a component whose rules change is still an ACL change', count($nrGot) === 1 && strpos($nrGot[0], 'componentRules') !== false);
$nrStore->inventory['assets'][2]['rules'] = '{"core.admin":{"7":1}}';
check('new rows: and the site is back to only being bigger', $nrWarnings(), ['Quickstart inventory changed']);

// ---------------------------------------------- Tracy's design-system module is not counted
// What tch's design-system slot writes (packages/design/tracy-ds-slot/src/joomla.mjs): a fonts link,
// then the marked <style>. Module 255 above carried plain words, so it counted; now it is Tracy's.
$nrDsContent = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter"><style id="tracy-ds-slot">/* tracy-ds-slot: test */ :root{--ds-c-accent:#123456}</style>';
$nrWriter->store['module'][255]['content'] = $nrDsContent;
check('design system: Tracy\'s own module is not a row the site gained', $nrWarnings(), []);
$nrWriter->store['module'][256] = ['id' => '256', 'title' => 'Summer sale', 'position' => 'header-r', 'content' => '<p>Sale</p>'] + $nrModule;
check('design system: a module someone added beside it still is', $nrWarnings(), ['Quickstart inventory changed']);
unset($nrWriter->store['module'][256]);
$nrWriter->store['module'][255]['module'] = 'mod_ja_acm';
check('design system: the mark on another kind of module does not make it Tracy\'s', $nrWarnings(), ['Quickstart inventory changed']);
$nrWriter->store['module'][255]['module'] = 'mod_custom';
$nrWriter->store['module'][255]['client_id'] = '1';
check('design system: nor does it on an administrator module', $nrWarnings(), ['Quickstart inventory changed']);
$nrWriter->store['module'][255]['client_id'] = '0';
$nrWriter->store['module'][255]['content'] = '<style>:root{--ds-c-accent:#123456}</style>';
check('design system: a mod_custom with a <style> but not the mark is counted', $nrWarnings(), ['Quickstart inventory changed']);
$nrWriter->store['module'][255]['content'] = $nrDsContent;

// A baseline saved while the module still counted — 0.24.8 moved it to two modules on the first
// contract apply after the slot — must not refuse the next content apply now that it does not.
// Nothing is compared with a baseline's counts (the lock's are), so a difference there alone moves it.
$nrBind = function (array $stored) use ($nrWriter, $nrStore, $nrDir): string {
    $contract = new QuickstartContract($nrWriter, $nrStore, $nrDir, $nrDir);
    $nrStore->binding = $stored;
    $contract->newRequest();
    try { $contract->bind($contract->inspect()['snapshot']); return 'bound'; }
    catch (Throwable $error) { return 'refused: ' . $error->getMessage(); }
    finally { $contract->endRequest(); }
};
$nrStore->binding = null;
$nrNow = (new QuickstartContract($nrWriter, $nrStore, $nrDir, $nrDir))->inspect()['snapshot'];
check('design system: the baseline counts the quickstart\'s one module', $nrNow['counts'], ['module' => 1]);
$nrStale = $nrNow; $nrStale['counts']['module'] = 2;
check('design system: a baseline that counted Tracy\'s module is moved, not refused', [$nrBind($nrStale), $nrStore->binding['counts']], ['bound', ['module' => 1]]);
check('design system: an identical baseline is re-saved as before', [$nrBind($nrNow), $nrStore->binding], ['bound', $nrNow]);
$nrOther = $nrStale; $nrOther['assignments']['hero'] = [5];
check('design system: a baseline that differs in more than its counts is still refused', $nrBind($nrOther), 'refused: Cannot replace a bound baseline');
$nrStore->binding = null;
