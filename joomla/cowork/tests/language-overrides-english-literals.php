<?php
// English template words use the same native override file, with exact source provenance and guarded Undo.
if (function_exists('check')) {
    $elRoot = sys_get_temp_dir() . '/cowork-english-literals-' . bin2hex(random_bytes(6));
    $elPut = static function (string $path, string $bytes) use ($elRoot): void {
        if (!is_dir(dirname($elRoot . '/' . $path))) mkdir(dirname($elRoot . '/' . $path), 0755, true);
        file_put_contents($elRoot . '/' . $path, $bytes);
    };
    $elPut('templates/ja_fresco/templateDetails.xml', '<extension/>');
    $elPut('templates/ja_fresco/html/contact.php', '<h2>Have question? get in touch!</h2><h2>Always here to help you</h2><p>Write a message</p><p>Get in touch!</p><p>Get in touch?</p>');
    $elPut('modules/mod_finder/tmpl/default.php', '<a title="search">Search</a>');
    $elPut('language/en-GB/joomla.ini', 'CORE_WORD="Core words"');
    $elPut('language/overrides/en-GB.override.ini', "OTHER=\"keep me\"\n");
    $elFiles = new LanguageOverrides($elRoot);
    $elLog = new FakeApplyLog();
    $elMedia = new FakeMediaWriter();
    $elEngine = (new Engine('english-literal-test-token-58', [], null, null, null, null, null, $elMedia, $elLog))->languageOverrides($elFiles);
    $elCall = static fn(array $params) => $elEngine->handle(['token' => 'english-literal-test-token-58', 'action' => 'template.languageOverrides', 'params' => ['template' => 'ja_fresco', 'locale' => 'en-GB'] + $params]);
    $elUndo = static fn(string $id) => $elEngine->handle(['token' => 'english-literal-test-token-58', 'action' => 'apply.revert', 'params' => ['apply_id' => $id]]);
    $elRead = $elCall([]);
    $elSources = array_column($elRead['strings'] ?? [], 'source', 'key');
    $elKey = 'TRACY_LITERAL_HAVE_QUESTION_GET_IN_TOUCH';
    check('English offers exactly the three unambiguous template literals', array_values($elSources), ['Have question? get in touch!', 'Always here to help you', 'Write a message']);
    check('English read discloses site-wide exact-source scope', $elRead['scope'] ?? null, 'site-wide locale/key; literals use exact source text with case and punctuation preserved');
    check('English reports a punctuation collision instead of picking a source', $elRead['ambiguous'][0]['key'] ?? null, 'TRACY_LITERAL_GET_IN_TOUCH');
    $elPut('templates/ja_fresco/language/en-GB/en-GB.tpl_ja_fresco.ini', "TPL_CONTACT_TITLE_FORM=\"Always here to help you\"\nTPL_CONTACT_DESC_FORM=\"Write a Message\"\nTPL_CONTACT_INFORMATION=\"Have question?<br />Get in touch!\"\nDATE_FORMAT_X=\"Y-m-d\"\nJANUARY=\"January\"\nMONDAY=\"Monday\"\nCORE_WORD=\"Core words\"\nTPL_UNSAFE=\"<script>bad</script>\"\n");
    $elLanguage = array_column($elCall([])['strings'], 'source', 'key');
    check('English offers the three actual JA Fresco Contact language keys', array_intersect_key($elLanguage, array_flip(['TPL_CONTACT_TITLE_FORM', 'TPL_CONTACT_DESC_FORM', 'TPL_CONTACT_INFORMATION'])), ['TPL_CONTACT_TITLE_FORM' => 'Always here to help you', 'TPL_CONTACT_DESC_FORM' => 'Write a Message', 'TPL_CONTACT_INFORMATION' => 'Have question?<br />Get in touch!']);
    checkTrue('English never offers template date formats or arbitrary markup', !isset($elLanguage['DATE_FORMAT_X']) && !isset($elLanguage['TPL_UNSAFE']) && !isset($elLanguage['JANUARY']) && !isset($elLanguage['MONDAY']) && !isset($elLanguage['CORE_WORD']));
    foreach (['tpl_ja_fresco.ini', 'en-GB.tpl_ja_fresco.ini'] as $elName) {
        $elPut('language/en-GB/' . $elName, "TPL_CONTACT_TITLE_FORM=\"Always here to help you\"\n");
        check('English accepts the global template language copy: ' . $elName, array_column($elCall([])['strings'], 'source', 'key')['TPL_CONTACT_TITLE_FORM'] ?? null, 'Always here to help you');
        $elRows = array_column($elCall([])['strings'], null, 'key');
        check('English prefers the template tree as source: ' . $elName, $elRows['TPL_CONTACT_TITLE_FORM']['file'] ?? null, 'templates/ja_fresco/language/en-GB/en-GB.tpl_ja_fresco.ini');
        $elPut('language/en-GB/' . $elName, "TPL_GLOBAL_ONLY=\"Global template words\"\nJANUARY=\"January\"\nMONDAY=\"Monday\"\nDATE_FORMAT_X=\"Y-m-d\"\n");
        $elRows = array_column($elCall([])['strings'], 'source', 'key');
        check('English offers a template key present only in the global copy: ' . $elName, $elRows['TPL_GLOBAL_ONLY'] ?? null, 'Global template words');
        checkTrue('English excludes dates from the global template copy: ' . $elName, !isset($elRows['JANUARY']) && !isset($elRows['MONDAY']) && !isset($elRows['DATE_FORMAT_X']));
        $elPut('language/en-GB/' . $elName, "TPL_CONTACT_TITLE_FORM=\"A different template source\"\n");
        checkTrue('English refuses differing template copies: ' . $elName, !isset(array_column($elCall([])['strings'], 'source', 'key')['TPL_CONTACT_TITLE_FORM']));
        unlink($elRoot . '/language/en-GB/' . $elName);
    }
    $elPut('language/en-GB/mod_example.ini', "TPL_CONTACT_TITLE_FORM=\"Always here to help you\"\n");
    checkTrue('English refuses a module collision even with identical words', !isset(array_column($elCall([])['strings'], 'source', 'key')['TPL_CONTACT_TITLE_FORM']));
    unlink($elRoot . '/language/en-GB/mod_example.ini');
    foreach (['No break', 'A<br>B<br>C', 'A<br onclick="bad">B', 'A<script>bad</script>B', 'A&lt;br&gt;B'] as $bad) {
        checkTrue('English preserves line-break structure: ' . $bad, LanguageOverrides::englishRefusal('Have question?<br />Get in touch!', $bad) !== null);
    }
    check('simple break spelling may vary without changing structure', LanguageOverrides::englishRefusal('Have question?<br />Get in touch!', 'Contact<br>Via Carota'), null);
    checkTrue('text-only template words cannot gain markup', LanguageOverrides::englishRefusal('Write a Message', 'Write<br>a message') !== null);
    checkTrue('encoded markup cannot enter plain text', LanguageOverrides::englishRefusal('Write a Message', '&lt;script&gt;bad&lt;/script&gt;') !== null);
    $elLanguageSet = $elCall(['operation' => 'set', 'apply_id' => 'el-language', 'strings' => ['TPL_CONTACT_INFORMATION' => 'Contact<br />Via Carota', 'TPL_CONTACT_TITLE_FORM' => 'A note to Via Carota', 'TPL_CONTACT_DESC_FORM' => 'Send your question']]);
    check('the native English door writes the three real Contact keys', $elLanguageSet['written'] ?? null, 3);
    $elLanguageValues = LanguageOverrides::parse(file_get_contents($elRoot . '/language/overrides/en-GB.override.ini'));
    check('native template keys retain the break and have no synthetic-source metadata', [$elLanguageValues['TPL_CONTACT_INFORMATION'], isset($elLanguageValues[PageLiterals::sourceKey('TPL_CONTACT_INFORMATION')])], ['Contact<br />Via Carota', false]);
    check('native template language Undo succeeds', $elUndo('el-language')['ok'] ?? null, true);
    $elBefore = file_get_contents($elRoot . '/language/overrides/en-GB.override.ini');
    $elSet = $elCall(['operation' => 'set', 'apply_id' => 'el-a', 'strings' => [$elKey => 'Contact Via Carota', 'CORE_WORD' => 'No', 'TRACY_LITERAL_SEARCH' => 'No', PageLiterals::sourceKey($elKey) => 'Fake source', 'TRACY_LITERAL_GET_IN_TOUCH' => 'No']]);
    check('English writes only the offered literal; caller cannot write metadata or ambiguous/core/module keys', [$elSet['written'] ?? null, count($elSet['refused'] ?? [])], [1, 4]);
    $elBytesA = file_get_contents($elRoot . '/language/overrides/en-GB.override.ini');
    $elValuesA = LanguageOverrides::parse($elBytesA);
    check('the companion source is taken from server inventory', $elValuesA[PageLiterals::sourceKey($elKey)] ?? null, 'Have question? get in touch!');
    $elHtml = '<form method="post"><h2>Have question? get in touch!</h2><p> Have  question? get in touch! </p><a title="Have question? get in touch!">Link</a><h2>Have question? get in touch?</h2><h2>HAVE QUESTION? GET IN TOUCH!</h2><input name="nonce" value="same"><script>"<p>Have question? get in touch!</p>"</script><style>/* Have question? get in touch! */</style><!-- Have question? get in touch! --></form>';
    $elRendered = PageLiterals::english($elHtml, $elValuesA);
    check('English changes every exact-source occurrence, whitespace and allowed attributes included', substr_count($elRendered, 'Contact Via Carota'), 3);
    checkTrue('English preserves punctuation/case neighbours, form nonce, script, style and comments', strpos($elRendered, '<h2>Have question? get in touch?</h2>') !== false && strpos($elRendered, '<h2>HAVE QUESTION? GET IN TOUCH!</h2>') !== false && strpos($elRendered, '<input name="nonce" value="same">') !== false && strpos($elRendered, '<script>"<p>Have question? get in touch!</p>"</script>') !== false && strpos($elRendered, '<!-- Have question? get in touch! -->') !== false && strpos($elRendered, '<style>/* Have question? get in touch! */</style>') !== false);
    check('English without provenance is inert', PageLiterals::english($elHtml, [$elKey => 'No']), $elHtml);
    check('English with mismatched provenance is inert', PageLiterals::english($elHtml, [$elKey => 'No', PageLiterals::sourceKey($elKey) => 'Different words']), $elHtml);
    check('English with a multiline source companion is inert', PageLiterals::english($elHtml, [$elKey => 'No', PageLiterals::sourceKey($elKey) => "Have question?\nget in touch!"]), $elHtml);
    $elBad = $elCall(['operation' => 'set', 'apply_id' => 'el-bad', 'strings' => [$elKey => '<b>No</b>']]);
    check('English still refuses markup without writing', [$elBad['written'] ?? null, file_get_contents($elRoot . '/language/overrides/en-GB.override.ini')], [0, $elBytesA]);
    $elCall(['operation' => 'set', 'apply_id' => 'el-b', 'strings' => [$elKey => 'Reach Via Carota']]);
    check('the original source remains offered after an English edit', array_column($elCall([])['strings'], 'source', 'key')[$elKey] ?? null, 'Have question? get in touch!');
    check('Undo an older English receipt refuses to erase a newer edit', $elUndo('el-a')['error'] ?? null, 'conflict');
    check('Undo the latest English receipt succeeds', $elUndo('el-b')['ok'] ?? null, true);
    check('Undo returns both the prior source and replacement, keeping unrelated values', file_get_contents($elRoot . '/language/overrides/en-GB.override.ini'), $elBytesA);
    check('Undo the first English receipt succeeds', $elUndo('el-a')['ok'] ?? null, true);
    check('Undo the first receipt restores the exact original bytes', file_get_contents($elRoot . '/language/overrides/en-GB.override.ini'), $elBefore);
    $elCall(['operation' => 'set', 'apply_id' => 'el-chain', 'strings' => [$elKey => 'First chain edit']]);
    $elCall(['operation' => 'set', 'apply_id' => 'el-chain', 'strings' => [$elKey => 'Second chain edit']]);
    check('two English writes in the same Apply revert as one chain', [$elUndo('el-chain')['ok'] ?? null, file_get_contents($elRoot . '/language/overrides/en-GB.override.ini')], [true, $elBefore]);
    $elCall(['operation' => 'set', 'apply_id' => 'el-c', 'strings' => [$elKey => 'Contact Via Carota']]);
    file_put_contents($elRoot . '/language/overrides/en-GB.override.ini', "LATER=\"another editor\"\n", FILE_APPEND);
    $elOutside = file_get_contents($elRoot . '/language/overrides/en-GB.override.ini');
    $elMedia->store['images/other.png'] = 'new bytes';
    $elLog->record('el-c', ['op' => 'media', 'path' => 'images/other.png', 'before' => 'old bytes']);
    check('Undo refuses a later out-of-band file change', $elUndo('el-c')['error'] ?? null, 'conflict');
    check('one English conflict prevents every other entry from reverting', $elMedia->store['images/other.png'], 'new bytes');
    check('a refused Undo changes no bytes', file_get_contents($elRoot . '/language/overrides/en-GB.override.ini'), $elOutside);
    $elFailEngine = (new Engine('english-literal-test-token-58', [], null, null, null, null, null, null, new FailingApplyLog()))->languageOverrides($elFiles);
    $elFailed = $elFailEngine->handle(['token' => 'english-literal-test-token-58', 'action' => 'template.languageOverrides', 'params' => ['operation' => 'set', 'apply_id' => 'el-fail', 'template' => 'ja_fresco', 'locale' => 'en-GB', 'strings' => [$elKey => 'Changed without Undo']]]);
    check('an English write rolls back when its Undo cannot be recorded', [$elFailed['error'] ?? null, file_get_contents($elRoot . '/language/overrides/en-GB.override.ini')], ['write_failed', $elOutside]);
}
