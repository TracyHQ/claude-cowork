<?php
/**
 * apply.revert takes back ONE receipt: the span its write changed, never the whole row.
 *
 * Measured 29/09/2026 on the Tracy stand (comment → edit, WP L2): reverting receipt 1 of three
 * restored the whole `post_content` it had saved, so the image block receipt 3 had changed in the
 * same post went back too, silently. These cases pin the rule that replaced it.
 */
declare(strict_types=1);

echo "\napply.revert: one receipt, one change\n";

$sWriter = new FakeSiteWriter();
$sLog = new FakeApplyLog();
$sEngine = new Engine($WTOKEN, [], null, null, null, null, $sWriter, new FakeMediaWriter(), $sLog);
$s = static function (string $action, array $params) use ($sEngine, $WTOKEN): array {
    return $sEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
};

$heading = '<!-- wp:heading --><h2 class="wp-block-heading">Our services</h2><!-- /wp:heading -->';
$filler = "\n\n<!-- wp:paragraph --><p>We build and repair appliances for homes and offices across the region.</p><!-- /wp:paragraph -->\n\n";
$image = '<!-- wp:image {"id":87} --><figure class="wp-block-image"><img src="/a.jpg" alt="Old alt" class="wp-image-87"/></figure><!-- /wp:image -->';
$body = $heading . $filler . $image;
$sWriter->store['post'][42] = ['post_title' => 'Home', 'post_content' => $body];

// Two receipts on one post, as content.replace sends them: the whole post_content each time.
$r1 = $s('content.update', ['apply_id' => 'replace-1', 'kind' => 'post', 'id' => 42,
    'fields' => ['post_content' => str_replace('Our services', 'What we do', $body)]]);
check('receipt 1 lands', $r1['ok'], true);
$afterOne = $sWriter->read('post', 42)['post_content'];
$r3 = $s('content.update', ['apply_id' => 'replace-3', 'kind' => 'post', 'id' => 42,
    'fields' => ['post_content' => str_replace('Old alt', 'A repaired washing machine', $afterOne)]]);
check('receipt 3 lands', $r3['ok'], true);

$entry = $sLog->entries('replace-1')[0];
check('a receipt records the span it changed, not only the row', $entry['undo'] ?? null, 'span');
check('the span is the words that changed', [$entry['changes']['post_content']['old'], $entry['changes']['post_content']['new']], ['Our services', 'What we do']);
check('with 32 bytes around it as they stood after the write', [$entry['changes']['post_content']['pre'], $entry['changes']['post_content']['post']],
    ['--><h2 class="wp-block-heading">', '</h2><!-- /wp:heading -->' . "\n\n" . '<!-- ']);
check('apply.list says the undo is a span', $s('apply.list', ['apply_id' => 'replace-1'])['steps'][0]['undo'], 'span');

$back1 = $s('apply.revert', ['apply_id' => 'replace-1']);
check('reverting receipt 1 after receipt 3 is allowed', [$back1['ok'], $back1['reverted']], [true, 1]);
$now = $sWriter->read('post', 42);
checkTrue('receipt 1\'s words are back', str_contains($now['post_content'], 'Our services'));
checkTrue('receipt 3\'s change is kept', str_contains($now['post_content'], 'alt="A repaired washing machine"'));
check('and nothing else in the post moved', $now['post_content'], str_replace('Old alt', 'A repaired washing machine', $body));
check('the title the write never named is untouched', $now['post_title'], 'Home');
check('the reverted receipt is forgotten', $sLog->entries('replace-1'), []);

$back3 = $s('apply.revert', ['apply_id' => 'replace-3']);
check('receipt 3 then reverts on its own', $back3['ok'], true);
check('and the post is as it began', $sWriter->read('post', 42)['post_content'], $body);

// Reverted after its own words were edited since: refused, nothing written, receipt kept.
$s('content.update', ['apply_id' => 'replace-4', 'kind' => 'post', 'id' => 42,
    'fields' => ['post_content' => str_replace('Our services', 'What we do', $body)]]);
