<?php
// Public names are site content; account identity and permissions are not.
WP_Fake::reset();
WP_Fake::$users = [2 => ['display_name' => 'Admin', 'user_login' => 'admin', 'user_email' => 'owner@example.test', 'user_nicename' => 'admin', 'roles' => ['administrator']]];
WP_Fake::$posts = [42 => ['ID' => 42, 'post_author' => 2]];
$anOriginal = WP_Fake::$users[2];
$anWriter = new Claude_Cowork_Site_Writer();
$anLog = new FakeApplyLog();
$anEngine = new Engine($WTOKEN, [], null, null, null, null, $anWriter, null, $anLog);
$anCall = static fn(string $action, array $params): array => $anEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
$anParams = ['apply_id' => 'author-name-1', 'kind' => 'author', 'id' => 2, 'expected_display_name' => 'Admin', 'fields' => ['display_name' => 'Levain Bakery']];
check('author reads only public display name', $anCall('content.get', ['kind' => 'author', 'id' => 2])['item'] ?? null, ['display_name' => 'Admin']);
$anNoExpected = $anParams; unset($anNoExpected['expected_display_name']);
check('author refuses a write without the name read', $anCall('content.update', $anNoExpected)['ok'] ?? null, false);
check('author refuses a stale expected name', $anCall('content.update', array_replace($anParams, ['expected_display_name' => 'Someone else']))['error'] ?? null, 'conflict');
foreach (['user_login', 'user_email', 'user_pass', 'user_nicename', 'role', 'roles', 'ID', 'post_author'] as $anField) {
    check('author refuses account field ' . $anField, $anCall('content.update', array_replace($anParams, ['fields' => ['display_name' => 'Levain Bakery', $anField => 'changed']]))['ok'] ?? null, false);
}
foreach (['', '   ', '<b>Name</b>', "Name\nName", str_repeat('x', 251), chr(255)] as $anInvalid) {
    check('author refuses an invalid public name', $anCall('content.update', array_replace($anParams, ['fields' => ['display_name' => $anInvalid]]))['ok'] ?? null, false);
}
foreach ([0, 99] as $anId) check('author cannot create an account', $anCall('content.update', array_replace($anParams, ['id' => $anId]))['ok'] ?? null, false);
WP_Fake::$multisite = true;
check('network-wide name is not readable with a site token', $anCall('content.get', ['kind' => 'author', 'id' => 2])['ok'] ?? null, false);
check('network-wide name is not writable with a site token', $anCall('content.update', $anParams)['ok'] ?? null, false);
WP_Fake::$multisite = false;
WP_Fake::$userWriteFails = true;
check('WordPress write error is reported', $anCall('content.update', $anParams)['ok'] ?? null, false);
WP_Fake::$userWriteFails = false;
check('refused writes leave the account unchanged', WP_Fake::$users[2], $anOriginal);
check('author changes the public name through Apply', $anCall('content.update', $anParams)['ok'] ?? null, true);
check('only the public name changes', WP_Fake::$users[2], array_replace($anOriginal, ['display_name' => 'Levain Bakery']));
check('post ownership does not move', WP_Fake::$posts[42]['post_author'], 2);
check('author snapshot holds no account data', $anLog->entries('author-name-1')[0]['before'] ?? null, ['display_name' => 'Admin']);
check('author delete is refused', $anCall('content.delete', ['apply_id' => 'author-delete', 'kind' => 'author', 'id' => 2])['ok'] ?? null, false);
WP_Fake::$users[2]['display_name'] = 'Levain Bakery Team';
check('revert refuses even a later suffix change to the name', $anCall('apply.revert', ['apply_id' => 'author-name-1'])['ok'] ?? null, false);
check('conflicted revert preserves the later name', WP_Fake::$users[2]['display_name'], 'Levain Bakery Team');
WP_Fake::$users[2]['display_name'] = 'Levain Bakery';
check('revert restores the exact original name', $anCall('apply.revert', ['apply_id' => 'author-name-1'])['ok'] ?? null, true);
check('revert changes no account fields', WP_Fake::$users[2], $anOriginal);

WP_Fake::$filters['pre_user_display_name'] = static fn($name) => $name . ' Team';
$anFiltered = $anCall('content.update', array_replace($anParams, ['apply_id' => 'author-filtered']));
check('receipt reports a name changed by WordPress filters', [$anFiltered['item'] ?? null, $anFiltered['name_matches'] ?? null], [['display_name' => 'Levain Bakery Team'], false]);
check('undo records the actual filtered name', $anLog->entries('author-filtered')[0]['changes']['display_name']['now'] ?? null, 'Levain Bakery Team');
unset(WP_Fake::$filters['pre_user_display_name']);
check('a filtered name can be reverted using its actual value', $anCall('apply.revert', ['apply_id' => 'author-filtered'])['ok'] ?? null, true);
check('filtered write reverts to the original name', WP_Fake::$users[2]['display_name'], 'Admin');
