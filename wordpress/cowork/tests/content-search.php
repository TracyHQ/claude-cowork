<?php
/**
 * `content.list` `search`: find a post by the words of its title (or slug) instead of paging.
 *
 * Runs the REAL engine and the REAL site writer. What stands in for WordPress is `WP_Query` and the
 * reads a row is described by (FakeWordPress.php), and a `$wpdb` (FakePostsDb.php) that READS the
 * statements the writer builds and answers from the fake posts: a pattern that forgot to escape `%`,
 * an OR that lost its parentheses, a missing ORDER BY or a wrong page shows up as a wrong answer here,
 * not as a string that happens to look right. That holds for the two SQL statements (the ids of a page,
 * and the count). The same handle answers what a real one answers about its columns (`get_col_charset()`)
 * and refuses what a real site refuses, in both ways it does: a character above U+FFFF compared with a
 * column that keeps three bytes is refused by MySQL or, for most collations, by `wpdb` itself before MySQL
 * is asked. A statement that should never have been sent is then a failure here, not a shorter answer.
 * The `WP_Query` that loads the page is different: it refuses the arguments it does not
 * know and the ones it would have to guess, and what it is asked is compared with what it should be
 * (`WP_Query::$lastArgs`), because a fake cannot show a wrong order or a filter left on.
 *
 * Loaded by run.php, last (uses `check()`, `checkTrue()`, `$WTOKEN`, `WP_Fake`, `FakeApplyLog`).
 */
declare(strict_types=1);

echo "\ncontent.list search\n";
require_once __DIR__ . '/FakePostsDb.php';

// `describe_post` asks for each row's address. An earlier test may have defined this already.
if (!function_exists('get_permalink')) {
    function get_permalink($post)
    {
        return 'https://site.test/?p=' . (is_object($post) ? $post->ID : $post);
    }
}
// `post_type => 'any'` means "every type not kept out of search"; this site has one custom type and
// a menu-item type that is kept out. Defined here, after the tests that read the real list of types.
if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names')
    {
        return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment', 'event' => 'event'];
    }
}

$csPreviousDb = $GLOBALS['wpdb'];
$csDb = new WP_Fake_PostsDb();
$GLOBALS['wpdb'] = $csDb;
$csWriter = new Claude_Cowork_Site_Writer();
$csEngine = new Engine($WTOKEN, [], null, null, null, null, $csWriter, null, new FakeApplyLog());
/** One `content.list` call. */
$csAsk = static fn (array $params): array => $csEngine->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => $params]);
/** The ids of the rows an answer lists, in the order it lists them. */
$csIds = static fn (array $answer): array => array_column($answer['items'] ?? [], 'id');
/** A fresh site: nothing but the rows the callback adds. */
$csSite = static function (callable $fill): void {
    WP_Fake::reset();
    $fill();
};
/** One post row; the slug is unrelated to the title unless given. */
$csPost = static function (int $id, string $title, array $more = []): void {
    WP_Fake::$posts[$id] = $more + [
        'ID' => $id,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => 'item-' . $id,
        'post_content' => '<p>Body ' . $id . '</p>',
    ];
};
/** What a search asked of the database: how many statements, and whether any of them wrote. */
$csStatements = static function () use ($csDb): array {
    return [count($csDb->queries), count(array_filter($csDb->queries, static fn (string $q): bool => preg_match('/^SELECT (ID|COUNT\(\*\)) FROM wp_posts WHERE /', $q) !== 1))];
};

// ── intl is optional on a WordPress host; the code must work without it ─────────────────────────
//
// Without `Normalizer` the words are matched in the spelling they were sent in. That is checked here,
// first, while the class is still absent; then a stand-in that knows the few letters these tests use
// is defined so the rest of the file runs the same on a machine without intl. Where intl exists
// (CI, most hosts) the real class is used throughout and this block does nothing.
$csIntl = class_exists('Normalizer');
if (!$csIntl) {
    $csSite(static function () use ($csPost): void {
        $csPost(41, "Nh\u{E0} h\u{E0}ng Vi\u{1EC7}t");
        $csPost(42, "Nha\u{300} ha\u{300}ng Vie\u{323}\u{302}t");
    });
    $r = $csAsk(['search' => "Vi\u{1EC7}t"]);
    check('without intl a composed needle finds the composed title only', [$csIds($r), $r['search'] ?? null], [[41], "Vi\u{1EC7}t"]);
    $r = $csAsk(['search' => "Vie\u{323}\u{302}t"]);
    check('and a decomposed needle is echoed as sent and finds the decomposed title only', [$csIds($r), $r['search'] ?? null], [[42], "Vie\u{323}\u{302}t"]);
    check('without intl the writer has one spelling of the words to try', Claude_Cowork_Site_Writer::search_forms("Vi\u{1EC7}t"), ["Vi\u{1EC7}t"]);

    /** A stand-in for intl's Normalizer that knows only the letters these tests use. */
    final class Normalizer
    {
        public const FORM_D = 4;
        public const FORM_C = 16;
        private const PAIRS = ["\u{E0}" => "a\u{300}", "\u{E9}" => "e\u{301}", "\u{1EC7}" => "e\u{323}\u{302}"];

        public static function normalize(string $input, int $form = self::FORM_C)
        {
            if (preg_match('//u', $input) !== 1) {
                return false;
            }
            return $form === self::FORM_D ? strtr($input, self::PAIRS) : strtr($input, array_flip(self::PAIRS));
        }
    }
    echo "  (no intl in this PHP: the rest runs on a stand-in Normalizer)\n";
} else {
    echo "  (intl is loaded: the real Normalizer is used)\n";
}

// ── the fixture most of the file reads ──────────────────────────────────────────────────────────

$csRoofSite = static function () use ($csPost): void {
    $csPost(1, 'Roof repair services', ['post_name' => 'roof-repair-services']);
    $csPost(2, 'Emergency roof repair', ['post_type' => 'page', 'post_name' => 'emergency-roof-repair']);
    $csPost(3, 'Gutter cleaning', ['post_name' => 'gutter-cleaning']);
    $csPost(4, 'About us', ['post_type' => 'page', 'post_name' => 'about-us']);
    $csPost(5, 'Roof inspection checklist', ['post_status' => 'draft', 'post_name' => 'roof-inspection-checklist']);
    $csPost(6, 'Roof warranty', ['post_type' => 'page', 'post_status' => 'private', 'post_name' => 'roof-warranty']);
    $csPost(7, 'Old roof repair prices', ['post_status' => 'trash', 'post_name' => 'old-roof-repair-prices']);
    $csPost(8, 'roof-repair.jpg', ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'roof-repair-jpg']);
    $csPost(9, 'ROOF REPAIR COST', ['post_name' => 'roof-repair-cost']);
    $csPost(10, 'Get a quote', ['post_name' => 'roof-repair-old']);
    $csPost(11, 'Why roof-repair matters', ['post_name' => 'why-roof-repair-matters']);
    $csPost(12, 'Plain', ['post_name' => 'plain', 'post_content' => '<p>zebra</p>', 'post_excerpt' => 'zebra']);
};
$csSite($csRoofSite);

// ── the words: trimmed, case-insensitive, titles and slugs, the states the plain list shows ─────

$r = $csAsk(['search' => ' Roof repair ']);
check('the words are trimmed, and found in a title in any case', $csIds($r), [1, 2, 9]);
check('control characters are removed and what is left is trimmed', $csAsk(['search' => "\n roof \t\x00"])['search'] ?? null, 'roof');
// A line break or a tab between words is where the words were wrapped or copied from a table: it separates them,
// so deleting it would join them (`roof\nrepair` searched as `roofrepair`, which finds nothing).
check('a line break or a tab between words is a space, not nothing', [
    $csAsk(['search' => "roof\nrepair"])['search'] ?? null,
    $csAsk(['search' => "roof\r\n\trepair"])['search'] ?? null,
    $csAsk(['search' => "roof\x0Brepair\x0C"])['search'] ?? null,
    $csAsk(['search' => "roof\u{85}repair"])['search'] ?? null,
], ['roof repair', 'roof repair', 'roof repair', 'roof repair']);
check('and it finds the title the words came from', [$csIds($csAsk(['search' => "Emergency roof\nrepair"])), $csIds($csAsk(['search' => "roof\n\nrepair"]))], [[2], [1, 2, 9]]);
check('the other control characters are still removed, and plain spaces stay as they were typed', [
    $csAsk(['search' => "roof\x00\nre\x01pair"])['search'] ?? null,
    $csAsk(['search' => 'roof  repair'])['search'] ?? null,
    $csAsk(['search' => "\nroof repair\n"])['search'] ?? null,
], ['roof repair', 'roof  repair', 'roof repair']);
check('the answer says what was matched, and how many rows hold it', [$r['search'] ?? null, $r['matched'] ?? null], ['Roof repair', 3]);
check('the answer has the plain list\'s keys, then the proof and the count, in that order', array_keys($r), ['ok', 'kind', 'offset', 'search', 'matched', 'items']);
check('it is a list of posts, from the top', [$r['ok'] ?? null, $r['kind'] ?? null, $r['offset'] ?? null], [true, 'post', 0]);
check('case does not matter either way', $csIds($csAsk(['search' => 'roof REPAIR'])), [1, 2, 9]);
check('a draft and a private page are found, the trash and an attachment are not', $csIds($csAsk(['search' => 'roof'])), [1, 2, 5, 6, 9, 10, 11]);
check('a slug holds words the title lacks', $csIds($csAsk(['search' => 'repair-old'])), [10]);
$r = $csAsk(['search' => 'roof-repair']);
check('a row holding the words in its title AND its slug is listed once', [$csIds($r), $r['matched'] ?? null], [[1, 2, 9, 10, 11], 5]);
check('the body and the excerpt are not searched', $csIds($csAsk(['search' => 'zebra'])), []);
check('a slug with a hyphen is not found by the same words with a space', $csIds($csAsk(['search' => 'repair old'])), []);
check('a post type narrows a search, and a slug hit of another type does not leak in', $csIds($csAsk(['search' => 'roof-repair', 'post_type' => 'page'])), [2]);
check('and so does the other type', $csIds($csAsk(['search' => 'roof', 'post_type' => 'post'])), [1, 5, 9, 10, 11]);
check('a slug named with a search is an exact slug, narrowed by the words', [$csIds($csAsk(['search' => 'about', 'name' => 'about-us'])), $csIds($csAsk(['search' => 'roof', 'name' => 'about-us']))], [[4], []]);
check('a slug is cleaned as the plain list cleans it', $csIds($csAsk(['search' => 'about', 'name' => 'About.Us'])), [4]);
check('a bad slug is still refused', $csAsk(['search' => 'about', 'name' => 'a b'])['error'] ?? null, 'bad_params');
check('a bad post type is still refused', $csAsk(['search' => 'about', 'post_type' => 'a b'])['error'] ?? null, 'bad_params');

