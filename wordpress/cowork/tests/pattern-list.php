<?php
/**
 * `content.list {kind: "pattern"}`: the block patterns registered on the site, a page at a time,
 * narrowed by words or by category.
 *
 * A block theme builds pages out of patterns (`<!-- wp:pattern {"slug":"…"} /-->`), and an agent
 * putting a section on a page has to know which ones there are before content.get can read one. The
 * engine's half (paging, filters, the byte ceiling) runs over the in-memory writer; the writer's half
 * (what the registry holds, mapped to rows) runs the REAL writer over the WordPress stubs.
 *
 * Loaded by run.php (uses `$WTOKEN`).
 */
declare(strict_types=1);

echo "\nBlock patterns listed: content.list kind pattern\n";

/** One writer row as listPatterns() answers it. */
function plRow(string $name, string $title, array $categories = [], string $description = '', array $keywords = [], bool $inserter = true, int $chars = 10): array
{
    return ['name' => $name, 'title' => $title, 'description' => $description, 'categories' => $categories, 'keywords' => $keywords, 'inserter' => $inserter, 'chars' => $chars];
}

$plWriter = new FakeSiteWriter();
$plEngine = new Engine($WTOKEN, [], null, null, null, null, $plWriter, null, new FakeApplyLog());
$pl = static fn (array $plParams) => $plEngine->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => ['kind' => 'pattern'] + $plParams]);

$plWriter->patterns = [
    plRow('tracy/pricing', 'Pricing', ['tracy-pages'], 'Three plans side by side', ['plans', 'cost'], true, 812),
    plRow('tracy/hero', 'Hero', ['banner', 'featured'], 'A big headline over a photo', ['header'], true, 340),
];
$plAll = $pl([]);
check('patterns are listed with the five fields a caller picks one by, in name order', $plAll, [
    'ok' => true,
    'kind' => 'pattern',
    'items' => [
        ['name' => 'tracy/hero', 'title' => 'Hero', 'categories' => ['banner', 'featured'], 'description' => 'A big headline over a photo', 'chars' => 340],
        ['name' => 'tracy/pricing', 'title' => 'Pricing', 'categories' => ['tracy-pages'], 'description' => 'Three plans side by side', 'chars' => 812],
    ],
    'matched' => 2,
    'offset' => 0,
    'limit' => 50,
]);

$plWriter->patterns[] = plRow('tracy/hidden-part', 'Hidden part', ['banner'], 'Only inserted by a template', [], false);
check('a pattern registered with inserter => false is not listed, nor counted',
    [array_column($pl([])['items'], 'name'), $pl([])['matched']], [['tracy/hero', 'tracy/pricing'], 2]);

// search: one substring of the name, title, description or a keyword, whatever its case
$plWriter->patterns = [
    plRow('tracy/hero', 'Hero', ['banner', 'featured'], 'A big headline over a photo', ['header']),
    plRow('tracy/pricing', 'Pricing', ['tracy-pages'], 'Three plans side by side', ['plans', 'cost']),
    plRow('tracy/team', 'Our TEAM', ['about'], 'Faces and names', ['people']),
    plRow('core/quote', 'Quote', ['text'], 'Ünicode quote', ['testimonial']),
];
$plNames = static fn (array $plR): array => array_column($plR['items'] ?? [], 'name');
check('search finds a name', $plNames($pl(['search' => 'pric'])), ['tracy/pricing']);
check('a title, whatever the case', $plNames($pl(['search' => 'team'])), ['tracy/team']);
check('a description', $plNames($pl(['search' => 'SIDE BY'])), ['tracy/pricing']);
check('a keyword', $plNames($pl(['search' => 'testimonial'])), ['core/quote']);
check('a letter beyond ASCII, whatever the case', $plNames($pl(['search' => 'ünicode'])), ['core/quote']);
$plFound = $pl(['search' => 'tracy/']);
check('the answer echoes the words and counts what matched', [$plFound['search'] ?? null, $plFound['matched'] ?? null, $plNames($plFound)],
    ['tracy/', 3, ['tracy/hero', 'tracy/pricing', 'tracy/team']]);
