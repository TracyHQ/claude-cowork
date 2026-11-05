<?php
// Loaded by run.php after contract-json-columns.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog).
// A menu entry of type `url` opens an address of its own: a "Follow us" menu whose entries are Twitter, Facebook…
// each pointing at `#` in the demo. The capture declares that address as a `link` slot of the entry, after its title;
// the business's own profile address must write through this door into the entry's `link` column, read back as the
// slot's value, and leave the entry's other columns and no drift behind.

$mlDir = sys_get_temp_dir() . '/cowork-menu-link-' . bin2hex(random_bytes(6));
mkdir($mlDir); mkdir($mlDir . '/assets');
file_put_contents($mlDir . '/assets/site.css', 'body { color: #111 }');
$mlEntry = ['title' => 'Twitter', 'alias' => 'twitter', 'path' => 'twitter', 'menutype' => 'follow-us', 'type' => 'url', 'link' => '#',
    'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0', 'parent_id' => '1', 'home' => '0', 'note' => '',
    'params' => json_encode(['menu-anchor_title' => '', 'menu_icon_css' => ''])];
$mlSlots = [
    ['key' => 'menuItem-132.0', 'entity' => 'menuItem-132', 'column' => 'title', 'type' => 'text', 'sample' => 'Twitter', 'maxCharacters' => 40, 'customize' => 'keep'],
    ['key' => 'menuItem-132.1', 'entity' => 'menuItem-132', 'column' => 'link', 'type' => 'url', 'sample' => '#', 'maxCharacters' => 1024, 'customize' => 'keep'],
];
$mlStore = new TestContractStore();
$mlFiles = [
    'manifest' => ['id' => 'menu-link/v1'],
    'content-map' => ['entities' => [
        ['key' => 'menuItem-132', 'kind' => 'menuItem', 'sourceId' => 132, 'identity' => ['menutype' => 'follow-us', 'path' => 'twitter', 'language' => '*', 'client_id' => '0']],
    ], 'slots' => $mlSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['menuItem-132' => $mlEntry], 'assignments' => [],
        'fileRoots' => ['assets'], 'files' => ['assets/site.css' => hash_file('sha256', $mlDir . '/assets/site.css')],
        'inventoryCounts' => ['menuItem' => 1], 'access' => $mlStore->acl],
];
foreach ($mlFiles as $name => $body) file_put_contents($mlDir . '/' . $name . '.json', json_encode($body));
$mlLog = new FakeApplyLog();
$mlWriter = new ContractTestWriter($mlLog, $mlStore);
$mlWriter->store['menuItem'][132] = ['id' => '132'] + $mlEntry;
$mlContract = new QuickstartContract($mlWriter, $mlStore, $mlDir, $mlDir);
$mlEngine = new Engine($WTOKEN, [], null, null, null, null, $mlWriter, null, $mlLog, null, null, null, $mlContract);
$mlCall = fn(array $params) => $mlEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);

check('menu link: the profile binds', $mlCall(['operation' => 'bind'])['bound'] ?? null, true);
$mlState = $mlContract->inspect();
check('menu link: the entry address reads back as its slot', array_column($mlState['slots'], 'current', 'key'), ['menuItem-132.0' => 'Twitter', 'menuItem-132.1' => '#']);
$mlApplied = $mlCall(['operation' => 'apply', 'apply_id' => 'contract-menu-link-1', 'request_id' => 'ml-1', 'expected_revision' => $mlState['revision'],
    'changes' => ['menuItem-132.1' => 'https://twitter.com/rowing-club']]);
check('menu link: an apply of the address commits', [$mlApplied['ok'] ?? null, $mlApplied['error'] ?? null, $mlApplied['message'] ?? null], [true, null, null]);
$mlAfter = $mlWriter->store['menuItem'][132];
check('menu link: the entry opens the business address, its title, type and menu stay',
    [$mlAfter['link'], $mlAfter['title'], $mlAfter['type'], $mlAfter['menutype']], ['https://twitter.com/rowing-club', 'Twitter', 'url', 'follow-us']);
check('menu link: the slot reads the new address', array_column($mlContract->inspect()['slots'], 'current', 'key')['menuItem-132.1'] ?? null, 'https://twitter.com/rowing-club');
check('menu link: a written address is no drift', $mlContract->driftWarnings(), []);