// `matched` comes from a second statement, and the searches above never reach it: at the top of the list
// a page that is not full already tells the total. A page of one is always full, so each of these forces
// the count, over rows of another type, another state and another slug, which is what its WHERE has to
// keep apart (the trash row 7 and the attachment 8 above are in the table and in no answer).
$r = $csAsk(['search' => 'roof', 'limit' => 1]);
check('a page of one still counts every row that holds the words, in the states the list reads', [$csIds($r), $r['matched'] ?? null], [[1], 7]);
check('the count keeps to the post type asked for', $csAsk(['search' => 'roof', 'post_type' => 'page', 'limit' => 1])['matched'] ?? null, 2);
check('and to the exact slug named', $csAsk(['search' => 'roof', 'name' => 'roof-warranty', 'limit' => 1])['matched'] ?? null, 1);
check('a page past the end asks for the count too, and it keeps to the type', $csAsk(['search' => 'roof', 'post_type' => 'page', 'offset' => 5])['matched'] ?? null, 2);
check('and to the slug', $csAsk(['search' => 'roof', 'name' => 'roof-warranty', 'offset' => 5])['matched'] ?? null, 1);

$r = $csAsk(['search' => 'roof repair', 'include_body' => true]);
check('with bodies, each row carries its content and excerpt', [$r['items'][0]['content'] ?? null, array_key_exists('excerpt', $r['items'][0] ?? [])], ['<p>Body 1</p>', true]);
$csBare = $csAsk(['search' => 'roof repair'])['items'][0] ?? null;
check('without bodies it carries neither (and there is a row to look at)', [is_array($csBare), array_key_exists('content', $csBare ?? []), array_key_exists('excerpt', $csBare ?? [])], [true, false, false]);

// A row is what the plain list shows for that row, not a thinner copy of it.
$plainRows = [];
foreach ($csWriter->list_posts(0, 200, false) as $row) {
    $plainRows[$row['id']] = $row;
}
$found = $csAsk(['search' => 'roof'])['items'] ?? [];
check('every row is exactly the plain list\'s row for that post', [count($found), $found], [7, array_map(static fn (array $row): array => $plainRows[$row['id']], $found)]);
check('and the rows a search can reach are the plain list\'s: the trash and the attachment are in neither',
    array_values(array_diff(array_column($csWriter->list_posts(0, 200, false), 'id'), [3, 4, 12])), [1, 2, 5, 6, 9, 10, 11]);

// ── nothing to look for: the plain list, and the key still proves the parameter was read ────────

$plain = $csAsk([]);
foreach (['an empty string' => '', 'spaces' => '   ', 'a no-break space' => "\u{A0}", 'an ideographic space' => "\u{3000}\u{3000}", 'control characters' => "\x00\x1f\x7f", 'line breaks and tabs' => "\n\t \r\n"] as $what => $words) {
    $r = $csAsk(['search' => $words]);
    check("{$what}: the plain list, with the key echoed empty", [$r['search'] ?? 'absent', array_key_exists('matched', $r), $csIds($r)], ['', false, $csIds($plain)]);
}
check('the empty search keeps the list\'s keys and adds one', array_keys($csAsk(['search' => ''])), ['ok', 'kind', 'offset', 'search', 'items']);
check('and still pages', $csIds($csAsk(['search' => '', 'offset' => 2, 'limit' => 2])), array_slice($csIds($plain), 2, 2));

// ── without `search` the answer is what it always was ───────────────────────────────────────────

check('no search: the keys are the plain list\'s', array_keys($plain), ['ok', 'kind', 'offset', 'items']);
check('no search: the plain list as it was, ids in order (posts and pages; not the trash or an attachment)', $csIds($plain), [1, 2, 3, 4, 5, 6, 9, 10, 11, 12]);
check('no search: the answer starts with exactly these bytes', strpos(json_encode($plain), '{"ok":true,"kind":"post","offset":0,"items":[{"id":1,"type":"post","title":"Roof repair services","slug":"roof-repair-services","status":"publish","url":') === 0, true);
$csGolden = $plain['items'][0];
unset($csGolden['url']); // the address comes from a stub another test defines
check('no search: the first row, exact bytes', json_encode($csGolden),
    '{"id":1,"type":"post","title":"Roof repair services","slug":"roof-repair-services","status":"publish","parent":0,"menu_order":0,"comment_status":"closed",'
    . '"created":"0000-00-00 00:00:00","modified":"0000-00-00 00:00:00","author":{"id":0,"name":"","slug":""},"categories":[],"tags":[],"featured_image":null,'
    . '"template":"","seo":[],"in_menu":false,"checksum":"' . hash('sha256', "<p>Body 1</p>\0") . '"}');
check('no search: each row has the keys it always had, in that order', array_keys($plain['items'][0]),
    ['id', 'type', 'title', 'slug', 'status', 'url', 'parent', 'menu_order', 'comment_status', 'created', 'modified', 'author', 'categories', 'tags', 'featured_image', 'template', 'seo', 'in_menu', 'checksum']);
$csDb->queries = [];
foreach ([[], ['offset' => 1, 'limit' => 3], ['include_body' => true], ['post_type' => 'page'], ['name' => 'about-us'], ['kind' => 'post', 'limit' => 500], ['offset' => -5]] as $params) {
    $offset = max(0, (int) ($params['offset'] ?? 0));
    $body = !empty($params['include_body']);
    $limit = min($body ? 25 : 200, max(1, (int) ($params['limit'] ?? ($body ? 25 : 100))));
    $was = json_encode(['ok' => true, 'kind' => 'post', 'offset' => $offset, 'items' => $csWriter->list_posts($offset, $limit, $body, (string) ($params['name'] ?? ''), (string) ($params['post_type'] ?? ''))]);
    check('no search, byte for byte: ' . json_encode($params), json_encode($csAsk($params)), $was);
}
check('and a list with no search asks the database nothing itself', $csDb->queries, []);

// ── what is refused, never answered with the whole list ─────────────────────────────────────────

foreach (['an array' => ['roof'], 'a number' => 2024, 'a boolean' => true, 'null' => null, 'an object' => ['a' => 'b']] as $what => $value) {
    $r = $csAsk(['search' => $value]);
    check("search as {$what} is refused", [$r['ok'] ?? null, $r['error'] ?? null, array_key_exists('items', $r)], [false, 'bad_params', false]);
}
$r = $csAsk(['search' => "\xC3\x28"]);
check('text that is not UTF-8 is refused', [$r['ok'] ?? null, $r['error'] ?? null], [false, 'bad_params']);
check('200 characters are fine', [$csAsk(['search' => str_repeat('a', 200)])['ok'] ?? null, $csAsk(['search' => str_repeat('a', 200)])['matched'] ?? null], [true, 0]);
check('201 are refused', [$csAsk(['search' => str_repeat('a', 201)])['ok'] ?? null, $csAsk(['search' => str_repeat('a', 201)])['error'] ?? null], [false, 'bad_params']);
check('the limit counts characters, not bytes', [$csAsk(['search' => str_repeat('根', 200)])['ok'] ?? null, $csAsk(['search' => str_repeat('根', 201)])['ok'] ?? null], [true, false]);
check('spaces around 200 characters do not count', $csAsk(['search' => '  ' . str_repeat('a', 200) . "\t "])['ok'] ?? null, true);
check('a pile of padding is refused rather than cleaned', $csAsk(['search' => str_repeat(' ', 5000) . 'x'])['error'] ?? null, 'bad_params');
$csDb->queries = [];
$r = $csAsk(['search' => ['x']]);
check('a refusal lists nothing, echoes nothing and asks the database nothing', [array_key_exists('items', $r), array_key_exists('search', $r), count($csDb->queries)], [false, false, 0]);

// templates: the parameter is refused, never ignored (and the two things that go with it: an empty search is refused
// too, and the plain list still works)
foreach (['templatePart', 'template'] as $kind) {
    $r = $csAsk(['kind' => $kind, 'search' => 'header']);
    check("{$kind} cannot be searched: refused, naming the kind", [$r['ok'] ?? null, $r['error'] ?? null, strpos((string) ($r['message'] ?? ''), "\"{$kind}\"") !== false, array_key_exists('items', $r)], [false, 'bad_params', true, false]);
    check("{$kind}: even an empty search is refused, so the key never goes unread", $csAsk(['kind' => $kind, 'search' => ''])['error'] ?? null, 'bad_params');
    check("{$kind}: a null search is the key too", $csAsk(['kind' => $kind, 'search' => null])['error'] ?? null, 'bad_params');
    check("{$kind} still lists without a search", $csAsk(['kind' => $kind])['ok'] ?? null, true);
}

// EVERY kind: a search is filtered and echoed, or refused naming the kind. It is never answered `ok:true` with the whole
// list and no `search` key, which reads as "these are the ones that match" and is the failure this door exists to
// prevent. The kinds are READ from SiteWriter::KINDS (postmeta and term included, which nothing else here names), so a
// kind added to the writer later is held to the same rule without anyone remembering to list it; the ones after
// them are kinds the door never had, and names a caller might guess.
$csKinds = array_values(array_unique(array_merge(SiteWriter::KINDS, ['pattern', 'user', 'page', 'menutype', 'nosuchkind'])));
foreach ($csKinds as $kind) {
    $r = $csAsk(['kind' => $kind, 'search' => 'x']);
    $refused = ($r['ok'] ?? null) === false && ($r['error'] ?? null) === 'bad_params'
        && strpos((string) ($r['message'] ?? ''), "\"{$kind}\"") !== false && !array_key_exists('items', $r);
    $filtered = ($r['ok'] ?? null) === true && ($r['search'] ?? null) === 'x' && array_key_exists('matched', $r);
    check("kind {$kind} with a search: refused naming it, or filtered and echoed, never the whole list under ok:true",
        $refused || $filtered ? 'refused or filtered' : $r, 'refused or filtered');
}
check('(the table reads the writer\'s own list) it holds the kinds no other test names', [in_array('postmeta', $csKinds, true), in_array('term', $csKinds, true)], [true, true]);