check('words that match nothing are an empty page, not an error', [$pl(['search' => 'nothing like it'])['items'] ?? null, $pl(['search' => 'nothing like it'])['matched'] ?? null], [[], 0]);
check('a search is cleaned as a post search is: trimmed, and echoed as matched', $pl(['search' => "  Hero\n"])['search'] ?? null, 'Hero');
check('words empty once cleaned filter nothing, and say they were read', [$pl(['search' => '   '])['search'] ?? null, $pl(['search' => '   '])['matched'] ?? null], ['', 4]);
check('no search, no echo', array_key_exists('search', $pl([])), false);

// category: one category slug, exactly
$plByCat = $pl(['category' => 'banner']);
check('category keeps the patterns filed under that slug, and is echoed', [$plNames($plByCat), $plByCat['category'] ?? null, $plByCat['matched'] ?? null], [['tracy/hero'], 'banner', 1]);
check('exactly: no part of a slug, no other case', [$plNames($pl(['category' => 'bann'])), $plNames($pl(['category' => 'Banner']))], [[], []]);
check('with a search, both must hold', [$plNames($pl(['category' => 'banner', 'search' => 'photo'])), $plNames($pl(['category' => 'banner', 'search' => 'plans']))], [['tracy/hero'], []]);
check('no category, no echo', array_key_exists('category', $pl([])), false);

// paging: offset and limit, as a post list pages; `matched` counts every row past the filters
$plWriter->patterns = [];
for ($plI = 1; $plI <= 230; $plI++) {
    $plWriter->patterns[] = plRow(sprintf('tracy/section-%03d', $plI), "Section {$plI}");
}
$plPage = $pl([]);
check('a page holds 50 rows unless the caller names a limit', [count($plPage['items']), $plPage['limit'], $plPage['matched']], [50, 50, 230]);
$plPage = $pl(['offset' => 49, 'limit' => 2]);
check('offset and limit cut the page', [$plNames($plPage), $plPage['offset'], $plPage['limit']], [['tracy/section-050', 'tracy/section-051'], 49, 2]);
check('a limit of 200 is 200 rows', [count($pl(['limit' => 200])['items']), $pl(['limit' => 200])['limit']], [200, 200]);
check('a limit past 200 is 200, and the answer says so', [count($pl(['limit' => 500])['items']), $pl(['limit' => 500])['limit']], [200, 200]);
check('a limit below 1 is 1', $pl(['limit' => 0])['limit'], 1);
check('a limit sent as digits is read as the number', $pl(['limit' => '3'])['limit'], 3);
check('an offset past the end is an empty page with the count', [$pl(['offset' => 230])['items'], $pl(['offset' => 230])['matched']], [[], 230]);

// a parameter of the wrong type is refused, never read as its default
foreach ([
    'a search that is not a string' => ['search' => 7],
    'a search that is a list' => ['search' => ['hero']],
    'a limit in words' => ['limit' => 'fifty'],
    'a null search' => ['search' => null],
    'a category that is not a string' => ['category' => ['banner']],
    'an empty category' => ['category' => '  '],
    'an offset that is not a number' => ['offset' => 'ten'],
    'an offset that is a list' => ['offset' => [1]],
    'a limit with a fraction' => ['limit' => 1.5],
    'a limit that is true' => ['limit' => true],
] as $plWhat => $plParams) {
    $plR = $pl($plParams);
    check("{$plWhat} is refused", [$plR['ok'] ?? null, $plR['error'] ?? null, array_key_exists('items', $plR)], [false, 'bad_params', false]);
}

// the byte ceiling: a page of long descriptions is cut at a whole row, and says so
$plWriter->patterns = [];
for ($plI = 1; $plI <= 120; $plI++) {
    $plWriter->patterns[] = plRow(sprintf('tracy/long-%03d', $plI), "Long {$plI}", ['text'], str_repeat('d', 1000));
}
$plCut = $pl(['limit' => 200]);
$plCutBytes = strlen(json_encode($plCut['items']));
check('a page whose rows pass 64 KB holds only whole rows that fit, and says it was cut',
    [$plCut['truncated'] ?? null, count($plCut['items']) < 120, $plCutBytes <= 65536, $plCut['matched'], $plCut['limit']], [true, true, true, 120, 200]);
check('one more row would not have fit', $plCutBytes + 1 + strlen(json_encode([
    'name' => 'tracy/long-999', 'title' => 'Long 999', 'categories' => ['text'], 'description' => str_repeat('d', 1000), 'chars' => 10])) > 65536, true);
