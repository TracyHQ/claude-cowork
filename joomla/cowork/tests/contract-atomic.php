<?php
// Loaded by run.php after contracts.php (reuses TestContractStore and ContractTestWriter).
// A contract apply is all or nothing, even when Joomla's own Table::store() commits the transaction
// under it (LOCK TABLES #__assets is an implicit COMMIT; TCH #1013, D3).

$atDir = sys_get_temp_dir() . '/cowork-atomic-' . bin2hex(random_bytes(6));
mkdir($atDir); mkdir($atDir . '/assets');
file_put_contents($atDir . '/assets/demo.css', '.hero { color: red }');
$atModule = fn(string $title, string $content, string $ordering) => ['title' => $title, 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1',
    'publish_up' => null, 'publish_down' => null, 'ordering' => $ordering, 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0',
    'content' => $content, 'params' => '{"style":"0"}'];
$atProtected = ['hero' => $atModule('Home hero', '<h1>Demo title</h1>', '1'), 'foot' => $atModule('Footer', '<p>Footer text</p>', '2'),
    'side' => $atModule('Side', '<p>Side text</p>', '3')];
$atEntities = []; $atSlots = [];
foreach ($atProtected as $key => $row) {
    $atEntities[] = ['key' => $key, 'kind' => 'module', 'sourceId' => 10 + count($atEntities), 'identity' => ['title' => $row['title']]];
    foreach (ContentSlots::htmlSlots($row['content']) as $n => $s) $atSlots[] = ['key' => $key . '.' . $n, 'entity' => $key, 'column' => 'content', 'maxCharacters' => 40] + $s;
}
$atStore = new TestContractStore();
$atData = ['manifest' => ['id' => 'test-atomic/v1', 'quickstart' => ['release' => 'test', 'version' => '1.0.0']],
    'content-map' => ['entities' => $atEntities, 'slots' => $atSlots, 'pages' => []],
    'presentation-lock' => ['entities' => $atProtected, 'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 11, 'menuid' => 0], ['moduleid' => 12, 'menuid' => 0]],
        'fileRoots' => ['assets'], 'files' => ['assets/demo.css' => hash_file('sha256', $atDir . '/assets/demo.css')],
        'inventoryCounts' => ['module' => 3], 'access' => $atStore->acl]];
foreach ($atData as $name => $body) file_put_contents($atDir . '/' . $name . '.json', json_encode($body));

$atLog = new FakeApplyLog();
$atWriter = new ContractTestWriter($atLog, $atStore);
foreach (array_values($atProtected) as $i => $row) {
    $atWriter->store['module'][110 + $i] = ['id' => (string) (110 + $i)] + $row;
    $atWriter->store['moduleAssignment'][110 + $i] = ['menuids' => '[0]'];
}
$atContract = new QuickstartContract($atWriter, $atStore, $atDir, $atDir);
$atContract->bind($atContract->inspect()['snapshot']);
$atEngine = new Engine($WTOKEN, [], null, null, null, null, $atWriter, null, $atLog, null, null, null, $atContract);
$atApply = fn(string $id, string $word = 'Customer') => $atEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply',
    'apply_id' => 'contract-' . $id, 'request_id' => $id, 'expected_revision' => $atContract->inspect()['revision'],
    'changes' => ['hero.0' => $word . ' hero', 'foot.0' => $word . ' footer', 'side.0' => $word . ' side']]]);
$atSite = fn() => array_map(fn($row) => $row['content'], $atWriter->store['module']);
$atBefore = $atSite();
$atRevision = $atContract->inspect()['revision'];

// Write 3 of 3 fails after writes 1 and 2 were committed under the apply by Joomla's LOCK TABLES.
$atWriter->tableCommits = true;
$atWriter->failOn = ['module', 112];
$atFailed = $atApply('broken');
check('a contract apply whose last write fails answers failed', [$atFailed['ok'], $atFailed['error']], [false, 'batch_failed']);
check('and leaves no write of it on the site, though Joomla committed the first ones', $atSite(), $atBefore);
check('and keeps no undo log for writes that are gone', $atLog->entries('contract-broken'), []);
check('and the site reads exactly as before it', $atContract->inspect()['revision'], $atRevision);
checkTrue('the answer says the writes were taken back', str_contains($atFailed['message'], 'taken back'));

// A write that lands and then fails (Joomla stored the row, then its tags or asset step threw) is taken back too.
$atWriter->failOn = null;
$atWriter->failAfter = ['module', 112];
$atFailed = $atApply('landed');
check('a write that landed before it failed is taken back with the others', [$atFailed['ok'], $atSite()], [false, $atBefore]);
check('and leaves no undo log', $atLog->entries('contract-landed'), []);

// Where the rollback itself works (no Table write committed it), nothing changes either.
$atWriter->failAfter = null;
$atWriter->tableCommits = false;
$atWriter->failOn = ['module', 112];
check('a failed apply under a working rollback still leaves the site as it was', [$atApply('plain')['ok'], $atSite()], [false, $atBefore]);

// And the same apply, retried with nothing failing, lands whole.
$atWriter->failOn = null;
$atWriter->tableCommits = true;
$atOk = $atApply('broken');
check('the same apply_id may be retried after a failure that was taken back', $atOk['ok'], true);
check('and its writes all land', array_values($atSite()), ['<h1>Customer hero</h1>', '<p>Customer footer</p>', '<p>Customer side</p>']);
$atWriter->tableCommits = false;

// What cannot be taken back is named, with the call that finishes it, never answered as a clean failure.
$atWriter->tableCommits = true;
$atWriter->failOn = ['module', 112];
$atWriter->failOutside = ['module', 110];
$atStuck = $atApply('stuck', 'Stuck');
check('a failure that could not be fully taken back says so in errors[]', array_column($atStuck['errors'] ?? [], 'code'), ['CONTRACT_FAILED', 'PARTIAL_APPLY']);
checkTrue('and names the call that finishes it', str_contains($atStuck['message'], 'apply.revert contract-stuck'));
check('what could be taken back was, and the rest is still in the undo log', [$atWriter->store['module'][111]['content'], count($atLog->entries('contract-stuck'))],
    ['<p>Customer footer</p>', 2]);
$atWriter->failOutside = null;
check('which apply.revert then finishes', [$atEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-stuck']])['ok'],
    $atWriter->store['module'][110]['content']], [true, '<h1>Customer hero</h1>']);
$atWriter->failOn = null;
$atWriter->tableCommits = false;
