<?php
/*
 * content.read `ids`: many details from ONE projection. Building the projection is nearly the whole
 * cost of a WordPress read (every row in scope, its permalinks and revisions: ~100 ms on the stand's
 * 950-row site, 27/09/2026, against 1-5 ms for a detail on top), and a relay indexing a site paid it
 * once per record. A batch answers each listed content exactly as `{id}` would, fills the byte budget
 * in the order asked, and names what it left out: `pending` (did not fit whole, pages its blocks, or
 * holds a value over budget: read it alone) and `missing` (no readable content now).
 * Loaded after content-natives.php (reuses $revSite, $idOf, $FIXTURES, WP_Fake_ContentDb).
 */
echo "\nMany details in one read (content.read ids)\n";

final class BatchContentSource implements ContentSource
{
    /** @var array<string,array<string,mixed>> */
    public $contents = [];
    public $details = 0;

    public function add(string $id, int $bytes, int $blocks = 1): void
    {
        $content = [
            'id' => $id, 'type' => 'article', 'title' => 'Title ' . $id, 'slug' => $id, 'url' => 'https://site.test/' . $id . '/',
            'locale' => 'en-US', 'translationGroupId' => null, 'summary' => null, 'publishedAt' => null, 'createdAt' => null,
            'updatedAt' => null, 'revision' => 'rev_' . $id,
            'publication' => ['status' => 'published', 'valueSource' => 'published', 'scheduledAt' => null],
            'detailState' => 'complete', 'links' => ['self' => '/content.json?id=' . $id],
            'bodyHtml' => str_repeat('b', $bytes), 'tags' => [], 'fields' => [], 'blocks' => [], 'relations' => [],
            'images' => [['id' => 'm_' . $id, 'src' => '/i.png', 'alt' => null, 'width' => null, 'height' => null,
                'usages' => [['contentId' => $id, 'blockId' => null, 'itemId' => null], ['contentId' => $id, 'blockId' => $id . '_block_0', 'itemId' => null]]]],
        ];
        for ($i = 0; $i < $blocks; $i++) {
            $content['blocks'][] = ['id' => $id . '_block_' . $i, 'key' => 'k' . $i, 'role' => null, 'position' => $i, 'sharedContentId' => null,
                'visibility' => 'unknown', 'fields' => [['key' => 'text', 'type' => 'text', 'value' => 'v' . $i, 'slotKey' => null, 'semanticKey' => null]], 'items' => []];
        }
        $this->contents[$id] = $content;
    }

    public function site(): array
    {
        return ['id' => 's-batch', 'name' => 'Batch', 'url' => 'https://site.test', 'defaultLocale' => 'en-US', 'locales' => ['en-US']];
    }

    public function provenance(): ?array
    {
        return null;
    }

    public function revision(): string
    {
        return 'rev-batch';
    }

    public function summaries(): array
    {
        return array_values($this->contents);
    }

    public function detail(string $id, bool $withBody = true): ?array
    {
        $this->details++;
        return $this->contents[$id] ?? null;
    }

    public function unresolved(): array
    {
        return [];
    }

    public function release(): void
    {
    }
}

$bt = new BatchContentSource();
$bt->add('c1', 100);
$bt->add('c2', 3000);
$bt->add('c3', 200);
$bt->add('c4', 50, 105);
$bt->add('c5', 9000);
$bt->add('huge', ContentReader::MAX_BYTES);
$btReader = static fn() => new ContentReader($bt, str_repeat('k', 32), 'site-token', 'published', 1000);
$btError = static function (callable $call): ?string {
    try {
        $call();
        return null;
    } catch (ContentReadError $e) {
        return $e->status . ' ' . $e->getMessage();
    }
};
$btIds = static fn(array $page): array => array_column($page['contents'], 'id');
$btBytes = static fn(array $page): int => strlen(ContentReader::encode($page));

