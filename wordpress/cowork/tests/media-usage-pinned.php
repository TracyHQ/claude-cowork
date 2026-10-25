<?php
declare(strict_types=1);

// media.usage on a sealed site: an option the presentation lock pins is `pinned` (a write would only
// make drift), apart from `locked` (the option door refuses it). Uses imageSite() from image-slots.php.

$muFixtures = sys_get_temp_dir() . '/cc-media-usage-' . bin2hex(random_bytes(4));
try {
    mkdir($muFixtures . '/image-design/wp7/1.0.0', 0777, true);
    foreach (['manifest.json', 'content-map.json', 'presentation-lock.json'] as $muFile) {
        copy($IMAGE_FIXTURES . '/image-design/wp7/1.0.0/' . $muFile, $muFixtures . '/image-design/wp7/1.0.0/' . $muFile);
    }
    $muLock = json_decode((string) file_get_contents($muFixtures . '/image-design/wp7/1.0.0/presentation-lock.json'), true);
    $muLock['options']['wp_ja_diner_blog_banner_image'] = '24';
    file_put_contents($muFixtures . '/image-design/wp7/1.0.0/presentation-lock.json', json_encode($muLock));

    $muSite = imageSite($IMAGE_HOME, $muFixtures);
    WP_Fake::$options['wp_ja_diner_blog_banner_image'] = '24';
    $muEngine = $muSite['engine'];
    $muMedia = (new ReflectionProperty(Engine::class, 'media'))->getValue($muEngine);
    $muMedia->store['wp-content/uploads/2026/10/blog.jpg'] = 'JPEG';
    $muMedia->attachments[24] = 'wp-content/uploads/2026/10/blog.jpg';
    $muMedia->storageUsage[24] = ['postmeta' => [], 'options' => [
        ['option' => 'wp_ja_diner_blog_banner_image', 'shape' => 'id'],
        ['option' => 'stylesheet', 'shape' => 'id'],
        ['option' => 'theme_mods_test-theme', 'shape' => 'serialized'],
    ], 'truncated' => false];
    $muUsage = static fn(): array => $muEngine->handle(['token' => $WTOKEN, 'action' => 'media.usage', 'params' => ['id' => 24]]);

    check('before the bind nothing is pinned', $muUsage()['usedInStorage']['options'] ?? null, [
        ['option' => 'wp_ja_diner_blog_banner_image', 'shape' => 'id'],
        ['option' => 'stylesheet', 'shape' => 'id', 'locked' => true],
        ['option' => 'theme_mods_test-theme', 'shape' => 'serialized'],
    ]);
    check('the profile with a pinned option binds', $muEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract',
        'params' => ['operation' => 'bind', 'contract' => 'image-design/wp7/1.0.0']])['ok'] ?? null, true);
    check('a bound site marks pinned options apart from locked ones', $muUsage()['usedInStorage']['options'] ?? null, [
        ['option' => 'wp_ja_diner_blog_banner_image', 'shape' => 'id', 'pinned' => true],
        ['option' => 'stylesheet', 'shape' => 'id', 'locked' => true, 'pinned' => true],
        ['option' => 'theme_mods_test-theme', 'shape' => 'serialized'],
    ]);
    $muDry = $muEngine->handle(['token' => $WTOKEN, 'action' => 'media.delete', 'params' => ['apply_id' => 'MU1', 'id' => 24, 'dry_run' => true]]);
    check('media.delete answers the same pinned rows', $muDry['usedInStorage'] ?? null, $muUsage()['usedInStorage']);
} finally {
    contractRootRemove($muFixtures);
    unset(WP_Fake::$options['wp_ja_diner_blog_banner_image']);
}
