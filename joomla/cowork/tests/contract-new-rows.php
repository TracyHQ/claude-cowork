<?php
// Loaded by run.php. A row the quickstart never shipped is held, in the contract's ACL check, to what
// it inherits — not reported as an ACL change for being new.
//
// Tracy's design-system slot creates one module through content.update. The next contract call
// warned "Access-level or ACL definition changed: entityRules(modules.255)" (10/10/2026, dskeepjo,
// tracy-base j6), although the module's chain was com_modules' own: the check compared the site's
// whole entityRules against the lock's, so any row the lock did not name was a difference. Its
// newness is "Quickstart inventory changed", which stays.

/** The access half of a contract store, read through the real ContractAccess::snapshot(). */
final class NewRowsContractStore implements ContractStore {
    public ?array $binding = null;
    public ?array $job = null;
    /** `#__viewlevels`, `#__usergroups`, `#__assets`, `#__modules`, `#__content`, `#__categories` as rows. */
    public array $inventory = [];
    public function load(): ?array { return $this->binding; }
    public function save(array $value): void { $this->binding = $value; }
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
