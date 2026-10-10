<?php
// Loaded by run.php, after language-overrides.php: what `template.languageOverrides` offers FIRST on a site that also
// carries a big third-party language file. Measured 10/10/2026 on dev g59-ess-j-full (JA Essence j6 1.0.4, vi-VN,
// AcyMailing installed): plugin 0.24.11 walked the files template first, then language/en-GB in name order, so
// `en-GB.com_acym.ini` (2,861 offered strings, nearly all administrator text) came before joomla.ini and every
// mod_*.ini, and the silent cap of 1500 cut inside it. "All Rights Reserved" (MOD_FOOTER_LINE1), "Remember me"
// (JGLOBAL_REMEMBER_ME), "Advanced Search" (COM_FINDER_ADVANCED_SEARCH), "Jun" (JUNE_SHORT), "Details" (JDETAILS) and
// AcyMailing's consent line (ACYM_I_AGREE_TERMS) were never offered, so the site kept them in English.
//
// What is real here: the files' names and folders as that site has them, the keys and their en-GB words, the size of
// the AcyMailing file (2,861 strings the vi-VN pack does not have), and the code that names a key on the front end
// (a module's template, a layout, a component view). What stands in: the AcyMailing strings other than the consent
// line are generated, in its own naming.

if (function_exists('check')) {
    $lrRoot = sys_get_temp_dir() . '/cowork-language-ranking-' . bin2hex(random_bytes(6));
    $lrPut = static function (string $relative, string $bytes) use ($lrRoot): void {
        $path = $lrRoot . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);
    };
    $lrPut('templates/ja_essence/templateDetails.xml', "<?xml version=\"1.0\"?>\n<extension type=\"template\" client=\"site\"><name>ja_essence</name></extension>\n");
    $lrPut('templates/ja_essence/language/en-GB/en-GB.tpl_ja_essence.ini', "TPL_SHARE_ARTICLE=\"Share article:\"\nTPL_TAGS_IN=\"Tags in: \"\n");
    // The template prints the article details heading with a core key.
    $lrPut('templates/ja_essence/html/layouts/joomla/content/info_block.php', "<?php echo Text::_('JDETAILS'); ?>\n");
    // AcyMailing: 2,861 strings, the consent line in the middle, the rest administrator words.
    $acym = [];
    for ($i = 0; $i < 2860; $i++) {
        $acym[] = sprintf('ACYM_ADMIN_STRING_%04d="Configure the campaign option number %d"', $i, $i);
        if ($i === 1430) $acym[] = 'ACYM_I_AGREE_TERMS="I agree with the %s"';
    }
    $lrPut('language/en-GB/en-GB.com_acym.ini', implode("\n", $acym) . "\n");
    $lrPut('language/en-GB/mod_acym.ini', "MOD_ACYM=\"AcyMailing subscription form\"\n");
    // The module renders its form through the component's shared partial, as AcyMailing 9 does.
    $lrPut('modules/mod_acym/mod_acym.php', "<?php require JPATH_ADMINISTRATOR . '/components/com_acym/partial/forms/termspolicy.php';\n");
    $lrPut('administrator/components/com_acym/partial/forms/termspolicy.php', "<?php echo acym_translationSprintf('ACYM_I_AGREE_TERMS', \$link); ?>\n");
    // Administrator code names every other AcyMailing key; that is not the front end.
    $lrPut('administrator/components/com_acym/Controllers/Campaigns.php', "<?php acym_translation('ACYM_ADMIN_STRING_0001'); acym_translation('ACYM_ADMIN_STRING_0002');\n");
    $lrPut('language/en-GB/joomla.ini', implode("\n", [
        'JDETAILS="Details"',
        'JGLOBAL_REMEMBER_ME="Remember me"',
        'JUNE="June"',
        'JUNE_SHORT="Jun"',
        'MONDAY="Monday"',
        'DATE_FORMAT_LC3="d F Y"',
        'JGLOBAL_ARCHIVE_OPTIONS="Archive options"',
    ]) . "\n");
    $lrPut('language/en-GB/mod_footer.ini', "MOD_FOOTER_LINE1=\"Copyright &#169; %date% %sitename%. All Rights Reserved.\"\n");
    $lrPut('language/en-GB/mod_finder.ini', "COM_FINDER_ADVANCED_SEARCH=\"Advanced Search\"\n");
    $lrPut('language/en-GB/mod_login.ini', "MOD_LOGIN_VALUE_USERNAME=\"Username\"\n");
    $lrPut('modules/mod_login/tmpl/default.php', "<?php echo Text::_('JGLOBAL_REMEMBER_ME'); ?>\n");
    $lrPut('modules/mod_finder/tmpl/default.php', "<?php echo Text::_('COM_FINDER_ADVANCED_SEARCH'); ?>\n");
    // The vi-VN pack: its joomla.ini keeps these in English (MONDAY left English here to see a day name ranked), it has no AcyMailing file.
    $lrPut('language/vi-VN/joomla.ini', implode("\n", [
        'JDETAILS="Banner được lưu thành công."',
        'JGLOBAL_REMEMBER_ME="Remember me"',
        'JUNE="Tháng sáu"',
        'JUNE_SHORT="Jun"',
        'MONDAY="Monday"',
        'DATE_FORMAT_LC3="d F Y"',
        'JGLOBAL_ARCHIVE_OPTIONS="Archive options"',
    ]) . "\n");
    $lrPut('language/vi-VN/mod_footer.ini', "MOD_FOOTER_LINE1=\"Copyright &#169; %date% %sitename%. All Rights Reserved.\"\n");
    $lrPut('language/vi-VN/mod_finder.ini', "COM_FINDER_ADVANCED_SEARCH=\"Advanced Search\"\n");

    $lrFiles = new LanguageOverrides($lrRoot);
    $lrToken = 'language-ranking-token-16';
    $lrEngine = (new Engine($lrToken, [], null, null, null, null, null, null, new FakeApplyLog()))->languageOverrides($lrFiles);
    $lrCall = static fn(array $params) => $lrEngine->handle(['token' => $lrToken, 'action' => 'template.languageOverrides', 'params' => $params]);

    $page = $lrCall(['template' => 'ja_essence', 'locale' => 'vi-VN']);
    $keys = array_column($page['strings'] ?? [], 'key');
    $at = array_flip($keys);
    $want = ['MOD_FOOTER_LINE1', 'JGLOBAL_REMEMBER_ME', 'COM_FINDER_ADVANCED_SEARCH', 'JUNE_SHORT', 'JDETAILS', 'ACYM_I_AGREE_TERMS'];
    $offered = [];
    foreach ($want as $k) $offered[$k] = isset($at[$k]) && $at[$k] < 40;
    check('the strings a visitor reads are offered within the first 40, ahead of 2,861 administrator strings', $offered, array_fill_keys($want, true));
    check('the template words still come first', array_slice($keys, 0, 2), ['TPL_SHARE_ARTICLE', 'TPL_TAGS_IN']);
    check('a key the front end names ranks as used', array_column($page['strings'], 'rank', 'key')['ACYM_I_AGREE_TERMS'] ?? null, 'used');
    check('a month and day name ranks as used: the date prints it', [array_column($page['strings'], 'rank', 'key')['JUNE_SHORT'] ?? null, array_column($page['strings'], 'rank', 'key')['MONDAY'] ?? null], ['used', 'used']);
    check('an unnamed core string comes before any unnamed third-party one', $at['JGLOBAL_ARCHIVE_OPTIONS'] < $at['ACYM_ADMIN_STRING_0000'], true);
    checkTrue('a key only administrator code names is not used', (array_column($page['strings'], 'rank', 'key')['ACYM_ADMIN_STRING_0001'] ?? null) === 'other');
    checkTrue('a date format is configuration, never offered as words', !isset($at['DATE_FORMAT_LC3']));
    check('the first page says it is not the whole list, and how many there are', [$page['truncated'] ?? null, $page['total'] ?? null, count($keys), $page['nextOffset'] ?? null],
        [true, 2872, LanguageOverrides::MAX_STRINGS, LanguageOverrides::MAX_STRINGS]);

    $rest = $lrCall(['template' => 'ja_essence', 'locale' => 'vi-VN', 'offset' => $page['nextOffset']]);
    $all = array_merge($keys, array_column($rest['strings'] ?? [], 'key'));
    check('the next page holds the rest, and says it is the last', [$rest['truncated'] ?? null, array_key_exists('nextOffset', $rest) ? $rest['nextOffset'] : 'absent', count($all), count(array_unique($all))],
        [false, null, $page['total'], $page['total']]);
    check('a small page is a page', [count($lrCall(['template' => 'ja_essence', 'locale' => 'vi-VN', 'limit' => 5])['strings'] ?? []), $lrCall(['template' => 'ja_essence', 'locale' => 'vi-VN', 'limit' => 5])['nextOffset'] ?? null], [5, 5]);
    check('a page bigger than the cap is refused', $lrCall(['template' => 'ja_essence', 'locale' => 'vi-VN', 'limit' => LanguageOverrides::MAX_STRINGS + 1])['error'] ?? null, 'bad_params');
    check('a negative offset is refused', $lrCall(['template' => 'ja_essence', 'locale' => 'vi-VN', 'offset' => -1])['error'] ?? null, 'bad_params');

    // A key from the second page is as writable as one from the first.
    $last = end($all);
    $set = $lrCall(['operation' => 'set', 'apply_id' => 'lrank-a', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => [$last => 'Cấu hình', 'DATE_FORMAT_LC3' => 'j F Y']]);
    check('a key of a later page is written; a date format is not', [$set['ok'] ?? null, $set['written'] ?? null, array_column($set['refused'] ?? [], 'key')], [true, 1, ['DATE_FORMAT_LC3']]);
}
