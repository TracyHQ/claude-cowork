<?php
declare(strict_types=1);

// apply.revert of a content.update on a post puts back every field that call can change: the row's
// columns, and the parts kept beside the row (the featured image, the page template). Measured on a
// real site: the title came back and the featured image did not, so a later media.upload revert
// deleted the attachment and WordPress took the posts' `_thumbnail_id` with it.

WP_Fake::reset();
if (!function_exists('set_post_thumbnail')) {
    function set_post_thumbnail($post, int $thumbnail): bool { return update_post_meta(is_object($post) ? (int) $post->ID : (int) $post, '_thumbnail_id', $thumbnail); }
}
if (!function_exists('delete_post_thumbnail')) {
    function delete_post_thumbnail($post): bool { return delete_post_meta(is_object($post) ? (int) $post->ID : (int) $post, '_thumbnail_id'); }
}
$rpLog = new FakeApplyLog();
$rpEngine = new Engine($WTOKEN, [], null, null, null, null, new Claude_Cowork_Site_Writer(), null, $rpLog);
$rp = static fn (string $action, array $params) => $rpEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
$rpPost = static fn (int $id, string $title) => ['ID' => $id, 'post_type' => 'post', 'post_title' => $title, 'post_status' => 'publish',
    'post_content' => '<p>Card</p>', 'post_excerpt' => 'Old excerpt', 'post_name' => 'card', 'post_parent' => 0, 'menu_order' => 0, 'post_date' => '2026-01-02 03:04:05'];

// The featured image a post had comes back.
WP_Fake::$posts[80] = $rpPost(80, 'Card');
WP_Fake::$meta['80:_thumbnail_id'] = 11;
$rpUpdate = $rp('content.update', ['apply_id' => 'rp-1', 'kind' => 'post', 'id' => 80, 'fields' => ['post_title' => 'New card', 'featured_image_id' => 22]]);
check('content.update sets the title and the featured image', [$rpUpdate['ok'] ?? null, WP_Fake::$posts[80]['post_title'], WP_Fake::$meta['80:_thumbnail_id'] ?? null], [true, 'New card', 22]);
$rpRevert = $rp('apply.revert', ['apply_id' => 'rp-1']);
check('apply.revert puts the title and the featured image back', [$rpRevert['ok'] ?? null, WP_Fake::$posts[80]['post_title'], WP_Fake::$meta['80:_thumbnail_id'] ?? null], [true, 'Card', 11]);

// A post that had none has none again.
WP_Fake::$posts[81] = $rpPost(81, 'Bare');
$rp('content.update', ['apply_id' => 'rp-2', 'kind' => 'post', 'id' => 81, 'fields' => ['post_title' => 'Dressed', 'featured_image_id' => 22]]);
check('the featured image was set', WP_Fake::$meta['81:_thumbnail_id'] ?? null, 22);
$rpRevert = $rp('apply.revert', ['apply_id' => 'rp-2']);
check('apply.revert removes a featured image the post did not have', [$rpRevert['ok'] ?? null, WP_Fake::$posts[81]['post_title'], array_key_exists('81:_thumbnail_id', WP_Fake::$meta)], [true, 'Bare', false]);

// A featured image the write took off comes back.
WP_Fake::$posts[82] = $rpPost(82, 'Card');
WP_Fake::$meta['82:_thumbnail_id'] = 11;
$rp('content.update', ['apply_id' => 'rp-3', 'kind' => 'post', 'id' => 82, 'fields' => ['post_title' => 'Plain', 'featured_image_id' => 0]]);
check('the featured image was taken off', array_key_exists('82:_thumbnail_id', WP_Fake::$meta), false);
$rp('apply.revert', ['apply_id' => 'rp-3']);
check('apply.revert puts a removed featured image back', WP_Fake::$meta['82:_thumbnail_id'] ?? null, 11);

// The page template, set and cleared, the same way.
WP_Fake::$posts[83] = $rpPost(83, 'Page');
WP_Fake::$meta['83:_wp_page_template'] = 'templates/wide.php';
WP_Fake::$posts[84] = $rpPost(84, 'Page');
$rp('content.update', ['apply_id' => 'rp-4', 'kind' => 'post', 'id' => 83, 'fields' => ['post_title' => 'Narrow', 'template' => 'templates/narrow.php']]);
$rp('content.update', ['apply_id' => 'rp-5', 'kind' => 'post', 'id' => 84, 'fields' => ['post_title' => 'Narrow', 'template' => 'templates/narrow.php']]);
check('the templates were set', [WP_Fake::$meta['83:_wp_page_template'] ?? null, WP_Fake::$meta['84:_wp_page_template'] ?? null], ['templates/narrow.php', 'templates/narrow.php']);
$rp('apply.revert', ['apply_id' => 'rp-5']);
$rp('apply.revert', ['apply_id' => 'rp-4']);
check('apply.revert puts the template back, or takes it off', [WP_Fake::$meta['83:_wp_page_template'] ?? null, array_key_exists('84:_wp_page_template', WP_Fake::$meta)], ['templates/wide.php', false]);

// Every other field the call writes on a post is a column of its row and comes back with it.
WP_Fake::$posts[85] = $rpPost(85, 'Card');
WP_Fake::$posts[86] = $rpPost(86, 'Parent');
$rpAll = ['post_title' => 'All', 'post_content' => '<p>All</p>', 'post_excerpt' => 'New excerpt', 'post_status' => 'draft', 'post_name' => 'all',
    'post_parent' => 86, 'menu_order' => 4, 'post_date' => '2026-10-01 00:00:00', 'featured_image_id' => 22, 'template' => 'templates/narrow.php'];
