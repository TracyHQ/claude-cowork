<?php
// Loaded by run.php, after contracts.php (it reuses TestContractStore): `template.siteSettings`
// writes a template's logo, name, slogan and favicon (TCH #1013, ticket T18).
//
// What is real here: a site root on disk with JA Spa's two site profiles copied byte for byte from
// its quickstart (ja-spa j6 1.0.1), the files the door writes and deletes, T4's optimize cache, the
// contract's file check on the same root, and the favicon a non-T4 page prints. What stands in: the
// template styles (a callable naming each style's `typelist-site`), the undo log and the site writer.

if (function_exists('check')) {
    $tsToken = 'template-settings-token-16';
    $tsRoot = sys_get_temp_dir() . '/cowork-template-settings-' . bin2hex(random_bytes(6));
    // JA Spa 1.0.1, templates/ja_spa/etc/site/: the exact bytes the quickstart ships.
    $tsSpaDefault = '{"site_name":"","site_slogan":"","site_logo":"images\/joomlart\/logo\/logo-color.png","site_logo_2":"images\/joomlart\/logo\/logo-light.png","site_logo_small":"","dont_use_google_font":false,"body_font_load_weights":"400,500,600,700","body_font_family":"Mulish","body_font_style":"normal","body_font_weight":"400","body_font_size":"15px","body_line_height":"1.8667","letter_spacing":"0px","heading_font_load_weights":"400","heading_font_family":"Marcellus","heading_font_style":"normal","heading_font_weight":"400","heading_line_height":"1.4","heading_letter_spacing":"0px","h1_font_size":"64px","h2_font_size":"40px","h3_font_size":"26px","h4_font_size":"20px","h5_font_size":"14px","h6_font_size":"13px","body_bg_img":"","body_bg_img_repeat":"repeat","body_bg_img_size":"","body_bg_img_attachment":"scroll","body_bg_img_position":"left top","other_faviconFile":"","other_backToTop":false}';
    $tsSpaLight = '{"site_name":"","site_slogan":"","site_logo":"images\/joomlart\/logo\/logo-light.png","site_logo_2":"images\/joomlart\/logo\/logo-light.png","site_logo_small":"","other_faviconFile":"","other_backToTop":"1"}';
    $tsPut = static function (string $relative, string $bytes) use ($tsRoot): void {
        $path = $tsRoot . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);
    };
    $tsPut('templates/ja_spa/templateDetails.xml', "<?xml version=\"1.0\"?>\n<extension type=\"template\" client=\"site\">\n\t<name>ja_spa</name>\n\t<t4>\n\t\t<basetheme>base</basetheme>\n\t</t4>\n</extension>\n");
    $tsPut('templates/ja_spa/etc/site/default.json', $tsSpaDefault);
    $tsPut('templates/ja_spa/etc/site/logo-light.json', $tsSpaLight);
    $tsPut('templates/ja_mood/templateDetails.xml', "<?xml version=\"1.0\"?>\n<extension type=\"template\" client=\"site\">\n\t<name>ja_mood</name>\n\t<t3>\n\t\t<base>base-bs3</base>\n\t</t3>\n</extension>\n");
    $tsPut('templates/ja_mood/favicon.ico', 'template icon');
    foreach (['images/tracy-brand/1111aaaa.png', 'images/tracy-brand/2222bbbb.png', 'images/tracy-brand/3333cccc.png', 'images/tracy-brand/4444dddd.ico', 'images/tracy-brand/5555eeee.png', 'images/tracy-brand/6666ffff.svg', 'images/tracy-brand/7777aaaa.jpg'] as $tsImage)
        $tsPut($tsImage, 'picture ' . $tsImage);
    $tsOptimize = static function () use ($tsPut): void {
        $tsPut('media/t4/optimize/css/' . str_repeat('a', 32) . '.css', 'combined css with content:url(/images/joomlart/logo/logo-dark.png)');
        $tsPut('media/t4/optimize/js/' . str_repeat('b', 32) . '.js', 'combined js');
    };
    $tsOptimized = static function () use ($tsRoot): array {
        $dir = $tsRoot . '/media/t4/optimize';
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $out[] = $file->getFilename();
        return $out;
    };
    // JA Spa's five site styles: four on `default`, style 13 on `logo-light` (its quickstart database).
    $tsStyles = ['ja_spa' => ['default', 'default', 'default', 'default', 'logo-light'], 'ja_mood' => [null, null]];
    $tsFiles = new TemplateSiteFiles($tsRoot, static fn(string $template): array => $tsStyles[$template] ?? []);
    $tsLog = new FakeApplyLog();
    $tsWriter = new class extends FakeSiteWriter {
        public array $serialized = [];
        public function serialize(callable $work, array $holder = []) { $this->serialized[] = $holder; return $work(); }
    };
    $tsEngine = (new Engine($tsToken, [], null, null, null, null, $tsWriter, null, $tsLog))->templateSiteSettings($tsFiles);
    $tsCall = static fn(Engine $engine, array $params) => $engine->handle(['token' => $tsToken, 'action' => 'template.siteSettings', 'params' => $params]);
    $tsSet = static fn(array $params, string $apply = 'apply-brand') => $tsCall($tsEngine, ['operation' => 'set', 'apply_id' => $apply, 'template' => 'ja_spa'] + $params);
    $tsLocal = static fn(string $profile, string $template = 'ja_spa'): ?string => is_file($tsRoot . "/templates/$template/local/etc/site/$profile.json") ? (string) file_get_contents($tsRoot . "/templates/$template/local/etc/site/$profile.json") : null;

    // ---- what a value may be -----------------------------------------------------------------
    check('a picture is a path under images/ that exists on the site', TemplateSiteSettings::clean('site_logo', 'images/tracy-brand/1111aaaa.png', $tsRoot), ['value' => 'images/tracy-brand/1111aaaa.png']);
    check('a leading slash is dropped', TemplateSiteSettings::clean('site_logo', '/images/tracy-brand/1111aaaa.png', $tsRoot), ['value' => 'images/tracy-brand/1111aaaa.png']);
    check('an empty picture clears the key', TemplateSiteSettings::clean('site_logo_dark', '', $tsRoot), ['value' => '']);
    check('a picture not uploaded yet is refused, saying how to upload it',
        TemplateSiteSettings::clean('site_logo', 'images/tracy-brand/9999ffff.png', $tsRoot),
        ['error' => 'site_logo: images/tracy-brand/9999ffff.png is not a file on this site; upload it first (media.upload)']);
    foreach (['../configuration.php', 'images/../configuration.php', 'templates/ja_spa/index.php', 'images/logo.php', 'https://cdn.example/logo.png', 'images/a b.png'] as $tsBad)
        checkTrue('an unusable picture path is refused: ' . $tsBad, isset(TemplateSiteSettings::clean('site_logo', $tsBad, $tsRoot)['error']));
    checkTrue('a favicon may be an .ico', TemplateSiteSettings::clean('other_faviconFile', 'images/tracy-brand/4444dddd.ico', $tsRoot) === ['value' => 'images/tracy-brand/4444dddd.ico']);
    check('a name is one line without tags', TemplateSiteSettings::clean('site_name', " <b>Hanoi</b>\n Roofing &amp; Sons ", $tsRoot), ['value' => 'Hanoi Roofing & Sons']);
    check('a name may be empty: T4 then shows the global site name', TemplateSiteSettings::clean('site_name', '', $tsRoot), ['value' => '']);
    checkTrue('a name past 200 characters is refused', isset(TemplateSiteSettings::clean('site_slogan', str_repeat('a', 201), $tsRoot)['error']));
    check('a value must be a string', TemplateSiteSettings::clean('site_name', 5, $tsRoot), ['error' => 'site_name must be a string']);
    check('no other key is a site setting', TemplateSiteSettings::clean('body_font_family', 'Arial', $tsRoot), ['error' => 'body_font_family is not a site setting this door writes']);

    // ---- the JSON edit keeps every other byte --------------------------------------------------
    check('a key in place is replaced where it stands, every other byte kept',
        TemplateSiteSettings::withValues($tsSpaLight, ['site_logo' => 'images/tracy-brand/1111aaaa.png']),
        str_replace('"site_logo":"images\/joomlart\/logo\/logo-light.png"', '"site_logo":"images\/tracy-brand\/1111aaaa.png"', $tsSpaLight));
    check('a missing key is added at the end', TemplateSiteSettings::withValues('{"a":1}', ['site_logo_dark' => 'images/x.png']), '{"a":1,"site_logo_dark":"images\/x.png"}');
    check('pretty-printed files keep their layout', TemplateSiteSettings::withValues("{\n    \"site_name\": \"Kinetic\",\n    \"x\": [1, {\"site_name\": \"nested\"}]\n}", ['site_name' => 'Hanoi Roofing']),
        "{\n    \"site_name\": \"Hanoi Roofing\",\n    \"x\": [1, {\"site_name\": \"nested\"}]\n}");
    check('a file that writes slashes unescaped keeps writing them so', TemplateSiteSettings::withValues('{"site_logo":"images/a.png"}', ['site_logo' => 'images/b.png']), '{"site_logo":"images/b.png"}');
    check('an empty object takes the first key', TemplateSiteSettings::withValues('{ }', ['site_name' => 'A']), '{"site_name":"A"}');
    check('text that is not a JSON object is refused', TemplateSiteSettings::withValues('[1,2]', ['site_name' => 'A']), null);

    // ---- read ------------------------------------------------------------------------------------
    $tsRead = $tsCall($tsEngine, ['template' => 'ja_spa']);
    check('read names the framework, the keys, and each profile a style uses', [$tsRead['ok'], $tsRead['framework'], $tsRead['keys'], array_keys($tsRead['profiles'])],
        [true, 't4', array_merge(TemplateSiteSettings::T4_KEYS, ['other_shareImage']), ['default', 'logo-light']]);
    check('and its share image, none yet', $tsRead['settings'], ['other_shareImage' => '']);
    check('a profile answers where it is read from and its keys of the whitelist only', $tsRead['profiles']['logo-light'],
        ['source' => 'templates/ja_spa/etc/site/logo-light.json', 'settings' => ['site_name' => '', 'site_slogan' => '', 'site_logo' => 'images/joomlart/logo/logo-light.png', 'site_logo_2' => 'images/joomlart/logo/logo-light.png', 'site_logo_small' => '', 'other_faviconFile' => '']]);
    check('read takes no write lock', $tsWriter->serialized, []);
    check('a template is required', $tsCall($tsEngine, [])['error'], 'bad_params');
    check('a template that is not installed is not found', $tsCall($tsEngine, ['template' => 'ja_nowhere'])['error'], 'not_found');
    check('a template name is a folder name, nothing else', $tsCall($tsEngine, ['template' => '../administrator'])['error'], 'bad_params');

    // ---- set: refusals ----------------------------------------------------------------------------
    $tsOther = $tsSet(['fields' => ['site_logo' => 'images/tracy-brand/1111aaaa.png', 'body_font_family' => 'Arial']]);
    check('a key outside the whitelist is refused whole, naming the boundary', [$tsOther['error'], $tsOther['message']],
        ['unsupported', 'body_font_family cannot be written through template.siteSettings: only site_logo, site_logo_small, site_logo_dark, site_logo_dark_small, site_logo_2, site_name, site_slogan, other_faviconFile, other_shareImage can. Nothing was written']);
    check('the same inside a profile', $tsSet(['profiles' => ['default' => ['other_backToTop' => true]]])['error'], 'unsupported');
    check('a set needs an apply_id', $tsCall($tsEngine, ['operation' => 'set', 'template' => 'ja_spa', 'fields' => ['site_name' => 'A']])['error'], 'bad_params');
    check('a set needs fields or profiles', [$tsSet([])['error'], $tsSet(['fields' => []])['error'], $tsSet(['fields' => ['A']])['error']], ['bad_params', 'bad_params', 'bad_params']);
    check('a profile no style uses and no file holds is refused', $tsSet(['profiles' => ['home-9' => ['site_name' => 'A']]])['error'], 'bad_params');
    check('a picture that is not on the site is refused', $tsSet(['fields' => ['site_logo' => 'images/tracy-brand/9999ffff.png']])['error'], 'bad_params');
    check('an unknown operation is refused by name', $tsCall($tsEngine, ['operation' => 'write', 'template' => 'ja_spa'])['message'], 'Unknown template.siteSettings operation: use read or set');
    check('the refusals wrote nothing and recorded nothing', [is_dir($tsRoot . '/templates/ja_spa/local'), $tsLog->entries('apply-brand')], [false, []]);

    // ---- set: JA Spa, both profiles ----------------------------------------------------------------
    $tsOptimize();
    $tsWriter->serialized = [];
    $tsDone = $tsSet([
        'fields' => ['site_logo' => 'images/tracy-brand/1111aaaa.png', 'site_logo_2' => 'images/tracy-brand/2222bbbb.png', 'other_faviconFile' => 'images/tracy-brand/4444dddd.ico'],
        // Style 13 shows the light logo on its dark header: the light version in both of its places.
        'profiles' => ['logo-light' => ['site_logo' => 'images/tracy-brand/2222bbbb.png']],
    ]);
    check('a set answers the files it wrote', [$tsDone['ok'], $tsDone['changed']],
        [true, ['templates/ja_spa/local/etc/site/default.json', 'templates/ja_spa/local/etc/site/logo-light.json']]);
    check('default.json: only the three keys changed, every other byte as shipped', $tsLocal('default'), strtr($tsSpaDefault, [
        '"site_logo":"images\/joomlart\/logo\/logo-color.png"' => '"site_logo":"images\/tracy-brand\/1111aaaa.png"',
        '"site_logo_2":"images\/joomlart\/logo\/logo-light.png"' => '"site_logo_2":"images\/tracy-brand\/2222bbbb.png"',
        '"other_faviconFile":""' => '"other_faviconFile":"images\/tracy-brand\/4444dddd.ico"',
    ]));
    check('logo-light.json: the profile\'s own value wins over the shared one', $tsLocal('logo-light'), strtr($tsSpaLight, [
        '"site_logo":"images\/joomlart\/logo\/logo-light.png"' => '"site_logo":"images\/tracy-brand\/2222bbbb.png"',
        '"site_logo_2":"images\/joomlart\/logo\/logo-light.png"' => '"site_logo_2":"images\/tracy-brand\/2222bbbb.png"',
        '"other_faviconFile":""' => '"other_faviconFile":"images\/tracy-brand\/4444dddd.ico"',
    ]));
    check('the template\'s own profiles are untouched', [file_get_contents($tsRoot . '/templates/ja_spa/etc/site/default.json'), file_get_contents($tsRoot . '/templates/ja_spa/etc/site/logo-light.json')], [$tsSpaDefault, $tsSpaLight]);
    check('T4\'s optimize cache is empty after the write', [$tsOptimized(), $tsDone['cleared']], [[], 2]);
    check('the answer reads the profiles back as now written', $tsDone['profiles']['logo-light']['settings']['site_logo'], 'images/tracy-brand/2222bbbb.png');
    check('and their source is the local copy', $tsDone['profiles']['default']['source'], 'templates/ja_spa/local/etc/site/default.json');
    check('a set takes the write lock, naming itself', $tsWriter->serialized, [['action' => 'template.siteSettings', 'operation' => 'set', 'applyId' => 'apply-brand']]);
    check('one undo step for the whole set', count($tsLog->entries('apply-brand')), 1);
    check('the cache is purged', $tsWriter->purges >= 1, true);
    check('apply.list names the files, never their bytes', $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.list', 'params' => ['apply_id' => 'apply-brand']])['steps'],
        [['op' => 'siteSettings', 'created' => true, 'files' => ['templates/ja_spa/local/etc/site/default.json', 'templates/ja_spa/local/etc/site/logo-light.json']]]);
    $tsAgain = $tsSet(['fields' => ['site_logo_2' => 'images/tracy-brand/2222bbbb.png'], 'profiles' => ['logo-light' => ['site_logo' => 'images/tracy-brand/2222bbbb.png']]]);
    check('the same values again write and record nothing', [$tsAgain['unchanged'] ?? null, $tsAgain['changed'], count($tsLog->entries('apply-brand'))], [true, [], 1]);

    // ---- the contract's file check does not see the door's files ---------------------------------
    $tsContractDir = $tsRoot . '/contract';
    mkdir($tsContractDir);
    $tsLockFiles = [];
    foreach (['templates/ja_spa/templateDetails.xml', 'templates/ja_spa/etc/site/default.json', 'templates/ja_spa/etc/site/logo-light.json', 'templates/ja_spa/favicon-local.txt'] as $tsLocked) {
        if (!is_file($tsRoot . '/' . $tsLocked)) $tsPut($tsLocked, 'locked');
        $tsLockFiles[$tsLocked] = hash_file('sha256', $tsRoot . '/' . $tsLocked);
    }
    $tsContractStore = new TestContractStore();
    foreach (['manifest' => ['id' => 'ja-spa/test'], 'content-map' => ['entities' => [], 'slots' => [], 'pages' => []],
        'presentation-lock' => ['entities' => [], 'assignments' => [], 'fileRoots' => ['templates/ja_spa'], 'files' => $tsLockFiles, 'inventoryCounts' => [], 'access' => $tsContractStore->acl]] as $tsName => $tsBody)
        file_put_contents($tsContractDir . '/' . $tsName . '.json', json_encode($tsBody));
    $tsContract = new QuickstartContract(new FakeSiteWriter(), $tsContractStore, $tsRoot, $tsContractDir);
    $tsContract->inspect();
    check('inspect: no "Unexpected presentation file" for the door\'s profiles', $tsContract->driftWarnings(), []);
    // JA Nova and JA Voyara ship local/etc/site/default.json in their lock: a write there is the door's too.
    $tsLockFiles['templates/ja_spa/local/etc/site/default.json'] = hash('sha256', $tsSpaDefault);
    file_put_contents($tsContractDir . '/presentation-lock.json', json_encode(['entities' => [], 'assignments' => [], 'fileRoots' => ['templates/ja_spa'], 'files' => $tsLockFiles, 'inventoryCounts' => [], 'access' => $tsContractStore->acl]));
    $tsContract = new QuickstartContract(new FakeSiteWriter(), $tsContractStore, $tsRoot, $tsContractDir);
    $tsContract->inspect();
    check('inspect: no "Presentation asset changed" for a locked local profile the door rewrote', $tsContract->driftWarnings(), []);
    $tsPut('templates/ja_spa/local/etc/site/evil.php', '<?php');
    $tsPut('templates/ja_spa/local/css/custom.css', 'body{}');
    $tsContract->inspect();
    $tsDrift = $tsContract->driftWarnings();
    sort($tsDrift);
    check('the exemption is the profiles alone: anything else under local/ is still reported', $tsDrift,
        ['Unexpected presentation file: templates/ja_spa/local/css/custom.css', 'Unexpected presentation file: templates/ja_spa/local/etc/site/evil.php']);
    unlink($tsRoot . '/templates/ja_spa/local/etc/site/evil.php');
    unlink($tsRoot . '/templates/ja_spa/local/css/custom.css');
    rmdir($tsRoot . '/templates/ja_spa/local/css');
    foreach (['templates/x/local/etc/site/default.json' => true, 'templates/ja_spa/local/etc/site/home magz.json' => true, 'templates/x/local/etc/site/a.json.php' => false,
        'templates/x/local/etc/layout/default.json' => false, 'templates/x/etc/site/default.json' => false, 'templates/x/local/etc/site/../../../index.php' => false] as $tsPath => $tsWant)
        check('door file? ' . $tsPath, TemplateSiteSettings::isDoorFile($tsPath), $tsWant);

    // ---- two uploads: the profile follows the newest file name -----------------------------------
    $tsSet(['fields' => ['site_logo' => 'images/tracy-brand/3333cccc.png']], 'apply-second');
    check('a second logo under a new name: the profile points at the new file', json_decode((string) $tsLocal('default'), true)['site_logo'], 'images/tracy-brand/3333cccc.png');
    check('and the first upload is still on disk, untouched', is_file($tsRoot . '/images/tracy-brand/1111aaaa.png'), true);

    // ---- undo ------------------------------------------------------------------------------------
    $tsOptimize();
    $tsBack = $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-second']]);
    check('reverting the second set puts the first logo back', [$tsBack, json_decode((string) $tsLocal('default'), true)['site_logo']], [['ok' => true, 'reverted' => 1], 'images/tracy-brand/1111aaaa.png']);
    check('a revert empties the optimize cache too', $tsOptimized(), []);
    $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-brand']]);
    check('reverting the first set deletes the files, and the local/ folder it made', [$tsLocal('default'), $tsLocal('logo-light'), is_dir($tsRoot . '/templates/ja_spa/local')], [null, null, false]);
    check('the undo log is cleared', $tsLog->entries('apply-brand'), []);

    // A template that already has its own local profile (JA Nova ships one): the revert puts its bytes back.
    $tsNova = "{\n  \"site_logo\": \"images/joomlart/logo/item-1.png\",\n  \"site_logo_dark\": \"images/joomlart/logo/item-2.png\",\n  \"block_custom\": \"kept\"\n}\n";
    $tsPut('templates/ja_spa/local/etc/site/default.json', $tsNova);
    $tsPut('templates/ja_spa/local/notes.txt', 'the owner\'s own file');
    $tsSet(['fields' => ['site_logo' => 'images/tracy-brand/1111aaaa.png', 'site_logo_dark' => 'images/tracy-brand/2222bbbb.png']], 'apply-nova');
    check('a local profile is edited in place, not replaced by the template\'s', $tsLocal('default'),
        "{\n  \"site_logo\": \"images/tracy-brand/1111aaaa.png\",\n  \"site_logo_dark\": \"images/tracy-brand/2222bbbb.png\",\n  \"block_custom\": \"kept\"\n}\n");
    $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-nova']]);
    check('its revert restores the exact bytes it had', $tsLocal('default'), $tsNova);
    check('and deletes only what the set created', [$tsLocal('logo-light'), is_file($tsRoot . '/templates/ja_spa/local/notes.txt')], [null, true]);
    unlink($tsRoot . '/templates/ja_spa/local/etc/site/default.json');
    unlink($tsRoot . '/templates/ja_spa/local/notes.txt');
    rmdir($tsRoot . '/templates/ja_spa/local/etc/site'); rmdir($tsRoot . '/templates/ja_spa/local/etc'); rmdir($tsRoot . '/templates/ja_spa/local');

    // A log row is data: an undo step naming a file outside the door's own is not written.
    $tsLog->record('apply-forged', ['op' => 'siteSettings', 'files' => [['path' => 'configuration.php', 'before' => '<?php evil'], ['path' => 'templates/ja_spa/local/etc/site/../../../../configuration.php', 'before' => 'x']]]);
    $tsForged = $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-forged']]);
    check('an undo row outside the door\'s files fails and writes nothing', [isset($tsForged['failed']), is_file($tsRoot . '/configuration.php')], [true, false]);

    // ---- an undo that cannot be recorded leaves nothing behind ------------------------------------
    $tsNoLog = (new Engine($tsToken, [], null, null, null, null, null, null, new FailingApplyLog()))->templateSiteSettings($tsFiles);
    $tsLost = $tsCall($tsNoLog, ['operation' => 'set', 'apply_id' => 'a', 'template' => 'ja_spa', 'fields' => ['site_name' => 'Hanoi Roofing']]);
    check('a set whose undo cannot be recorded is rolled back', [$tsLost['error'], $tsLost['message'], is_dir($tsRoot . '/templates/ja_spa/local')], ['write_failed', 'change was rolled back: could not record its undo', false]);
    check('a receiver without the door answers unavailable', $tsCall(new Engine($tsToken, [], null, null, null, null, null, null, new FakeApplyLog()), ['template' => 'ja_spa'])['error'], 'unavailable');

    // ---- T3: the favicon is the one setting, printed by this plugin ------------------------------
    $tsMood = $tsCall($tsEngine, ['template' => 'ja_mood']);
    check('a T3 template reads its favicon and share image settings only', [$tsMood['framework'], $tsMood['keys'], $tsMood['settings']],
        ['t3', ['other_faviconFile', 'other_shareImage'], ['other_faviconFile' => '', 'other_shareImage' => '']]);
    $tsMoodSet = static fn(array $params, string $apply = 'apply-mood') => $tsCall($tsEngine, ['operation' => 'set', 'apply_id' => $apply, 'template' => 'ja_mood'] + $params);
    check('a T3 template refuses the T4 profile keys: its logo is a style param (templateStyle)', $tsMoodSet(['fields' => ['site_logo' => 'images/tracy-brand/1111aaaa.png']])['error'], 'unsupported');
    check('and refuses profiles', $tsMoodSet(['profiles' => ['default' => ['other_faviconFile' => 'images/tracy-brand/5555eeee.png']]])['error'], 'unsupported');
    check('an empty favicon on a T3 template with none set changes nothing', $tsMoodSet(['fields' => ['other_faviconFile' => '']])['unchanged'] ?? null, true);
    $tsMoodDone = $tsMoodSet(['fields' => ['other_faviconFile' => 'images/tracy-brand/5555eeee.png']]);
    check('a T3 favicon is written to the template\'s local settings file', [$tsMoodDone['changed'], $tsMoodDone['settings']],
        [['templates/ja_mood/local/etc/site/' . TemplateSiteSettings::FAVICON_FILE], ['other_faviconFile' => 'images/tracy-brand/5555eeee.png', 'other_shareImage' => '']]);
    check('the page points at the customer\'s file', TemplateSiteSettings::faviconLink($tsRoot, '/sub', 'ja_mood'), ['href' => '/sub/images/tracy-brand/5555eeee.png', 'type' => 'image/png']);
    check('a template with no setting prints Joomla\'s own favicon', TemplateSiteSettings::faviconLink($tsRoot, '', 'ja_spa'), null);
    $tsLinks = ['/sub/templates/ja_mood/favicon.ico' => ['relation' => 'shortcut icon', 'relType' => 'rel', 'attribs' => ['type' => 'image/vnd.microsoft.icon']],
        '/sub/media/system/images/joomla-favicon.svg' => ['relation' => 'icon', 'relType' => 'rel', 'attribs' => []],
        '/sub/apple.png' => ['relation' => 'apple-touch-icon', 'relType' => 'rel', 'attribs' => []],
        '/sub/feed' => ['relation' => 'alternate', 'relType' => 'rel', 'attribs' => []]];
    check('every favicon link goes; the touch icon and the feed stay', array_keys(TemplateSiteSettings::withoutFavicons($tsLinks)), ['/sub/apple.png', '/sub/feed']);
    // Joomla's MetasRenderer adds the template's favicon.ico AFTER onBeforeCompileHead whenever no head link has the
    // type image/vnd.microsoft.icon (measured on JA Smallbiz, Sensei, Morgan, Joomla 6.1): the printed page is cleaned.
    $tsKeep = '/sub/images/tracy-brand/5555eeee.png';
    $tsPage = "<!DOCTYPE html>\n<html><head>\n\t<meta charset=\"utf-8\">\n"
        . "\t<link href=\"/sub/images/tracy-brand/5555eeee.png\" rel=\"icon\" type=\"image/png\">\n"
        . "\t<link href=\"/sub/templates/ja_sensei/favicon.ico\" rel=\"icon\" type=\"image/vnd.microsoft.icon\">\n"
        . "\t<link rel='shortcut icon' href='/sub/favicon.ico' />\n"
        . "\t<link href=\"/sub/apple.png\" rel=\"apple-touch-icon\">\n"
        . "\t<link data-rel=\"icon\" href=\"/sub/x.css\" rel=\"stylesheet\">\n"
        . "\t<link href=\"/sub/feed\" rel=\"alternate\" type=\"application/rss+xml\">\n"
        . "</head><body><link href=\"/sub/body.ico\" rel=\"icon\"><p>icon</p></body></html>";
    $tsClean = TemplateSiteSettings::withoutOtherFaviconTags($tsPage, $tsKeep);
    check('the printed page keeps only the customer\'s favicon in its head', $tsClean, "<!DOCTYPE html>\n<html><head>\n\t<meta charset=\"utf-8\">\n"
        . "\t<link href=\"/sub/images/tracy-brand/5555eeee.png\" rel=\"icon\" type=\"image/png\">\n"
        . "\t<link href=\"/sub/apple.png\" rel=\"apple-touch-icon\">\n"
        . "\t<link data-rel=\"icon\" href=\"/sub/x.css\" rel=\"stylesheet\">\n"
        . "\t<link href=\"/sub/feed\" rel=\"alternate\" type=\"application/rss+xml\">\n"
        . "</head><body><link href=\"/sub/body.ico\" rel=\"icon\"><p>icon</p></body></html>");
    check('a page with no head is left as it is', TemplateSiteSettings::withoutOtherFaviconTags('{"ok":true}', $tsKeep), '{"ok":true}');
    check('a page without other favicons is left byte for byte', TemplateSiteSettings::withoutOtherFaviconTags($tsClean, $tsKeep), $tsClean);

    // ---- T3: the share image (og:image), the customer's logo (D5) --------------------------------
    // ---- T4: the share image is a fallback for a page without its own og:image (D5) -------------
    check('a T4 share image is one for the site: refused inside a profile', $tsSet(['profiles' => ['default' => ['other_shareImage' => 'images/tracy-brand/7777aaaa.jpg']]], 'apply-share-t4'),
        ['ok' => false, 'error' => 'unsupported', 'message' => 'other_shareImage is one share image for the whole site: send it in fields, not in a profile. Nothing was written']);
    check('a T4 share image must be a picture social networks draw too', $tsSet(['fields' => ['other_shareImage' => 'images/tracy-brand/6666ffff.svg']], 'apply-share-t4')['error'], 'bad_params');
    $tsT4Share = $tsSet(['fields' => ['other_shareImage' => 'images/tracy-brand/7777aaaa.jpg']], 'apply-share-t4');
    check('a T4 share image alone writes the settings file only, no profile', [$tsT4Share['ok'], $tsT4Share['changed'], $tsT4Share['settings'], $tsLocal('default')],
        [true, ['templates/ja_spa/local/etc/site/' . TemplateSiteSettings::FAVICON_FILE], ['other_shareImage' => 'images/tracy-brand/7777aaaa.jpg'], null]);
    check('the file holds that key only', $tsLocal('tracy-favicon'), '{"other_shareImage":"images\/tracy-brand\/7777aaaa.jpg"}');
    check('T4 pages print it by its absolute URL', TemplateSiteSettings::shareImageUrl($tsRoot, 'https://example.test/', 'ja_spa'), 'https://example.test/images/tracy-brand/7777aaaa.jpg');
    check('no favicon comes of it: T4 prints its own', TemplateSiteSettings::faviconLink($tsRoot, '', 'ja_spa'), null);
    $tsT4Both = $tsSet(['fields' => ['site_name' => 'Hanoi Roofing', 'other_shareImage' => 'images/tracy-brand/1111aaaa.png']], 'apply-share-t4-2');
    check('beside profile keys it goes to the settings file, they to the profiles', [$tsT4Both['changed'], array_key_exists('other_shareImage', json_decode((string) $tsLocal('default'), true))],
        [['templates/ja_spa/local/etc/site/' . TemplateSiteSettings::FAVICON_FILE, 'templates/ja_spa/local/etc/site/default.json', 'templates/ja_spa/local/etc/site/logo-light.json'], false]);
    $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-share-t4-2']]);
    check('its revert puts the first share image back and the profiles away', [TemplateSiteSettings::shareImageUrl($tsRoot, 'https://example.test/', 'ja_spa'), $tsLocal('default')],
        ['https://example.test/images/tracy-brand/7777aaaa.jpg', null]);
    $tsT4Url = 'https://example.test/images/tracy-brand/7777aaaa.jpg';
    $tsT4Page = "<html><head>\n\t<meta charset=\"utf-8\">\n\t<meta property=\"og:title\" content=\"Spa\">\n</head><body><meta property=\"og:image\" content=\"/body.png\"></body></html>";
    check('a T4 page without an og:image of its own is given the customer\'s, before </head>', TemplateSiteSettings::withShareImageFallback($tsT4Page, $tsT4Url),
        "<html><head>\n\t<meta charset=\"utf-8\">\n\t<meta property=\"og:title\" content=\"Spa\">\n<meta property=\"og:image\" content=\"" . $tsT4Url . "\">\n</head><body><meta property=\"og:image\" content=\"/body.png\"></body></html>");
    // T4's own tag, as T4\Helper\Metadata::renderTag prints it for a menu item given an og image.
    $tsT4Own = "<html><head>\n\t<meta property=\"og:image\" content=\"https://example.test/images/joomlart/hero.jpg\" />\n</head><body></body></html>";
    check('a T4 page with its own og:image keeps it, byte for byte', TemplateSiteSettings::withShareImageFallback($tsT4Own, $tsT4Url), $tsT4Own);
    check('so does one naming it by name=', TemplateSiteSettings::withShareImageFallback("<head><meta name='OG:image' content='/x.png'></head>", $tsT4Url), "<head><meta name='OG:image' content='/x.png'></head>");
    check('og:image:width alone is not an og:image', substr_count(TemplateSiteSettings::withShareImageFallback('<head><meta property="og:image:width" content="1"></head>', $tsT4Url), 'property="og:image" content'), 1);
    check('the URL is escaped in the tag', TemplateSiteSettings::withShareImageFallback('<head></head>', 'https://e.test/a.png?x="1"&y'), "<head><meta property=\"og:image\" content=\"https://e.test/a.png?x=&quot;1&quot;&amp;y\">\n</head>");
    check('a page with no head is left as it is', TemplateSiteSettings::withShareImageFallback('{"ok":true}', $tsT4Url), '{"ok":true}');
    $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-share-t4']]);
    check('the T4 revert removes the settings file and the local/ folder it made', [TemplateSiteSettings::shareImageUrl($tsRoot, 'https://example.test/', 'ja_spa'), is_dir($tsRoot . '/templates/ja_spa/local')], [null, false]);

    // ---- T3: the share image replaces any other ---------------------------------------------------
    check('a share image must be a picture social networks draw', $tsMoodSet(['fields' => ['other_shareImage' => 'images/tracy-brand/6666ffff.svg']], 'apply-share'),
        ['ok' => false, 'error' => 'bad_params', 'message' => 'other_shareImage must be a png, jpg, webp or gif: social networks do not draw a svg share image']);
    check('and a file on the site', $tsMoodSet(['fields' => ['other_shareImage' => 'images/tracy-brand/none.png']], 'apply-share')['error'], 'bad_params');
    check('no share image is printed before one is set', TemplateSiteSettings::shareImageUrl($tsRoot, 'https://example.test/sub/', 'ja_mood'), null);
    $tsShare = $tsMoodSet(['fields' => ['other_shareImage' => 'images/tracy-brand/7777aaaa.jpg']], 'apply-share');
    check('a T3 share image is written beside the favicon, which stays', $tsShare['settings'], ['other_faviconFile' => 'images/tracy-brand/5555eeee.png', 'other_shareImage' => 'images/tracy-brand/7777aaaa.jpg']);
    check('every page prints it as an absolute URL', TemplateSiteSettings::shareImageUrl($tsRoot, 'https://example.test/sub/', 'ja_mood'), 'https://example.test/sub/images/tracy-brand/7777aaaa.jpg');
    check('a root URL that is not absolute prints nothing: og:image must be', TemplateSiteSettings::shareImageUrl($tsRoot, '/sub', 'ja_mood'), null);
    check('the favicon is still the customer\'s', TemplateSiteSettings::faviconLink($tsRoot, '/sub', 'ja_mood')['href'] ?? null, '/sub/images/tracy-brand/5555eeee.png');
    $tsShareKeep = 'https://example.test/sub/images/tracy-brand/7777aaaa.jpg';
    $tsSharePage = "<html><head>\n\t<meta property=\"og:image\" content=\"https://example.test/sub/images/demo/hero.jpg\">\n"
        . "\t<meta property=\"og:image:width\" content=\"1200\" />\n"
        . "\t<meta property=\"og:image\" content=\"" . $tsShareKeep . "\">\n"
        . "\t<meta name='og:image' content='/demo.png'>\n"
        . "\t<meta property=\"og:title\" content=\"Hanoi Roofing\">\n"
        . "\t<meta name=\"twitter:image\" content=\"/demo.png\">\n"
        . "</head><body><meta property=\"og:image\" content=\"/body.png\"></body></html>";
    $tsShareClean = TemplateSiteSettings::withoutOtherShareImageTags($tsSharePage, $tsShareKeep);
    check('the printed head keeps only the customer\'s og:image, and every other og tag', $tsShareClean,
        "<html><head>\n\t<meta property=\"og:image\" content=\"" . $tsShareKeep . "\">\n"
        . "\t<meta property=\"og:title\" content=\"Hanoi Roofing\">\n"
        . "\t<meta name=\"twitter:image\" content=\"/demo.png\">\n"
        . "</head><body><meta property=\"og:image\" content=\"/body.png\"></body></html>");
    check('a page with only the customer\'s share image is left byte for byte', TemplateSiteSettings::withoutOtherShareImageTags($tsShareClean, $tsShareKeep), $tsShareClean);
    $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-share']]);
    check('its revert takes the share image back and keeps the favicon', [TemplateSiteSettings::shareImageUrl($tsRoot, 'https://example.test/', 'ja_mood'), TemplateSiteSettings::faviconLink($tsRoot, '', 'ja_mood')['href'] ?? null],
        [null, '/images/tracy-brand/5555eeee.png']);
    $tsPut('templates/ja_mood/local/etc/site/' . TemplateSiteSettings::FAVICON_FILE, '{"other_faviconFile":"images/tracy-brand/5555eeee.png","other_shareImage":"images/tracy-brand/6666ffff.svg"}');
    check('a settings file naming an SVG share image prints none', TemplateSiteSettings::shareImageUrl($tsRoot, 'https://example.test/', 'ja_mood'), null);

    $tsPut('templates/ja_mood/local/etc/site/' . TemplateSiteSettings::FAVICON_FILE, '{"other_faviconFile":"../configuration.php"}');
    check('a setting file naming something else prints nothing', TemplateSiteSettings::faviconLink($tsRoot, '', 'ja_mood'), null);
    $tsEngine->handle(['token' => $tsToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-mood']]);
    check('the T3 revert removes the setting and the folder it made', is_dir($tsRoot . '/templates/ja_mood/local'), false);

    // Clean up the site root.
    $tsItems = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tsRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($tsItems as $tsItem) $tsItem->isDir() ? @rmdir($tsItem->getPathname()) : @unlink($tsItem->getPathname());
    @rmdir($tsRoot);
}