$edited = str_replace('What we do', 'What we really do', $sWriter->read('post', 42)['post_content']);
$s('content.update', ['apply_id' => 'replace-5', 'kind' => 'post', 'id' => 42, 'fields' => ['post_content' => $edited]]);
$refused = $s('apply.revert', ['apply_id' => 'replace-4']);
check('a revert whose words were edited since is refused', [$refused['ok'], $refused['error'], $refused['code']], [false, 'conflict', 'CONFLICT']);
check('with the Joomla shape: one CONFLICT in errors[]', $refused['errors'][0]['code'], 'CONFLICT');
check('it says what changed', $refused['message'], 'The words this change wrote in post 42 (post_content) were changed since; nothing was reverted');
check('it names the field and the reason', [$refused['target']['field'], $refused['target']['reason']], ['post_content', 'edited']);
checkTrue('and names a way on', str_contains($refused['next'], 'content.update'));
check('the post is untouched', $sWriter->read('post', 42)['post_content'], $edited);
check('the receipt is kept', count($sLog->entries('replace-4')), 1);
$s('apply.revert', ['apply_id' => 'replace-5']);
check('newest first still works: the later edit comes out', $sWriter->read('post', 42)['post_content'], str_replace('Our services', 'What we do', $body));
$s('apply.revert', ['apply_id' => 'replace-4']);
check('and then the earlier one', $sWriter->read('post', 42)['post_content'], $body);

// Twins: the same words in two blocks whose surroundings are identical for more than 32 bytes, both
// changed by two receipts; then a third receipt moves every byte after the top of the post.
$line = '<!-- wp:paragraph --><p>Our phone line is open every day.</p><!-- /wp:paragraph -->';
$twin = $line . '<p>Call us</p>' . $line . '<p>Call us</p>' . $line;
$sWriter->store['post'][43] = ['post_content' => $twin];
$oneRing = $line . '<p>Ring us</p>' . $line . '<p>Call us</p>' . $line;
$twoRing = $line . '<p>Ring us</p>' . $line . '<p>Ring us</p>' . $line;
$s('content.update', ['apply_id' => 'twin-1', 'kind' => 'post', 'id' => 43, 'fields' => ['post_content' => $oneRing]]);
$s('content.update', ['apply_id' => 'twin-2', 'kind' => 'post', 'id' => 43, 'fields' => ['post_content' => $twoRing]]);
$s('content.update', ['apply_id' => 'twin-3', 'kind' => 'post', 'id' => 43, 'fields' => ['post_content' => '<h2>Hi</h2>' . $twoRing]]);
$twinRefused = $s('apply.revert', ['apply_id' => 'twin-1']);
check('words found twice and neither where the write put them: refused', [$twinRefused['error'], $twinRefused['target']['reason']], ['conflict', 'ambiguous']);
check('it says so', $twinRefused['message'], 'The words this change wrote appear more than once in post 43 (post_content), and none of them is where it put them; nothing was reverted');
check('and the post is untouched', $sWriter->read('post', 43)['post_content'], '<h2>Hi</h2>' . $twoRing);
$s('apply.revert', ['apply_id' => 'twin-3']);
check('with the later insert gone, the twin at the recorded place is the one reverted', $s('apply.revert', ['apply_id' => 'twin-1'])['ok'], true);
check('so the first block is back and the second keeps receipt 2', $sWriter->read('post', 43)['post_content'], $line . '<p>Call us</p>' . $line . '<p>Ring us</p>' . $line);

// A change in the NEXT 32 bytes counts as touching this one: refused rather than guessed.
$sWriter->store['post'][47] = ['post_content' => '<p>Call us</p><p>Call us</p>'];
$s('content.update', ['apply_id' => 'near-1', 'kind' => 'post', 'id' => 47, 'fields' => ['post_content' => '<p>Ring us</p><p>Call us</p>']]);
$s('content.update', ['apply_id' => 'near-2', 'kind' => 'post', 'id' => 47, 'fields' => ['post_content' => '<p>Ring us</p><p>Ring us</p>']]);
check('an edit within the recorded context refuses the earlier revert', $s('apply.revert', ['apply_id' => 'near-1'])['target']['reason'] ?? null, 'edited');

