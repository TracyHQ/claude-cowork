<?php
// Loaded by run.php, after language-overrides-literals.php: the English a Joomla site written in vi-VN still printed after
// round 5 (dev g61-ess-j-full, JA Essence j6 1.0.4, plugin 0.24.13, 11/10/2026; TracyHQ/tch#1013):
//
// - the registration form's "Show Profile" and "Postal / ZIP Code". The vi-VN 4.2.2 pack holds them in ENGLISH, but not in the
//   en-GB words: en-GB says "User Profile" and "Postal/ZIP Code" (Joomla 6). A value byte-equal to en-GB was offered as
//   `untranslated`; a reworded English one was taken for a translation and never offered. "Phone" (byte-equal) was offered
//   and printed "Điện thoại" on the same page.
// - the T4 mega menu's column titles "User", "J!Pages", "J! Content" on all 158 pages: T4 keeps them in the template's
//   navigation profile (`etc/navigation/<profile>.json`, a `local/` copy wins) and prints them raw
//   (`plugins/system/t4/themes/base/html/mod_menu/mega_sub.php`: `echo $col['title']`), so no key and no row held them.
//
// What is real here: the lines of Joomla 6's en-GB plg_user_profile.ini and of the vi-VN 4.2.2 pack's, and JA Essence 1.3.3's
// `etc/navigation/default.json` (its `mega_settings`, a JSON string, as shipped).

require_once __DIR__ . '/../lib/PageLiterals.php';

