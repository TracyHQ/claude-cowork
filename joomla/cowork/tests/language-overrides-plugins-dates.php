<?php
// Loaded by run.php, after language-overrides-ranking.php: the words and the dates a Joomla site written in vi-VN still
// printed in English on dev g60-ess-j-full (JA Essence j6 1.0.4, template 1.3.3, plugin 0.24.12, 10/10/2026):
//
// - "Page Navigation" (the aria label of every article's Previous/Next) and the registration form's "Show Profile",
//   "Postal / ZIP Code", "Phone", "About Me": plugin strings, which Joomla reads from administrator/language/<tag>/plg_*.ini
//   on the site too. The door read language/en-GB and the template's folder only, so they were never offered; the vi-VN
//   4.2.2 pack leaves the profile ones in English.
// - "Tháng sáu 9, 2023" beside the pack's "09 Tháng sáu 2023": the template's layouts print dates with
//   `HTMLHelper::_('date', $d, Text::_('F j, Y'))`. The format goes through Text::_ but names no key, so English order
//   wins. Text::_ looks the literal up upper-cased ("F J, Y"), so an override of that key gives the language's own format.
//
// What is real here: the lines those files ship (en-GB plg_content_pagenavigation.ini and plg_user_profile.ini of Joomla 6,
// the vi-VN 4.2.2 pack's profile file, JA Essence 1.3.3's layout calls), and the file the door writes.