// A writer that cannot search refuses the words; it never lists as if it had read them.
$csOld = new FakeSiteWriter();
$csOld->posts = [['id' => 1, 'type' => 'post', 'title' => 'Roof repair', 'slug' => 'roof-repair']];
$csOldEngine = new Engine($WTOKEN, [], null, null, null, null, $csOld, null, new FakeApplyLog());
$csOldAsk = static fn (array $params): array => $csOldEngine->handle(['token' => $WTOKEN, 'action' => 'content.list', 'params' => $params]);
$r = $csOldAsk(['search' => 'roof']);
check('a writer with no search answers a refusal, not its whole list', [$r['ok'] ?? null, $r['error'] ?? null, array_key_exists('items', $r)], [false, 'unavailable', false]);
check('but an empty search needs no search', [$csOldAsk(['search' => ''])['ok'] ?? null, $csOldAsk(['search' => ''])['search'] ?? null, $csIds($csOldAsk(['search' => '']))], [true, '', [1]]);
check('and with no search it lists as it always did', json_encode($csOldAsk([])), json_encode(['ok' => true, 'kind' => 'post', 'offset' => 0, 'items' => $csOld->list_posts(0, 100, false)]));

// ── the characters LIKE treats as its own ───────────────────────────────────────────────────────

$csDb->queries = [];
$csSite(static function () use ($csPost): void {
    foreach ([
        21 => 'Sale: 50%_off today', 22 => '500 off everything', 23 => '50 percent off', 24 => '100% cotton', 25 => '1000 threads',
        26 => 'snake_case names', 27 => 'snakeXcase names', 28 => 'Wow! Big deal', 29 => 'Wow Big deal',
        30 => 'C:\\temp\\files', 31 => 'C:temp files', 32 => 'Path a\\b', 33 => 'Path ab', 34 => 'Path a\\\\b',
        35 => "O'Brien's bakery", 36 => 'The "best" roofer', 37 => 'Discount %s coupon',
    ] as $id => $title) {
        $csPost($id, $title);
    }
});
foreach ([
    'a percent sign and an underscore are themselves' => ['50%_off', [21]],
    'a lone percent sign finds only titles with one' => ['%', [21, 24, 37]],
    'a lone underscore finds only titles with one' => ['_', [21, 26]],
    'a percent sign does not stand for the digits after it' => ['100%', [24]],
    'a backslash is itself' => ['C:\\temp', [30]],
    'a lone backslash finds every title with one' => ['\\', [30, 32, 34]],
    'a backslash between letters keeps its place' => ['a\\b', [32]],
    'and two stay two' => ['a\\\\b', [34]],
    'an exclamation mark is itself' => ['Wow!', [28]],
    'a lone exclamation mark finds only its title' => ['!', [28]],
    'a printf placeholder is text' => ['%s', [37]],
    'percent, underscore, percent is a three-character sequence, not two wildcards' => ['%_%', []],
    'an apostrophe' => ["O'Brien", [35]],
    'an apostrophe and what follows it' => ["o'brien's", [35]],
    'double quotes' => ['"best"', [36]],
    'a quote that tries to end the string finds nothing' => ["' OR 1=1 --", []],
    'so does a second try' => ["x' OR '1'='1", []],
    'and a stacked statement' => ['"; DROP TABLE wp_posts; --', []],
] as $what => [$words, $want]) {
    check($what, $csIds($csAsk(['search' => $words])), $want);
}
[$csAsked, $csOther] = $csStatements();
check('a search reads and writes nothing else: one SELECT of ids per search, none of anything else', [$csAsked, $csOther], [18, 0]);

// the reader behind those answers is not vacuous: an unescaped pattern DOES act as wildcards
check('(the SQL reader treats an unescaped % and _ as wildcards)',
    WP_Fake_PostsSql::run("SELECT ID FROM wp_posts WHERE post_type IN ('post') AND post_status IN ('publish') AND (post_title LIKE '%50%_off%') ORDER BY ID ASC"), ['21', '22', '23']);
check('(and reads AND before OR, so an ungrouped OR would leak rows of the wrong type)',
    WP_Fake_PostsSql::run("SELECT ID FROM wp_posts WHERE post_title LIKE '%Wow%' OR post_title LIKE '%snake%' AND post_type IN ('page') ORDER BY ID ASC"), ['28', '29']);
try {
    WP_Fake_PostsSql::run("SELECT ID FROM wp_posts WHERE post_title LIKE '%x%' AND");
    check('(and refuses SQL it cannot read)', 'read', 'refused');
} catch (LogicException $e) {
    check('(and refuses SQL it cannot read)', 'refused', 'refused');
}

// ── spellings: composed and decomposed letters, other scripts, four-byte characters ─────────────

$csSite(static function () use ($csPost): void {
    $csPost(41, "Nh\u{E0} h\u{E0}ng Vi\u{1EC7}t");
    $csPost(42, "Nha\u{300} ha\u{300}ng Vie\u{323}\u{302}t");
    $csPost(43, '屋根の修理サービス');
    $csPost(44, '外壁塗装');
    $csPost(45, "Summer sale \u{1F525} today");
    $csPost(46, "Caf\u{E9} menu");
    $csPost(47, "Cafe\u{301} menu");
    // WordPress stores the slug of a title in a non-Latin script percent-encoded, in lower case
    $csPost(48, 'サービス点検', ['post_name' => '%e3%82%b5%e3%83%bc%e3%83%93%e3%82%b9%e7%82%b9%e6%a4%9c']);
});
check('a composed needle finds the title stored composed AND the one stored decomposed', $csIds($csAsk(['search' => "Vi\u{1EC7}t"])), [41, 42]);
$r = $csAsk(['search' => "Vie\u{323}\u{302}t"]);
check('a decomposed needle finds both too, and is echoed composed', [$csIds($r), $r['search'] ?? null, $r['matched'] ?? null], [[41, 42], "Vi\u{1EC7}t", 2]);
check('a needle spanning composed letters in the middle of the words', $csIds($csAsk(['search' => "h\u{E0}ng Vi"])), [41, 42]);
check('an accent on a Latin letter, both ways', [$csIds($csAsk(['search' => "Caf\u{E9}"])), $csIds($csAsk(['search' => "Cafe\u{301}"]))], [[46, 47], [46, 47]]);
check('a control character between a letter and its accent is removed first, so the echo is composed all the same', [$csAsk(['search' => "Cafe\x01\u{301}"])['search'] ?? null, $csAsk(['search' => "Cafe\u{301}\x01"])['search'] ?? null, $csIds($csAsk(['search' => "Cafe\x01\u{301}"]))], ["Caf\u{E9}", "Caf\u{E9}", [46, 47]]);
check('the writer tries the composed and the decomposed spelling', Claude_Cowork_Site_Writer::search_forms("Caf\u{E9}"), ["Caf\u{E9}", "Cafe\u{301}"]);
check('CJK: two characters', $csIds($csAsk(['search' => '屋根'])), [43]);
check('CJK: one character is enough', $csIds($csAsk(['search' => '修'])), [43]);
check('a page whose slug was stored percent-encoded is found by its title', $csIds($csAsk(['search' => '点検'])), [48]);
check('and by a slice of that slug, percent signs as themselves: the slug is matched as stored', [$csIds($csAsk(['search' => '%e3%82%b5'])), $csIds($csAsk(['search' => '%bc%e3%83'])), $csIds($csAsk(['search' => '%']))], [[48], [48], [48]]);
check('a bare hex pair matches through that slug too, which is why words make the better needle', [$csIds($csAsk(['search' => 'e3'])), $csIds($csAsk(['search' => 'zz']))], [[48], []]);
check('CJK with an ideographic space around it', $csIds($csAsk(['search' => "\u{3000}外壁\u{3000}"])), [44]);
check('a four-byte character (the test source has four bytes for it)', strlen('🔥'), 4);
$r = $csAsk(['search' => '🔥']);
check('a four-byte character is found and echoed whole', [$csIds($r), $r['search'] ?? null], [[45], '🔥']);
check('an underscore is not a wildcard for a four-byte character (nor for any other)', [$csIds($csAsk(['search' => 'sale _ today'])), $csIds($csAsk(['search' => '_']))], [[], []]);
check('one Latin letter is a needle', $csIds($csAsk(['search' => 'y'])), [45]);

// ── paging over a result: the page is of the matches, and `matched` is all of them ──────────────

$csSite(static function () use ($csPost): void {
    // inserted out of order on purpose: the order comes from the statement, not from the table
    foreach ([57 => 'Zebra C', 51 => 'Zebra A', 58 => 'Filler five', 53 => 'Zebra B', 52 => 'Filler one', 54 => 'Filler two', 55 => 'Filler three', 56 => 'Filler four'] as $id => $title) {
        $csPost($id, $title);
    }
});
$page = static function (array $params) use ($csAsk, $csIds, $csDb, $csStatements): array {
    $csDb->queries = [];
    $r = $csAsk(['search' => 'zebra'] + $params);
    return [$csIds($r), $r['matched'] ?? null, $r['offset'] ?? null, $csStatements()[0]];
};
check('all three, in id order, with one statement: the page is not full so the total is known', $page([]), [[51, 53, 57], 3, 0, 1]);
check('a full first page asks for the total, and it is three not two', $page(['limit' => 2]), [[51, 53], 3, 0, 2]);
check('the last page is partial: its total needs no second statement', $page(['limit' => 2, 'offset' => 2]), [[57], 3, 2, 1]);
check('a page past the end is empty, and the total is still three', $page(['limit' => 2, 'offset' => 3]), [[], 3, 3, 2]);
check('far past the end too', $page(['offset' => 500]), [[], 3, 500, 2]);
check('one at a time', $page(['limit' => 1, 'offset' => 1]), [[53], 3, 1, 2]);
check('a limit of zero is one, as in the plain list', $page(['limit' => 0]), [[51], 3, 0, 2]);
$csDb->queries = [];
$r = $csAsk(['search' => 'no such words']);
check('no match at the top: nothing, zero, one statement', [$csIds($r), $r['matched'] ?? null, $r['items'] ?? null, count($csDb->queries)], [[], 0, [], 1]);
check('no match on a later page asks the total, and it is zero', (function () use ($csAsk, $csDb) {
    $csDb->queries = [];
    $r = $csAsk(['search' => 'no such words', 'offset' => 10]);
    return [$r['matched'] ?? null, count($csDb->queries)];
})(), [0, 2]);
$csDb->queries = [];
$csAsk(['search' => 'zebra', 'limit' => 1000]);
check('the limit is capped at 200, as the plain list caps it', strpos($csDb->queries[0], 'ORDER BY ID ASC LIMIT 0, 200') !== false, true);
$csDb->queries = [];
$csAsk(['search' => 'zebra', 'limit' => 1000, 'include_body' => true]);
check('and at 25 with bodies', strpos($csDb->queries[0], 'ORDER BY ID ASC LIMIT 0, 25') !== false, true);
$csDb->queries = [];
$csAsk(['search' => 'zebra', 'offset' => -4]);
check('a negative offset is the top', strpos($csDb->queries[0], 'LIMIT 0, 100') !== false, true);