$plNext = $pl(['limit' => 200, 'offset' => count($plCut['items'])]);
check('the next page starts at the first row left out', $plNames($plNext)[0] ?? null, sprintf('tracy/long-%03d', count($plCut['items']) + 1));
$plSeen = [];
$plMarks = [];
for ($plAt = 0, $plGuard = 0; $plAt < 120 && $plGuard < 10; $plGuard++) {
    $plR = $pl(['limit' => 200, 'offset' => $plAt]);
    $plSeen = array_merge($plSeen, $plNames($plR));
    $plMarks[] = $plR['truncated'] ?? false;
    $plAt += count($plR['items']);
}
check('paging on by offset + rows reaches every row once, and only the last page is not marked',
    [count($plSeen), count(array_unique($plSeen)), array_pop($plMarks), array_unique($plMarks)], [120, 120, false, [true]]);
check('a page that fits carries no truncated', array_key_exists('truncated', $pl(['limit' => 10])), false);
$plWriter->patterns = [plRow('tracy/huge', 'Huge', [], str_repeat('h', 70000)), plRow('tracy/small', 'Small')];
$plHuge = $pl([]);
check('a row longer than the ceiling still comes, alone, so paging always moves on', [$plNames($plHuge), $plHuge['truncated'] ?? null], [['tracy/huge'], true]);

// an empty registry, and a writer from before this list
$plWriter->patterns = [];
check('no patterns registered: an empty list', [$pl([])['ok'] ?? null, $pl([])['items'] ?? null, $pl([])['matched'] ?? null], [true, [], 0]);
$plOld = new Engine($WTOKEN, [], null, null, null, null, new class implements SiteWriter {
    public function list_posts(int $offset, int $limit, bool $withBody, string $name = '', string $type = ''): array { return []; }
    public function read(string $plKind, int $id, string $key = ''): ?array { return null; }
    public function write(string $plKind, int $id, array $fields, string $key = ''): int { return 0; }
    public function editLock(int $postId): ?array { return null; }
    public function delete(string $plKind, int $id, string $key = ''): void {}
    public function canTrash(string $plKind): bool { return false; }
    public function trash(string $plKind, int $id): void {}
    public function purgeCache(): void {}
}, null, new FakeApplyLog());
check('a writer that cannot list patterns says so, never answers a list',
    $plOld->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => ['kind' => 'pattern']])['error'] ?? null, 'unavailable');
// The other kinds are refused exactly as 0.16.0 refused them, sentence included.
foreach (['option', 'postmeta', 'term', 'menuItem', 'page'] as $plKind) {
    check("content.list kind {$plKind} is refused as 0.16.0 refused it", $plEngine->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => ['kind' => $plKind]]), [
        'ok' => false,
        'error' => 'bad_params',
        'message' => "content.list serves kinds \"post\", \"templatePart\" and \"template\"; \"{$plKind}\" is not listed here. "
            . 'Read one with content.get {kind, id or key}, and use post_type to narrow posts.',
    ]);
}

// ── the real writer: the registry's patterns, mapped to rows ───────────────────────────────────────

WP_Fake::reset();
$plReal = new Claude_Cowork_Site_Writer();
WP_Fake::$patterns = [
    'tracy/hero' => ['name' => 'tracy/hero', 'title' => 'Hero', 'content' => '<h1>Café</h1>', 'description' => 'A big headline',
        'categories' => ['banner'], 'keywords' => ['header', 'intro']],
    'tracy/hidden' => ['name' => 'tracy/hidden', 'title' => 'Hidden', 'content' => '<p>x</p>', 'inserter' => false],
    'core/bare' => ['name' => 'core/bare', 'title' => 'Bare', 'content' => '<p>Hi</p>'],
];
check('the writer maps every registered pattern, counting its content in characters, not bytes', $plReal->listPatterns(), [
    ['name' => 'tracy/hero', 'title' => 'Hero', 'description' => 'A big headline', 'categories' => ['banner'], 'keywords' => ['header', 'intro'], 'inserter' => true, 'chars' => 13],
    ['name' => 'tracy/hidden', 'title' => 'Hidden', 'description' => '', 'categories' => [], 'keywords' => [], 'inserter' => false, 'chars' => 8],
    ['name' => 'core/bare', 'title' => 'Bare', 'description' => '', 'categories' => [], 'keywords' => [], 'inserter' => true, 'chars' => 9],
]);
WP_Fake::$patterns = [];
check('an empty registry is an empty list', $plReal->listPatterns(), []);
WP_Fake::reset();