if (function_exists('check')) {
    $epToken = 'language-english-pack-token-16';
    $epRoot = sys_get_temp_dir() . '/cowork-language-english-pack-' . bin2hex(random_bytes(6));
    $epPut = static function (string $relative, string $bytes) use ($epRoot): void {
        $path = $epRoot . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);
    };
    $epPut('templates/ja_essence/templateDetails.xml', "<?xml version=\"1.0\"?>\n<extension type=\"template\" client=\"site\"><name>ja_essence</name></extension>\n");
    $epPut('templates/ja_essence/language/en-GB/en-GB.tpl_ja_essence.ini', "TPL_SHARE_ARTICLE=\"Share article:\"\nTPL_SHOW_MORE=\"Show more\"\n");
    $epPut('language/en-GB/joomla.ini', "DATE_FORMAT_LC3=\"d F Y\"\nJGLOBAL_EMAIL=\"Email\"\nJNEXT=\"Next\"\n");
    $epPut('language/vi-VN/joomla.ini', "DATE_FORMAT_LC3=\"d F Y\"\nJGLOBAL_EMAIL=\"Email\"\nJNEXT=\"Tiếp\"\n");
    $epPut('administrator/language/en-GB/plg_user_profile.ini', implode("\n", [
        'PLG_USER_PROFILE_FIELD_COUNTRY_LABEL="Country"',
        'PLG_USER_PROFILE_FIELD_PHONE_LABEL="Phone"',
        'PLG_USER_PROFILE_FIELD_POSTAL_CODE_LABEL="Postal/ZIP Code"',
        'PLG_USER_PROFILE_FIELD_WEBSITE_LABEL="Website"',
        'PLG_USER_PROFILE_SLIDER_LABEL="User Profile"',
    ]) . "\n");
    $epPut('administrator/language/vi-VN/plg_user_profile.ini', implode("\n", [
        'PLG_USER_PROFILE_FIELD_COUNTRY_LABEL="Quốc gia"',
        'PLG_USER_PROFILE_FIELD_PHONE_LABEL="Phone"',
        'PLG_USER_PROFILE_FIELD_POSTAL_CODE_LABEL="Postal / ZIP Code"',
        'PLG_USER_PROFILE_FIELD_WEBSITE_LABEL="Trang web"',
        'PLG_USER_PROFILE_SLIDER_LABEL="Show Profile"',
    ]) . "\n");
    $epPut('plugins/user/profile/forms/profile.xml', implode("\n", [
        '<form><fields name="profile"><fieldset name="profile" label="PLG_USER_PROFILE_SLIDER_LABEL">',
        '<field name="country" label="PLG_USER_PROFILE_FIELD_COUNTRY_LABEL"/><field name="postal_code" label="PLG_USER_PROFILE_FIELD_POSTAL_CODE_LABEL"/>',
        '<field name="phone" label="PLG_USER_PROFILE_FIELD_PHONE_LABEL"/><field name="website" label="PLG_USER_PROFILE_FIELD_WEBSITE_LABEL"/>',
        '</fieldset></fields></form>',
    ]) . "\n");
    // JA Essence 1.3.3 etc/navigation/default.json, the menu "mainmenu", item 102's mega dropdown (as shipped, abridged).
    $epMega = ['mainmenu' => [
        '101' => ['align' => 'left', 'settings' => [['contents' => [[]]]], 'name' => 'None'],
        '102' => ['width' => 600, 'megabuild' => 1, 'align' => 'center', 'caption' => 'Every page of the demo', 'settings' => [['contents' => [
            ['name' => 'Selected Items', 'type' => 'items', 'title' => 'User', 'items' => '106,107,108,151'],
            ['name' => 'Selected Items', 'type' => 'items', 'title' => 'J!Pages', 'items' => '109,110,111,112'],
            ['name' => 'Selected Items', 'type' => 'items', 'title' => 'J! Content', 'items' => '113,114,115,146'],
            ['name' => 'Module', 'type' => 'module', 'title' => '', 'module' => '91'],
        ]]]],
    ]];
    $epPut('templates/ja_essence/etc/navigation/default.json', json_encode(['menu_type' => 'mainmenu', 'mega_settings' => json_encode($epMega)]));
    // A profile with a `local/` copy: the copy is what T4 reads, so its words are offered (and the shipped one's too).
    $epPut('templates/ja_essence/local/etc/navigation/offcanvas.json', json_encode(['mega_settings' => ['mainmenu' => ['7' => ['settings' => [['contents' => [['title' => 'Our Shop']]]]]]]]));
    // Not a visitor's words: a navigation file that is not JSON, and a title that is no words.
    $epPut('templates/ja_essence/etc/navigation/broken.json', '{"mega_settings": ');
    $epPut('templates/ja_essence/etc/navigation/numbers.json', json_encode(['mega_settings' => ['mainmenu' => ['9' => ['settings' => [['contents' => [['title' => '2024']]]]]]]]));

    $epFiles = new LanguageOverrides($epRoot);
    $epEngine = (new Engine($epToken, [], null, null, null, null, null, null, new FakeApplyLog()))->languageOverrides($epFiles);
    $epCall = static fn(array $params) => $epEngine->handle(['token' => $epToken, 'action' => 'template.languageOverrides', 'params' => $params]);
    $epRows = [];
    foreach ($epCall(['template' => 'ja_essence', 'locale' => 'vi-VN'])['strings'] ?? [] as $row) $epRows[$row['key']] = $row;

    check('g61: a pack value in reworded English is offered as untranslated ("Show Profile" for en-GB "User Profile")',
        $epRows['PLG_USER_PROFILE_SLIDER_LABEL']['reason'] ?? null, 'untranslated');
    check('g61: a pack value that is the en-GB words spaced otherwise is offered ("Postal / ZIP Code")',
        $epRows['PLG_USER_PROFILE_FIELD_POSTAL_CODE_LABEL']['reason'] ?? null, 'untranslated');
    check('it is offered from the en-GB words, which a set translates', $epRows['PLG_USER_PROFILE_SLIDER_LABEL']['source'] ?? null, 'User Profile');
    checkTrue('a pack value in the language\'s own words is not offered', !isset($epRows['PLG_USER_PROFILE_FIELD_COUNTRY_LABEL']) && !isset($epRows['PLG_USER_PROFILE_FIELD_WEBSITE_LABEL']) && !isset($epRows['JNEXT']));
    check('the byte-equal English stays offered', $epRows['PLG_USER_PROFILE_FIELD_PHONE_LABEL']['reason'] ?? null, 'untranslated');

    $epTitles = [];
    foreach ($epRows as $row) if (($row['reason'] ?? null) === 'literal' && str_contains((string) ($row['file'] ?? ''), '/navigation/')) $epTitles[$row['source']] = $row['key'];
    ksort($epTitles);
    check('g61: every mega column title and caption of the template\'s navigation profiles is offered as a page literal', $epTitles, [
        'Every page of the demo' => 'TRACY_LITERAL_EVERY_PAGE_OF_THE_DEMO',
        'J! Content' => 'TRACY_LITERAL_J_CONTENT',
        'J!Pages' => 'TRACY_LITERAL_J_PAGES',
        'Our Shop' => 'TRACY_LITERAL_OUR_SHOP',
        'User' => 'TRACY_LITERAL_USER',
    ]);
    // The output filter then prints the override's words where T4 printed the title.
    $epSet = $epCall(['operation' => 'set', 'apply_id' => 'ep-1', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => [
        'TRACY_LITERAL_J_PAGES' => 'Trang', 'TRACY_LITERAL_USER' => "Ng\u{01B0}\u{1EDD}i d\u{00F9}ng", 'PLG_USER_PROFILE_SLIDER_LABEL' => "H\u{1ED3} s\u{01A1} ng\u{01B0}\u{1EDD}i d\u{00F9}ng",
    ]]);
    check('a set writes the column titles and the reworded strings', $epSet['written'] ?? null, 3);
    $epWords = PageLiterals::fromOverrides(LanguageOverrides::parse((string) file_get_contents($epRoot . '/language/overrides/vi-VN.override.ini')));
    check('the mega column titles print in the site\'s language', PageLiterals::translate(
        '<h3 class="mega-col-title"><span>User</span></h3><h3 class="mega-col-title"><span>J!Pages</span></h3>', $epWords),
        "<h3 class=\"mega-col-title\"><span>Ng\u{01B0}\u{1EDD}i d\u{00F9}ng</span></h3><h3 class=\"mega-col-title\"><span>Trang</span></h3>");

    // An English site's own pack is English: none of it is taken for an untranslated string.
    $epPut('administrator/language/en-US/plg_user_profile.ini', "PLG_USER_PROFILE_SLIDER_LABEL=\"Show Profile\"\n");
    $epUs = [];
    foreach ((new LanguageOverrides($epRoot))->offered('ja_essence', 'en-US') as $row) $epUs[$row['key']] = $row;
    checkTrue('an en-* language never takes its own English for untranslated', !isset($epUs['PLG_USER_PROFILE_SLIDER_LABEL']));
}