// Receipts from before this change: a whole row, no span.
$sWriter->store['post'][44] = ['post_title' => 'About', 'post_content' => '<p>New words</p>'];
$sLog->record('legacy-1', ['op' => 'content', 'kind' => 'post', 'id' => 44, 'key' => '', 'before' => ['post_title' => 'About', 'post_content' => '<p>Old words</p>']]);
check('an old receipt reads as a row undo', $s('apply.list', ['apply_id' => 'legacy-1'])['steps'][0]['undo'], 'row');
$s('content.update', ['apply_id' => 'after-legacy', 'kind' => 'post', 'id' => 44, 'fields' => ['post_title' => 'About us']]);
$legacyRefused = $s('apply.revert', ['apply_id' => 'legacy-1']);
check('an old receipt with a later receipt on the same post is refused', [$legacyRefused['error'], $legacyRefused['code']], ['conflict', 'CONFLICT']);
check('with Joomla\'s words, and which apply came later', $legacyRefused['message'], 'Later content exists; revert the latest change first: apply after-legacy changed post 44 after this one');
check('it lists the later applies', $legacyRefused['target']['later'], ['after-legacy']);
checkTrue('and says to revert that one first', str_contains($legacyRefused['next'], 'apply.revert {apply_id:"after-legacy"}'));
check('nothing moved', $sWriter->read('post', 44), ['post_title' => 'About us', 'post_content' => '<p>New words</p>']);
$s('apply.revert', ['apply_id' => 'after-legacy']);
$legacyAlone = $s('apply.revert', ['apply_id' => 'legacy-1']);
check('an old receipt with nothing later on its post restores the row, as before', [$legacyAlone['ok'], $sWriter->read('post', 44)], [true, ['post_title' => 'About', 'post_content' => '<p>Old words</p>']]);

// A later receipt on ANOTHER post does not block an old receipt.
$sWriter->store['post'][45] = ['post_content' => '<p>Now</p>'];
$sLog->record('legacy-2', ['op' => 'content', 'kind' => 'post', 'id' => 45, 'key' => '', 'before' => ['post_content' => '<p>Then</p>']]);
$s('content.update', ['apply_id' => 'elsewhere', 'kind' => 'post', 'id' => 42, 'fields' => ['post_title' => 'Home page']]);
check('a later change to another post does not block an old receipt', $s('apply.revert', ['apply_id' => 'legacy-2'])['ok'], true);
check('which restores its own post', $sWriter->read('post', 45)['post_content'], '<p>Then</p>');
$s('apply.revert', ['apply_id' => 'elsewhere']);

// A create is a whole-row undo too: deleting it would drop a later receipt's edit of it.
$made = $s('content.update', ['apply_id' => 'make', 'kind' => 'post', 'fields' => ['post_title' => 'Draft', 'post_content' => '<p>a</p>']]);
$s('content.update', ['apply_id' => 'edit-made', 'kind' => 'post', 'id' => $made['id'], 'fields' => ['post_content' => '<p>b</p>']]);
check('a create a later receipt edited is not deleted under it', $s('apply.revert', ['apply_id' => 'make'])['error'], 'conflict');
$s('apply.revert', ['apply_id' => 'edit-made']);
check('once that edit is reverted, the create reverts', [$s('apply.revert', ['apply_id' => 'make'])['ok'], $sWriter->read('post', $made['id'])], [true, null]);

