<?php
// Loaded by run.php: which site pages get the keyboard for T3's off-canvas drawer (lib/T3OffCanvas.php),
// and that the plugin ships the script and the switch that turns it off. The browser half is
// tests/e2e/t3-offcanvas.sh, on a real JA Diner site.

require_once __DIR__ . '/../lib/TemplateSiteSettings.php';
require_once __DIR__ . '/../lib/T3OffCanvas.php';

if (function_exists('check')) {
    $ocRoot = sys_get_temp_dir() . '/cowork-t3oc-' . bin2hex(random_bytes(4));
    try {
        $ocTemplate = function (string $name, string $manifest) use ($ocRoot) {
            @mkdir("$ocRoot/templates/$name", 0777, true);
            file_put_contents("$ocRoot/templates/$name/templateDetails.xml", $manifest);
        };
        $ocT3 = function (string $base, string $bytes) use ($ocRoot) {
            @mkdir("$ocRoot/plugins/system/t3/$base/js", 0777, true);
            file_put_contents("$ocRoot/plugins/system/t3/$base/js/off-canvas.js", $bytes);
            return hash('sha256', $bytes);
        };
        $ocTemplate('ja_t3', '<extension type="template"><name>ja_t3</name><t3><base>base-bs3</base></t3></extension>');
        $ocTemplate('ja_plain', '<extension type="template"><name>ja_plain</name></extension>');
        $ocTemplate('ja_t4', '<extension type="template"><name>ja_t4</name><t4><basetheme>base</basetheme></t4></extension>');
        $ocTemplate('ja_t3base', '<extension type="template"><name>ja_t3base</name><t3></t3></extension>');
        $ocKnown = ['base-bs3' => [$ocT3('base-bs3', "/* the checked bytes */\n")]];
        $ocT3('base', "/* T3's other base */\n");

        check('T3 off-canvas: a T3 style with the drawer on and the checked off-canvas.js gets the keyboard',
            [T3OffCanvas::applies($ocRoot, 'ja_t3', '1', $ocKnown), T3OffCanvas::applies($ocRoot, 'ja_t3', 1, $ocKnown)], [true, true]);
        check('T3 off-canvas: not with the drawer off, or unset',
            [T3OffCanvas::applies($ocRoot, 'ja_t3', '0', $ocKnown), T3OffCanvas::applies($ocRoot, 'ja_t3', null, $ocKnown), T3OffCanvas::applies($ocRoot, 'ja_t3', '', $ocKnown)], [false, false, false]);
        check('T3 off-canvas: not on a template without T3 (Cassiopeia, T4), nor one that is not there',
            [T3OffCanvas::applies($ocRoot, 'ja_plain', '1', $ocKnown), T3OffCanvas::applies($ocRoot, 'ja_t4', '1', $ocKnown), T3OffCanvas::applies($ocRoot, 'cassiopeia', '1', $ocKnown), T3OffCanvas::applies($ocRoot, '../ja_t3', '1', $ocKnown)],
            [false, false, false, false]);
        check('T3 off-canvas: not on a T3 base the script was not checked against', [T3OffCanvas::baseOf(file_get_contents("$ocRoot/templates/ja_t3base/templateDetails.xml")), T3OffCanvas::applies($ocRoot, 'ja_t3base', '1', $ocKnown + ['other' => []])], ['base', false]);
        // A T3 that is not the bytes the script was written against: updated upstream, or edited by hand.
        $ocTemplate('ja_t3b', '<extension type="template"><name>ja_t3b</name><t3><base>base-bs3</base></t3></extension>');
        @mkdir("$ocRoot/plugins/system/t3/base-bs3/js", 0777, true);
        file_put_contents("$ocRoot/plugins/system/t3/base-bs3/js/off-canvas.js", "/* the checked bytes, edited */\n");
        check('T3 off-canvas: not where off-canvas.js is other bytes, read again once it changed', T3OffCanvas::applies($ocRoot, 'ja_t3b', '1', $ocKnown), false);
        unlink("$ocRoot/plugins/system/t3/base-bs3/js/off-canvas.js");
        check('T3 off-canvas: nor where it is missing', T3OffCanvas::applies($ocRoot, 'ja_t3b', '1', ['base-bs3' => [hash('sha256', '')]]), false);

        // The hash the receiver lists is the one every T3 quickstart contract locks for that file.
        $ocLocked = [];
        foreach (glob(__DIR__ . '/../lib/contracts/*/*/*/presentation-lock.json') as $ocLock)
            if (preg_match('~"plugins/system/t3/base-bs3/js/off-canvas\.js":\s*"([0-9a-f]{64})"~', file_get_contents($ocLock), $m)) $ocLocked[$m[1]] = true;
        check('T3 off-canvas: the listed off-canvas.js is the one the locked T3 quickstarts ship', [count($ocLocked) > 0, array_keys($ocLocked)], [true, T3OffCanvas::KNOWN['base-bs3']]);
    } finally {
        if (is_dir($ocRoot)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ocRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $ocFile)
                $ocFile->isDir() ? rmdir($ocFile->getPathname()) : unlink($ocFile->getPathname());
            rmdir($ocRoot);
        }
    }

    // The plugin ships the script, installs it under media/, and has the switch, on unless turned off.
    $ocXml = simplexml_load_file(__DIR__ . '/../plg_system_claudecoworkapi/claudecoworkapi.xml');
    check('T3 off-canvas: the plugin installs the script as its media',
        [(string) $ocXml->media['destination'], (string) $ocXml->media['folder'], (string) $ocXml->media->folder, is_file(__DIR__ . '/../plg_system_claudecoworkapi/media/js/t3-offcanvas-a11y.js')],
        ['plg_system_claudecoworkapi', 'media', 'js', true]);
    $ocField = $ocXml->xpath('//config/fields[@name="params"]//field[@name="t3_offcanvas_a11y"]');
    check('T3 off-canvas: its own switch, on by default', [count($ocField), (string) ($ocField[0]['default'] ?? '')], [1, '1']);
    $ocPlugin = file_get_contents(__DIR__ . '/../plg_system_claudecoworkapi/src/Extension/ClaudeCoworkApi.php');
    check('T3 off-canvas: the plugin reads that switch and prints the script by its media name',
        [str_contains($ocPlugin, "params->get('t3_offcanvas_a11y', 1)"), str_contains($ocPlugin, "'plg_system_claudecoworkapi/t3-offcanvas-a11y.js'")], [true, true]);
    // Before the favicon's returns: a site with no favicon of its own still gets the keyboard.
    check('T3 off-canvas: asked for before anything the favicon needs',
        strpos($ocPlugin, '$this->t3OffCanvasKeyboard();') < strpos($ocPlugin, "'/local/etc/site/tracy-favicon.json'"), true);
}
