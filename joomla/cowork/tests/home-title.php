<?php
// Loaded by run.php: the home tab reads the site name alone once Tracy put the name in every page
// title (TCH #1013, D1, spec NAME-1; lib/HomeTitle.php). Pure rules; the system plugin applies them
// to the home page only.

require_once __DIR__ . '/../lib/HomeTitle.php';

if (function_exists('check')) {
    $htName = 'Tamarind Bakery';
    check('"Home - Name" on a site Tracy switched on reads the name alone',
        HomeTitle::of('Home - Tamarind Bakery', $htName, 2, 2), $htName);
    check('"Name - Name" reads the name once', HomeTitle::of('Tamarind Bakery - Tamarind Bakery', $htName, 2, 2), $htName);
    check('the switch and the mark may be strings, as configuration.php may hold them',
        HomeTitle::of('Home - Tamarind Bakery', $htName, '2', '2'), $htName);
    check('a language with another JPAGETITLE is read by its own format',
        HomeTitle::of('Trang chủ | Tamarind Bakery', $htName, 2, 2, '%1$s | %2$s'), $htName);
    check('a JPAGETITLE without both places falls back to Joomla\'s own format',
        HomeTitle::of('Home - Tamarind Bakery', $htName, 2, 2, 'JPAGETITLE'), $htName);
    check('a name with pattern characters is matched as text',
        HomeTitle::of('Home - A+B (Co.)', 'A+B (Co.)', 2, 2), 'A+B (Co.)');
    check('nothing to change on a site Tracy did not switch on (no mark)', HomeTitle::of('Home - Tamarind Bakery', $htName, 2, null), null);
    check('nor when the mark names another value', HomeTitle::of('Home - Tamarind Bakery', $htName, 2, 1), null);
    check('nor when the owner turned the switch away since', [HomeTitle::of('Tamarind Bakery - Home', $htName, 1, 2), HomeTitle::of('Home', $htName, 0, 2)], [null, null]);
    check('a title already the name alone is left', HomeTitle::of($htName, $htName, 2, 2), null);
    check('a title that does not end in the name is left (a title the owner wrote by hand)',
        [HomeTitle::of('Fresh bread every morning', $htName, 2, 2), HomeTitle::of('Home - Tamarind Bakery Ltd', $htName, 2, 2), HomeTitle::of(' - Tamarind Bakery', $htName, 2, 2)],
        [null, null, null]);
    check('an empty site name changes nothing', HomeTitle::of('Home - ', '', 2, 2), null);

    // Whether the request is the home entry's own page. The router merges the entry's query into the
    // request, so on "/" the request holds the entry's own values, raw.
    $htEss = ['option' => 'com_content', 'view' => 'featured', 'layout' => 'ja_essence:news'];
    check('the home page of an entry with a template layout (ja-essence j6 1.0.3, entry 134) is its own page',
        HomeTitle::isEntryPage($htEss, $htEss), true);
    check('the home page of an entry without a layout is its own page',
        HomeTitle::isEntryPage(['option' => 'com_content', 'view' => 'featured'], ['option' => 'com_content', 'view' => 'featured']), true);
    check('another layout under the entry is not its own page',
        HomeTitle::isEntryPage($htEss, ['option' => 'com_content', 'view' => 'featured', 'layout' => 'ja_essence:other']), false);
    check('another view under the entry is not its own page (the login page)',
        HomeTitle::isEntryPage($htEss, ['option' => 'com_users', 'view' => 'login']), false);
    check('an article reached under an entry with no id is not its own page',
        HomeTitle::isEntryPage(['option' => 'com_content', 'view' => 'featured'], ['option' => 'com_content', 'view' => 'article', 'id' => '7:slug']), false);
    check('an entry for one article is its own page with that id, "7:slug" read as 7',
        [HomeTitle::isEntryPage(['option' => 'com_content', 'view' => 'article', 'id' => '7'], ['option' => 'com_content', 'view' => 'article', 'id' => '7:slug']),
         HomeTitle::isEntryPage(['option' => 'com_content', 'view' => 'article', 'id' => '7'], ['option' => 'com_content', 'view' => 'article', 'id' => '8'])],
        [true, false]);
}
