<?php
/**
 * Content API v1: the block projection on real Tracy Business markup, the reader protocol
 * (query, cursor, budget, partial) and the door (method, auth, headers) over an in-memory source.
 * What a real WordPress answers is measured on a site, not here.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/BlockProjection.php';
require_once __DIR__ . '/../lib/ContentReader.php';
require_once __DIR__ . '/../lib/ContentDoor.php';

// ── BlockProjection on the home page of tracy-business/wp7 1.1.0 ─────────────────────────────

$home = json_decode((string) file_get_contents(__DIR__ . '/fixtures/content/post-42.json'), true);
$projected = BlockProjection::project($home['blocks']);
$sections = array_values(array_filter($projected['entries'], static fn($e) => $e['kind'] === 'section'));
check('home: every top-level section is keyed by the names it holds',
    array_column($sections, 'key'), ['hero', 'services', 'projects', 'text', 'cta', 'news', 'gallery']);
check('home: nothing is left unkeyed', $projected['unkeyed'], 0);
$hero = $sections[0];
check('a paragraph is a text field under its metadata.name', $hero['fields'][0], ['key' => 'hero.eyebrow', 'type' => 'text', 'value' => "Established\u{00a0}2004"]);
check('text holding markup is html, not stripped', $hero['fields'][1]['type'], 'html');
check('a button gives its text and its link', array_column(array_slice($hero['fields'], 3, 2), 'key'), ['hero.cta.1', 'hero.cta.1:url']);
check('a named group of named words is an item', array_column($hero['items'], 'key'), ['hero.item.1', 'hero.item.2', 'hero.item.3', 'hero.item.4']);
check('an item holds its own words', $hero['items'][0]['fields'][1], ['key' => 'hero.item.1.text', 'type' => 'text', 'value' => 'Projects delivered']);
check('an image names its attachment and the alt it renders', [$hero['images'][0]['attachment'], $hero['images'][0]['fieldKey'], $hero['images'][0]['itemKey']], [91, 'hero.img', null]);
checkTrue('static html keeps the words', strpos($projected['bodyHtml'], 'Projects delivered') !== false);
checkTrue('static html drops every block comment', strpos($projected['bodyHtml'], '<!-- wp:') === false);

$header = json_decode((string) file_get_contents(__DIR__ . '/fixtures/content/post-80.json'), true);
$headerProjected = BlockProjection::project($header['blocks']);
check('header: two navigations are shared pointers, named', array_map(static fn($e) => [$e['kind'], $e['key'], $e['ref']['type']], $headerProjected['entries']),
    [['shared', 'nav.topbar', 'navigation'], ['shared', 'nav.tracy', 'navigation']]);
check('header: its unnamed groups are counted, not given index ids', $headerProjected['unkeyed'], 2);

$synced = BlockProjection::project([
    ['blockName' => 'core/block', 'attrs' => ['ref' => 7], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
    ['blockName' => 'core/template-part', 'attrs' => ['slug' => 'footer', 'theme' => 'x'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
    ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>Loose words</p>', 'innerContent' => ['<p>Loose words</p>']],
]);
check('a synced pattern and a template part are pointers, an unnamed paragraph is unkeyed',
    [$synced['entries'][0]['ref'], $synced['entries'][1]['ref'], $synced['unkeyed']],
    [['type' => 'wp_block', 'id' => 7], ['type' => 'template-part', 'slug' => 'footer', 'theme' => 'x'], 1]);
check('a dynamic block contributes no static html', BlockProjection::staticHtml([
    ['blockName' => 'core/latest-posts', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
]), '');

$links = BlockProjection::navigationItems([
    ['blockName' => 'core/navigation-link', 'attrs' => ['label' => 'Home', 'id' => 42, 'type' => 'page', 'url' => '/'], 'innerBlocks' => []],
    ['blockName' => 'core/navigation-submenu', 'attrs' => ['label' => 'More', 'url' => 'https://x.test/'], 'innerBlocks' => [
        ['blockName' => 'core/navigation-link', 'attrs' => ['label' => 'Home again', 'id' => 42, 'type' => 'page'], 'innerBlocks' => []],
    ]],
]);
check('navigation links keep their order and targets', array_column($links, 'targetId'), [42, null, 42]);
check('two links to one page are told apart without an index', array_column($links, 'key'), ['post:42#1', 'url:https://x.test/|More#1', 'post:42#2']);

// ── ContentReader over an in-memory source ───────────────────────────────────────────────────

final class FakeContentSource implements ContentSource
{
    public $revision = 'rev-1';
    public $contents = [];
    public $unresolved = [];

    public function __construct(int $n)
    {
        for ($i = 0; $i < $n; $i++) {
            $id = sprintf('c%03d', $i);
            $this->contents[$id] = [
                'id' => $id, 'type' => $i % 2 ? 'article' : 'page', 'title' => 'T' . $i, 'slug' => 's' . $i,
                'url' => 'https://site.test/s' . $i . '/', 'locale' => $i % 3 ? 'en-US' : 'vi', 'translationGroupId' => null,
                'summary' => null, 'publishedAt' => null, 'createdAt' => null, 'updatedAt' => null, 'revision' => 'r' . $i,
                'publication' => ['status' => 'published', 'valueSource' => 'published', 'scheduledAt' => null],
                'detailState' => 'complete', 'links' => ['self' => '/content.json?id=' . $id],
                'bodyHtml' => null, 'tags' => [], 'fields' => [], 'blocks' => [], 'images' => [], 'relations' => [],
            ];
        }
    }

    public function site(): array
    {
        return ['id' => 's-test', 'name' => 'Test', 'url' => 'https://site.test', 'defaultLocale' => 'en-US', 'locales' => ['en-US', 'vi']];
    }

    public function provenance(): ?array
    {
        return null;
    }

    public function revision(): string
    {
        return $this->revision;
    }

    public function summaries(): array
    {
        $out = [];
        foreach ($this->contents as $c) {
            foreach (['bodyHtml', 'tags', 'fields', 'blocks', 'images', 'relations'] as $k) {
                unset($c[$k]);
            }
            $c['detailState'] = 'summary';
            $out[] = $c;
        }
        return $out;
    }

    public $released = 0;

    public function detail(string $id, bool $withBody = true): ?array
    {
        $c = $this->contents[$id] ?? null;
        if ($c !== null && !$withBody) {
            $c['bodyHtml'] = null;
        }
        return $c;
    }

    public function release(): void
    {
        $this->released++;
    }

    public function unresolved(): array
    {
        return $this->unresolved;
    }
}

$SECRET = str_repeat('k', 32);
$source = new FakeContentSource(75);
$reader = static fn(string $principal = 'site-token', string $scope = 'published', int $now = 1000, int $max = ContentReader::MAX_BYTES)
    => new ContentReader($source, $GLOBALS['SECRET'], $principal, $scope, $now, $max);
$status = static function (callable $fn) {
    try {
        $fn();
        return 'no error';
    } catch (ContentReadError $e) {
        return $e->status . ' ' . $e->reason;
    }
};

$first = $reader()->read([]);
check('a listing defaults to 30 summaries', [count($first['contents']), $first['pagination']['limit'], $first['pagination']['total']], [30, 30, 75]);
check('a summary carries no heavy field', array_key_exists('blocks', $first['contents'][0]) || array_key_exists('bodyHtml', $first['contents'][0]), false);
checkTrue('a listing with more has a cursor', is_string($first['pagination']['nextCursor']));
check('the envelope says the scope it covers', $first['completeness'], ['scope' => 'supported-content', 'status' => 'complete', 'unresolved' => []]);

$seen = array_column($first['contents'], 'id');
$cursor = $first['pagination']['nextCursor'];
while ($cursor !== null) {
    $page = $reader()->read(['cursor' => $cursor]);
    $seen = array_merge($seen, array_column($page['contents'], 'id'));
    $cursor = $page['pagination']['nextCursor'];
}
check('a scan returns every content once, in order', $seen, array_keys($source->contents));

$filtered = $reader()->read(['type' => 'page', 'locale' => 'vi', 'limit' => '5']);
check('filters AND', array_unique(array_map(static fn($c) => $c['type'] . '/' . $c['locale'], $filtered['contents'])), ['page/vi']);
$next = $reader()->read(['cursor' => $filtered['pagination']['nextCursor']]);
check('a cursor keeps its filters when they are not repeated', array_unique(array_map(static fn($c) => $c['type'] . '/' . $c['locale'], $next['contents'])), ['page/vi']);
check('a cursor accepts its own filters repeated', count($reader()->read(['cursor' => $filtered['pagination']['nextCursor'], 'type' => 'page'])['contents']), 5);
check('a cursor refuses a different filter', $status(static fn() => $reader()->read(['cursor' => $filtered['pagination']['nextCursor'], 'type' => 'article'])), '400 CONTENT_BAD_QUERY');

foreach ([
    'unknown name' => ['token' => 'x'],
    'limit 0' => ['limit' => '0'],
    'limit 101' => ['limit' => '101'],
    'limit NaN' => ['limit' => 'NaN'],
    'limit padded' => ['limit' => '05'],
    'empty value' => ['type' => ''],
    'unknown type' => ['type' => 'post'],
    'undeclared locale' => ['locale' => 'fr'],
    'id with list cursor' => ['id' => 'c001', 'cursor' => $first['pagination']['nextCursor']],
    'id with a filter' => ['id' => 'c001', 'type' => 'page'],
    'blocksCursor without id' => ['blocksCursor' => 'x.y'],
    'forged cursor' => ['cursor' => 'eyJ2IjoxfQ.' . str_repeat('0', 64)],
] as $name => $query) {
    check('400 for ' . $name, $status(static fn() => $reader()->read($query)), '400 CONTENT_BAD_QUERY');
}
foreach (['duplicate name' => 'type=page&type=page', 'array name' => 'type[]=page', 'empty pair' => 'a=1&&b=2'] as $name => $raw) {
    check('parseQuery refuses a ' . $name, $status(static fn() => ContentReader::parseQuery($raw)), '400 CONTENT_BAD_QUERY');
}
check('parseQuery decodes', ContentReader::parseQuery('locale=en-US&cursor=a%2Eb'), ['locale' => 'en-US', 'cursor' => 'a.b']);

$tampered = $first['pagination']['nextCursor'];
$tampered[3] = $tampered[3] === 'A' ? 'B' : 'A';
check('a cursor with one byte changed is refused', $status(static fn() => $reader()->read(['cursor' => $tampered])), '400 CONTENT_BAD_QUERY');
check('another principal cannot use it', $status(static fn() => $reader('someone-else')->read(['cursor' => $first['pagination']['nextCursor']])), '400 CONTENT_BAD_QUERY');
check('another scope cannot use it', $status(static fn() => $reader('site-token', 'editorial')->read(['cursor' => $first['pagination']['nextCursor']])), '400 CONTENT_BAD_QUERY');
check('a cursor past its five minutes is expired', $status(static fn() => $reader('site-token', 'published', 1000 + ContentReader::TTL)->read(['cursor' => $first['pagination']['nextCursor']])), '409 CONTENT_SNAPSHOT_EXPIRED');
$source->revision = 'rev-2';
check('a cursor from before a change is expired', $status(static fn() => $reader()->read(['cursor' => $first['pagination']['nextCursor']])), '409 CONTENT_SNAPSHOT_EXPIRED');
$source->revision = 'rev-1';
check('another secret (a new token) voids a cursor', $status(static fn() => (new ContentReader($source, str_repeat('z', 32), 'site-token', 'published', 1000))->read(['cursor' => $first['pagination']['nextCursor']])), '400 CONTENT_BAD_QUERY');

check('an unknown id is 404', $status(static fn() => $reader()->read(['id' => 'nope'])), '404 CONTENT_NOT_FOUND');
check('itemsCursor is declared unsupported, not ignored', $status(static fn() => $reader()->read(['id' => 'c001', 'itemsCursor' => 'b'])), '501 CONTENT_ADAPTER_UNSUPPORTED');
check('an unknown blockId is 404', $status(static fn() => $reader()->read(['id' => 'c001', 'blockId' => 'b'])), '404 CONTENT_NOT_FOUND');
check('blockId without id is 400', $status(static fn() => $reader()->read(['blockId' => 'b'])), '400 CONTENT_BAD_QUERY');
$releasedBefore = $source->released;
$status(static fn() => $reader()->read(['id' => 'nope']));
check('every read, refused or not, releases the source', $source->released, $releasedBefore + 1);
$detail = $reader()->read(['id' => 'c001']);
check('a detail is one complete content', [count($detail['contents']), $detail['contents'][0]['detailState'], $detail['pagination']], [1, 'complete', ['limit' => 1, 'nextCursor' => null, 'total' => 1]]);

// A content with many blocks is cut to the budget, and the pieces put back in order.
$blocks = [];
for ($b = 0; $b < 250; $b++) {
    $blocks[] = ['id' => 'b' . $b, 'key' => 'k' . $b, 'role' => null, 'position' => $b, 'sharedContentId' => null, 'visibility' => 'unknown',
        'fields' => [['key' => 'text', 'type' => 'text', 'value' => str_repeat('w', 900), 'slotKey' => null, 'semanticKey' => null]], 'items' => []];
}
$source->contents['c002']['blocks'] = $blocks;
$source->contents['c002']['images'] = [
    ['id' => 'm1', 'src' => '/a.png', 'alt' => null, 'width' => null, 'height' => null, 'usages' => [['contentId' => 'c002', 'blockId' => 'b0', 'itemId' => null], ['contentId' => 'c002', 'blockId' => 'b249', 'itemId' => null]]],
];
$gathered = [];
$pages = 0;
$images = [];
$query = ['id' => 'c002'];
do {
    $page = $reader('site-token', 'published', 1000, 65536)->read($query)['contents'][0];
    $pages++;
    checkTrue('page ' . $pages . ' fits the budget', strlen(ContentReader::encode($page)) <= 65536);
    $gathered = array_merge($gathered, array_column($page['blocks'], 'position'));
    foreach ($page['images'] as $image) {
        $images[] = array_column($image['usages'], 'blockId');
    }
    $query = $page['detailState'] === 'partial' ? ['id' => 'c002', 'blocksCursor' => $page['blocksPagination']['nextCursor']] : null;
    if ($page['detailState'] === 'partial') {
        checkTrue('a partial page links to the rest', strpos($page['links']['next'], '/content.json?id=c002&blocksCursor=') === 0);
    }
} while ($query !== null && $pages < 50);
checkTrue('a large detail takes several pages', $pages > 1);
check('blocks come back whole, in absolute order', $gathered, range(0, 249));
check('each page carries the image usages of its own blocks', $images, [['b0'], ['b249']]);
$source->contents['c002']['bodyHtml'] = str_repeat('h', ContentReader::MAX_BYTES);
$one = $reader()->read(['id' => 'c002', 'blockId' => 'b5'])['contents'][0];
check('a block read of a content whose body is over budget: one block, partial, no body',
    [array_column($one['blocks'], 'id'), $one['detailState'], array_key_exists('bodyHtml', $one), array_key_exists('fields', $one)], [['b5'], 'partial', false, false]);
check('and it names the next block', $one['links']['next'], '/content.json?id=c002&blockId=b6');
check('its images are the ones that block uses', $one['images'], []);
check('the block read names itself', $one['links']['self'], '/content.json?id=c002&blockId=b5');
$last = $reader()->read(['id' => 'c002', 'blockId' => 'b249'])['contents'][0];
check('the last block points back at the content', [$last['links']['next'], array_column($last['images'][0]['usages'], 'blockId')], ['/content.json?id=c002', ['b249']]);
check('blockId with blocksCursor is 400', $status(static fn() => $reader()->read(['id' => 'c002', 'blockId' => 'b5', 'blocksCursor' => 'x.y'])), '400 CONTENT_BAD_QUERY');
check('the full detail of that content is still 413 on bodyHtml', $status(static fn() => $reader()->read(['id' => 'c002'])), '413 CONTENT_FIELD_TOO_LARGE');
$source->contents['c002']['bodyHtml'] = null;
check('a blocks cursor for another id is refused', $status(static function () use ($reader) {
    $c = $reader('site-token', 'published', 1000, 65536)->read(['id' => 'c002'])['contents'][0]['blocksPagination']['nextCursor'];
    $reader('site-token', 'published', 1000, 65536)->read(['id' => 'c001', 'blocksCursor' => $c]);
}), '400 CONTENT_BAD_QUERY');

$source->contents['c004']['bodyHtml'] = str_repeat('x', ContentReader::MAX_BYTES);
try {
    $reader()->read(['id' => 'c004']);
    check('an oversized scalar is 413', 'no error', '413');
} catch (ContentReadError $e) {
    check('an oversized scalar is 413 naming the field, never truncated', [$e->status, $e->body()['error']['field']], [413, ['contentId' => 'c004', 'blockId' => null, 'itemId' => null, 'key' => 'bodyHtml']]);
}

// ── ContentDoor ──────────────────────────────────────────────────────────────────────────────

$TOKEN = 'a-site-token-of-24-bytes';
$built = 0;
$factory = static function (string $principal, string $scope, string $secret) use ($source, &$built) {
    $built++;
    return new ContentReader($source, $secret, $principal, $scope, 1000);
};
$get = static fn(string $method, ?string $auth, string $q = '', ?string $token = null) => ContentDoor::get($method, $auth, $q, $token ?? $TOKEN, $factory);

$anon = $get('GET', null);
check('no credential: 401 with a bearer challenge', [$anon['status'], $anon['headers']['WWW-Authenticate'], $anon['body']['error']['code']], [401, 'Bearer realm="content"', 'CONTENT_UNAUTHENTICATED']);
check('a wrong token is the same 401 body', $get('GET', 'Bearer wrong-token-wrong-token')['body'], $anon['body']);
check('a token in the query is not read', $get('GET', null, 'token=' . $TOKEN)['status'], 401);
check('a lowercase scheme is not a bearer', $get('GET', 'bearer ' . $TOKEN)['status'], 401);
check('a site with no token configured answers 401, not "off"', $get('GET', 'Bearer ' . $TOKEN, '', '')['body'], $anon['body']);
check('an anonymous request builds no reader', $built, 0);
check('a token in the query is refused after auth', $get('GET', 'Bearer ' . $TOKEN, 'token=' . $TOKEN)['status'], 400);
check('POST is not the GET door', [$get('POST', 'Bearer ' . $TOKEN)['status'], $get('POST', 'Bearer ' . $TOKEN)['headers']['Allow']], [405, 'GET']);
$ok = $get('GET', 'Bearer ' . $TOKEN, 'limit=2');
check('an authenticated GET answers the listing', [$ok['status'], count($ok['body']['contents'])], [200, 2]);
check('every answer is private and not stored', [$ok['headers']['Cache-Control'], $anon['headers']['Cache-Control']], ['private, no-store, max-age=0', 'private, no-store, max-age=0']);
checkTrue('no answer carries an ETag', !isset($ok['headers']['ETag']) && !isset($anon['headers']['ETag']));
$broken = ContentDoor::get('GET', 'Bearer ' . $TOKEN, '', $TOKEN, static function () {
    throw new RuntimeException('SQLSTATE[HY000] secret detail');
});
check('a source failure is 503, never an empty listing', [$broken['status'], $broken['body']['error']['code']], [503, 'CONTENT_SOURCE_UNAVAILABLE']);
checkTrue('and does not leak the failure text', strpos(json_encode($broken['body']), 'SQLSTATE') === false);
$gotCursor = $ok['body']['pagination']['nextCursor'];
checkTrue('the GET cursor holds no token', strpos(base64_decode(strtr(explode('.', $gotCursor)[0], '-_', '+/')), $TOKEN) === false);
check('the POST action reads the same listing', ContentDoor::action(['query' => ['limit' => '2']], $TOKEN, $factory)['content']['contents'], $ok['body']['contents']);
check('the POST action refuses an unknown scope', ContentDoor::action(['scope' => 'admin'], $TOKEN, $factory)['status'], 400);
check('a GET cursor is not an editorial cursor', ContentDoor::action(['scope' => 'editorial', 'query' => ['cursor' => $gotCursor]], $TOKEN, $factory)['status'], 400);

$seatA = ContentDoor::action(['principal' => 'seat:alpha-0001', 'query' => ['limit' => '2']], $TOKEN, $factory);
check('a relayed seat reads under its own principal', $seatA['status'], 200);
check('another seat cannot continue that seat\'s cursor', ContentDoor::action(['principal' => 'seat:bravo-0002', 'query' => ['cursor' => $seatA['content']['pagination']['nextCursor']]], $TOKEN, $factory)['status'], 400);
check('the site token cannot continue it either', ContentDoor::action(['query' => ['cursor' => $seatA['content']['pagination']['nextCursor']]], $TOKEN, $factory)['status'], 400);
check('the same seat can', ContentDoor::action(['principal' => 'seat:alpha-0001', 'query' => ['cursor' => $seatA['content']['pagination']['nextCursor']]], $TOKEN, $factory)['status'], 200);
foreach (['a principal that is not a seat label' => ['principal' => 'admin'], 'an empty seat label' => ['principal' => 'seat:'],
    'an unknown parameter' => ['role' => 'owner'], 'a query that is not a map' => ['query' => 'limit=2']] as $name => $params) {
    check('content.read refuses ' . $name, ContentDoor::action($params, $TOKEN, $factory)['status'], 400);
}
