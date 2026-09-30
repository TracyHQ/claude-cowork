<?php
/**
 * Words a block theme holds: a full template (`wp_template`) and a registered block pattern.
 *
 * A block theme may keep a page's words in `templates/*.html` or in a pattern a page inserts with
 * `<!-- wp:pattern {"slug":"…"} /-->` — both are WordPress's own ways to build a site, so a caller
 * must be able to read them, and to change a template the way the Site Editor does (an override
 * row, whose delete hands the template back to the theme file). A pattern is read-only here: the
 * block editor changes one by expanding it into the record that inserts it, so that record is the
 * one written. Measured 30/09/2026 on the Tracy stand: /pricing/ was one `wp:pattern` line and
 * every word of it answered "not in the site's content".
 *
 * Runs the REAL site writer over the WordPress stubs. Loaded by run.php (uses `$WTOKEN`).
 */
declare(strict_types=1);

echo "\nTheme words: templates and patterns\n";

WP_Fake::reset();
$twWriter = new Claude_Cowork_Site_Writer();
$twLog = new FakeApplyLog();
$twEngine = new Engine($WTOKEN, [], null, null, null, null, $twWriter, null, $twLog);
$tw = static fn (string $action, array $params) => $twEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);

$twTheme = sys_get_temp_dir() . '/cowork-theme-words-' . bin2hex(random_bytes(4));
mkdir($twTheme . '/templates', 0777, true);
mkdir($twTheme . '/parts', 0777, true);
$twPage = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:post-content /--><!-- wp:paragraph --><p>Built with Tracy</p><!-- /wp:paragraph -->';
file_put_contents($twTheme . '/templates/page.html', $twPage);
file_put_contents($twTheme . '/templates/front-page.html', '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->');
file_put_contents($twTheme . '/parts/header.html', '<!-- wp:site-title /-->');
file_put_contents($twTheme . '/parts/footer-marketing.html', '<!-- wp:html --><footer>© 2026 Tracy</footer><!-- /wp:html -->');
WP_Fake::$themeDir = $twTheme;

// ── template: read from the theme file, written as an override, undone by its delete ────────────

check('an untouched template reads as absent, so its first write undoes by delete', $twWriter->read('template', 0, 'page'), null);
$got = $tw('content.get', ['kind' => 'template', 'key' => 'page']);
check('a theme-only template is served from the theme file', [$got['ok'] ?? null, $got['stored'] ?? null, $got['item']['content'] ?? null, $got['item']['source'] ?? null, $got['item']['file'] ?? null],
    [true, false, $twPage, 'theme', $twTheme . '/templates/page.html']);
check('a template neither stored nor in the theme is not found', $tw('content.get', ['kind' => 'template', 'key' => 'archive'])['error'] ?? null, 'not_found');
check('a slug with a path in it never reaches the file system', $twWriter->themeTemplate('../page'), null);

$edited = str_replace('Built with Tracy', 'Made with Tracy', $twPage);
$up = $tw('content.update', ['apply_id' => 'tw-1', 'kind' => 'template', 'key' => 'page', 'fields' => ['content' => $edited]]);
check('writing it creates the override the Site Editor would', [$up['ok'] ?? null, $up['created'] ?? null], [true, true]);
$row = WP_Fake::$posts[$up['id']] ?? [];
check('filed as a published wp_template under its slug', [$row['post_type'] ?? null, $row['post_name'] ?? null, $row['post_status'] ?? null], ['wp_template', 'page', 'publish']);
check('carrying the active theme, which is what makes WordPress render it', WP_Fake::$terms[$up['id'] . ':wp_theme'] ?? null, ['tracy']);
check('and no template-part area: a template has none', WP_Fake::$terms[$up['id'] . ':wp_template_part_area'] ?? null, null);
$stored = $tw('content.get', ['kind' => 'template', 'key' => 'page']);
check('the stored row is now what content.get reads', [$stored['item']['content'] ?? null, $stored['stored'] ?? true], [$edited, true]);
$twice = $tw('content.update', ['apply_id' => 'tw-2', 'kind' => 'template', 'key' => 'page', 'fields' => ['content' => $twPage]]);
check('a second write edits the same row', [$twice['id'] ?? null, $twice['created'] ?? null], [$up['id'], false]);
$tw('apply.revert', ['apply_id' => 'tw-2']);
$back = $tw('apply.revert', ['apply_id' => 'tw-1']);
check('undoing the first write deletes the override and hands the page back to the theme',
    [$back['ok'] ?? null, $twWriter->read('template', 0, 'page'), $tw('content.get', ['kind' => 'template', 'key' => 'page'])['stored'] ?? null], [true, null, false]);