// Alt text in postmeta and words in the post: separate rows, separate receipts, either order.
$sWriter->store['postmeta']['87:_wp_attachment_image_alt'] = ['value' => 'Old alt'];
$s('content.update', ['apply_id' => 'alt-1', 'kind' => 'postmeta', 'id' => 87, 'key' => '_wp_attachment_image_alt', 'fields' => ['value' => 'A washing machine']]);
$s('content.update', ['apply_id' => 'words-1', 'kind' => 'post', 'id' => 42, 'fields' => ['post_content' => str_replace('Our services', 'What we do', $body)]]);
check('an alt receipt is a span too', $sLog->entries('alt-1')[0]['undo'], 'span');
$s('apply.revert', ['apply_id' => 'alt-1']);
check('reverting the alt puts the alt back', $sWriter->read('postmeta', 87, '_wp_attachment_image_alt'), ['value' => 'Old alt']);
checkTrue('and leaves the words of the post as they are', str_contains($sWriter->read('post', 42)['post_content'], 'What we do'));
$s('content.update', ['apply_id' => 'alt-2', 'kind' => 'postmeta', 'id' => 87, 'key' => '_wp_attachment_image_alt', 'fields' => ['value' => 'A dryer']]);
$s('apply.revert', ['apply_id' => 'words-1']);
check('reverting the words leaves the alt', $sWriter->read('postmeta', 87, '_wp_attachment_image_alt'), ['value' => 'A dryer']);
check('and the words are back', $sWriter->read('post', 42)['post_content'], $body);
$s('content.update', ['apply_id' => 'alt-3', 'kind' => 'postmeta', 'id' => 87, 'key' => '_wp_attachment_image_alt', 'fields' => ['value' => 'A tumble dryer']]);
check('an alt changed again since is refused, like the words', $s('apply.revert', ['apply_id' => 'alt-2'])['target']['reason'] ?? null, 'edited');
check('and the alt stays', $sWriter->read('postmeta', 87, '_wp_attachment_image_alt'), ['value' => 'A tumble dryer']);

// A title changed by one receipt, the body by the next: each comes back alone.
$s('content.update', ['apply_id' => 'title-1', 'kind' => 'post', 'id' => 42, 'fields' => ['post_title' => 'Welcome home']]);
$s('content.update', ['apply_id' => 'body-1', 'kind' => 'post', 'id' => 42, 'fields' => ['post_content' => $body . '<p>More</p>']]);
$s('apply.revert', ['apply_id' => 'title-1']);
check('the title comes back and the later body stays', [$sWriter->read('post', 42)['post_title'], $sWriter->read('post', 42)['post_content']], ['Home', $body . '<p>More</p>']);

// A value that is not a string is kept whole: it comes back only while it is what the write left.
$sWriter->store['menuItem'][301] = ['title' => 'Services', 'position' => 2];
$s('content.update', ['apply_id' => 'move-1', 'kind' => 'menuItem', 'id' => 301, 'fields' => ['position' => 5]]);
check('a number is recorded whole', $sLog->entries('move-1')[0]['changes']['position'], ['was' => 2, 'now' => 5]);
$s('content.update', ['apply_id' => 'rename-1', 'kind' => 'menuItem', 'id' => 301, 'fields' => ['title' => 'What we do']]);
$s('apply.revert', ['apply_id' => 'move-1']);
check('and reverts without touching the later rename', $sWriter->read('menuItem', 301), ['title' => 'What we do', 'position' => 2]);

// One apply, two writes to one post (a batch): both come out, newest first.
$sWriter->store['post'][46] = ['post_content' => '<p>Call us</p><p>Ring us</p>'];
$s('content.update', ['apply_id' => 'batch-1', 'kind' => 'post', 'id' => 46, 'fields' => ['post_content' => '<p>One</p><p>Ring us</p>']]);
$s('content.update', ['apply_id' => 'batch-1', 'kind' => 'post', 'id' => 46, 'fields' => ['post_content' => '<p>One</p><p>Two</p>']]);
$batch = $s('apply.revert', ['apply_id' => 'batch-1']);
check('a batch of writes to one post reverts whole', [$batch['reverted'], $sWriter->read('post', 46)['post_content']], [2, '<p>Call us</p><p>Ring us</p>']);
