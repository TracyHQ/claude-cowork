<?php
/**
 * Content API v2 byte budget (`maxBytes`): a page of `content.read` is cut by whole contents to
 * the UTF-8 length of the JSON the reader returns; a first content that does not fit alone is sent
 * truncated, never refused as an empty page. Runs over an in-memory source (loaded by run.php
 * after content-api.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/ContentReader.php';

/** Contents whose listing rows carry fields, so the reader's cut is visible in a listing. */
final class BudgetContentSource implements ContentSource
{
    /** @var array<string,array<string,mixed>> */
    public $contents = [];

    /** @param array<string,int> $sizes content id => bytes of its long field */
    public function __construct(array $sizes)
    {
        foreach ($sizes as $id => $size) {
            $this->contents[$id] = [
                'id' => $id, 'type' => 'article', 'title' => 'Title ' . $id, 'slug' => $id, 'url' => 'https://site.test/' . $id . '/',
                'locale' => 'en-US', 'translationGroupId' => null, 'summary' => null, 'publishedAt' => null, 'createdAt' => null,
                'updatedAt' => null, 'revision' => 'rev-of-' . $id,
                'publication' => ['status' => 'published', 'valueSource' => 'published', 'scheduledAt' => null],
                'detailState' => 'summary', 'links' => ['self' => '/content.json?id=' . $id],
                'fields' => [
                    ['key' => 'eyebrow', 'type' => 'text', 'value' => str_repeat('e', 200), 'slotKey' => null, 'semanticKey' => null],
                    ['key' => 'body', 'type' => 'html', 'value' => '<p>' . str_repeat('w', $size) . '</p>', 'slotKey' => null, 'semanticKey' => null],
                    ['key' => 'more', 'type' => 'text', 'value' => str_repeat('m', 300), 'slotKey' => null, 'semanticKey' => null],
                ],
            ];
        }
    }

    public function site(): array
    {
        return ['id' => 's-budget', 'name' => 'Budget', 'url' => 'https://site.test', 'defaultLocale' => 'en-US', 'locales' => ['en-US']];
    }

    public function provenance(): ?array
    {
        return null;
    }

    public function revision(): string
    {
        return 'rev-budget';
    }

    public function summaries(): array
    {
        return array_values($this->contents);
    }

    public function detail(string $id, bool $withBody = true): ?array
    {
        if (!isset($this->contents[$id])) {
            return null;
        }
        $content = $this->contents[$id];
        $content['detailState'] = 'complete';
        return $content + ['bodyHtml' => null, 'tags' => [], 'blocks' => [], 'images' => [], 'relations' => []];
    }

    public function unresolved(): array
    {
        return [];
    }

    public function release(): void
    {
    }
}

$budgetSource = new BudgetContentSource(['c1' => 2000, 'c2' => 40000, 'c3' => 3000]);
$budgetReader = static fn() => new ContentReader($budgetSource, str_repeat('k', 32), 'site-token', 'published', 1000);
$budgetStatus = static function (callable $fn) {
    try {
        $fn();
        return 'no error';
    } catch (ContentReadError $e) {
        return $e->status . ' ' . $e->reason . ' ' . $e->getMessage();
    }
};

// ── conformance: 3 contents of ~2 KB, ~40 KB, ~3 KB at maxBytes 8192 ─────────────────────────

$page1 = $budgetReader()->read(['maxBytes' => '8192']);
check('budget p1: the first content only, whole', array_column($page1['contents'], 'id'), ['c1']);
check('budget p1: nothing is marked truncated', [isset($page1['contents'][0]['truncated']), array_column($page1['contents'][0]['fields'], 'truncated')], [false, []]);
check('budget p1: pagination names the budget and the whole total', [$page1['pagination']['budget'], $page1['pagination']['total']], [8192, 3]);
checkTrue('budget p1: fits the budget', strlen(ContentReader::encode($page1)) <= 8192);
checkTrue('budget p1: a cursor points on', is_string($page1['pagination']['nextCursor']));