WP_Fake::$stylesheet = 'twentytwentyfive';
check('another theme\'s template file is not this theme\'s override', $twWriter->read('template', 0, 'page'), null);
WP_Fake::$stylesheet = 'tracy';

// ── listing: every template and part the active theme renders, files and overrides alike ─────────

$tw('content.update', ['apply_id' => 'tw-3', 'kind' => 'templatePart', 'key' => 'header', 'fields' => ['content' => '<!-- wp:site-logo /-->']]);
$parts = $tw('content.list', ['kind' => 'templatePart']);
check('parts are listed from the theme files and the stored overrides, once each',
    array_map(static fn ($i) => [$i['key'], $i['stored']], $parts['items'] ?? []), [['footer-marketing', false], ['header', true]]);
$templates = $tw('content.list', ['kind' => 'template']);
check('templates too, with no bodies', array_map(static fn ($i) => [$i['key'], $i['stored'], isset($i['content'])], $templates['items'] ?? []),
    [['front-page', false, false], ['page', false, false]]);
check('a list names the kind it listed', [$parts['kind'] ?? null, $templates['kind'] ?? null], ['templatePart', 'template']);
check('any other kind is still refused, naming the door', $tw('content.list', ['kind' => 'option'])['error'] ?? null, 'bad_params');

// ── pattern: read by name from the registry, never written ──────────────────────────────────────

WP_Fake::$patterns['tracy/page-pricing'] = [
    'name' => 'tracy/page-pricing',
    'title' => 'Pricing',
    'content' => "<header><h1>One month free with JoomlArt</h1></header>\n",
    'filePath' => $twTheme . '/patterns/page-pricing.php',
];
$pat = $tw('content.get', ['kind' => 'pattern', 'key' => 'tracy/page-pricing']);
check('a registered pattern is read by its name, as rendered', [$pat['ok'] ?? null, $pat['kind'] ?? null, $pat['stored'] ?? null, $pat['item']['content'] ?? null, $pat['item']['title'] ?? null, $pat['item']['source'] ?? null],
    [true, 'pattern', false, "<header><h1>One month free with JoomlArt</h1></header>\n", 'Pricing', 'registry']);
check('an unknown pattern is not found', $tw('content.get', ['kind' => 'pattern', 'key' => 'tracy/nothing'])['error'] ?? null, 'not_found');
check('a pattern needs its name', $tw('content.get', ['kind' => 'pattern'])['error'] ?? null, 'bad_params');
$write = $tw('content.update', ['apply_id' => 'tw-4', 'kind' => 'pattern', 'key' => 'tracy/page-pricing', 'fields' => ['content' => 'x']]);
check('a pattern is never written: the record that inserts it is', [$write['error'] ?? null, str_contains((string) ($write['message'] ?? ''), 'expand')], ['bad_params', true]);
check('nor deleted', $tw('content.delete', ['apply_id' => 'tw-5', 'kind' => 'pattern', 'key' => 'tracy/page-pricing'])['error'] ?? null, 'bad_params');

foreach (['templates/page.html', 'templates/front-page.html', 'parts/header.html', 'parts/footer-marketing.html'] as $f) {
    unlink($twTheme . '/' . $f);
}
rmdir($twTheme . '/templates');
rmdir($twTheme . '/parts');
rmdir($twTheme);
WP_Fake::reset();
