<?php
// Loaded by run.php: `template.languageOverrides` (TracyHQ/tch#1013). A site whose default language is vi-VN still read
// "Share article:", "Tags in:" and "Author's latest articles" (JA Essence ships its words in en-GB only), "All Rights
// Reserved." (the vi-VN pack leaves MOD_FOOTER_LINE1 in English) and "Banner được lưu thành công." above every article's
// details (vi-VN 4.2.2.1 ships COM_CONTENT_ARTICLE_INFO, "Details", as the translation of another string). Measured
// 10/10/2026 on dev g58-ess-j-full (JA Essence j6 1.0.4, template 1.3.3, Joomla 6).
//
// What is real here: a site root on disk with the lines those files ship, byte for byte (en-GB.tpl_ja_essence.ini of
// JA Essence 1.3.3; com_content.ini and mod_footer.ini of en-GB and of the vi-VN 4.2.2.1 pack), and the file the door
// writes and deletes. What stands in: the undo log and the site writer.

if (function_exists('check')) {
    $loToken = 'language-overrides-token-16';
    $loRoot = sys_get_temp_dir() . '/cowork-language-overrides-' . bin2hex(random_bytes(6));
    $loPut = static function (string $relative, string $bytes) use ($loRoot): void {
        $path = $loRoot . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);
    };
    $loPut('templates/ja_essence/templateDetails.xml', "<?xml version=\"1.0\"?>\n<extension type=\"template\" client=\"site\"><name>ja_essence</name></extension>\n");
    $loPut('templates/ja_essence/language/en-GB/en-GB.tpl_ja_essence.ini', implode("\n", [
        ';Template Info',
        'T4_TPL_DESC_6         		="<a href=\'//www.joomlart.com/downloads/joomla-templates/ja-essence\' title=\'Download\'><span class=\'fal fa-download\'></span>Download</a>"',
        'TPL_CONTENT_WRITTEN_BY = "By %s"',
        'TPL_SHARE_ARTICLE = "Share article:"',
        'TPL_TAGS_IN = "Tags in: "',
        'TPL_AUTHOR_ARTICLE_LASTEST = "Author\'s latest articles";',
        'TPL_CONTACT_SEND = "Send message"',
        'TPL_BACK = "\"Back\""',
    ]) . "\n");
    $loPut('templates/ja_essence/language/en-GB/en-GB.tpl_ja_essence.sys.ini', "TPL_JA_ESSENCE_XML_DESCRIPTION=\"Minimal Blogging Joomla Template\"\n");
    // Another template's words are not this site's front end.
    $loPut('language/en-GB/tpl_cassiopeia.ini', "TPL_CASSIOPEIA_SKIP=\"Skip to main content\"\n");
    $loPut('language/en-GB/com_content.ini', implode("\n", [
        'COM_CONTENT_ARTICLE_INFO="Details"',
        'COM_CONTENT_READ_MORE="Read more &hellip;"',
        'COM_CONTENT_WRITTEN_BY="Written by: %s"',
        'COM_CONTENT_FIELD_NEW_SINCE_J4="Added after the pack"',
    ]) . "\n");
    $loPut('language/en-GB/mod_footer.ini', "MOD_FOOTER=\"Footer\"\nMOD_FOOTER_LINE1=\"Copyright &#169; %date% %sitename%. All Rights Reserved.\"\n");
    $loPut('language/en-GB/mod_footer.sys.ini', "MOD_FOOTER=\"Footer\"\n");
    $loPut('language/vi-VN/com_content.ini', implode("\n", [
        'COM_CONTENT_ARTICLE_INFO="Banner được lưu thành công."',
        'COM_CONTENT_READ_MORE="Chi tiết &hellip;"',
        'COM_CONTENT_WRITTEN_BY="Được viết bởi: %s"',
    ]) . "\n");
    $loPut('language/vi-VN/mod_footer.ini', "MOD_FOOTER=\"Footer\"\nMOD_FOOTER_LINE1=\"Copyright &#169; %date% %sitename%. All Rights Reserved.\" ; Note : %date% will be auto replaced by current year! Don't translate\n");

    $loFiles = new LanguageOverrides($loRoot);
    $loLog = new FakeApplyLog();
    $loEngine = (new Engine($loToken, [], null, null, null, null, null, null, $loLog))->languageOverrides($loFiles);
    $loCall = static fn(array $params) => $loEngine->handle(['token' => $loToken, 'action' => 'template.languageOverrides', 'params' => $params]);
    $loFile = static fn(): ?string => is_file($loRoot . '/language/overrides/vi-VN.override.ini') ? (string) file_get_contents($loRoot . '/language/overrides/vi-VN.override.ini') : null;

    // ---- what reads in en-GB ---------------------------------------------------------------------
    $read = $loCall(['template' => 'ja_essence', 'locale' => 'vi-VN']);
    $loReason = [];
    foreach ($read['strings'] ?? [] as $row) $loReason[$row['key']] = $row['reason'];
    check('read answers', $read['ok'] ?? null, true);
    check('the template words come first, then the extensions', array_slice(array_keys($loReason), 0, 2), ['TPL_CONTENT_WRITTEN_BY', 'TPL_SHARE_ARTICLE']);
    check('every template string the pack cannot have is missing', [$loReason['TPL_SHARE_ARTICLE'] ?? null, $loReason['TPL_TAGS_IN'] ?? null, $loReason['TPL_AUTHOR_ARTICLE_LASTEST'] ?? null], ['missing', 'missing', 'missing']);
    check('a pack string left in English is untranslated', $loReason['MOD_FOOTER_LINE1'] ?? null, 'untranslated');
    check('a key the pack predates is missing', $loReason['COM_CONTENT_FIELD_NEW_SINCE_J4'] ?? null, 'missing');
    check('"Details" given as another string\'s translation is suspect', $loReason['COM_CONTENT_ARTICLE_INFO'] ?? null, 'suspect');
    checkTrue('a translated string is not offered', !isset($loReason['COM_CONTENT_READ_MORE']) && !isset($loReason['COM_CONTENT_WRITTEN_BY']));
    checkTrue('markup, administrator strings and another template are not offered',
        !isset($loReason['T4_TPL_DESC_6']) && !isset($loReason['TPL_JA_ESSENCE_XML_DESCRIPTION']) && !isset($loReason['TPL_CASSIOPEIA_SKIP']));
    check('a short label the pack names "Footer" is that word, untranslated', $loReason['MOD_FOOTER'] ?? null, 'untranslated');
    check('an escaped quote reads as a quote', array_column($read['strings'], 'source', 'key')['TPL_BACK'] ?? null, '"Back"');
    check('nothing is overridden yet', $read['overrides'], []);
    check('the other languages are refused', $loCall(['template' => 'ja_essence', 'locale' => 'en-GB'])['error'] ?? null, 'bad_params');
    check('a template that is not installed is not found', $loCall(['template' => 'ja_none', 'locale' => 'vi-VN'])['error'] ?? null, 'not_found');

    // ---- what may be written -----------------------------------------------------------------------
    check('placeholders are kept or the words are refused', LanguageOverrides::refusal('By %s', 'Bởi'), 'its placeholders are not the en-GB ones');
    check('the footer keeps its two placeholders', LanguageOverrides::refusal('Copyright &#169; %date% %sitename%. All Rights Reserved.', 'Bản quyền &#169; %date% %sitename%. Bảo lưu mọi quyền.'), null);
    check('markup is refused', LanguageOverrides::refusal('Send message', '<b>Gửi</b>'), 'markup is not written');
    check('two lines are refused', LanguageOverrides::refusal('Send message', "Gửi\ntin"), 'more than one line');

    $set = $loCall(['operation' => 'set', 'apply_id' => 'lover-a', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => [
        'TPL_SHARE_ARTICLE' => 'Chia sẻ bài viết:',
        'TPL_CONTENT_WRITTEN_BY' => 'Bởi %s',
        'TPL_BACK' => '"Quay lại"',
        'COM_CONTENT_ARTICLE_INFO' => 'Chi tiết',
        'MOD_FOOTER_LINE1' => 'Bản quyền &#169; %date% %sitename%. Bảo lưu mọi quyền.',
        'TPL_TAGS_IN' => 'Thẻ: ',
        'COM_CONTENT_READ_MORE' => 'Đọc thêm',
        'CONFIGURATION_SECRET' => 'x',
    ]]);
    check('set writes the strings it was offered', [$set['ok'] ?? null, $set['written'] ?? null], [true, 6]);
    check('and names what it would not write', array_column($set['refused'] ?? [], 'reason', 'key'), [
        'COM_CONTENT_READ_MORE' => 'not a string this site shows in en-GB',
        'CONFIGURATION_SECRET' => 'not a string this site shows in en-GB',
    ]);
    $loWritten = LanguageOverrides::parse((string) $loFile());
    check('the override file holds them as Joomla reads them', [$loWritten['TPL_SHARE_ARTICLE'] ?? null, $loWritten['COM_CONTENT_ARTICLE_INFO'] ?? null, $loWritten['TPL_BACK'] ?? null, $loWritten['TPL_CONTENT_WRITTEN_BY'] ?? null],
        ['Chia sẻ bài viết:', 'Chi tiết', '"Quay lại"', 'Bởi %s']);
    check('a quote is written escaped', strpos((string) $loFile(), 'TPL_BACK="\"Quay lại\""') !== false, true);
    check('the write is in the undo log', $loLog->entries('lover-a')[0]['op'] ?? null, 'languageOverrides');
    check('read now names the overrides', ($loCall(['template' => 'ja_essence', 'locale' => 'vi-VN'])['overrides'] ?? [])['TPL_SHARE_ARTICLE'] ?? null, 'Chia sẻ bài viết:');

    // An administrator's own override stays, line for line.
    file_put_contents($loRoot . '/language/overrides/vi-VN.override.ini', "; mine\nMY_OWN=\"Của tôi\"\n" . $loFile());
    $loAgain = $loCall(['operation' => 'set', 'apply_id' => 'lover-b', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => ['TPL_SHARE_ARTICLE' => 'Chia sẻ:']]);
    check('a second set replaces its own line and keeps the others', [$loAgain['ok'] ?? null, LanguageOverrides::parse((string) $loFile())['TPL_SHARE_ARTICLE'] ?? null, LanguageOverrides::parse((string) $loFile())['MY_OWN'] ?? null, substr((string) $loFile(), 0, 7)],
        [true, 'Chia sẻ:', 'Của tôi', '; mine' . "\n"]);
    check('the same words again write nothing', $loCall(['operation' => 'set', 'apply_id' => 'lover-c', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => ['TPL_SHARE_ARTICLE' => 'Chia sẻ:']])['unchanged'] ?? null, true);
    check('set needs an apply_id', $loCall(['operation' => 'set', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => ['TPL_SHARE_ARTICLE' => 'x']])['error'] ?? null, 'bad_params');

    // ---- undo ----------------------------------------------------------------------------------------
    check('reverting the second set puts the file back as it was before it', [$loEngine->handle(['token' => $loToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'lover-b']])['ok'] ?? null, LanguageOverrides::parse((string) $loFile())['TPL_SHARE_ARTICLE'] ?? null], [true, 'Chia sẻ bài viết:']);
    file_put_contents($loRoot . '/language/overrides/vi-VN.override.ini', substr((string) $loFile(), strlen("; mine\nMY_OWN=\"Của tôi\"\n")));
    check('reverting the first deletes the file it made, and the folder', [$loEngine->handle(['token' => $loToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'lover-a']])['ok'] ?? null, $loFile(), is_dir($loRoot . '/language/overrides')], [true, null, false]);
    checkTrue('an undo row naming another file is refused', (static function () use ($loFiles): bool {
        try { $loFiles->restore([['path' => 'configuration.php', 'before' => 'x']]); return false; } catch (RuntimeException $e) { return true; }
    })());
    check('a receiver without the door answers unavailable', (new Engine($loToken, [], null, null, null, null, null, null, new FakeApplyLog()))->handle(['token' => $loToken, 'action' => 'template.languageOverrides', 'params' => ['template' => 'ja_essence', 'locale' => 'vi-VN']])['error'] ?? null, 'unavailable');
}