if (function_exists('check')) {
    $pdToken = 'language-plugins-dates-token-16';
    $pdRoot = sys_get_temp_dir() . '/cowork-language-plugins-dates-' . bin2hex(random_bytes(6));
    $pdPut = static function (string $relative, string $bytes) use ($pdRoot): void {
        $path = $pdRoot . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);
    };
    $pdPut('templates/ja_essence/templateDetails.xml', "<?xml version=\"1.0\"?>\n<extension type=\"template\" client=\"site\"><name>ja_essence</name></extension>\n");
    $pdPut('templates/ja_essence/language/en-GB/en-GB.tpl_ja_essence.ini', "TPL_SHARE_ARTICLE=\"Share article:\"\n");
    $pdPut('templates/ja_essence/html/mod_articles_latest/default.php', "<span><?php echo HTMLHelper::_('date', \$item->created, Text::_('F j, Y')); ?></span>\n");
    $pdPut('templates/ja_essence/html/layouts/joomla/content/info_block/publish_date.php', implode("\n", [
        "<time datetime=\"<?php echo HTMLHelper::_('date', \$displayData['item']->publish_up, 'c'); ?>\">",
        "<?php echo HTMLHelper::_('date', \$displayData['item']->publish_up, JText::_( \"M d, Y\" )); ?>",
        "<?php echo HTMLHelper::_('date', \$x, Text::_('d M Y')); ?> <?php echo HTMLHelper::_('date', \$x, Text::_('l, j F Y')); ?>",
        "<?php echo HTMLHelper::_('date', \$x, Text::_('DATE_FORMAT_LC3')); ?> <?php echo Text::_('F'); ?>",
    ]) . "\n");
    $pdPut('language/en-GB/joomla.ini', "DATE_FORMAT_LC3=\"d F Y\"\nJUNE=\"June\"\n");
    $pdPut('language/vi-VN/joomla.ini', "DATE_FORMAT_LC3=\"d F Y\"\nJUNE=\"Tháng sáu\"\n");
    // Plugin strings, where Joomla keeps them: one the front end prints, one an administrator reads in the plugin's settings.
    $pdPut('administrator/language/en-GB/plg_content_pagenavigation.ini', "PLG_PAGENAVIGATION_ARIA_LABEL=\"Page Navigation\"\nPLG_PAGENAVIGATION_FIELD_POSITION_LABEL=\"Position\"\n");
    $pdPut('administrator/language/en-GB/plg_content_pagenavigation.sys.ini', "PLG_CONTENT_PAGENAVIGATION=\"Content - Page Navigation\"\n");
    $pdPut('administrator/language/vi-VN/plg_content_pagenavigation.ini', "PLG_PAGENAVIGATION_FIELD_POSITION_LABEL=\"Vị trí\"\n");
    $pdPut('plugins/content/pagenavigation/tmpl/default.php', "<nav class=\"pagenavigation\" aria-label=\"<?php echo Text::_('PLG_PAGENAVIGATION_ARIA_LABEL'); ?>\">\n");
    $pdPut('plugins/content/pagenavigation/pagenavigation.xml', "<field name=\"position\" label=\"PLG_PAGENAVIGATION_FIELD_POSITION_LABEL\"/>\n");
    $pdPut('administrator/language/en-GB/plg_user_profile.ini', implode("\n", [
        'PLG_USER_PROFILE_SLIDER_LABEL="Show Profile"',
        'PLG_USER_PROFILE_FIELD_POSTAL_CODE_LABEL="Postal / ZIP Code"',
        'PLG_USER_PROFILE_FIELD_PHONE_LABEL="Phone"',
        'PLG_USER_PROFILE_FIELD_NAME_REGISTER_LABEL="Postal / ZIP Code:"',
        'PLG_USER_PROFILE_OPTION_DISABLED="Disabled"',
    ]) . "\n");
    $pdPut('administrator/language/vi-VN/plg_user_profile.ini', implode("\n", [
        'PLG_USER_PROFILE_SLIDER_LABEL="Show Profile"',
        'PLG_USER_PROFILE_FIELD_POSTAL_CODE_LABEL="Postal / ZIP Code"',
        'PLG_USER_PROFILE_FIELD_PHONE_LABEL="Điện thoại"',
        'PLG_USER_PROFILE_OPTION_DISABLED="Disabled"',
    ]) . "\n");
    $pdPut('plugins/user/profile/forms/profile.xml', implode("\n", [
        '<form><fields name="profile"><fieldset name="profile" label="PLG_USER_PROFILE_SLIDER_LABEL">',
        '<field name="postal_code" label="PLG_USER_PROFILE_FIELD_POSTAL_CODE_LABEL"/><field name="phone" label="PLG_USER_PROFILE_FIELD_PHONE_LABEL"/>',
        '</fieldset></fields></form>',
    ]) . "\n");
    $pdPut('plugins/user/profile/profile.xml', "<field name=\"register-require_postal_code\" label=\"PLG_USER_PROFILE_FIELD_NAME_REGISTER_LABEL\"><option>PLG_USER_PROFILE_OPTION_DISABLED</option></field>\n");
    // A form of a plugin no visitor fills in: its words are an administrator's.
    $pdPut('plugins/task/checkfiles/forms/checkfiles.xml', "<field name=\"path\" label=\"PLG_TASK_CHECK_FILES_LABEL_DIRECTORY\"/>\n");
    $pdPut('administrator/language/en-GB/plg_task_checkfiles.ini', "PLG_TASK_CHECK_FILES_LABEL_DIRECTORY=\"Image Directory\"\n");

    $pdFiles = new LanguageOverrides($pdRoot);
    $pdEngine = (new Engine($pdToken, [], null, null, null, null, null, null, new FakeApplyLog()))->languageOverrides($pdFiles);
    $pdCall = static fn(array $params) => $pdEngine->handle(['token' => $pdToken, 'action' => 'template.languageOverrides', 'params' => $params]);
    $pdFile = static fn(): string => (string) @file_get_contents($pdRoot . '/language/overrides/vi-VN.override.ini');

    $pdRead = $pdCall(['template' => 'ja_essence', 'locale' => 'vi-VN']);
    $pdRows = [];
    foreach ($pdRead['strings'] ?? [] as $row) $pdRows[$row['key']] = $row;
    check('g60: the article navigation label a plugin prints is offered, missing from the pack', $pdRows['PLG_PAGENAVIGATION_ARIA_LABEL']['reason'] ?? null, 'missing');
    check('g60: the registration form labels the pack leaves English are offered', [
        $pdRows['PLG_USER_PROFILE_SLIDER_LABEL']['reason'] ?? null, $pdRows['PLG_USER_PROFILE_FIELD_POSTAL_CODE_LABEL']['reason'] ?? null,
    ], ['untranslated', 'untranslated']);
    check('a plugin string the pack translates is not offered', isset($pdRows['PLG_USER_PROFILE_FIELD_PHONE_LABEL']), false);
    checkTrue('a plugin string only its settings print is not offered (manifest params, a task form, a .sys.ini)',
        !isset($pdRows['PLG_PAGENAVIGATION_FIELD_POSITION_LABEL']) && !isset($pdRows['PLG_USER_PROFILE_FIELD_NAME_REGISTER_LABEL'])
        && !isset($pdRows['PLG_USER_PROFILE_OPTION_DISABLED']) && !isset($pdRows['PLG_TASK_CHECK_FILES_LABEL_DIRECTORY'])
        && !isset($pdRows['PLG_CONTENT_PAGENAVIGATION']));
    check('g60: a date format the layouts name through Text::_ in English order is offered with the language\'s own', [
        $pdRows['F J, Y']['reason'] ?? null, $pdRows['F J, Y']['source'] ?? null, $pdRows['F J, Y']['value'] ?? null,
    ], ['date-format', 'F j, Y', 'd F Y']);
    check('a short month keeps its width; JText and double quotes are read too', $pdRows['M D, Y']['value'] ?? null, 'd M Y');
    checkTrue('a format that already puts the day first, a language key and a lone letter are not offered',
        !isset($pdRows['D M Y']) && !isset($pdRows['L, J F Y']) && !isset($pdRows['DATE_FORMAT_LC3']) && !isset($pdRows['F']));

    $pdSet = $pdCall(['operation' => 'set', 'apply_id' => 'lover-pd-a', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => [
        'PLG_PAGENAVIGATION_ARIA_LABEL' => 'Điều hướng trang',
        'F J, Y' => 'd F Y',
        'M D, Y' => 'j M, Y',
    ]]);
    check('set writes the plugin string and the language\'s format', [$pdSet['ok'] ?? null, $pdSet['written'] ?? null], [true, 2]);
    check('a date format other than the language\'s own is refused', array_column($pdSet['refused'] ?? [], 'reason', 'key'), [
        'M D, Y' => 'a date format takes the language\'s own (d M Y)',
    ]);
    $pdWritten = LanguageOverrides::parse($pdFile());
    check('the override file holds them as Joomla reads them, the format under its upper-cased literal', [
        $pdWritten['PLG_PAGENAVIGATION_ARIA_LABEL'] ?? null, $pdWritten['F J, Y'] ?? null,
    ], ['Điều hướng trang', 'd F Y']);
    $pdCall(['operation' => 'set', 'apply_id' => 'lover-pd-b', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => ['M D, Y' => 'd M Y', 'F J, Y' => 'd F Y']]);
    check('a second set replaces the format line it wrote, never adds a second one', substr_count($pdFile(), 'F J, Y='), 1);
    check('and adds the other', LanguageOverrides::parse($pdFile())['M D, Y'] ?? null, 'd M Y');
    check('read now names the format overridden', [
        ($pdCall(['template' => 'ja_essence', 'locale' => 'vi-VN'])['overrides'] ?? [])['F J, Y'] ?? null,
    ], ['d F Y']);
}