$page2 = $budgetReader()->read(['cursor' => $page1['pagination']['nextCursor'], 'maxBytes' => 8192]);
$c2 = $page2['contents'][0] ?? [];
check('budget p2: the content that did not fit is sent alone, not skipped', array_column($page2['contents'], 'id'), ['c2']);
check('budget p2: the content is marked truncated', $c2['truncated'] ?? null, true);
$cut = array_values(array_filter($c2['fields'] ?? [], static fn($f) => ($f['truncated'] ?? false) === true));
check('budget p2: exactly the longest field is cut and marked', array_column($cut, 'key'), ['body']);
check('budget p2: to maxBytes/2 characters', mb_strlen((string) ($cut[0]['value'] ?? ''), 'UTF-8'), 4096);
check('budget p2: the other fields are whole', array_column(array_slice($c2['fields'] ?? [], 0, 1), 'value'), [str_repeat('e', 200)]);
check('budget p2: the revision is the content\'s own, not moved by the cut', $c2['revision'] ?? null, 'rev-of-c2');
checkTrue('budget p2: fits the budget', strlen(ContentReader::encode($page2)) <= 8192);
checkTrue('budget p2: a cursor points on', is_string($page2['pagination']['nextCursor']));

$page3 = $budgetReader()->read(['cursor' => $page2['pagination']['nextCursor'], 'maxBytes' => '8192']);
check('budget p3: the last content, whole, and no cursor', [array_column($page3['contents'], 'id'), $page3['pagination']['nextCursor'], isset($page3['contents'][0]['truncated'])], [['c3'], null, false]);
checkTrue('budget p3: fits the budget', strlen(ContentReader::encode($page3)) <= 8192);
$ids = array_merge(array_column($page1['contents'], 'id'), array_column($page2['contents'], 'id'), array_column($page3['contents'], 'id'));
check('budget: the ids over all pages are total, none twice', [count($ids), count(array_unique($ids))], [$page1['pagination']['total'], 3]);

// ── default, range, limit ────────────────────────────────────────────────────────────────────

$default = $budgetReader()->read([]);
check('budget: absent maxBytes is 65536, and all three fit', [$default['pagination']['budget'], array_column($default['contents'], 'id'), $default['pagination']['nextCursor']],
    [65536, ['c1', 'c2', 'c3'], null]);
foreach (['8191' => '8191', '262145' => '262145', 'zero' => '0', 'a fraction' => '8192.5', 'a word' => 'lots', 'padded' => '08192', 'negative' => '-9000'] as $name => $value) {
    check('budget: maxBytes ' . $name . ' is refused naming the range', $budgetStatus(static fn() => $budgetReader()->read(['maxBytes' => $value])),
        '400 CONTENT_BAD_QUERY maxBytes must be an integer from 8192 to 262144.');
}
check('budget: the bounds themselves are accepted', [$budgetReader()->read(['maxBytes' => 8192])['pagination']['budget'], $budgetReader()->read(['maxBytes' => '262144'])['pagination']['budget']], [8192, 262144]);
$limited = $budgetReader()->read(['limit' => '1', 'maxBytes' => '262144']);
check('budget: limit still stops a page first', [array_column($limited['contents'], 'id'), $limited['pagination']['limit'], is_string($limited['pagination']['nextCursor'])], [['c1'], 1, true]);
$limited2 = $budgetReader()->read(['cursor' => $limited['pagination']['nextCursor'], 'maxBytes' => '262144']);
check('budget: and the cursor keeps that limit beside a new budget', array_column($limited2['contents'], 'id'), ['c2']);
$wide = $budgetReader()->read(['cursor' => $page1['pagination']['nextCursor']]);
check('budget: maxBytes is not bound into the cursor — the next page may ask for more', [array_column($wide['contents'], 'id'), isset($wide['contents'][0]['truncated'])], [['c2', 'c3'], false]);

// ── one content by id ────────────────────────────────────────────────────────────────────────