// the shape of the statement is pinned: the plain list's types and states, the words grouped, id order
$csDb->queries = [];
$csAsk(['search' => 'zebra']);
check('the statement: types and states as the plain list reads them, words grouped, in id order',
    $csDb->queries[0],
    "SELECT ID FROM wp_posts WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') "
    . "AND (post_title LIKE '%zebra%' OR post_name LIKE '%zebra%') ORDER BY ID ASC LIMIT 0, 100");

// The query that then LOADS the page is pinned by what it is asked for. The statement above is read by a
// reader that understands its SQL; this one is answered by a fake that can only refuse what it does not
// know, and an argument it accepts but never uses (a sticky post kept in, a row count asked for) shows in
// no answer at all. Left to core's defaults, a page would come back newest first, and any plugin's query
// filter would narrow it after the count above had already been taken.
/** The arguments of the last WP_Query, in key order, so a comparison does not depend on how they were written. */
$csArgs = static function (): array {
    $args = WP_Query::$lastArgs;
    ksort($args);
    return $args;
};
$csAsk(['search' => 'zebra', 'limit' => 2, 'offset' => 1]);
check('the query that loads a page of a search: those ids, the plain list\'s types, states, order and flags, and every language', $csArgs(), [
    'ignore_sticky_posts' => true,
    'lang' => '',
    'no_found_rows' => true,
    'order' => 'ASC',
    'orderby' => 'ID',
    'post__in' => [53, 57],
    'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
    'post_type' => ['post', 'page'],
    'posts_per_page' => 2,
    'suppress_filters' => true,
]);
$csAsk(['search' => 'zebra', 'post_type' => 'post']);
check('and with a post type named, that type', $csArgs()['post_type'] ?? null, 'post');
$csWriter->list_posts(1, 3, false);
check('the plain list asks for the same order and flags, from its own offset', $csArgs(), [
    'ignore_sticky_posts' => true,
    'name' => '',
    'no_found_rows' => true,
    'offset' => 1,
    'order' => 'ASC',
    'orderby' => 'ID',
    'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
    'post_type' => ['post', 'page'],
    'posts_per_page' => 3,
    'suppress_filters' => true,
]);
$csWriter->list_posts(0, 5, false, 'zebra-b', 'page');
check('and with a slug and a type named, those', [$csArgs()['name'] ?? null, $csArgs()['post_type'] ?? null], ['zebra-b', 'page']);

// ── states, protected posts, entities, types ────────────────────────────────────────────────────

$csSite(static function () use ($csPost): void {
    foreach (['publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit'] as $i => $status) {
        $csPost(61 + $i, "Status probe {$status}", ['post_status' => $status]);
    }
    $csPost(69, 'Status probe protected', ['post_password' => 'secret']);
    $csPost(71, 'Tom &amp; Jerry bakery');
    $csPost(72, 'Tom & Jerry diner');
    $csPost(73, 'Size &gt; 10 inches');
    $csPost(74, 'Size > 10 inches');
    // What core's KSES stores for a title that has a lone `<`, saved by someone without `unfiltered_html`
    // (the sign and everything after it, up to the next `<`, is escaped), beside what a write that skips
    // KSES stores (this plugin's own writes do): the raw sign.
    $csPost(91, 'Angle a &lt; b compare');
    $csPost(92, 'Raw a < b compare');
    $csPost(93, 'Kids &lt;12 only');
    $csPost(94, 'Price &lt;$10');
    $csPost(95, 'A&amp;B &lt; C');
    $csPost(81, 'Gizmo event night', ['post_type' => 'event']);
    $csPost(82, 'Gizmo menu link', ['post_type' => 'nav_menu_item']);
    $csPost(83, 'Gizmo post');
    $csPost(84, 'Gizmo page', ['post_type' => 'page']);
    $csPost(85, 'Gizmo picture', ['post_type' => 'attachment', 'post_status' => 'inherit']);
});
check('publish, draft, pending, private and future are found; the trash, an auto-draft and an inherited row are not', $csIds($csAsk(['search' => 'status probe'])), [61, 62, 63, 64, 65, 69]);
check('a password-protected post is found, as the plain list shows it', in_array(69, array_column($csWriter->list_posts(0, 200, false), 'id'), true), true);
check('a title stored with the entity is found by the plain character', $csIds($csAsk(['search' => 'Tom & Jerry'])), [71, 72]);
check('and the stored spelling, copied out of a list, still finds the row that holds it', $csIds($csAsk(['search' => 'Tom &amp; Jerry'])), [71]);
check('the same for the angle bracket', $csIds($csAsk(['search' => 'Size > 10'])), [73, 74]);
check('the entity spellings the writer tries', Claude_Cowork_Site_Writer::search_forms('Tom & Jerry'), ['Tom & Jerry', 'Tom &amp; Jerry']);
check('a plain ASCII word has one spelling', Claude_Cowork_Site_Writer::search_forms('roof'), ['roof']);
// A `<` that no `>` closes is text, and KSES stores it as `&lt;` (only a real tag such as `<script>` is
// dropped): a caller who types the title it sees on the page has to find it.
check('a less-than sign is tried as core stores it too', Claude_Cowork_Site_Writer::search_forms('a < b'), ['a < b', 'a &lt; b']);
check('every sign at once: the ampersand is encoded once, not twice', Claude_Cowork_Site_Writer::search_forms('A&B > C < D'), ['A&B > C < D', 'A&amp;B &gt; C &lt; D']);
check('a less-than sign finds the title KSES stored with the entity AND the one a raw write stored', $csIds($csAsk(['search' => 'a < b'])), [91, 92]);
check('and the stored spelling, copied out of a list, finds only the row that holds it', $csIds($csAsk(['search' => 'a &lt; b'])), [91]);
check('with no space after the sign', $csIds($csAsk(['search' => 'Kids <12'])), [93]);
check('a sign before a dollar amount', $csIds($csAsk(['search' => 'Price <$10'])), [94]);
check('an ampersand and a sign in one title', $csIds($csAsk(['search' => 'A&B < C'])), [95]);
check('the default types are posts and pages', $csIds($csAsk(['search' => 'gizmo'])), [83, 84]);
check('a custom type is searched when it is named', $csIds($csAsk(['search' => 'gizmo', 'post_type' => 'event'])), [81]);
check('"any" is every type not kept out of search, as WP_Query reads it', $csIds($csAsk(['search' => 'gizmo', 'post_type' => 'any'])), [81, 83, 84]);

// The spellings above come from a fallback: the plugin asks WordPress itself how KSES stores a title
// (`wp_kses_normalize_entities()`, then `wp_pre_kses_less_than()`, the two steps `wp_kses()` runs first),
// and only where WordPress is absent does it use a short list. Both functions are defined here, after the
// fallback was checked, as the WordPress a site runs has them: the same two steps, with a short list of
// named entities (core's is long). Over the inputs these tests type, and a few more, they answer what
// WordPress 7.1.2's own functions answer (compared one by one, on a real site).
if (!function_exists('wp_kses_normalize_entities')) {
    function wp_kses_normalize_entities($content)
    {
        $content = str_replace('&', '&amp;', $content);
        $content = preg_replace_callback('/&amp;([A-Za-z]{2,8}[0-9]{0,2});/', static function (array $m): string {
            return in_array($m[1], ['amp', 'lt', 'gt', 'quot', 'nbsp', 'copy', 'eacute'], true) ? "&{$m[1]};" : $m[0];
        }, $content);
        $content = preg_replace_callback('/&amp;#(0*[0-9]{1,7});/', static function (array $m): string {
            return '&#' . str_pad(ltrim($m[1], '0'), 3, '0', STR_PAD_LEFT) . ';';
        }, $content);
        return preg_replace_callback('/&amp;#[Xx](0*[0-9A-Fa-f]{1,6});/', static function (array $m): string {
            return '&#x' . ltrim($m[1], '0') . ';';
        }, $content);
    }
}
if (!function_exists('wp_pre_kses_less_than')) {
    function wp_pre_kses_less_than($content)
    {
        return preg_replace_callback('%<[^>]*?((?=<)|>|$)%', static function (array $m): string {
            return strpos($m[0], '>') === false ? htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8', false) : $m[0];
        }, $content);
    }
}
$csPost(96, 'Under &lt;18&#039;s guide');
$csPost(97, 'Say "a &lt; b&quot;');
$csPost(98, '100&#037; sure');
check('with WordPress present the spellings are the ones KSES writes', [
    Claude_Cowork_Site_Writer::search_forms('a < b'),
    Claude_Cowork_Site_Writer::search_forms('Tom & Jerry'),
    Claude_Cowork_Site_Writer::search_forms('A&B > C < D'),
], [['a < b', 'a &lt; b'], ['Tom & Jerry', 'Tom &amp; Jerry'], ['A&B > C < D', 'A&amp;B &gt; C &lt; D']]);
check('a sign that a later `>` closes is a tag to KSES, not text: it is not written as an entity', array_filter(Claude_Cowork_Site_Writer::search_forms('a <b> c'), static fn (string $form): bool => strpos($form, '&lt;') !== false), []);
check('what follows a lone sign is escaped too: a quote becomes an entity', Claude_Cowork_Site_Writer::search_forms("Under <18's guide"), ["Under <18's guide", 'Under &lt;18&#039;s guide']);
check('and a title stored that way is found by what a caller types', $csIds($csAsk(['search' => "Under <18's"])), [96]);
check('what comes BEFORE the sign is not escaped', $csIds($csAsk(['search' => 'Say "a < b"'])), [97]);
check('a numeric reference is stored padded to three digits, and found by the one a caller typed', [Claude_Cowork_Site_Writer::search_forms('100&#37; sure'), $csIds($csAsk(['search' => '100&#37; sure']))], [['100&#37; sure', '100&#037; sure'], [98]]);
check('the rows found by the plain spellings are the same, and the sign now also finds the quoted title that holds it', [$csIds($csAsk(['search' => 'Tom & Jerry'])), $csIds($csAsk(['search' => 'a < b']))], [[71, 72], [91, 92, 97]]);
$csEmpty = [];
foreach (['<script>alert(1)</script>', '<', '<<', '&', '>', '<>', '<b>'] as $csNeedle) {
    foreach (Claude_Cowork_Site_Writer::search_forms($csNeedle) as $csForm) {
        if ($csForm === '') {
            $csEmpty[] = $csNeedle;
        }
    }
}
check('no needle has an empty spelling: that would be a pattern matching every row', $csEmpty, []);