$page = $btReader()->read(['ids' => ['c3', 'c1', 'c2']]);
check('batch answers the listed contents in the order asked', $btIds($page), ['c3', 'c1', 'c2']);
foreach (['c3', 'c1', 'c2'] as $at => $id) {
    check('batch: ' . $id . ' is exactly what a single read answers', $page['contents'][$at], $btReader()->read(['id' => $id])['contents'][0]);
}
check('batch: every content is complete, with its own revision',
    [array_values(array_unique(array_column($page['contents'], 'detailState'))), array_column($page['contents'], 'revision')], [['complete'], ['rev_c3', 'rev_c1', 'rev_c2']]);
check('batch: nothing pending, nothing missing, no cursor',
    [$page['pagination']['pending'], $page['pagination']['missing'], $page['pagination']['nextCursor']], [[], [], null]);
check('batch: the snapshot envelope of every read', [$page['schemaVersion'], $page['snapshot']['revision']], ['tracy-content/v1', 'rev-batch']);
check('batch with no budget named answers up to the detail ceiling', $page['pagination']['budget'], ContentReader::MAX_BYTES);
$bt->details = 0;
$btReader()->read(['ids' => ['c1', 'c2', 'c3']]);
check('batch builds each content once', $bt->details, 3);

check('batch over GET: ids is one comma-separated value', $btIds($btReader()->read(ContentReader::parseQuery('ids=' . rawurlencode('c1,c3')))), ['c1', 'c3']);
$door = ContentDoor::action(['params' => ['ids' => ['c2', 'c1']]], 'token', static fn() => $btReader());
check('batch through the POST door: ids is a list in params', [$door['status'], $btIds($door['body'])], [200, ['c2', 'c1']]);

$gone = $btReader()->read(['ids' => ['c1', 'gone', 'c3']]);
check('batch: an id with no content is missing, the rest are answered', [$btIds($gone), $gone['pagination']['missing'], $gone['pagination']['pending']], [['c1', 'c3'], ['gone'], []]);
$long = $btReader()->read(['ids' => ['c4', 'c1']]);
check('batch: a content whose blocks a single read pages is pending, the rest answered', [$btIds($long), $long['pagination']['pending']], [['c1'], ['c4']]);
check('batch: and that single read does page it', $btReader()->read(['id' => 'c4'])['contents'][0]['detailState'], 'partial');
$big = $btReader()->read(['ids' => ['huge', 'c1']]);
check('batch: a content a single read refuses (413) is pending, not an error', [$btIds($big), $big['pagination']['pending']], [['c1'], ['huge']]);

// The budget fills greedily in the order asked; what does not fit is pending, never cut.
$tight = $btReader()->read(['ids' => ['c1', 'c5', 'c2', 'c3'], 'maxBytes' => 8192]);
check('batch fills the budget in order and leaves out what does not fit', [$btIds($tight), $tight['pagination']['pending']], [['c1', 'c2', 'c3'], ['c5']]);
checkTrue('batch fits the budget it was given', $btBytes($tight) <= 8192);
check('batch states the budget it filled', $tight['pagination']['budget'], 8192);
check('batch never truncates a content', array_filter(array_map(static fn($c) => $c['truncated'] ?? false, $tight['contents'])), []);
check('batch: each content under a budget is still the whole single read', $tight['contents'][1], $btReader()->read(['id' => 'c2'])['contents'][0]);
$none = $btReader()->read(['ids' => ['c5'], 'maxBytes' => 8192]);
check('batch: a content that alone overflows is pending, not an empty refusal', [$btIds($none), $none['pagination']['pending']], [[], ['c5']]);
// A budget exactly the size of the answer fits it; one byte less leaves the last content out.
// (The last content stays well inside what its own `{id}` read accepts at that budget: a single read
// keeps a margin for its envelope, and the batch defers to it.)
$four = ['c1', 'c2', 'c3', 'c5'];
$size = $btBytes($btReader()->read(['ids' => $four, 'maxBytes' => 20000]));
checkTrue('batch fixture: the answer has as many digits as its budget', $size >= 10000 && $size < 20000);
check('batch: a budget of exactly the answer size fits it', $btIds($btReader()->read(['ids' => $four, 'maxBytes' => $size])), $four);
check('batch: one byte less leaves the last content pending', $btReader()->read(['ids' => $four, 'maxBytes' => $size - 1])['pagination']['pending'], ['c5']);
$many = [];
for ($i = 0; $i < 100; $i++) {
    $bt->add('m' . $i, 4000);
    $many[] = 'm' . $i;
}
$full = $btReader()->read(['ids' => $many]);
checkTrue('batch: 100 ids of 4 KB fill the 256 KB ceiling and no more', $btBytes($full) <= ContentReader::MAX_BYTES && $full['contents'] !== []);
check('batch: every id is answered or pending, none lost', count($full['contents']) + count($full['pagination']['pending']), 100);
check('batch: the pending ids are the tail, in order', $full['pagination']['pending'], array_slice($many, count($full['contents'])));

