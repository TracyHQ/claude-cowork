<?php
// Loaded by run.php after contract-json-columns.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog).
// A visitor reads an author's name under every post and, on the author pages, the picture and job title.
// TCH capture (`--full-coverage`) declares them: a `user` entity (matched by username, only `name` a slot)
// and a `userProfile` entity per profile value (\UserProfileKey id, matched by its field and its author).
// The contract door writes an author's display name through the content batch it otherwise refuses `user` in,
// and nothing else of the account; the people on a site are not quickstart inventory (every provision adds one).

$auDir = sys_get_temp_dir() . '/cowork-authors-' . bin2hex(random_bytes(6));
mkdir($auDir); mkdir($auDir . '/assets');
file_put_contents($auDir . '/assets/site.css', 'body { color: #111 }');
mkdir($auDir . '/images/author', 0777, true);
foreach (['pic-1.jpg', 'pic-4.jpg'] as $auPicture) file_put_contents($auDir . '/images/author/' . $auPicture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$auAvatar = \UserProfileKey::encode(44, 'profile.user_avatar');
$auJob = \UserProfileKey::encode(44, 'profile.user_jobtitle');
// The release's Super User: its picture is demo content too, but the account is not an author (its username changes), so the
// profile is matched by the account's id and no `user` entity names it.
$auSuper = \UserProfileKey::encode(42, 'profile.user_avatar');
$auSlots = [
    ['key' => 'user-44.0', 'entity' => 'user-44', 'column' => 'name', 'type' => 'text', 'sample' => 'Drew Stokes', 'maxCharacters' => 40, 'customize' => 'keep'],
    ['key' => "userProfile-{$auJob}.0", 'entity' => "userProfile-{$auJob}", 'column' => 'value', 'type' => 'text', 'sample' => 'Boxer', 'maxCharacters' => 40, 'customize' => 'keep'],
    ['key' => "userProfile-{$auAvatar}.0", 'entity' => "userProfile-{$auAvatar}", 'column' => 'value', 'type' => 'image', 'sample' => 'images/author/pic-4.jpg', 'maxCharacters' => 255, 'customize' => 'keep'],
    ['key' => "userProfile-{$auSuper}.0", 'entity' => "userProfile-{$auSuper}", 'column' => 'value', 'type' => 'image', 'sample' => 'images/author/pic-1.jpg', 'maxCharacters' => 255, 'customize' => 'keep'],
];
$auStore = new TestContractStore();
$auFiles = [
    'manifest' => ['id' => 'authors/v1'],
    'content-map' => ['entities' => [
        ['key' => 'user-44', 'kind' => 'user', 'sourceId' => 44, 'identity' => ['username' => 'Drew_Stokes']],
        ['key' => "userProfile-{$auJob}", 'kind' => 'userProfile', 'sourceId' => $auJob, 'identity' => ['field' => 'user_jobtitle'], 'identityReferences' => ['user_id' => 'user-44']],
        ['key' => "userProfile-{$auAvatar}", 'kind' => 'userProfile', 'sourceId' => $auAvatar, 'identity' => ['field' => 'user_avatar'], 'identityReferences' => ['user_id' => 'user-44']],
        ['key' => "userProfile-{$auSuper}", 'kind' => 'userProfile', 'sourceId' => $auSuper, 'identity' => ['field' => 'user_avatar', 'user_id' => '42']],
    ], 'slots' => $auSlots, 'pages' => []],
    'presentation-lock' => ['entities' => [
        'user-44' => ['name' => 'Drew Stokes', 'username' => 'Drew_Stokes'],
        "userProfile-{$auJob}" => ['value' => 'Boxer'],
        "userProfile-{$auAvatar}" => ['value' => 'images/author/pic-4.jpg'],
        "userProfile-{$auSuper}" => ['value' => 'images/author/pic-1.jpg'],
    ], 'assignments' => [], 'fileRoots' => ['assets'], 'files' => ['assets/site.css' => hash_file('sha256', $auDir . '/assets/site.css')],
        'inventoryCounts' => [], 'access' => $auStore->acl],
];
foreach ($auFiles as $name => $body) file_put_contents($auDir . '/' . $name . '.json', json_encode($body));
$auLog = new FakeApplyLog();
$auWriter = new ContractTestWriter($auLog, $auStore);
$auWriter->store['user'][44] = ['id' => '44', 'name' => 'Drew Stokes', 'username' => 'Drew_Stokes', 'email' => 'drew@example.com', 'block' => '0'];
// The owner's account a provision adds, with a job title of its own: neither is the quickstart's.
$auWriter->store['user'][52] = ['id' => '52', 'name' => 'Site Owner', 'username' => 'owner', 'email' => 'owner@example.com', 'block' => '0'];
$auWriter->store['userProfile'][$auJob] = ['id' => $auJob, 'user_id' => 44, 'name' => 'Drew Stokes', 'field' => 'user_jobtitle', 'value' => 'Boxer'];
$auWriter->store['userProfile'][$auAvatar] = ['id' => $auAvatar, 'user_id' => 44, 'name' => 'Drew Stokes', 'field' => 'user_avatar', 'value' => 'images/author/pic-4.jpg'];
// The Super User's account is renamed on a site, and no contract entity names it: only its picture is declared.
$auWriter->store['user'][42] = ['id' => '42', 'name' => 'Renamed Owner', 'username' => 'renamed', 'email' => 'owner@example.com', 'block' => '0'];
$auWriter->store['userProfile'][$auSuper] = ['id' => $auSuper, 'user_id' => 42, 'name' => 'Renamed Owner', 'field' => 'user_avatar', 'value' => 'images/author/pic-1.jpg'];
$auWriter->store['userProfile'][\UserProfileKey::encode(52, 'profile.user_jobtitle')] = ['id' => \UserProfileKey::encode(52, 'profile.user_jobtitle'), 'user_id' => 52, 'name' => 'Site Owner', 'field' => 'user_jobtitle', 'value' => 'Owner'];
$auContract = new QuickstartContract($auWriter, $auStore, $auDir, $auDir);
$auEngine = new Engine($WTOKEN, [], null, null, null, null, $auWriter, null, $auLog, null, null, null, $auContract);
$auCall = fn(array $params) => $auEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);