// ── an emoji or a symbol that WordPress stored as an entity ─────────────────────────────────────
//
// On a posts table that is `utf8` (three bytes a character) core cannot keep an emoji, so wp_insert_post()
// runs wp_encode_emoji() over the title and stores `&#x1f525;` instead, and does the same for a number of
// symbols that do fit in three bytes (`™` is `&#x2122;`, `❤` `&#x2764;`). A table converted to utf8mb4
// afterwards keeps that text. A caller who types the character has to reach the page all the same, and the
// statement asked only for the character: on such a site `matched` said 0 for a page that exists.
check('without WordPress the entity spelling of a symbol is not tried', Claude_Cowork_Site_Writer::search_forms("Tracy\u{2122} plan"), ["Tracy\u{2122} plan"]);

// Core's function replaces each code point of its emoji list with `&#x<hex in lower case>;` and leaves the
// rest of the text as it is. Its list is long; this one holds the few code points these tests use, and gives
// what core's own function gives for them (compared one by one against WordPress 7.1's).
if (!function_exists('wp_encode_emoji')) {
    function wp_encode_emoji($content)
    {
        $map = [];
        foreach ([0x1F525, 0x2122, 0x2764, 0x2714, 0x2600, 0x26A0, 0x2B50, 0xFE0F] as $codePoint) {
            $entity = '&#x' . dechex($codePoint) . ';';
            $map[html_entity_decode($entity, ENT_QUOTES, 'UTF-8')] = $entity;
        }
        return strtr($content, $map);
    }
}
check('(the stand-in for core\'s wp_encode_emoji leaves the signs that core leaves)', [
    wp_encode_emoji("\u{A9} \u{AE} \u{20AC} \u{2192} caf\u{E9} 屋根"),
    wp_encode_emoji("Sale \u{1F525}, \u{2122} \u{2764}\u{FE0F} \u{2714}"),
], ["\u{A9} \u{AE} \u{20AC} \u{2192} caf\u{E9} 屋根", 'Sale &#x1f525;, &#x2122; &#x2764;&#xfe0f; &#x2714;']);

check('an emoji and a symbol are tried as the character and as the entity core stores', [
    Claude_Cowork_Site_Writer::search_forms("Sale \u{1F525}"),
    Claude_Cowork_Site_Writer::search_forms("Tracy\u{2122}"),
    Claude_Cowork_Site_Writer::search_forms("\u{2764}"),
], [["Sale \u{1F525}", 'Sale &#x1f525;'], ["Tracy\u{2122}", 'Tracy&#x2122;'], ["\u{2764}", '&#x2764;']]);
check('a sign core leaves alone has no entity spelling', Claude_Cowork_Site_Writer::search_forms("\u{A9} 2026 caf\u{E9}"), ["\u{A9} 2026 caf\u{E9}", "\u{A9} 2026 cafe\u{301}"]);
check('KSES first and the emoji second, as wp_insert_post stores a title: an ampersand and an emoji make four spellings', Claude_Cowork_Site_Writer::search_forms("Tom & Jerry \u{1F525}"),
    ["Tom & Jerry \u{1F525}", "Tom &amp; Jerry \u{1F525}", 'Tom & Jerry &#x1f525;', 'Tom &amp; Jerry &#x1f525;']);
check('words with no character beyond ASCII have the spellings they always had', [
    Claude_Cowork_Site_Writer::search_forms('roof'),
    Claude_Cowork_Site_Writer::search_forms('Tom & Jerry'),
], [['roof'], ['Tom & Jerry', 'Tom &amp; Jerry']]);

$csSite(static function () use ($csPost): void {
    // stored as a three-byte table stores them: by the function core stores them with
    $csPost(101, wp_encode_emoji("Summer sale \u{1F525} today"));
    $csPost(102, wp_encode_emoji("Tracy\u{2122} plan"));
    $csPost(103, wp_encode_emoji("We \u{2764} roofing"));
    $csPost(104, wp_encode_emoji("Done \u{2714}"));
    // `Tom & Jerry 🔥` is stored four ways: KSES on (an author) or off (unfiltered_html) and, after it, a column that
    // keeps three bytes (the emoji is an entity) or one that takes four (it stays the character)
    $csPost(105, wp_encode_emoji('Tom &amp; Jerry ' . "\u{1F525}"));
    $csPost(110, wp_encode_emoji('Tom & Jerry ' . "\u{1F525}"));
    // what a table that takes four bytes keeps as the character itself
    $csPost(106, "Winter sale \u{1F525}");
    $csPost(111, 'Tom &amp; Jerry ' . "\u{1F525}");
    $csPost(112, 'Tom & Jerry ' . "\u{1F525}");
    $csPost(107, "Tracy\u{2122} raw plan");
    $csPost(108, "\u{A9} 2026 roofing");
    $csPost(109, 'Plain sale');
});
check('the fixtures are what core stores: entities, and the character where core leaves it', [
    WP_Fake::$posts[101]['post_title'], WP_Fake::$posts[102]['post_title'], WP_Fake::$posts[105]['post_title'], WP_Fake::$posts[110]['post_title'], WP_Fake::$posts[108]['post_title'],
], ['Summer sale &#x1f525; today', 'Tracy&#x2122; plan', 'Tom &amp; Jerry &#x1f525;', 'Tom & Jerry &#x1f525;', "\u{A9} 2026 roofing"]);
$r = $csAsk(['search' => "\u{1F525}"]);
check('an emoji finds the page that holds the entity AND the one that holds the character', [$csIds($r), $r['matched'] ?? null, $r['search'] ?? null], [[101, 105, 106, 110, 111, 112], 6, "\u{1F525}"]);
check('the emoji with the words around it, either side or both', [
    $csIds($csAsk(['search' => "Summer sale \u{1F525}"])),
    $csIds($csAsk(['search' => "\u{1F525} today"])),
    $csIds($csAsk(['search' => "Summer sale \u{1F525} today"])),
    $csIds($csAsk(['search' => "sale \u{1F525}"])),
], [[101], [101], [101], [101, 106]]);
check('a symbol that fits in three bytes is found as the entity core stored, and as the character', [
    $csIds($csAsk(['search' => "\u{2122}"])),
    $csIds($csAsk(['search' => "Tracy\u{2122}"])),
    $csIds($csAsk(['search' => "Tracy\u{2122} plan"])),
    $csIds($csAsk(['search' => "We \u{2764} roofing"])),
    $csIds($csAsk(['search' => "\u{2714}"])),
], [[102, 107], [102, 107], [102], [103], [104]]);
check('an ampersand and an emoji together: each of the four ways a title is stored is found (KSES first, then core encodes the emoji)', $csIds($csAsk(['search' => "Tom & Jerry \u{1F525}"])), [105, 110, 111, 112]);
check('the stored spelling, copied out of a list, finds its page', $csIds($csAsk(['search' => 'Summer sale &#x1f525; today'])), [101]);
check('a sign core does not encode is asked for as it is', $csIds($csAsk(['search' => "\u{A9} 2026"])), [108]);
check('the plain words around the emoji still find the page, as ever', $csIds($csAsk(['search' => 'summer sale'])), [101]);
check('the count behind `matched` asks the same spellings, so a page of one still totals them all', [$csIds($csAsk(['search' => "\u{1F525}", 'limit' => 1])), $csAsk(['search' => "\u{1F525}", 'limit' => 1])['matched'] ?? null], [[101], 6]);
$csDb->queries = [];
$csAsk(['search' => "\u{1F525}"]);
check('the statement: the character and the entity for the title, the character for the slug (the same words, asked in lower case, are the same)', $csDb->queries[0],
    "SELECT ID FROM wp_posts WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') "
    . "AND (post_title LIKE '%\u{1F525}%' OR post_title LIKE '%&#x1f525;%' OR post_name LIKE '%\u{1F525}%') ORDER BY ID ASC LIMIT 0, 100");
$csDb->queries = [];
$csAsk(['search' => 'sale']);
check('a search with no such character asks exactly what it asked before', $csDb->queries[0],
    "SELECT ID FROM wp_posts WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') "
    . "AND (post_title LIKE '%sale%' OR post_name LIKE '%sale%') ORDER BY ID ASC LIMIT 0, 100");

// ── a failed statement is an error, never an empty page ─────────────────────────────────────────

// Its own rows: a page of one is full, so the count behind `matched` is asked for, and only a title that is
// there makes it so.
$csSite(static function () use ($csPost): void {
    $csPost(1, 'Gizmo post');
    $csPost(2, 'Gizmo page', ['post_type' => 'page']);
});
$csDb->failWith = 'Table wp_posts is marked as crashed';
$r = $csAsk(['search' => 'gizmo']);
check('a failed statement is read_failed and says why', [$r['ok'] ?? null, $r['error'] ?? null, strpos((string) ($r['message'] ?? ''), 'marked as crashed') !== false, array_key_exists('items', $r)], [false, 'read_failed', true, false]);
// only the count fails: a page of one is full, so the total has to be asked for
$csDb->failWith = 'Lost connection';
$csDb->failOnly = 'COUNT(';
check('a failed count is the same, not a wrong total', [$csAsk(['search' => 'gizmo', 'limit' => 1])['error'] ?? null, $csAsk(['search' => 'gizmo'])['ok'] ?? null], ['read_failed', true]);
$csDb->failWith = '';
$csDb->failOnly = '';
$csDb->prepareRefuses = true;
$r = $csAsk(['search' => 'gizmo']);
check('a statement WordPress refuses to build is an error, not an empty page', [$r['ok'] ?? null, $r['error'] ?? null, array_key_exists('items', $r)], [false, 'read_failed', false]);
$csDb->prepareRefuses = false;
check('and the next search is fine', $csAsk(['search' => 'gizmo'])['ok'] ?? null, true);