$bad = '400 ids must list 1 to 100 content ids.';
check('batch refuses an empty list', $btError(fn() => $btReader()->read(['ids' => []])), $bad);
check('batch refuses more than 100 ids', $btError(fn() => $btReader()->read(['ids' => array_map(static fn($i) => 'c' . $i, range(1, 101))])), $bad);
check('batch refuses a non-string id', $btError(fn() => $btReader()->read(['ids' => ['c1', 7]])), $bad);
check('batch refuses a map in place of a list', $btError(fn() => $btReader()->read(['ids' => ['a' => 'c1']])), $bad);
check('batch refuses an empty id in the GET form', $btError(fn() => $btReader()->read(['ids' => 'c1,,c3'])), $bad);
check('batch refuses an id listed twice', $btError(fn() => $btReader()->read(['ids' => ['c1', 'c1']])), '400 ids must not repeat an id.');
check('batch refuses a budget out of range', $btError(fn() => $btReader()->read(['ids' => ['c1'], 'maxBytes' => '100'])), '400 maxBytes must be an integer from 8192 to 262144.');
foreach (['id' => 'c1', 'type' => 'article', 'limit' => '2', 'cursor' => 'x', 'blockId' => 'b', 'blocksCursor' => 'x', 'locale' => 'en-US'] as $key => $value) {
    check('batch does not combine with ' . $key, $btError(fn() => $btReader()->read(['ids' => ['c1'], $key => $value])), '400 ids is read with maxBytes only.');
}
check('batch accepts a protocol announcement', $btIds($btReader()->read(['ids' => ['c1'], 'protocolVersions' => 'tracy-content/v1'])), ['c1']);

// ── the WordPress source: one snapshot, no writes, every record as its single read ────────────

// The stubs have no block parser (edit-lock.php reads a listing for that reason). An empty tree
// still walks every other part of a detail: template parts, relations, thumbnails, site fields.
if (!function_exists('parse_blocks')) {
    function parse_blocks($markup): array
    {
        return [];
    }
}
$previousBatchDb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new WP_Fake_ContentDb();
$revSite();
$wpReader = static fn() => new ContentReader(new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test'), str_repeat('k', 32), 'site-token', 'editorial', 1000);
$wpIds = array_column($wpReader()->read(['limit' => '100'])['contents'], 'id');
checkTrue('wp fixture: the site lists several contents', count($wpIds) >= 3);
$GLOBALS['wpdb']->queries = [];
$wpBatch = $wpReader()->read(['ids' => $wpIds]);
$wpQueries = $GLOBALS['wpdb']->queries;
check('wp: every listed content is answered, none pending or missing',
    [$btIds($wpBatch), $wpBatch['pagination']['pending'], $wpBatch['pagination']['missing']], [$wpIds, [], []]);
$same = true;
foreach ($wpIds as $at => $id) {
    $same = $same && $wpBatch['contents'][$at] === $wpReader()->read(['id' => $id])['contents'][0];
}
checkTrue('wp: each record is exactly what its single read answers', $same);
check('wp: one consistent snapshot for the whole batch',
    count(array_filter($wpQueries, static fn($q) => strpos($q, 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY') === 0)), 1);
check('wp: a batch writes nothing', array_values(array_filter($wpQueries, static fn($q) => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $q) === 1)), []);
$GLOBALS['wpdb'] = $previousBatchDb;
