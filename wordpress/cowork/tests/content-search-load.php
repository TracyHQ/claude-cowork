<?php
/**
 * One `content.list` search, run in a fresh process that loads the plugin the way a site does.
 *
 * tests/run.php requires the engine files by hand, in its own order, so a new file the search needs
 * would pass every test there and still be a fatal on a site: the plugin file loads an explicit list
 * (`claude_cowork_load_engine()`), and `build.sh` copies `lib/*.php` whether or not that list names
 * them. This script takes the class list and the `class-*.php` files from claude-cowork.php ITSELF,
 * loads exactly those and nothing else, then searches. A class the list forgot is "Class not found"
 * here — exit 1 — and so a red test in content-search.php.
 *
 * Run by tests/content-search.php; also runnable on its own: `php tests/content-search-load.php`.
 *
 * With the argument `nointl` it also checks how the words are read by a PHP that has no intl (no class
 * `Normalizer`, which a WordPress host may well lack). The parent starts it without the ini file that
 * loads the extension; where intl is built into PHP there is no way to leave it out, and this exits 3.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

// A site has WordPress's functions and classes; these stand in for the few this path calls.
require_once __DIR__ . '/FakeWordPress.php';

$plugin = (string) file_get_contents($root . '/claude-cowork/claude-cowork.php');
if (!preg_match('/foreach \(\[([^\]]+)\] as \$class\)/', $plugin, $list)) {
    fwrite(STDERR, "cannot find the engine class list in claude-cowork.php\n");
    exit(2);
}
preg_match_all("/'([A-Za-z]+)'/", $list[1], $classes);
preg_match_all("/require_once \\\$lib \\. '\\/(class-claude-cowork-[a-z-]+\\.php)';/", $plugin, $wordpressFiles);
if (count($classes[1]) < 10 || count($wordpressFiles[1]) < 3) {
    fwrite(STDERR, "the loader in claude-cowork.php no longer has the shape this script reads\n");
    exit(2);
}
foreach ($classes[1] as $class) {
    require_once $root . '/lib/' . $class . '.php';
}
foreach ($wordpressFiles[1] as $file) {
    require_once $root . '/lib/' . $file;
}

// Each listed row carries its address.
if (!function_exists('get_permalink')) {
    function get_permalink($post)
    {
        return 'https://site.test/?p=' . (is_object($post) ? $post->ID : $post);
    }
}

// The test's database handle only needs SqlValue, which the list above loaded.
require_once __DIR__ . '/FakePostsDb.php';
$GLOBALS['wpdb'] = new WP_Fake_PostsDb();

WP_Fake::$posts = [
    3 => ['ID' => 3, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Roof repair services', 'post_name' => 'roof-repair-services', 'post_content' => ''],
    4 => ['ID' => 4, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About us', 'post_name' => 'about-us', 'post_content' => ''],
    5 => ['ID' => 5, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Emergency roof repair', 'post_name' => 'emergency-roof-repair', 'post_content' => ''],
];

// A token under 16 characters is no token: the engine refuses every request.
$token = 'a-token-of-16-plus-characters';
$engine = new Engine($token, [], null, null, null, null, new Claude_Cowork_Site_Writer(), null, null);
$answer = $engine->handle(['token' => $token, 'action' => 'content.list', 'params' => ['search' => ' Roof repair ']]);

$ids = array_column($answer['items'] ?? [], 'id');
if (($answer['ok'] ?? null) !== true || $ids !== [3, 5] || ($answer['search'] ?? null) !== 'Roof repair' || ($answer['matched'] ?? null) !== 2) {
    fwrite(STDERR, 'the search answered ' . json_encode($answer) . "\n");
    exit(1);
}

echo "search loads and answers with the plugin's own class list\n";

if (($argv[1] ?? '') === 'nointl') {
    if (class_exists('Normalizer')) {
        echo "intl cannot be left out of this PHP\n";
        exit(3);
    }
    // A title stored composed and one stored decomposed: with no Normalizer the words are matched in the
    // spelling they were sent in, and echoed as sent.
    $composed = "Vi\u{1EC7}t";
    $decomposed = "Vie\u{323}\u{302}t";
    WP_Fake::$posts = [
        41 => ['ID' => 41, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => "Nh\u{E0} h\u{E0}ng Vi\u{1EC7}t", 'post_name' => 'item-41', 'post_content' => ''],
        42 => ['ID' => 42, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => "Nha\u{300} ha\u{300}ng Vie\u{323}\u{302}t", 'post_name' => 'item-42', 'post_content' => ''],
    ];
    $ask = static fn (string $words): array => $engine->handle(['token' => $token, 'action' => 'content.list', 'params' => ['search' => $words]]);
    $got = [$ask($composed), $ask($decomposed)];
    $seen = [array_column($got[0]['items'] ?? [], 'id'), $got[0]['search'] ?? null, array_column($got[1]['items'] ?? [], 'id'), $got[1]['search'] ?? null, Claude_Cowork_Site_Writer::search_forms($composed)];
    if ($seen !== [[41], $composed, [42], $decomposed, [$composed]]) {
        fwrite(STDERR, 'without intl the search answered ' . json_encode($seen) . "\n");
        exit(1);
    }
    echo "search without intl matches the spelling it was sent in\n";
}