// ── a four-byte character: what a column that keeps three bytes can be asked ────────────────────
//
// A posts table that was never moved to utf8mb4 keeps its text in three-byte columns, and core stores an emoji there as
// an entity (above): the page that holds one exists, and is found by the words asked for the way core stores them. What
// cannot be sent is the character itself. A string with a character above U+FFFF compared with a column of that kind is
// refused as a whole, whichever branch of an OR held it, and in two ways: MySQL says "Illegal mix of collations", and for
// every collation but six `wpdb` refuses first, in the site's language, without asking MySQL. WordPress says what a column
// keeps (`get_col_charset()`, the very test `wp_insert_post()` makes), so the spellings that hold such a character are left
// out for a column that keeps three bytes, and no statement that would be refused is sent.
/** The posts of a three-byte site: what core stored there (entities), not what was typed. */
$csThreeByteSite = static function () use ($csSite, $csPost): void {
    $csSite(static function () use ($csPost): void {
        $csPost(1, wp_encode_emoji("Summer sale \u{1F525} today"), ['post_name' => 'summer-sale']);
        $csPost(2, 'Winter sale', ['post_name' => 'winter-sale']);
        $csPost(3, '屋根の修理サービス');
        $csPost(4, wp_encode_emoji("Tracy\u{2122} plan"));
        $csPost(5, wp_encode_emoji('Tom &amp; Jerry ' . "\u{1F525}"));
    });
};
/** The statements of a list that hold a character above U+FFFF. */
$csFourByte = static fn (array $statements): array => array_values(array_filter($statements, static fn (string $q): bool => preg_match('/[\x{10000}-\x{10FFFF}]/u', $q) === 1));
/** A clean slate for what the handle records. */
$csForget = static function () use ($csDb): void {
    $csDb->queries = [];
    $csDb->refused = [];
    $csDb->last_error = '';
    $csDb->charsetLookups = 0;
    WP_Query::$lastArgs = [];
};
$csThreeBytes = [
    'utf8_general_ci' => 'MySQL refuses it',
    'utf8mb3_general_ci' => 'MySQL refuses it',
    'utf8mb3_bin' => 'MySQL refuses it',
    'utf8_unicode_ci' => 'wpdb refuses it itself',
    'utf8_unicode_520_ci' => 'wpdb refuses it itself',
    'utf8mb3_unicode_520_ci' => 'wpdb refuses it itself',
];
$csEmojiStatement = "SELECT ID FROM wp_posts WHERE post_type IN ('post') AND post_status IN ('publish') AND (post_title LIKE '%\u{1F525}%') ORDER BY ID ASC LIMIT 0, 100";

// the stand-in is not vacuous: it refuses what a real site refuses, in the way that collation refuses it
foreach ($csThreeBytes as $collation => $who) {
    $csDb->collation = $collation;
    $csForget();
    $csDb->get_col($csEmojiStatement);
    $viaMysql = $who === 'MySQL refuses it';
    check("(the stand-in on {$collation}: {$who})", [
        count($csDb->queries), count($csDb->refused),
        strpos($csDb->last_error, $viaMysql ? 'Illegal mix of collations' : 'WordPress database error: Could not perform query because it contains invalid data.') === 0,
    ], [$viaMysql ? 1 : 0, $viaMysql ? 0 : 1, true]);
}
foreach (['utf8mb4_unicode_520_ci', 'utf8mb4_general_ci', 'utf8mb4_bin'] as $collation) {
    $csDb->collation = $collation;
    $csForget();
    $csDb->get_col($csEmojiStatement);
    check("(and on {$collation}, which takes four bytes, it is not refused)", [count($csDb->queries), $csDb->last_error], [1, '']);
}
$csDb->collation = 'utf8mb3_general_ci';
$csForget();
$csDb->get_col("SELECT ID FROM wp_posts WHERE post_type IN ('post') AND post_status IN ('publish') AND (post_title LIKE '%\u{FFFF}%') ORDER BY ID ASC LIMIT 0, 100");
check('(and U+FFFF, the last character of three bytes, is not refused: the boundary is U+10000)', [count($csDb->queries), $csDb->last_error], [1, '']);

foreach ($csThreeBytes as $collation => $who) {
    $csThreeByteSite();
    $csDb->collation = $collation;
    $csForget();
    $r = $csAsk(['search' => "\u{1F525}"]);
    check("[{$collation}] an emoji finds the pages core stored with the entity, as an answer and not a failure ({$who})",
        [$r['ok'] ?? null, $csIds($r), $r['matched'] ?? null, $r['search'] ?? null, $r['error'] ?? null], [true, [1, 5], 2, "\u{1F525}", null]);
    check("[{$collation}] and no statement that this column would refuse was sent: none holds the character, none was refused, no error",
        [$csFourByte($csDb->queries), $csDb->refused, $csDb->last_error], [[], [], '']);
    check("[{$collation}] the statement asks for the entity alone: the character is left out of the title, and the slug, which never holds it, is not asked",
        $csDb->queries[0],
        "SELECT ID FROM wp_posts WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') "
        . "AND (post_title LIKE '%&#x1f525;%') ORDER BY ID ASC LIMIT 0, 100");
    $csForget();
    $r = $csAsk(['search' => "\u{1F525}", 'limit' => 1]);
    check("[{$collation}] a page of one asks the count too, of the same spellings", [$csIds($r), $r['matched'] ?? null, count($csDb->queries), $csFourByte($csDb->queries), $csDb->refused], [[1], 2, 2, [], []]);
    $r = $csAsk(['search' => "\u{1F525}", 'offset' => 1]);
    check("[{$collation}] and a later page", [$csIds($r), $r['matched'] ?? null, $r['offset'] ?? null], [[5], 2, 1]);
}

foreach (['utf8mb3_general_ci', 'utf8_unicode_ci'] as $collation) {
    $csThreeByteSite();
    $csDb->collation = $collation;
    $csForget();
    check("[{$collation}] the emoji with the words around it, either side or both", [
        $csIds($csAsk(['search' => "Summer sale \u{1F525}"])),
        $csIds($csAsk(['search' => "\u{1F525} today"])),
        $csIds($csAsk(['search' => "Summer sale \u{1F525} today"])),
        $csIds($csAsk(['search' => "sale \u{1F525}"])),
    ], [[1], [1], [1], [1]]);
    check("[{$collation}] the words of the title with no emoji still find it, and a slug is asked as ever", [$csIds($csAsk(['search' => 'summer sale'])), $csIds($csAsk(['search' => 'winter-sale']))], [[1], [2]]);
    check("[{$collation}] an ampersand and an emoji together: KSES stored the first, then core encoded the second", $csIds($csAsk(['search' => "Tom & Jerry \u{1F525}"])), [5]);
    check("[{$collation}] a symbol of three bytes is found as the entity core stored, in one statement", (function () use ($csAsk, $csIds, $csDb, $csForget) {
        $csForget();
        $r = $csAsk(['search' => "\u{2122}"]);
        return [$csIds($r), count($csDb->queries), $csDb->refused, $csDb->last_error];
    })(), [[4], 1, [], '']);
    check("[{$collation}] CJK is three bytes and needs no such care", $csIds($csAsk(['search' => '屋根'])), [3]);
    check("[{$collation}] the stored spelling, copied out of a list, finds its page", $csIds($csAsk(['search' => 'Summer sale &#x1f525; today'])), [1]);
    $r = $csAsk(['search' => 'summer 🔥 sale', 'offset' => 40, 'limit' => 5, 'include_body' => true, 'post_type' => 'page', 'name' => 'summer-sale']);
    check("[{$collation}] every other parameter beside it: answered, zero, the offset as asked", [$r['ok'] ?? null, $r['items'] ?? null, $r['matched'] ?? null, $r['offset'] ?? null], [true, [], 0, 40]);
}

// A character that core cannot store on such a column at all (an ideograph above U+FFFF is no emoji, so it has no entity):
// nothing is left to ask, and the answer is none, with no statement.
foreach (['utf8mb3_general_ci', 'utf8_unicode_ci'] as $collation) {
    $csThreeByteSite();
    $csDb->collation = $collation;
    foreach (["\u{20BB7}", "sale \u{20BB7}", "\u{20BB7} \u{1F525}"] as $csWords) {
        $csForget();
        $r = $csAsk(['search' => $csWords, 'offset' => 40, 'limit' => 5]);
        check("[{$collation}] words that cannot exist there (" . json_encode($csWords) . '): no rows, zero, the offset as asked, no statement, no page loaded',
            [$r['ok'] ?? null, $r['items'] ?? null, $r['matched'] ?? null, $r['offset'] ?? null, $csDb->queries, $csDb->refused, WP_Query::$lastArgs], [true, [], 0, 40, [], [], []]);
    }
    $csForget();
    $r = $csAsk(['search' => "\u{20BB7}"]);
    check("[{$collation}] as the door writes it: the keys of any search, an empty list and not an object", json_encode($r, JSON_UNESCAPED_UNICODE), '{"ok":true,"kind":"post","offset":0,"search":"' . "\u{20BB7}" . '","matched":0,"items":[]}');
    // U+FFFF is the last character of three bytes and U+10000 the first with four
    foreach ([["\u{FFFF}", 1], ["\u{10000}", 0], ["\u{10FFFF}", 0]] as [$csWords, $csSent]) {
        $csForget();
        $r = $csAsk(['search' => $csWords]);
        check("[{$collation}] the boundary: " . json_encode($csWords) . ($csSent === 1 ? ' is asked' : ' is not'), [$r['ok'] ?? null, $r['matched'] ?? null, count($csDb->queries), $csDb->refused], [true, 0, $csSent, []]);
    }
}

