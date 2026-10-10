<?php
/**
 * The words blocks keep in their attributes, in the site's language (lib/BlockWords.php, TracyHQ/tch#1013). Measured
 * 10/10/2026 on dev g59-ess-wp-full (JA Essence WP 1.0.12, vi): "Read more" on 9 pages (`moreText` of core/post-excerpt
 * and `content` of core/read-more, 8 of each in the release DB), "Search" in the header (core/search label and button),
 * and dates "Th6 09, 2023" from a post-date block's own `format` "M d, Y". The spellings below are the ones the
 * published release DBs hold ("Read more", "Read more ...", "Read more …", "By ", "Search the site").
 *
 * Loaded by run.php after string-overrides.php (uses contractSite(), `$SITE`, `$FIXTURES`, its add_filter stub).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/BlockWords.php';

echo "\nBlock words\n";

// WordPress's own vi translation of the words it has (core vi.po): "Read more" and "Search"; it has no "Read more ...".
$bwCore = static fn(string $words): string => ['Read more' => 'Xem thêm', 'Search' => 'Tìm kiếm', 'By' => 'Bởi'][$words] ?? $words;
$bwBlock = static fn(string $name, array $attrs): array => ['blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
$bwAttrs = static fn(array $block): array => $block['attrs'];

check('core translation: an excerpt\'s "Read more" reads in the site language',
    $bwAttrs(BlockWords::translateBlock($bwBlock('core/post-excerpt', ['excerptLength' => 200, 'moreText' => 'Read more']), 'vi', [], $bwCore)),
    ['excerptLength' => 200, 'moreText' => 'Xem thêm']);
check('the site map wins, for a spelling WordPress has no translation of',
    $bwAttrs(BlockWords::translateBlock($bwBlock('core/read-more', ['content' => 'Read more ...']), 'vi', ['Read more ...' => 'Đọc thêm ...'], $bwCore)),
    ['content' => 'Đọc thêm ...']);
check('without either, the words stay as they are (never emptied)',
    $bwAttrs(BlockWords::translateBlock($bwBlock('core/read-more', ['content' => 'Read more …']), 'vi', [], $bwCore)), ['content' => 'Read more …']);
check('the spaces around the words are kept: "By " becomes "Bởi "',
    [$bwAttrs(BlockWords::translateBlock($bwBlock('core/post-author-name', ['isLink' => false, 'prefix' => 'By ']), 'vi', [], $bwCore))['prefix'],
        $bwAttrs(BlockWords::translateBlock($bwBlock('core/post-author-name', ['prefix' => 'By ']), 'vi', ['By' => 'Viết bởi'], $bwCore))['prefix']],
    ['Bởi ', 'Viết bởi ']);
check('a search\'s label, placeholder and button, each',
    $bwAttrs(BlockWords::translateBlock($bwBlock('core/search', ['label' => 'Search', 'showLabel' => false, 'placeholder' => 'Search the site', 'buttonText' => 'Search']), 'vi',
        ['Search the site' => 'Tìm trên trang'], $bwCore)),
    ['label' => 'Tìm kiếm', 'showLabel' => false, 'placeholder' => 'Tìm trên trang', 'buttonText' => 'Tìm kiếm']);
check('a block or attribute outside the reviewed pairs is untouched: navigation is content, a social name is a brand',
    [$bwAttrs(BlockWords::translateBlock($bwBlock('core/navigation-link', ['label' => 'Search']), 'vi', ['Search' => 'x'], $bwCore)),
        $bwAttrs(BlockWords::translateBlock($bwBlock('core/social-link', ['service' => 'x', 'label' => 'Twitter']), 'vi', ['Twitter' => 'x'], $bwCore)),
        $bwAttrs(BlockWords::translateBlock($bwBlock('core/search', ['className' => 'Search']), 'vi', ['Search' => 'x'], $bwCore))],
    [['label' => 'Search'], ['service' => 'x', 'label' => 'Twitter'], ['className' => 'Search']]);
check('a date block\'s English-order format goes on a vi site, so the site\'s date format applies',
    $bwAttrs(BlockWords::translateBlock($bwBlock('core/post-date', ['format' => 'M d, Y', 'isLink' => true]), 'vi', [], $bwCore)), ['isLink' => true]);
check('a day-first or numeric format stays, and an English site keeps its own',
    [$bwAttrs(BlockWords::translateBlock($bwBlock('core/post-date', ['format' => 'd/m/Y']), 'vi', [], $bwCore)),
        $bwAttrs(BlockWords::translateBlock($bwBlock('core/post-date', ['format' => 'F j, Y']), 'en_US', [], $bwCore)),
        $bwAttrs(BlockWords::translateBlock($bwBlock('core/comment-date', ['format' => 'F j, Y']), 'de_DE', [], $bwCore))],
    [['format' => 'd/m/Y'], ['format' => 'F j, Y'], []]);

// Found: the release DB's spellings, where they are and how often, from post markup.
$bwFound = BlockWords::found([
    '<!-- wp:post-excerpt {"excerptLength":200,"moreText":"Read more"} /--><!-- wp:read-more {"content":"Read more ..."} /-->',
    '<!-- wp:post-excerpt {"moreText":"Read more"} /--><!-- wp:navigation-link {"label":"Typography"} /--><!-- wp:search {"label":"Search","placeholder":"Search the site","buttonText":"Search"} /-->',
    '<!-- wp:read-more {"content":"Read more …"} /--><!-- wp:post-author-name {"isLink":false,"prefix":"By "} /--><!-- wp:post-excerpt {"moreText":""} /-->',
]);
check('found: every source words in the reviewed pairs, most used first, nothing else',
    array_values(array_map(static fn(array $r): array => [$r['source'], $r['count'], $r['pairs']], $bwFound)), [
        ['Read more', 2, ['core/post-excerpt moreText']],
        ['Search', 2, ['core/search label', 'core/search buttonText']],
        ['By', 1, ['core/post-author-name prefix']],
        ['Read more ...', 1, ['core/read-more content']],
        ['Read more …', 1, ['core/read-more content']],
        ['Search the site', 1, ['core/search placeholder']],
    ]);

// The filter is on every request.
WP_Fake::$filters = [];
BlockWords::register();
check('the filter is registered on render_block_data', isset(WP_Fake::$filters['render_block_data']), true);

// The door: content.contract {operation:'blockWords.read' | 'blockWords.set'}, on a bound quickstart site.
$bwSite = contractSite($SITE, $FIXTURES, false, 'test-design/wp7/1.0.0');
$bwEngine = $bwSite['engine'];
$bwDoor = static fn(string $operation, array $params) => $bwEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
$bwEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'bind']]);
WP_Fake::$posts[26]['post_content'] = '<!-- wp:post-excerpt {"moreText":"Read more"} /--><!-- wp:read-more {"content":"Read more ..."} /-->';
$read = $bwDoor('blockWords.read', ['locale' => 'vi']);
check('read: the reviewed pairs, the words found on the site, and the map (none yet)',
    [$read['ok'] ?? $read, $read['pairs'] ?? null, array_map(static fn(array $r): array => [$r['source'], $r['count'], $r['core']], $read['found'] ?? []), (array) ($read['words'] ?? null)],
    [true, BlockWords::PAIRS, [['Read more', 1, null], ['Read more ...', 1, null]], []]);
check('read needs a locale', $bwDoor('blockWords.read', [])['error'] ?? null, 'bad_params');
$set = $bwDoor('blockWords.set', ['apply_id' => 'bw-1', 'locale' => 'vi', 'words' => ['Read more ...' => 'Đọc thêm ...', 'Search the site' => 'Tìm trên trang']]);
check('set writes the map for the locale', [$set['ok'] ?? $set, BlockWords::decode(WP_Fake::$options[BlockWords::OPTION] ?? '')],
    [true, ['vi' => ['Read more ...' => 'Đọc thêm ...', 'Search the site' => 'Tìm trên trang']]]);
check('under its apply id, as a content write of the option', array_map(static fn($e) => [$e['op'], $e['kind'], $e['key'], $e['before']], $bwSite['log']->entries('bw-1')),
    [['content', 'option', BlockWords::OPTION, null]]);
check('read now names the map', (array) ($bwDoor('blockWords.read', ['locale' => 'vi'])['words'] ?? null), ['Read more ...' => 'Đọc thêm ...', 'Search the site' => 'Tìm trên trang']);
$bwDoor('blockWords.set', ['apply_id' => 'bw-2', 'locale' => 'vi', 'words' => ['Search the site' => '']]);
check('an empty value removes one', BlockWords::decode(WP_Fake::$options[BlockWords::OPTION] ?? ''), ['vi' => ['Read more ...' => 'Đọc thêm ...']]);
check('the same words again write nothing', $bwDoor('blockWords.set', ['apply_id' => 'bw-3', 'locale' => 'vi', 'words' => ['Read more ...' => 'Đọc thêm ...']])['unchanged'] ?? null, true);
$bwEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'bw-2']]);
$bwEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'bw-1']]);
check('revert: taking both back leaves no option', array_key_exists(BlockWords::OPTION, WP_Fake::$options), false);
foreach ([
    'no apply_id' => ['locale' => 'vi', 'words' => ['Read more' => 'x']],
    'a contract- apply id' => ['apply_id' => 'contract-1', 'locale' => 'vi', 'words' => ['Read more' => 'x']],
    'a bad locale' => ['apply_id' => 'bw-x', 'locale' => 'vietnamese', 'words' => ['Read more' => 'x']],
    'a list, not a map' => ['apply_id' => 'bw-x', 'locale' => 'vi', 'words' => ['x']],
    'markup' => ['apply_id' => 'bw-x', 'locale' => 'vi', 'words' => ['Read more' => '<b>Xem</b>']],
    'two lines' => ['apply_id' => 'bw-x', 'locale' => 'vi', 'words' => ['Read more' => "Xem\nthêm"]],
] as $why => $params) {
    check('set refuses ' . $why, $bwDoor('blockWords.set', $params)['error'] ?? null, 'bad_params');
}
check('content.update cannot reach the option', $bwEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'bw-y', 'kind' => 'option', 'key' => BlockWords::OPTION, 'fields' => ['value' => '{}']]])['ok'] ?? null, false);
