<?php
// Loaded by run.php. `usermeta`: the fields of an author a visitor reads (lib/AuthorMeta.php), listed and written through the
// real writer over the WordPress stubs, and taken back by apply.revert (TracyHQ/tch#1013). Measured 10/10/2026 on dev
// g60-ess-wp-full (JA Essence WordPress, vi): "Collaborator & Editor" (theme meta `job_title`) and an English bio (core
// `description`) on the author listing and every author block, 17 pages, which no door reached.

$amWriter = new Claude_Cowork_Site_Writer();
$amEngine = new Engine($WTOKEN, [], null, null, null, null, $amWriter, null, new FakeApplyLog());
$amCall = static fn (string $action, array $params): array => $amEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
$amBio = 'It’s remarkable to watch a five-year-old draw.';

WP_Fake::reset();
WP_Fake::$users = [2 => ['display_name' => 'Alfonso Cortez'], 3 => ['display_name' => 'Trevor Patrick'], 4 => ['display_name' => 'Editor']];
WP_Fake::$userMeta = [
    2 => ['description' => $amBio, 'job_title' => 'Collaborator & Editor', 'je_social_facebook' => '#', 'wp_capabilities' => 'a:1:{s:6:"author";b:1;}'],
    3 => ['wp_ja_nova_job_title' => 'Senior Writer', 'first_name' => 'Trevor'],
    4 => ['description' => '', 'rich_editing' => 'true'],
];

check('a visitor reads the bio and a theme\'s job title or tagline; not a social link, not the account', [
    AuthorMeta::visitorKey('description'), AuthorMeta::visitorKey('job_title'), AuthorMeta::visitorKey('wp_ja_kinetic_user_tagline'),
    AuthorMeta::visitorKey('wp_ja_nova_job_title'), AuthorMeta::visitorKey('je_social_facebook'), AuthorMeta::visitorKey('wp_capabilities'),
    AuthorMeta::visitorKey('first_name'),
], [true, true, true, true, false, false, false]);

$amList = $amCall('content.list', ['kind' => 'usermeta']);
check('content.list kind usermeta: the authors with words a visitor reads, and only those words', [$amList['ok'] ?? null, $amList['items'] ?? null], [true, [
    ['id' => 2, 'name' => 'Alfonso Cortez', 'meta' => ['description' => $amBio, 'job_title' => 'Collaborator & Editor']],
    ['id' => 3, 'name' => 'Trevor Patrick', 'meta' => ['wp_ja_nova_job_title' => 'Senior Writer']],
]]);

$amSet = $amCall('content.update', ['apply_id' => 'authors-1', 'kind' => 'usermeta', 'id' => 2, 'key' => 'job_title', 'fields' => ['value' => 'Cộng tác viên & Biên tập']]);
check('content.update writes an author\'s job title', [$amSet['ok'] ?? null, WP_Fake::$userMeta[2]['job_title'] ?? null], [true, 'Cộng tác viên & Biên tập']);
$amCall('content.update', ['apply_id' => 'authors-1', 'kind' => 'usermeta', 'id' => 2, 'key' => 'description', 'fields' => ['value' => 'Thật đáng kinh ngạc.']]);
check('and the bio', WP_Fake::$userMeta[2]['description'] ?? null, 'Thật đáng kinh ngạc.');

foreach (['je_social_facebook', 'wp_capabilities'] as $amKey) {
    $amNo = $amCall('content.update', ['apply_id' => 'authors-2', 'kind' => 'usermeta', 'id' => 2, 'key' => $amKey, 'fields' => ['value' => 'x']]);
    check("a key a visitor does not read is refused, and left as it was: {$amKey}", [$amNo['ok'] ?? null, WP_Fake::$userMeta[2][$amKey] === 'x'], [false, false]);
}
$amNone = $amCall('content.update', ['apply_id' => 'authors-2', 'kind' => 'usermeta', 'id' => 99, 'key' => 'description', 'fields' => ['value' => 'x']]);
check('a user that does not exist is refused', $amNone['ok'] ?? null, false);

$amBack = $amCall('apply.revert', ['apply_id' => 'authors-1']);
check('apply.revert puts the job title and the bio back', [$amBack['ok'] ?? null, WP_Fake::$userMeta[2]['job_title'] ?? null, WP_Fake::$userMeta[2]['description'] ?? null],
    [true, 'Collaborator & Editor', $amBio]);