// A search that has no such character in it never asks what the column keeps: that is a read of the table's columns.
$csThreeByteSite();
$csDb->collation = 'utf8mb3_general_ci';
$csForget();
$csAsk(['search' => 'sale']);
$csAsk(['search' => "Caf\u{E9} \u{2122}"]);
check('words with no character above U+FFFF never ask what the columns keep', $csDb->charsetLookups, 0);
$csAsk(['search' => "\u{1F525}"]);
check('and words that have one ask', $csDb->charsetLookups > 0, true);

// The decision is the column's, not a message's. Nothing here reads an error to decide.
$csThreeByteSite();
$csDb->collation = 'utf8mb4_unicode_520_ci';
$csDb->failWith = "Illegal mix of collations (utf8_general_ci,IMPLICIT) and (utf8mb4_unicode_520_ci,COERCIBLE) for operation 'like'";
$r = $csAsk(['search' => "\u{1F525}"]);
check('a column that takes four bytes and a server that still refuses the words: a failure to report, not "no rows"', [$r['ok'] ?? null, $r['error'] ?? null, strpos((string) ($r['message'] ?? ''), 'Illegal mix of collations') !== false, array_key_exists('items', $r)], [false, 'read_failed', true, false]);
$csDb->failWith = "Incorrect string value: '\\xF0\\x9F\\x94\\xA5' for column 'post_title' at row 1";
check('and so is the other message a server gives', $csAsk(['search' => "\u{1F525}"])['error'] ?? null, 'read_failed');
$csDb->failWith = '';
foreach (['utf8mb3_general_ci', 'utf8_unicode_ci'] as $collation) {
    $csDb->collation = $collation;
    foreach (['Table wp_posts is marked as crashed', 'Lost connection to MySQL server during query', 'Deadlock found when trying to get lock; try restarting transaction'] as $csError) {
        $csDb->failWith = $csError;
        $r = $csAsk(['search' => "\u{1F525}"]);
        check("[{$collation}] a fault ({$csError}) with such words is a failure, not an answer", [$r['ok'] ?? null, $r['error'] ?? null, strpos((string) ($r['message'] ?? ''), $csError) !== false, array_key_exists('items', $r)], [false, 'read_failed', true, false]);
    }
    $csDb->failWith = 'Lost connection';
    $csDb->failOnly = 'COUNT(';
    check("[{$collation}] and a count that fails is one too, not a wrong total", [$csAsk(['search' => "\u{1F525}", 'limit' => 1])['error'] ?? null, $csAsk(['search' => "\u{1F525}"])['ok'] ?? null], ['read_failed', true]);
    $csDb->failWith = '';
    $csDb->failOnly = '';
}
$csDb->collation = 'utf8mb3_general_ci';
$r = $csAsk(['search' => 'sale']);
check('words without a four-byte character are searched as ever on that table', [$csIds($r), $r['matched'] ?? null], [[1, 2], 2]);

// A handle that cannot say what its columns keep (a database that is not MySQL answers false, as core does) has nothing
// to decide from: the words are sent as they are, and a refusal is a failure to report.
foreach (['utf8mb3_general_ci' => 'Illegal mix of collations', 'utf8_unicode_ci' => 'contains invalid data'] as $collation => $csText) {
    $csThreeByteSite();
    $csDb->collation = $collation;
    $csDb->charsetUnknown = true;
    $r = $csAsk(['search' => "\u{1F525}"]);
    check("[{$collation}] a handle that cannot say: the refusal is read_failed, never an answer", [$r['ok'] ?? null, $r['error'] ?? null, strpos((string) ($r['message'] ?? ''), $csText) !== false, array_key_exists('items', $r)], [false, 'read_failed', true, false]);
    $csDb->charsetUnknown = false;
}
$csThreeByteSite();
$csPost(6, "Autumn sale \u{1F525}"); // a table that takes four bytes may hold the character itself, and only the character asks for it
$csDb->collation = 'utf8mb4_unicode_520_ci';
$csDb->charsetUnknown = true;
$r = $csAsk(['search' => "\u{1F525}"]);
check('a handle that cannot say, over a table that takes four bytes: the words are sent as they are, so the character is found as well as its entity', [$csIds($r), $r['matched'] ?? null], [[1, 5, 6], 3]);
$csDb->charsetUnknown = false;

// A table half converted: each column is asked for what it can hold.
$csDb->collation = 'utf8mb4_general_ci';
$csDb->columnCollation = ['post_title' => 'utf8mb3_general_ci'];
$csThreeByteSite();
$csForget();
$r = $csAsk(['search' => "\u{1F525}"]);
check('(title keeps three bytes, slug takes four) the title is asked for the entity alone and the slug for the character', [$csIds($r), $csDb->last_error, $csDb->queries[0] ?? null], [[1, 5],  '',
    "SELECT ID FROM wp_posts WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') "
    . "AND (post_title LIKE '%&#x1f525;%' OR post_name LIKE '%\u{1F525}%') ORDER BY ID ASC LIMIT 0, 100"]);
$csDb->columnCollation = ['post_name' => 'utf8mb3_general_ci'];
$csThreeByteSite();
$csForget();
$r = $csAsk(['search' => "\u{1F525}"]);
check('(title takes four bytes, slug keeps three) the title is asked both ways and the slug not at all', [$csIds($r), $csDb->last_error, $csDb->queries[0] ?? null], [[1, 5], '',
    "SELECT ID FROM wp_posts WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') "
    . "AND (post_title LIKE '%\u{1F525}%' OR post_title LIKE '%&#x1f525;%') ORDER BY ID ASC LIMIT 0, 100"]);
$csDb->columnCollation = [];
$csDb->collation = 'utf8mb4_unicode_520_ci';

// On a table that takes four bytes nothing is left out, and the answer is what it was: the character AND the entity.
$csSite(static function () use ($csPost): void {
    $csPost(1, "Summer sale \u{1F525} today");
    $csPost(2, 'Winter sale');
    $csPost(3, wp_encode_emoji("Spring sale \u{1F525}"));
});
$csForget();
$r = $csAsk(['search' => '🔥']);
check('on a utf8mb4 table the same words are searched like any others: the row that holds the emoji is found, and the one that holds its entity', [$csIds($r), $r['matched'] ?? null, $csDb->last_error], [[1, 3], 2, '']);
check('the statement asks for the character, its entity and the slug', $csDb->queries[0],
    "SELECT ID FROM wp_posts WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') "
    . "AND (post_title LIKE '%\u{1F525}%' OR post_title LIKE '%&#x1f525;%' OR post_name LIKE '%\u{1F525}%') ORDER BY ID ASC LIMIT 0, 100");
check('and a fault there, with that character, is a failure', (function () use ($csAsk, $csDb) {
    $csDb->failWith = 'Table wp_posts is marked as crashed';
    $r = $csAsk(['search' => '🔥']);
    $csDb->failWith = '';
    return [$r['ok'] ?? null, $r['error'] ?? null];
})(), [false, 'read_failed']);

// ── the slug is lower case: a capitalised needle reaches it whatever the column's collation ─────
//
// WordPress stores a slug in lower case, and whether `post_name LIKE '%Roof%'` finds `roof-repair` is for the
// column's collation to say. The ones WordPress installs ignore case, but a site can set a binary one
// (DB_COLLATE `utf8mb4_bin`), which compares byte for byte. So the words are tried against the slug as typed
// AND in lower case. The title is stored as typed, so it has no such rule and keeps to its own spellings.
$csSite(static function () use ($csPost): void {
    $csPost(1, 'Get a quote', ['post_name' => 'roof-repair-old']);
    $csPost(2, 'Emergency call-out', ['post_type' => 'page', 'post_name' => 'emergency-call-out']);
    $csPost(3, 'ROOF REPAIR COST', ['post_name' => 'roof-repair-cost']);
    $csPost(4, 'Solar panels', ['post_name' => '%e3%82%b5%e3%83%bc']);
    // what a write straight to the table can leave and WordPress itself never writes: capitals in a slug
    $csPost(5, 'Legacy import', ['post_name' => 'Roof-Repair-Legacy']);
});
check('(default columns ignore case) a capitalised needle reaches a lower-case slug that the title does not hold', $csIds($csAsk(['search' => 'Roof-Repair-Old'])), [1]);
check('(default columns) and so does a shouted one, and a slug with capitals', $csIds($csAsk(['search' => 'ROOF-REPAIR'])), [1, 3, 5]);
check('the spellings a slug is tried in: as typed and in lower case, one when it is lower case already', [
    Claude_Cowork_Site_Writer::slug_forms('Roof-Repair'),
    Claude_Cowork_Site_Writer::slug_forms('roof-repair'),
    Claude_Cowork_Site_Writer::slug_forms('%E3%82%B5'),
    Claude_Cowork_Site_Writer::slug_forms('50%_OFF'),
], [['Roof-Repair', 'roof-repair'], ['roof-repair'], ['%E3%82%B5', '%e3%82%b5'], ['50%_OFF', '50%_off']]);
check('only the letters A to Z are lowered: other text is left as it is, multibyte characters whole', [
    Claude_Cowork_Site_Writer::slug_forms("CAF\u{C9} \u{5C4B}\u{6839} \u{1F525}"),
    Claude_Cowork_Site_Writer::slug_forms("\u{5C4B}\u{6839}"),
], [["CAF\u{C9} \u{5C4B}\u{6839} \u{1F525}", "caf\u{C9} \u{5C4B}\u{6839} \u{1F525}"], ["\u{5C4B}\u{6839}"]]);
$csDb->binaryColumns = ['post_name'];
check('(the stand-in is not vacuous) a binary column does not match a needle with other capitals', [
    WP_Fake_PostsSql::run("SELECT ID FROM wp_posts WHERE post_name LIKE '%Roof-Repair-Old%'", ['post_name']),
    WP_Fake_PostsSql::run("SELECT ID FROM wp_posts WHERE post_name LIKE '%roof-repair-old%'", ['post_name']),
    WP_Fake_PostsSql::run("SELECT ID FROM wp_posts WHERE post_name LIKE '%Roof-Repair-Old%'"),
], [[], ['1'], ['1']]);
$r = $csAsk(['search' => 'Roof-Repair-Old']);
check('(binary slug column) a capitalised needle reaches the lower-case slug, and is echoed as typed', [$csIds($r), $r['search'] ?? null, $r['matched'] ?? null], [[1], 'Roof-Repair-Old', 1]);
check('(binary slug column) a shouted one too', $csIds($csAsk(['search' => 'ROOF-REPAIR'])), [1, 3]);
check('(binary slug column) capitals as typed still reach a slug that has them, beside the lower-case ones', $csIds($csAsk(['search' => 'Roof-Repair'])), [1, 3, 5]);
check('(binary slug column) lower case does not reach a slug written with capitals: that one takes the capitals it has', $csIds($csAsk(['search' => 'roof-repair'])), [1, 3]);
check('(binary slug column) a percent-escape copied in capitals out of an address bar reaches the slug WordPress stored in lower case', $csIds($csAsk(['search' => '%E3%82%B5'])), [4]);
$r = $csAsk(['search' => 'ROOF-REPAIR', 'limit' => 1]);
check('(binary slug column) the count behind `matched` asks the same, so a page of one still totals both', [$csIds($r), $r['matched'] ?? null], [[1], 2]);
check('(binary slug column) an exact slug named beside the words is cleaned as the plain list cleans it', $csIds($csAsk(['search' => 'ROOF', 'name' => 'Roof-Repair-Old'])), [1]);
$csDb->queries = [];
$csAsk(['search' => 'Zebra']);
check('the statement for a capitalised needle: the slug asked as typed and in lower case, the title once', $csDb->queries[0],
    "SELECT ID FROM wp_posts WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') "
    . "AND (post_title LIKE '%Zebra%' OR post_name LIKE '%Zebra%' OR post_name LIKE '%zebra%') ORDER BY ID ASC LIMIT 0, 100");
