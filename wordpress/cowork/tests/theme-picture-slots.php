<?php
/**
 * Pictures a theme points at through its own option or post meta (TracyHQ/tracy#459).
 *
 * A theme can print a list thumbnail from a meta field of each post (`<theme>_intro_image`) or a banner from an option
 * (`<theme>_blog_banner_image`), each holding an attachment id. No block holds them, so no slot could. The profile's
 * manifest now declares them by name (`themePictures.options`, `themePictures.postMeta`); an image slot may name such
 * an option (`target.option`) or meta key (`target.meta`), reads it as the uploads path of its attachment and writes
 * the attachment id of a picture in the media library. Nothing undeclared is read or written, and `_thumbnail_id`
 * behaves exactly as before.
 *
 * Loaded by run.php after inline-markup-slots.php, whose `markedSite()` and `markedVariant()` it uses.
 */
declare(strict_types=1);

echo "\nTheme picture slots\n";

$ts = markedSite($MARKED_FIXTURES, $MARKED_MENU, $MARKED_POST);
$TE = $ts['engine'];
$tdoor = static function (string $operation, array $params = []) use ($TE, $WTOKEN): array {
    return $TE->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};
$tapply = static function (array $changes, string $id) use ($tdoor): array {
    return $tdoor('apply', ['expected_revision' => $tdoor('inspect')['revision'], 'apply_id' => 'contract-' . $id, 'request_id' => $id, 'changes' => $changes]);
};
$trevert = static function (string $id) use ($TE, $WTOKEN): bool {
    return (bool) ($TE->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-' . $id]])['ok'] ?? false);
};

check('a profile with theme pictures binds', $tdoor('bind', ['contract' => 'marked-design/wp7/1.0.0'])['ok'], true);
$tinsp = $tdoor('inspect');
check('nothing is a problem', $tinsp['problems'], []);
check('the theme meta picture reads as its uploads path', $tinsp['slots']['post-spring-notes.intro-image'], 'wp-content/uploads/2026/09/plates.jpg');
check('so does the theme option picture', $tinsp['slots']['blog.banner'], 'wp-content/uploads/2026/09/banner.jpg');
check('both are image slots with their demo picture', [$tinsp['imageSlots']['post-spring-notes.intro-image'] ?? null, $tinsp['imageSlots']['blog.banner'] ?? null],
    ['wp-content/uploads/2026/09/plates.jpg', 'wp-content/uploads/2026/09/banner.jpg']);

$wide = 'wp-content/uploads/tracy-content/' . hash('sha256', 'terrace') . '.jpg';
$card = 'wp-content/uploads/tracy-content/' . hash('sha256', 'soup') . '.jpg';
check('a picture not in the media library is refused', $tapply(['blog.banner' => $wide], 't1')['errors'][0]['code'] ?? null, 'SLOT_IMAGE_INVALID');
check('a file that is not a picture is refused', $tapply(['blog.banner' => 'wp-content/uploads/2026/09/menu.pdf'], 't2')['errors'][0]['code'] ?? null, 'SLOT_IMAGE_INVALID');
$ts['images']->images[$wide] = ['id' => 140, 'width' => 3200, 'height' => 1000];
$ts['images']->images[$card] = ['id' => 141, 'width' => 900, 'height' => 600];
$both = $tapply(['blog.banner' => $wide, 'post-spring-notes.intro-image' => $card], 't3');
check('pictures of the slots\' shape land', $both['ok'] ?? null, true);
check('as a meta write and an option write', $both['written'] ?? null,
    [['kind' => 'option', 'id' => 0, 'key' => 'test_theme_blog_banner_image'], ['kind' => 'postmeta', 'id' => 20, 'key' => 'tt_intro_image']]);
check('each holds the attachment id', [(string) WP_Fake::$meta['20:tt_intro_image'], (string) WP_Fake::$options['test_theme_blog_banner_image']], ['141', '140']);
WP_Fake::$meta['140:_wp_attached_file'] = 'tracy-content/' . hash('sha256', 'terrace') . '.jpg';
WP_Fake::$meta['141:_wp_attached_file'] = 'tracy-content/' . hash('sha256', 'soup') . '.jpg';
$tafter = $tdoor('inspect');
check('the slots answer the new pictures', [$tafter['slots']['blog.banner'], $tafter['slots']['post-spring-notes.intro-image']], [$wide, $card]);
checkTrue('and the revision moved', $tafter['revision'] !== $tinsp['revision']);
check('the revert puts both demo pictures back', [$trevert('t3'), (string) WP_Fake::$meta['20:tt_intro_image'], (string) WP_Fake::$options['test_theme_blog_banner_image']],
    [true, '92', '93']);
check('the body of the post was never touched', WP_Fake::$posts[20]['post_content'], $MARKED_POST);

WP_Fake::$options['test_theme_blog_banner_image'] = 'banner.jpg';
check('an option that does not hold an attachment id is a problem', $tdoor('inspect')['problems'],
    ['Slot blog.banner: the option does not hold an attachment id']);
WP_Fake::$options['test_theme_blog_banner_image'] = '93';

// ── only what the manifest declares ──────────────────────────────────────────────────────────

$undeclared = markedVariant(static function (array $manifest, array $map): array {
    $map['slots'][5]['target'] = ['meta' => 'tt_hero_image'];
    return [$manifest, $map];
});
check('an undeclared meta key is refused by name, and the profile does not bind', [$undeclared['inspect'], $undeclared['bind']],
    [['Slot post-spring-notes.intro-image: unsupported post meta tt_hero_image'], false]);
check('an undeclared picture option is refused by name', markedVariant(static function (array $manifest, array $map): array {
    $manifest['themePictures']['options'] = [];
    return [$manifest, $map];
})['inspect'] ?? null, ['Slot blog.banner: undeclared theme picture option test_theme_blog_banner_image']);
check('a declared meta key on a text slot is refused', markedVariant(static function (array $manifest, array $map): array {
    $map['slots'][4]['target'] = ['meta' => 'tt_intro_image'];
    return [$manifest, $map];
})['inspect'] ?? null, ['Slot post-spring-notes.post.intro: a theme picture is an image slot']);
check('a theme meta picture on a page is fine', markedVariant(static function (array $manifest, array $map): array {
    $map['slots'][5]['entity'] = 'page-menu';
    return [$manifest, $map];
})['inspect'] ?? null, []);
check('the featured picture of a page is still refused', markedVariant(static function (array $manifest, array $map): array {
    $map['slots'][5]['entity'] = 'page-menu';
    $map['slots'][5]['target'] = ['meta' => '_thumbnail_id'];
    return [$manifest, $map];
})['inspect'] ?? null, ['Slot post-spring-notes.intro-image: the featured picture is an image slot of a post']);

$unavailable = static function (callable $declare): ?string {
    $answer = markedVariant(static function (array $manifest, array $map) use ($declare): array {
        $manifest['themePictures'] = $declare($manifest['themePictures']);
        return [$manifest, $map];
    })['answer'];
    return ($answer['ok'] ?? null) === false ? (string) ($answer['message'] ?? json_encode($answer)) : null;
};
check('an option outside the theme\'s own names refuses the profile', $unavailable(static fn(array $d): array => ['options' => ['siteurl'], 'postMeta' => []]),
    'marked-design/wp7/1.0.0: theme picture option siteurl is not one of the theme test-theme (its name starts test_theme_)');
check('a protected meta key refuses the profile', $unavailable(static fn(array $d): array => ['options' => [], 'postMeta' => ['_wp_attached_file']]),
    'marked-design/wp7/1.0.0: theme picture meta _wp_attached_file is a protected key');
check('a declaration that is not a list of names refuses the profile', $unavailable(static fn(array $d): array => ['options' => 'test_theme_banner']),
    'marked-design/wp7/1.0.0: themePictures declares options and postMeta as lists of names');