$rp('content.update', ['apply_id' => 'rp-6', 'kind' => 'post', 'id' => 85, 'fields' => $rpAll]);
$rpNow = WP_Fake::$posts[85] + ['featured_image_id' => WP_Fake::$meta['85:_thumbnail_id'] ?? null, 'template' => WP_Fake::$meta['85:_wp_page_template'] ?? null];
check('every field was written', array_map(static fn (string $field) => $rpNow[$field] ?? null, array_keys($rpAll)), array_values($rpAll));
$rp('apply.revert', ['apply_id' => 'rp-6']);
check('apply.revert puts every field back', [WP_Fake::$posts[85], array_key_exists('85:_thumbnail_id', WP_Fake::$meta), array_key_exists('85:_wp_page_template', WP_Fake::$meta)],
    [$rpPost(85, 'Card'), false, false]);

// The featured image is its own undo step: a later change to the title does not block putting it back,
// and a later change to the featured image does not get overwritten silently.
WP_Fake::$posts[87] = $rpPost(87, 'Card');
WP_Fake::$meta['87:_thumbnail_id'] = 11;
$rp('content.update', ['apply_id' => 'rp-7', 'kind' => 'post', 'id' => 87, 'fields' => ['post_title' => 'Card two', 'featured_image_id' => 22]]);
$rp('content.update', ['apply_id' => 'rp-8', 'kind' => 'post', 'id' => 87, 'fields' => ['post_content' => '<p>Later</p>']]);
$rpRevert = $rp('apply.revert', ['apply_id' => 'rp-7']);
check('a later body edit does not stop the featured image coming back', [$rpRevert['ok'] ?? null, WP_Fake::$posts[87]['post_title'], WP_Fake::$posts[87]['post_content'], WP_Fake::$meta['87:_thumbnail_id'] ?? null],
    [true, 'Card', '<p>Later</p>', 11]);
WP_Fake::$posts[88] = $rpPost(88, 'Card');
WP_Fake::$meta['88:_thumbnail_id'] = 11;
$rp('content.update', ['apply_id' => 'rp-9', 'kind' => 'post', 'id' => 88, 'fields' => ['post_title' => 'Card two', 'featured_image_id' => 22]]);
$rp('content.update', ['apply_id' => 'rp-10', 'kind' => 'post', 'id' => 88, 'fields' => ['post_title' => 'Card two', 'featured_image_id' => 33]]);
$rpRevert = $rp('apply.revert', ['apply_id' => 'rp-9']);
check('a later featured image is not overwritten by an older revert', [$rpRevert['error'] ?? null, WP_Fake::$meta['88:_thumbnail_id'] ?? null], ['conflict', 33]);

// The modified date is part of what a write changes: WordPress stamps it on every update, so a revert
// that leaves the post as it was before the write puts the write's stamp back too. Measured on a
// stand: the text came back byte for byte and post_modified kept the write's time.
$rpDated = static fn (int $id) => $rpPost($id, 'Card') + ['post_modified' => '2026-01-02 03:04:05', 'post_modified_gmt' => '2026-01-02 01:04:05'];
WP_Fake::$posts[89] = $rpDated(89);
$rp('content.update', ['apply_id' => 'rp-12', 'kind' => 'post', 'id' => 89, 'fields' => ['post_content' => '<p>Card, edited</p>']]);
check('the write stamps the post as modified', WP_Fake::$posts[89]['post_modified'] !== '2026-01-02 03:04:05', true);
$rpRevert = $rp('apply.revert', ['apply_id' => 'rp-12']);
check('apply.revert puts the modified date back with the text', [$rpRevert['ok'] ?? null, WP_Fake::$posts[89]], [true, $rpDated(89)]);
// A whole-row undo (a trash) the same way.
WP_Fake::$posts[90] = $rpDated(90);
$rp('content.update', ['apply_id' => 'rp-13', 'kind' => 'post', 'id' => 90, 'fields' => ['post_status' => 'draft']]);
$rp('apply.revert', ['apply_id' => 'rp-13']);
check('a status change and its revert leave the modified date as it was', WP_Fake::$posts[90], $rpDated(90));
// A later change that the revert keeps is a change since then: the post stays dated now.
WP_Fake::$posts[91] = $rpDated(91);
$rp('content.update', ['apply_id' => 'rp-14', 'kind' => 'post', 'id' => 91, 'fields' => ['post_title' => 'Card two']]);
$rp('content.update', ['apply_id' => 'rp-15', 'kind' => 'post', 'id' => 91, 'fields' => ['post_content' => '<p>Later</p>']]);
$rpRevert = $rp('apply.revert', ['apply_id' => 'rp-14']);
check('with a later edit kept, the revert does not date the post back', [$rpRevert['ok'] ?? null, WP_Fake::$posts[91]['post_title'], WP_Fake::$posts[91]['post_content'], WP_Fake::$posts[91]['post_modified'] !== '2026-01-02 03:04:05'],
    [true, 'Card', '<p>Later</p>', true]);

// A create's undo is still the delete, featured image and all.
$rpNew = $rp('content.update', ['apply_id' => 'rp-11', 'kind' => 'post', 'id' => 0, 'fields' => ['post_title' => 'Fresh', 'featured_image_id' => 22]]);
$rpNewId = (int) ($rpNew['id'] ?? 0);
check('a create sets its featured image', WP_Fake::$meta[$rpNewId . ':_thumbnail_id'] ?? null, 22);
$rpRevert = $rp('apply.revert', ['apply_id' => 'rp-11']);
check('reverting a create deletes the post and its featured image', [$rpRevert['ok'] ?? null, isset(WP_Fake::$posts[$rpNewId]), array_key_exists($rpNewId . ':_thumbnail_id', WP_Fake::$meta)], [true, false, false]);
WP_Fake::reset();