// A title is compared as its column compares it: only the slug is also asked in lower case.
$csDb->binaryColumns = ['post_title', 'post_name'];
check('(binary title column) the title is found by the capitals it was stored with, not by lower case: the collation decides', [$csIds($csAsk(['search' => 'ROOF REPAIR'])), $csIds($csAsk(['search' => 'roof repair']))], [[3], []]);
$csDb->binaryColumns = [];

// ── a site with a language plugin: the search reaches every language ────────────────────────────
//
// Polylang narrows every WP_Query built during a request to the request's language, through
// `parse_query`, which `suppress_filters` does not switch off, and rewrites the ids of a `post__in` to
// their copies in that language; a query that carries `lang` is left alone. The ids of a page are cut
// from the whole table by SQL, so a loader that leaves either to Polylang loses rows AFTER the page was
// cut (a short page, which the caller reads as the end, and a `matched` that counts rows this door can
// never return) or hands back a row's translation in place of the row that holds the words.
$csLanguages = static function (string $requestLanguage) use ($csSite, $csPost): void {
    $csSite(static function () use ($csPost, $requestLanguage): void {
        WP_Fake::$polylang = true;
        WP_Fake::$requestLanguage = $requestLanguage;
        foreach ([1 => 'en', 2 => 'vi', 3 => 'en', 4 => 'vi', 5 => 'en', 6 => 'vi'] as $id => $language) {
            $csPost($id, "Roof repair {$id}", ['post_name' => "roof-repair-{$id}"]);
            WP_Fake::$postLanguage[$id] = $language;
        }
        // one page in two languages, whose titles share no word
        $csPost(10, 'Gutter cleaning', ['post_type' => 'page', 'post_name' => 'gutter-cleaning']);
        $csPost(11, 'Ve sinh mang xoi', ['post_type' => 'page', 'post_name' => 've-sinh-mang-xoi']);
        WP_Fake::$postLanguage[10] = 'en';
        WP_Fake::$postLanguage[11] = 'vi';
        WP_Fake::$translations[10] = ['en' => 10, 'vi' => 11];
        WP_Fake::$translations[11] = ['en' => 10, 'vi' => 11];
        // a type the plugin does not translate, so it has no language
        $csPost(20, 'Roof repair event', ['post_type' => 'event', 'post_name' => 'roof-repair-event']);
    });
};
foreach (['en' => [[1, 3, 5, 10], 'the English', 'repair 2', [2], 'sinh mang', [11]], 'vi' => [[2, 4, 6, 11], 'the Vietnamese', 'repair 1', [1], 'gutter', [10]]] as $language => [$plainIds, $which, $otherOnly, $otherOnlyIds, $twin, $twinIds]) {
    $csLanguages($language);
    check("[{$language}] the plain list is {$which} rows only, as Polylang narrows it", $csIds($csAsk(['limit' => 100])), $plainIds);

    $ids = [];
    $matched = [];
    $sizes = [];
    foreach ([0, 2, 4, 6] as $offset) {
        $r = $csAsk(['search' => 'roof', 'limit' => 2, 'offset' => $offset]);
        $ids[] = $csIds($r);
        $matched[] = $r['matched'] ?? null;
        $sizes[] = count($r['items'] ?? []);
    }
    check("[{$language}] pages of two, from the top: each is full, in both languages, none a translation", $ids, [[1, 2], [3, 4], [5, 6], []]);
    check("[{$language}] and `matched` is the six rows the walk returns, at every offset", [$matched, array_sum($sizes)], [[6, 6, 6, 6], 6]);
    $r = $csAsk(['search' => 'roof', 'limit' => 100]);
    check("[{$language}] one page of everything: all six, in both languages", [$csIds($r), $r['matched'] ?? null], [[1, 2, 3, 4, 5, 6], 6]);

    $r = $csAsk(['search' => $otherOnly]);
    check("[{$language}] a page that exists only in the other language is found: not an empty answer that sends a caller off to create it", [$csIds($r), $r['matched'] ?? null], [$otherOnlyIds, 1]);
    check("[{$language}] a row is never swapped for its translation: the words find the page that holds them", $csIds($csAsk(['search' => $twin])), $twinIds);
    check("[{$language}] and the other edition of that page is found by its own words", $csIds($csAsk(['search' => $language === 'en' ? 'gutter' : 'sinh mang'])), [$language === 'en' ? 10 : 11]);

    check("[{$language}] a type the plugin does not translate is found, as it is with no plugin", $csIds($csAsk(['search' => 'roof', 'post_type' => 'event'])), [20]);
    check("[{$language}] \"any\" includes it, beside every language of the translated types", $csIds($csAsk(['search' => 'roof', 'post_type' => 'any'])), [1, 2, 3, 4, 5, 6, 20]);
}

// A plugin that hides posts from EVERY query (`pre_get_posts` is not something `lang` or
// `suppress_filters` switches off) leaves the page short of the ids it was cut for. The search says so:
// a short page is what a caller reads as the end, and a `matched` that counts a row never listed is a
// total nobody can reach.
$csLanguages('en');
WP_Fake::$queryHides = [3];
$r = $csAsk(['search' => 'roof', 'limit' => 100]);
check('a page that loads short of its ids is read_failed, not a shorter list', [$r['ok'] ?? null, $r['error'] ?? null, preg_match('/found 6 posts.*loaded 5 of them/', (string) ($r['message'] ?? '')), array_key_exists('items', $r)], [false, 'read_failed', 1, false]);
WP_Fake::$queryHides = [];
check('and once nothing hides a post, the same search is whole', [$csAsk(['search' => 'roof', 'limit' => 100])['ok'] ?? null, count($csAsk(['search' => 'roof', 'limit' => 100])['items'] ?? [])], [true, 6]);

// ── the plugin loads the way a site loads it ────────────────────────────────────────────────────

if (function_exists('proc_open')) {
    /**
     * Runs content-search-load.php in a fresh PHP. Errors go to stderr whatever the machine's php.ini says:
     * with none (a bare CLI image) PHP prints them on STDOUT, and a check that only quotes stderr reports a
     * fatal "Class not found" as an empty message. `$iniDir`, when given, is the only directory PHP scans
     * for extra ini files in that process.
     *
     * @return array{0:int,1:string,2:string} exit code, stdout, and what went wrong in one line
     */
    $csChild = static function (array $args = [], ?string $iniDir = null): array {
        $env = getenv();
        if ($iniDir !== null) {
            $env['PHP_INI_SCAN_DIR'] = $iniDir;
        }
        $proc = proc_open(array_merge([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/content-search-load.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, substr(trim((string) preg_replace('/\s+/', ' ', $err . ' ' . $out)), 0, 300)];
    };
    [$code, $out, $why] = $csChild();
    check('a search runs with only the classes claude-cowork.php loads' . ($code === 0 ? '' : " ({$why})"), $code, 0);
    checkTrue('and says so', strpos($out, 'search loads and answers') !== false);

    // The check for a PHP with no intl ran above, in this process, only where intl is absent. Where it is
    // loaded (CI, most hosts) the same three checks run in a fresh PHP that is started without the ini file
    // that loads the extension: a copy of the directory this PHP scanned, minus that file.
    if ($csIntl) {
        $csDir = null;
        $csScanned = php_ini_scanned_files();
        if (is_string($csScanned) && trim($csScanned) !== '') {
            $csDir = sys_get_temp_dir() . '/cc-no-intl-' . getmypid();
            if (!is_dir($csDir) && !@mkdir($csDir, 0700, true)) {
                $csDir = null;
            }
        }
        $csLeftOut = 0;
        foreach ($csDir === null ? [] : array_filter(array_map('trim', explode(',', (string) $csScanned))) as $csIni) {
            if (stripos(basename($csIni), 'intl') !== false) {
                $csLeftOut++;
            } else {
                @copy($csIni, $csDir . '/' . basename($csIni));
            }
        }
        if ($csDir !== null && $csLeftOut > 0) {
            [$code, $out, $why] = $csChild(['nointl'], $csDir);
        } else {
            $code = 3;
            $out = '';
            $why = '';
        }
        if ($csDir !== null) {
            array_map('unlink', glob($csDir . '/*') ?: []);
            @rmdir($csDir);
        }
        if ($code === 3) {
            echo "  (intl cannot be left out of this PHP, so the no-intl path was checked only on runners that lack it)\n";
        } else {
            check('without intl (a fresh PHP started without the extension) the words are matched as they were sent' . ($code === 0 ? '' : " ({$why})"), $code, 0);
            checkTrue('and says so', strpos($out, 'without intl matches the spelling it was sent in') !== false);
        }
    }
} else {
    echo "  (proc_open is disabled: the production-load check was skipped)\n";
}

$GLOBALS['wpdb'] = $csPreviousDb;
WP_Fake::reset();