check('authors: the profile binds beside an account it does not name', $auCall(['operation' => 'bind'])['bound'] ?? null, true);
$auState = $auContract->inspect();
check('authors: the name, job title and picture read back', array_column($auState['slots'], 'current', 'key'), array_column($auSlots, 'sample', 'key'));
check('authors: the people on a site are not inventory, so the owner\'s account is no drift', $auContract->driftWarnings(), []);
check('an author\'s job title is a profile field a visitor reads, its picture too', \UserProfileKey::FIELDS, ['profile.aboutme', 'profile.user_jobtitle', 'profile.user_tagline', 'profile.user_avatar']);

$auApplied = $auCall(['operation' => 'apply', 'apply_id' => 'contract-authors-1', 'request_id' => 'au-1', 'expected_revision' => $auState['revision'],
    'changes' => ['user-44.0' => 'Dana Stokes', "userProfile-{$auJob}.0" => 'Coach']]);
check('authors: an apply through an author\'s name and job title commits', $auApplied['ok'] ?? null, true);
check('authors: only the display name of the account changed', $auWriter->store['user'][44], ['id' => '44', 'name' => 'Dana Stokes', 'username' => 'Drew_Stokes', 'email' => 'drew@example.com', 'block' => '0']);
check('authors: the job title changed, the other author\'s did not',
    [$auWriter->store['userProfile'][$auJob]['value'], $auWriter->store['userProfile'][\UserProfileKey::encode(52, 'profile.user_jobtitle')]['value']], ['Coach', 'Owner']);
check('authors: writing an author\'s words is not drift', $auContract->driftWarnings(), []);

// The Super User's picture (matched by account id, renamed account) is a slot like an author's.
$auSuperApplied = $auCall(['operation' => 'apply', 'apply_id' => 'contract-authors-1b', 'request_id' => 'au-1b', 'expected_revision' => $auContract->inspect()['revision'],
    'changes' => ["userProfile-{$auSuper}.0" => 'images/author/pic-4.jpg']]);
check('authors: an apply through the Super User\'s picture commits though no entity names its account', $auSuperApplied['ok'] ?? null, true);
check('authors: only the Super User\'s picture changed', [$auWriter->store['userProfile'][$auSuper]['value'], $auWriter->store['user'][42]['name']], ['images/author/pic-4.jpg', 'Renamed Owner']);

// Outside the contract the account stays its own door's: a content batch naming `user` is still refused.
$auBatch = $auEngine->handle(['token' => $WTOKEN, 'action' => 'content.batch', 'params' => ['apply_id' => 'batch-authors-1', 'request_id' => 'au-2',
    'operations' => [['kind' => 'user', 'id' => 44, 'fields' => ['name' => 'Someone']]]]]);
check('authors: a plain content batch still may not write a user', [$auBatch['ok'] ?? null, $auWriter->store['user'][44]['name']], [false, 'Dana Stokes']);