$one = $budgetReader()->read(['id' => 'c2', 'maxBytes' => '8192']);
check('budget {id}: an oversized field is cut, not refused', [$one['contents'][0]['truncated'] ?? null, array_column(array_filter($one['contents'][0]['fields'], static fn($f) => $f['truncated'] ?? false), 'key')], [true, ['body']]);
checkTrue('budget {id}: fits the budget', strlen(ContentReader::encode($one)) <= 8192);
check('budget {id}: pagination names the budget', $one['pagination']['budget'], 8192);
$whole = $budgetReader()->read(['id' => 'c2']);
check('budget {id}: under the default the same content is whole', [isset($whole['contents'][0]['truncated']), strlen($whole['contents'][0]['fields'][1]['value'])], [false, 40007]);
$budgetSource->contents['c3']['fields'][1]['value'] = str_repeat('ệ', 30000);
$multi = $budgetReader()->read(['id' => 'c3', 'maxBytes' => '8192']);
checkTrue('budget {id}: a multibyte cut stays valid UTF-8 and within budget',
    mb_check_encoding($multi['contents'][0]['fields'][1]['value'], 'UTF-8') && strlen(ContentReader::encode($multi)) <= 8192);

// ── a caller that sends no maxBytes sees v1 ──────────────────────────────────────────────────

$v1 = new BudgetContentSource(['c1' => 2000, 'big' => 70000]);
$v1Reader = static fn() => new ContentReader($v1, str_repeat('k', 32), 'site-token', 'published', 1000);
$v1First = $v1Reader()->read([]);
check('no maxBytes: a listing still pages whole contents to 65536', [array_column($v1First['contents'], 'id'), $v1First['pagination']['budget']], [['c1'], 65536]);
check('no maxBytes: a listing row over the budget is the v1 413, never cut',
    $budgetStatus(static fn() => $v1Reader()->read(['cursor' => $v1First['pagination']['nextCursor']])), '413 CONTENT_FIELD_TOO_LARGE A content field exceeds the response budget.');
check('with maxBytes sent the same row is cut instead', $v1Reader()->read(['cursor' => $v1First['pagination']['nextCursor'], 'maxBytes' => '65536'])['contents'][0]['truncated'] ?? null, true);
$v1Whole = $v1Reader()->read(['id' => 'big']);
check('no maxBytes: an {id} read keeps the v1 budget and shape (no cut, no pagination.budget)',
    [isset($v1Whole['contents'][0]['truncated']), $v1Whole['pagination']], [false, ['limit' => 1, 'nextCursor' => null, 'total' => 1]]);
$v1->contents['big']['bodyHtml'] = str_repeat('h', ContentReader::MAX_BYTES);
$v1->contents['big']['blocks'] = [['id' => 'b0', 'key' => 'k0', 'role' => null, 'position' => 0, 'sharedContentId' => null, 'visibility' => 'unknown', 'fields' => [], 'items' => []]];
try {
    $v1Reader()->read(['id' => 'big']);
    check('no maxBytes: an oversized body is 413 with firstBlock', 'no error', '413');
} catch (ContentReadError $e) {
    check('no maxBytes: an oversized body is 413 with firstBlock', [$e->status, $e->body()['error']['field']['key'], isset($e->body()['error']['links']['firstBlock'])], [413, 'bodyHtml', true]);
}
$v1Cut = $v1Reader()->read(['id' => 'big', 'maxBytes' => '65536']);
check('maxBytes sent: the body and the long field are both cut (no more than half the budget each)',
    [$v1Cut['contents'][0]['truncated'] ?? null, strlen($v1Cut['contents'][0]['bodyHtml']) <= 32768, $v1Cut['contents'][0]['fields'][1]['truncated'] ?? null, $v1Cut['pagination']['budget']], [true, true, true, 65536]);
checkTrue('maxBytes sent: and fits it', strlen(ContentReader::encode($v1Cut)) <= 65536);
