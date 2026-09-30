<?php
/**
 * `content.list` `search`: find a post by the words of its title (or slug) instead of paging.
 *
 * Runs the REAL engine and the REAL site writer. What stands in for WordPress is `WP_Query` and the
 * reads a row is described by (FakeWordPress.php), and a `$wpdb` (FakePostsDb.php) that READS the
 * statements the writer builds and answers from the fake posts: a pattern that forgot to escape `%`,
 * an OR that lost its parentheses, a missing ORDER BY or a wrong page shows up as a wrong answer here,
 * not as a string that happens to look right.
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
foreach (['an empty string' => '', 'spaces' => '   ', 'a no-break space' => "\u{A0}", 'an ideographic space' => "\u{3000}\u{3000}", 'control characters' => "\x00\x1f\x7f"] as $what => $words) {
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

// templates and every other kind: the parameter is refused, never ignored
foreach (['templatePart', 'template'] as $kind) {
    $r = $csAsk(['kind' => $kind, 'search' => 'header']);
    check("{$kind} cannot be searched: refused, naming the kind", [$r['ok'] ?? null, $r['error'] ?? null, strpos((string) ($r['message'] ?? ''), "\"{$kind}\"") !== false, array_key_exists('items', $r)], [false, 'bad_params', true, false]);
    check("{$kind}: even an empty search is refused, so the key never goes unread", $csAsk(['kind' => $kind, 'search' => ''])['error'] ?? null, 'bad_params');
    check("{$kind}: a null search is the key too", $csAsk(['kind' => $kind, 'search' => null])['error'] ?? null, 'bad_params');
    check("{$kind} still lists without a search", $csAsk(['kind' => $kind])['ok'] ?? null, true);
}
foreach (['option', 'menuItem', 'user', 'page', 'menutype'] as $kind) {
    $r = $csAsk(['kind' => $kind, 'search' => 'x']);
    check("kind {$kind} with a search is refused, naming it", [$r['ok'] ?? null, $r['error'] ?? null, strpos((string) ($r['message'] ?? ''), "\"{$kind}\"") !== false], [false, 'bad_params', true]);
}

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
});
check('a composed needle finds the title stored composed AND the one stored decomposed', $csIds($csAsk(['search' => "Vi\u{1EC7}t"])), [41, 42]);
$r = $csAsk(['search' => "Vie\u{323}\u{302}t"]);
check('a decomposed needle finds both too, and is echoed composed', [$csIds($r), $r['search'] ?? null, $r['matched'] ?? null], [[41, 42], "Vi\u{1EC7}t", 2]);
check('a needle spanning composed letters in the middle of the words', $csIds($csAsk(['search' => "h\u{E0}ng Vi"])), [41, 42]);
check('an accent on a Latin letter, both ways', [$csIds($csAsk(['search' => "Caf\u{E9}"])), $csIds($csAsk(['search' => "Cafe\u{301}"]))], [[46, 47], [46, 47]]);
check('the writer tries the composed and the decomposed spelling', Claude_Cowork_Site_Writer::search_forms("Caf\u{E9}"), ["Caf\u{E9}", "Cafe\u{301}"]);
check('CJK: two characters', $csIds($csAsk(['search' => '屋根'])), [43]);
check('CJK: one character is enough', $csIds($csAsk(['search' => '修'])), [43]);
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
check('a less-than sign is left as it is: KSES drops it, it never stores it as an entity', Claude_Cowork_Site_Writer::search_forms('a < b'), ['a < b']);
check('the default types are posts and pages', $csIds($csAsk(['search' => 'gizmo'])), [83, 84]);
check('a custom type is searched when it is named', $csIds($csAsk(['search' => 'gizmo', 'post_type' => 'event'])), [81]);
check('"any" is every type not kept out of search, as WP_Query reads it', $csIds($csAsk(['search' => 'gizmo', 'post_type' => 'any'])), [81, 83, 84]);

// ── a failed statement is an error, never an empty page ─────────────────────────────────────────

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

// ── the plugin loads the way a site loads it ────────────────────────────────────────────────────

if (function_exists('proc_open')) {
    $proc = proc_open([PHP_BINARY, __DIR__ . '/content-search-load.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    check('a search runs with only the classes claude-cowork.php loads' . ($code === 0 ? '' : " ({$err})"), $code, 0);
    checkTrue('and says so', strpos($out, 'search loads and answers') !== false);
} else {
    echo "  (proc_open is disabled: the production-load check was skipped)\n";
}

$GLOBALS['wpdb'] = $csPreviousDb;
WP_Fake::reset();
