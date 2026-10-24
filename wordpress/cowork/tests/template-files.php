<?php
// Loaded by run.php: `template.files.status|write|undo`, the files of the active theme and its parent
// ("Sync to live" of TCH pushes a theme's CSS, JS and PHP back to the live site). The same door as the
// Joomla engine's, over the same lib/TemplateFiles.php; the roots are WordPress's.
//
// What is real here: a site root on disk with two template folders, symbolic links, the files the
// door writes, renames, parks and removes, and PHP's own lint (the command line where this PHP
// can start one, the parser otherwise). What stands in: the theme folders WordPress names (a
// callable), the Apply log, the site writer's lock and the administrator's log.

if (function_exists('check')) {
    $tfToken = 'template-files-token-16';
    $tfRoot = sys_get_temp_dir() . '/cowork-template-files-' . bin2hex(random_bytes(6));
    $tfOutside = $tfRoot . '-outside';
    $tfPut = static function (string $relative, string $bytes) use ($tfRoot): void {
        $path = $tfRoot . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);
    };
    $tfGet = static function (string $relative) use ($tfRoot): ?string {
        $path = $tfRoot . '/' . $relative;
        return is_file($path) ? (string) file_get_contents($path) : null;
    };
    $tfBlob = static fn(string $bytes): string => sha1('blob ' . strlen($bytes) . "\0" . $bytes);
    $tfCss = "body { color: #222; }\n";
    $tfPhp = "<?php\ndefined('ABSPATH') || exit;\necho 'article';\n";
    $tfPut('wp-config.php', "<?php\ndefine('DB_PASSWORD', 'secret');\n");
    $tfPut('wp-content/themes/child/templateDetails.xml', '<?xml version="1.0"?><theme/>');
    $tfPut('wp-content/themes/child/index.php', "<?php\ndefined('ABSPATH') || exit;\n");
    $tfPut('wp-content/themes/child/css/template.css', $tfCss);
    $tfPut('wp-content/themes/child/js/a.js', "console.log('a');\n");
    $tfPut('wp-content/themes/child/template-parts/content.php', $tfPhp);
    $tfPut('wp-content/themes/parent/css/media.css', ".m { margin: 0; }\n");
    $tfPut('wp-content/plugins/akismet/css/y.css', 'not installed');
    mkdir($tfRoot . '/wp-content/themes/child/dir.css', 0777, true);
    mkdir($tfOutside, 0777, true);
    file_put_contents($tfOutside . '/a.css', 'outside');
    symlink($tfOutside, $tfRoot . '/wp-content/themes/child/linked');
    symlink($tfRoot . '/wp-config.php', $tfRoot . '/wp-content/themes/child/css/evil.css');
    symlink($tfOutside, $tfRoot . '/wp-content/themes/linked');
    $tfRoots = static fn(): array => [
        ['path' => 'wp-content/themes/child', 'kind' => 'theme', 'name' => 'child', 'php' => true],
        ['path' => 'wp-content/themes/parent', 'kind' => 'parent-theme', 'name' => 'parent', 'php' => true],
        ['path' => 'wp-content/themes/linked', 'kind' => 'theme', 'name' => 'linked', 'php' => true],
    ];
    $tfAudit = [];
    $tfChanged = [];
    // PHP allowed: the owner switched it on. The switch itself is tested below.
    $tfMake = static function (?string $lint = null, bool $php = true) use ($tfRoot, $tfRoots, &$tfAudit, &$tfChanged): TemplateFiles {
        return (new TemplateFiles($tfRoot, $tfRoots, static function (array $paths) use (&$tfChanged): void { $tfChanged[] = $paths; },
            static function (array $event) use (&$tfAudit): void { $tfAudit[] = $event; }, $lint))->phpAllowed($php);
    };
    $tfFiles = $tfMake();
    $tfLog = new FakeApplyLog();
    // The plugin's writer takes its lock in serialize(); this one counts the calls and holds no rows.
    $tfWriter = new class implements SiteWriter {
        public array $serialized = [];
        public int $purged = 0;
        public function serialize(callable $work) { $this->serialized[] = ['action' => 'lock']; return $work(); }
        public function read(string $kind, int $id, string $key = ''): ?array { return null; }
        public function write(string $kind, int $id, array $fields, string $key = ''): int { return $id; }
        public function editLock(int $postId): ?array { return null; }
        public function delete(string $kind, int $id, string $key = ''): void {}
        public function canTrash(string $kind): bool { return false; }
        public function trash(string $kind, int $id): void {}
        public function purgeCache(): void { $this->purged++; }
    };
    $tfEngine = (new Engine($tfToken, [], null, null, null, null, $tfWriter, null, $tfLog))->templateFiles($tfFiles);
    $tfCall = static fn(Engine $engine, string $action, array $params) => $engine->handle(['token' => $tfToken, 'action' => $action, 'params' => $params]);
    $tfFile = static fn(string $path, string $bytes, ?string $expected, string $as = 'expected_sha1'): array =>
        ['path' => $path, $as => $expected, 'content_b64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
    $tfWrite = static fn(array $files, string $apply = 'push-1', array $extra = []) => $tfCall($tfEngine, 'template.files.write', ['apply_id' => $apply, 'files' => $files] + $extra);
    $tfTemps = static function () use ($tfRoot): array {
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tfRoot, FilesystemIterator::SKIP_DOTS)) as $file)
            if (strpos($file->getFilename(), '.tracy-tf-') === 0) $out[] = $file->getPathname();
        return $out;
    };

    // ---- info -----------------------------------------------------------------------------------
    $tfInfo = $tfCall($tfEngine, 'info', [])['info']['template_files'] ?? null;
    check('info advertises the door: its version, that it is on, that PHP may be written, its actions and limits',
        [$tfInfo['version'] ?? null, $tfInfo['enabled'] ?? null, $tfInfo['php'] ?? null, $tfInfo['actions'] ?? null, $tfInfo['max_file_bytes'] ?? null, $tfInfo['max_bytes'] ?? null, $tfInfo['max_files'] ?? null],
        [1, true, true, ['template.files.status', 'template.files.write', 'template.files.undo'], 2097152, 8388608, 100]);
    checkTrue('info names how PHP is checked here', in_array($tfInfo['php_lint'] ?? 'x', ['cli', 'tokens'], true));
    $tfBare = new Engine($tfToken, [], null, null, null, null, $tfWriter, null, $tfLog);
    check('an engine without the door does not advertise it', array_key_exists('template_files', $tfCall($tfBare, 'info', [])['info']), false);
    check('and answers it unavailable', $tfCall($tfBare, 'template.files.write', ['apply_id' => 'x', 'files' => []])['error'], 'unavailable');

    // ---- status ---------------------------------------------------------------------------------
    $tfWriter->serialized = [];
    $tfStatus = $tfCall($tfEngine, 'template.files.status', ['paths' => ['wp-content/themes/child/css/template.css', 'wp-content/themes/child/css/new.css', 'wp-config.php', 'wp-content/themes/child/css/evil.css']]);
    check('status lists the roots that stand: a root reached through a link is left out', array_keys(array_column($tfStatus['roots'], null, 'path')),
        ['wp-content/themes/child', 'wp-content/themes/parent']);
    check('a file on the site answers its hashes: sha1, git blob, sha256, bytes', $tfStatus['files'][0],
        ['path' => 'wp-content/themes/child/css/template.css', 'exists' => true, 'sha1' => sha1($tfCss), 'blob' => $tfBlob($tfCss), 'sha256' => hash('sha256', $tfCss), 'bytes' => strlen($tfCss), 'root' => 'wp-content/themes/child']);
    check('a path with no file answers exists: false', $tfStatus['files'][1], ['path' => 'wp-content/themes/child/css/new.css', 'exists' => false, 'root' => 'wp-content/themes/child']);
    check('a path the door would refuse answers why, and nothing of the file', [$tfStatus['files'][2], $tfStatus['files'][3]],
        [['path' => 'wp-config.php', 'refused' => 'denied'], ['path' => 'wp-content/themes/child/css/evil.css', 'refused' => 'symlink']]);
    check('status takes no write lock', $tfWriter->serialized, []);

    // ---- scope and deny-list ----------------------------------------------------------------------
    $tfBad = [
        '../wp-config.php' => 'traversal',
        'wp-content/themes/child/../../../wp-config.php' => 'traversal',
        'wp-content/themes/child/./css/a.css' => 'traversal',
        '/etc/passwd.css' => 'absolute',
        'C:/x.css' => 'absolute',
        'configuration.php' => 'denied',
        'wp-config.php' => 'denied',
        'wp-content/themes/child/wp-config-sample.php' => 'denied',
        'wp-content/themes/child/wp-config.php' => 'denied',
        'wp-content/themes/child/web.config' => 'denied',
        'wp-content/themes/child/php.ini' => 'denied',
        'wp-content/themes/child/.htaccess' => 'dotfile',
        'wp-content/themes/child/.user.ini' => 'dotfile',
        'wp-content/themes/child/css/.hidden.css' => 'dotfile',
        'wp-content/themes/child/.git/config.json' => 'dotfile',
        'wp-content/themes/child/run.sh' => 'extension',
        'wp-content/themes/child/x.phtml' => 'extension',
        'wp-content/themes/child/x.phar' => 'extension',
        'wp-content/themes/child/templateDetails.xml' => 'extension',
        'wp-content/themes/child/README' => 'extension',
        'wp-content/themes/child/shell.php.css' => 'executable',
        'wp-content/themes/child/shell.PHP5.js' => 'executable',
        'wp-content/plugins/akismet/css/y.css' => 'not_in_scope',
        'wp-content/a.css' => 'not_in_scope',
        'wp-content/themes/child-two/a.css' => 'not_in_scope',
        'wp-content/themes/linked/a.css' => 'not_in_scope',
        'wp-content/plugins/akismet/akismet.php' => 'not_in_scope',
        'wp-content/uploads/x.php' => 'not_in_scope',
        'wp-content/mu-plugins/x.php' => 'not_in_scope',
        'wp-content/themes/child/linked/a.css' => 'symlink',
        'wp-content/themes/child/linked/new/b.css' => 'symlink',
        'wp-content/themes/child/css/evil.css' => 'symlink',
        'wp-content/themes/child/dir.css' => 'not_a_file',
        'wp-content/themes/child//a.css' => 'bad_path',
        'wp-content\\themes\\child\\a.css' => 'bad_path',
        "wp-content/themes/child/a\0.css" => 'bad_path',
        'wp-content/themes/child/a b.css' => 'bad_path',
    ];
    foreach ($tfBad as $tfPath => $tfReason) {
        $tfAnswer = $tfWrite([$tfFile($tfPath, 'x', null)]);
        check('refused: ' . str_replace("\0", '\\0', $tfPath), [$tfAnswer['error'] ?? null, $tfAnswer['refused'][0]['reason'] ?? null], ['refused', $tfReason]);
    }
    $tfMixed = $tfWrite([$tfFile('wp-content/themes/child/css/fine.css', 'fine', null), $tfFile('wp-content/themes/child/.htaccess', 'Options +ExecCGI', null)]);
    check('one refused path stops the whole call, and the answer names it', [$tfMixed['error'], $tfMixed['refused']], ['refused', [['path' => 'wp-content/themes/child/.htaccess', 'reason' => 'dotfile']]]);
    check('the refusals wrote nothing, outside or in, and recorded nothing',
        [$tfGet('wp-content/themes/child/css/fine.css'), file_get_contents($tfOutside . '/a.css'), (string) file_get_contents($tfRoot . '/wp-config.php') === "<?php\ndefine('DB_PASSWORD', 'secret');\n", $tfLog->entries('push-1'), $tfTemps()],
        [null, 'outside', true, [], []]);

    // ---- the request's own checks -------------------------------------------------------------------
    check('an apply_id is required', $tfCall($tfEngine, 'template.files.write', ['files' => [$tfFile('wp-content/themes/child/css/n.css', 'n', null)]])['error'], 'bad_params');
    check('files must be a non-empty list', [$tfWrite([])['error'], $tfWrite(['a' => 1])['error']], ['bad_params', 'bad_params']);
    check('each file names what the site holds now: no expectation is refused',
        $tfWrite([['path' => 'wp-content/themes/child/css/n.css', 'content_b64' => base64_encode('n'), 'sha256' => hash('sha256', 'n')]])['error'], 'bad_params');
    check('and both forms at once are refused', $tfWrite([$tfFile('wp-content/themes/child/css/n.css', 'n', null) + ['expected_blob' => null]])['error'], 'bad_params');
    check('the sha256 is required', $tfWrite([['path' => 'wp-content/themes/child/css/n.css', 'expected_sha1' => null, 'content_b64' => base64_encode('n')]])['error'], 'bad_params');
    $tfCorrupt = $tfWrite([['path' => 'wp-content/themes/child/css/n.css', 'expected_sha1' => null, 'content_b64' => base64_encode('n'), 'sha256' => hash('sha256', 'm')]]);
    check('bytes that are not the bytes sent are refused', [$tfCorrupt['error'], $tfCorrupt['path']], ['corrupt_upload', 'wp-content/themes/child/css/n.css']);
    check('a path named twice is refused', $tfWrite([$tfFile('wp-content/themes/child/css/n.css', 'n', null), $tfFile('wp-content/themes/child/css/N.css', 'n', null)])['error'], 'bad_params');
    check('a delete names the hash of the file it removes', $tfWrite([['path' => 'wp-content/themes/child/js/a.js', 'delete' => true, 'expected_sha1' => null]])['error'], 'bad_params');
    $tfBig = str_repeat('a', TemplateFiles::MAX_FILE_BYTES + 1);
    check('a file past the size cap is refused', $tfWrite([$tfFile('wp-content/themes/child/css/big.css', $tfBig, null)])['error'], 'too_large');
    $tfHalf = str_repeat('b', TemplateFiles::MAX_FILE_BYTES);
    $tfCap = $tfWrite(array_map(static fn($i) => $tfFile("wp-content/themes/child/css/h$i.css", $tfHalf, null), range(1, intdiv(TemplateFiles::MAX_BYTES, TemplateFiles::MAX_FILE_BYTES) + 1)));
    check('files each under the file cap but past the call cap together are refused', [$tfCap['error'], $tfCap['max_bytes'] ?? null], ['too_large', TemplateFiles::MAX_BYTES]);
    unset($tfBig, $tfHalf);
    check('more files than a call takes are refused', $tfWrite(array_map(static fn($i) => $tfFile("wp-content/themes/child/css/m$i.css", 'm', null), range(1, TemplateFiles::MAX_FILES + 1)))['error'], 'too_large');
    check('nothing of those was written', [$tfGet('wp-content/themes/child/css/n.css'), $tfGet('wp-content/themes/child/css/h1.css'), $tfGet('wp-content/themes/child/css/m1.css'), $tfTemps()], [null, null, null, []]);

    // ---- compare-and-set -----------------------------------------------------------------------------
    $tfConflict = $tfWrite([
        $tfFile('wp-content/themes/child/css/template.css', 'body{}', sha1('what the copy thought')),
        $tfFile('wp-content/themes/child/js/new.js', 'new', null),
        $tfFile('wp-content/themes/child/js/a.js', 'a2', null),
        $tfFile('wp-content/themes/child/css/missing.css', 'm', sha1('gone')),
    ]);
    check('every file the site holds otherwise is a conflict, each named with what the site holds', [$tfConflict['error'], $tfConflict['code'], $tfConflict['conflicts']], ['conflict', 'CONFLICT', [
        ['path' => 'wp-content/themes/child/css/template.css', 'expected' => ['sha1' => sha1('what the copy thought')], 'actual' => ['sha1' => sha1($tfCss), 'blob' => $tfBlob($tfCss)]],
        ['path' => 'wp-content/themes/child/js/a.js', 'expected' => null, 'actual' => ['sha1' => sha1("console.log('a');\n"), 'blob' => $tfBlob("console.log('a');\n")]],
        ['path' => 'wp-content/themes/child/css/missing.css', 'expected' => ['sha1' => sha1('gone')], 'actual' => null],
    ]]);
    check('a conflict writes nothing, not even the files that had none', [$tfGet('wp-content/themes/child/css/template.css'), $tfGet('wp-content/themes/child/js/new.js'), $tfLog->entries('push-1'), $tfTemps()], [$tfCss, null, [], []]);

    // ---- a dry run checks everything and writes nothing ------------------------------------------------
    $tfDry = $tfWrite([$tfFile('wp-content/themes/child/css/template.css', 'body{}', sha1($tfCss))], 'push-1', ['dry_run' => true]);
    check('a dry run answers what would happen', [$tfDry['ok'], $tfDry['dry_run'], $tfDry['changed'], $tfDry['files'][0]['op'], $tfDry['undo_id']], [true, true, 1, 'updated', null]);
    check('and writes nothing', [$tfGet('wp-content/themes/child/css/template.css'), $tfLog->entries('push-1'), $tfAudit], [$tfCss, [], []]);

    // ---- a write -------------------------------------------------------------------------------------
    $tfWriter->serialized = [];
    $tfNewCss = "body { color: #b00; }\n";
    $tfNewPhp = "<?php\ndefined('ABSPATH') || exit;\necho 'override';\n";
    $tfDone = $tfWrite([
        $tfFile('wp-content/themes/child/css/template.css', $tfNewCss, sha1($tfCss)),
        $tfFile('wp-content/themes/child/css/custom/brand.css', '.brand{}', null),
        $tfFile('wp-content/themes/child/template-parts/content.php', $tfNewPhp, $tfBlob($tfPhp), 'expected_blob'),
        ['path' => 'wp-content/themes/child/js/a.js', 'delete' => true, 'expected_sha1' => sha1("console.log('a');\n")],
        $tfFile('wp-content/themes/parent/css/media.css', ".m { margin: 0; }\n", sha1('anything: the bytes are already there')),
    ]);
    check('a write answers each file: updated, created, updated, deleted, unchanged',
        [$tfDone['ok'], $tfDone['changed'], array_column($tfDone['files'], 'op')], [true, 4, ['updated', 'created', 'updated', 'deleted', 'unchanged']]);
    check('with what each held before and holds after', [$tfDone['files'][0]['before']['sha1'], $tfDone['files'][0]['after']['blob'], $tfDone['files'][1]['before'], $tfDone['files'][3]['after']],
        [sha1($tfCss), $tfBlob($tfNewCss), null, null]);
    checkTrue('and the undo id of this call, with the call that takes it back', preg_match('~^[a-f0-9]{24}$~', $tfDone['undo_id']) === 1
        && $tfDone['undo'] === ['action' => 'template.files.undo', 'params' => ['apply_id' => 'push-1', 'undo_id' => $tfDone['undo_id']]]);
    check('the PHP override was linted first', $tfDone['lint'], $tfInfo['php_lint']);
    check('the site holds the new bytes; the new folder was made; the deleted file is gone; no temporary is left',
        [$tfGet('wp-content/themes/child/css/template.css'), $tfGet('wp-content/themes/child/css/custom/brand.css'), $tfGet('wp-content/themes/child/template-parts/content.php'), $tfGet('wp-content/themes/child/js/a.js'), $tfTemps()],
        [$tfNewCss, '.brand{}', $tfNewPhp, null, []]);
    check('one Apply log step per changed file, under the caller\'s apply_id, with the undo id',
        array_map(static fn($e) => [$e['op'], $e['undo_id'], $e['path'], $e['before'], $e['after'], $e['made']], $tfLog->entries('push-1')), [
            ['templateFiles', $tfDone['undo_id'], 'wp-content/themes/child/css/template.css', $tfCss, sha1($tfNewCss), []],
            ['templateFiles', $tfDone['undo_id'], 'wp-content/themes/child/css/custom/brand.css', null, sha1('.brand{}'), ['wp-content/themes/child/css/custom']],
            ['templateFiles', $tfDone['undo_id'], 'wp-content/themes/child/template-parts/content.php', $tfPhp, sha1($tfNewPhp), []],
            ['templateFiles', $tfDone['undo_id'], 'wp-content/themes/child/js/a.js', "console.log('a');\n", null, []],
        ]);
    check('the write ran under the write lock, named', array_column($tfWriter->serialized, 'action'), ['lock']);
    check('the administrator is told once, with every file it changed', [count($tfAudit), $tfAudit[0]['action'], $tfAudit[0]['undo_id'], $tfAudit[0]['files']], [1, 'template.files.write', $tfDone['undo_id'], [
        ['path' => 'wp-content/themes/child/css/template.css', 'op' => 'updated'], ['path' => 'wp-content/themes/child/css/custom/brand.css', 'op' => 'created'],
        ['path' => 'wp-content/themes/child/template-parts/content.php', 'op' => 'updated'], ['path' => 'wp-content/themes/child/js/a.js', 'op' => 'deleted']]]);
    check('the host emptied its caches for the files that changed', end($tfChanged), ['wp-content/themes/child/css/template.css', 'wp-content/themes/child/css/custom/brand.css', 'wp-content/themes/child/template-parts/content.php', 'wp-content/themes/child/js/a.js']);
    check('apply.list shows each file step', array_map(static fn($s) => [$s['op'], $s['path'], $s['created'], $s['deleted']], $tfCall($tfEngine, 'apply.list', ['apply_id' => 'push-1'])['steps']), [
        ['templateFiles', 'wp-content/themes/child/css/template.css', false, false], ['templateFiles', 'wp-content/themes/child/css/custom/brand.css', true, false],
        ['templateFiles', 'wp-content/themes/child/template-parts/content.php', false, false], ['templateFiles', 'wp-content/themes/child/js/a.js', false, true]]);

    // A retry after a lost reply: the same call again finds every file already as it asked.
    $tfAgain = $tfWrite([$tfFile('wp-content/themes/child/css/template.css', $tfNewCss, sha1($tfCss)), $tfFile('wp-content/themes/child/css/custom/brand.css', '.brand{}', null)]);
    check('the same write again changes nothing and records nothing', [$tfAgain['changed'], array_column($tfAgain['files'], 'op'), $tfAgain['undo_id'], count($tfLog->entries('push-1')), count($tfAudit)],
        [0, ['unchanged', 'unchanged'], null, 4, 1]);

    // ---- PHP lint --------------------------------------------------------------------------------------
    $tfBroken = "<?php\ndefined('ABSPATH') || exit;\necho 'missing semicolon'\n\$x = 1;\n";
    foreach ([null, 'tokens'] as $tfMode) {
        $tfLintEngine = (new Engine($tfToken, [], null, null, null, null, $tfWriter, null, $tfLog))->templateFiles($tfMake($tfMode));
        $tfLint = $tfCall($tfLintEngine, 'template.files.write', ['apply_id' => 'push-lint', 'files' => [
            $tfFile('wp-content/themes/child/css/lint.css', 'ok', null),
            $tfFile('wp-content/themes/child/template-parts/content.php', $tfBroken, sha1($tfNewPhp))]]);
        $tfName = $tfMode ?? $tfInfo['php_lint'];
        check("PHP that does not parse is refused ($tfName), naming the file and the line",
            [$tfLint['error'] ?? null, $tfLint['lint'][0]['path'] ?? null, $tfLint['lint'][0]['line'] ?? null, $tfLint['lint_mode'] ?? null],
            ['php_lint_failed', 'wp-content/themes/child/template-parts/content.php', 4, $tfName]);
        checkTrue("and its message is PHP's own ($tfName)", stripos($tfLint['lint'][0]['message'] ?? '', 'syntax error') !== false && strpos($tfLint['lint'][0]['message'] ?? '', sys_get_temp_dir() . '/tracy-lint') === false);
        check("nothing of that call was written ($tfName)", [$tfGet('wp-content/themes/child/css/lint.css'), $tfGet('wp-content/themes/child/template-parts/content.php'), $tfLog->entries('push-lint')], [null, $tfNewPhp, []]);
    }
    $tfNoLint = (new Engine($tfToken, [], null, null, null, null, $tfWriter, null, $tfLog))->templateFiles($tfMake('none'));
    check('a site that cannot check PHP refuses PHP', $tfCall($tfNoLint, 'template.files.write', ['apply_id' => 'push-lint', 'files' => [$tfFile('wp-content/themes/child/html/x.php', "<?php\n", null)]])['error'], 'php_lint_unavailable');
    check('and says so in info', $tfCall($tfNoLint, 'info', [])['info']['template_files']['php_lint'], null);
    $tfCssOnly = $tfCall($tfNoLint, 'template.files.write', ['apply_id' => 'push-lint', 'files' => [$tfFile('wp-content/themes/child/css/lint.css', 'ok', null)]]);
    check('but still writes what is not PHP', [$tfCssOnly['changed'], $tfGet('wp-content/themes/child/css/lint.css')], [1, 'ok']);

    // ---- all or nothing: a rename that fails halfway puts back what this call moved -------------------------
    $tfFailing = $tfMake()->renameWith(static function (string $from, string $to) use ($tfRoot): bool {
        if ($to === $tfRoot . '/wp-content/themes/child/js/third.js') return false;
        return rename($from, $to);
    });
    $tfAtomic = (new Engine($tfToken, [], null, null, null, null, $tfWriter, null, $tfLog))->templateFiles($tfFailing);
    $tfPut('wp-content/themes/child/js/keep.js', 'keep');
    $tfHalfway = $tfCall($tfAtomic, 'template.files.write', ['apply_id' => 'push-atomic', 'files' => [
        $tfFile('wp-content/themes/child/css/template.css', 'body{}', sha1($tfNewCss)),
        $tfFile('wp-content/themes/child/fresh/deep/one.css', 'one', null),
        ['path' => 'wp-content/themes/child/js/keep.js', 'delete' => true, 'expected_sha1' => sha1('keep')],
        $tfFile('wp-content/themes/child/js/third.js', 'third', null),
    ]]);
    check('a rename that fails is a write_failed, every file put back', [$tfHalfway['error'] ?? null, $tfHalfway['rolled_back'] ?? null, $tfHalfway['not_restored'] ?? null], ['write_failed', true, []]);
    check('the replaced file holds its old bytes, the new one and its folders are gone, the deleted one is back, no temporary is left',
        [$tfGet('wp-content/themes/child/css/template.css'), is_dir($tfRoot . '/wp-content/themes/child/fresh'), $tfGet('wp-content/themes/child/js/keep.js'), $tfGet('wp-content/themes/child/js/third.js'), $tfTemps()],
        [$tfNewCss, false, 'keep', null, []]);
    check('and nothing was recorded', $tfLog->entries('push-atomic'), []);

    $tfNoRecord = (new Engine($tfToken, [], null, null, null, null, $tfWriter, null, new FailingApplyLog()))->templateFiles($tfMake());
    $tfUnrecorded = $tfCall($tfNoRecord, 'template.files.write', ['apply_id' => 'push-nolog', 'files' => [
        $tfFile('wp-content/themes/child/css/template.css', 'body{}', sha1($tfNewCss)), $tfFile('wp-content/themes/child/css/unlogged.css', 'u', null)]]);
    check('a write whose undo cannot be recorded is taken back', [$tfUnrecorded['error'], $tfUnrecorded['rolled_back'], $tfGet('wp-content/themes/child/css/template.css'), $tfGet('wp-content/themes/child/css/unlogged.css'), $tfTemps()],
        ['write_failed', true, $tfNewCss, null, []]);

    // ---- undo: back only where the live file still holds what the write left ----------------------------------
    $tfAudit = [];
    $tfPut('wp-content/themes/child/css/template.css', "body { color: green; } /* the owner's own edit */\n");
    $tfWriter->serialized = [];
    $tfUndo = $tfCall($tfEngine, 'template.files.undo', ['apply_id' => 'push-1', 'undo_id' => $tfDone['undo_id']]);
    check('undo puts back what it can and names the rest', [$tfUndo['ok'], $tfUndo['complete'], $tfUndo['restored'], $tfUndo['kept']], [true, false, [
        ['path' => 'wp-content/themes/child/css/custom/brand.css', 'op' => 'removed', 'undo_id' => $tfDone['undo_id']],
        ['path' => 'wp-content/themes/child/template-parts/content.php', 'op' => 'restored', 'undo_id' => $tfDone['undo_id']],
        ['path' => 'wp-content/themes/child/js/a.js', 'op' => 'restored', 'undo_id' => $tfDone['undo_id']],
    ], [['path' => 'wp-content/themes/child/css/template.css', 'undo_id' => $tfDone['undo_id'], 'reason' => 'changed-on-live', 'sha1' => sha1("body { color: green; } /* the owner's own edit */\n")]]]);
    check('the owner\'s edit stays; the created file and the folder made for it are gone; the override and the deleted file are back',
        [$tfGet('wp-content/themes/child/css/template.css'), is_dir($tfRoot . '/wp-content/themes/child/css/custom'), $tfGet('wp-content/themes/child/template-parts/content.php'), $tfGet('wp-content/themes/child/js/a.js'), $tfTemps()],
        ["body { color: green; } /* the owner's own edit */\n", false, $tfPhp, "console.log('a');\n", []]);
    check('undo ran under the write lock and told the administrator', [array_column($tfWriter->serialized, 'action'), $tfAudit[0]['action'], count($tfAudit[0]['files']), $tfAudit[0]['kept']],
        [['lock'], 'template.files.undo', 3, 1]);
    $tfUndoAgain = $tfCall($tfEngine, 'template.files.undo', ['apply_id' => 'push-1', 'undo_id' => $tfDone['undo_id']]);
    check('undo again: the files already back answer already, the owner\'s edit is still kept', [$tfUndoAgain['restored'], count($tfUndoAgain['already']), count($tfUndoAgain['kept'])], [[], 3, 1]);
    $tfPut('wp-content/themes/child/css/template.css', $tfNewCss);
    $tfUndoLast = $tfCall($tfEngine, 'template.files.undo', ['apply_id' => 'push-1']);
    check('once the live file holds what the write left again, it goes back too', [$tfUndoLast['complete'], array_column($tfUndoLast['restored'], 'path'), $tfGet('wp-content/themes/child/css/template.css')],
        [true, ['wp-content/themes/child/css/template.css'], $tfCss]);
    check('an Apply of template files only, all back, is forgotten', $tfLog->entries('push-1'), []);
    check('an unknown undo is not found', [$tfCall($tfEngine, 'template.files.undo', ['apply_id' => 'push-1'])['error'], $tfCall($tfEngine, 'template.files.undo', ['apply_id' => 'push-x', 'undo_id' => 'zz'])['error']], ['not_found', 'bad_params']);

    // Two writes of one file under one Apply: undone newest first, back to the first bytes.
    $tfW1 = $tfWrite([$tfFile('wp-content/themes/child/css/template.css', 'v1', sha1($tfCss))], 'push-2');
    $tfW2 = $tfWrite([$tfFile('wp-content/themes/child/css/template.css', 'v2', sha1('v1'))], 'push-2');
    check('a write over an earlier write of the same Apply', [$tfW1['changed'], $tfW2['changed'], $tfGet('wp-content/themes/child/css/template.css')], [1, 1, 'v2']);
    check('undoing only the first while the second stands keeps the file', $tfCall($tfEngine, 'template.files.undo', ['apply_id' => 'push-2', 'undo_id' => $tfW1['undo_id']])['kept'][0]['reason'], 'changed-on-live');
    $tfBoth = $tfCall($tfEngine, 'template.files.undo', ['apply_id' => 'push-2']);
    check('undoing the Apply takes the newest first, back to the first bytes', [$tfBoth['undo_ids'], $tfBoth['complete'], $tfGet('wp-content/themes/child/css/template.css')], [[$tfW2['undo_id'], $tfW1['undo_id']], true, $tfCss]);

    // ---- apply.revert takes the same steps back, all or none ------------------------------------------------
    $tfR = $tfWrite([$tfFile('wp-content/themes/child/css/template.css', 'r1', sha1($tfCss)), $tfFile('wp-content/themes/child/css/r.css', 'r', null)], 'push-3');
    $tfR2 = $tfWrite([$tfFile('wp-content/themes/child/css/template.css', 'r2', sha1('r1'))], 'push-3');
    $tfPut('wp-content/themes/child/css/r.css', 'owner changed it');
    $tfRefused = $tfCall($tfEngine, 'apply.revert', ['apply_id' => 'push-3']);
    check('apply.revert refuses when a file changed on the site since, and reverts nothing', [$tfRefused['error'], $tfRefused['kept'], $tfGet('wp-content/themes/child/css/template.css')],
        ['conflict', [['path' => 'wp-content/themes/child/css/r.css', 'reason' => 'changed-on-live', 'sha1' => sha1('owner changed it')]], 'r2']);
    $tfPut('wp-content/themes/child/css/r.css', 'r');
    $tfReverted = $tfCall($tfEngine, 'apply.revert', ['apply_id' => 'push-3']);
    check('and otherwise takes every step back, newest first', [$tfReverted['ok'], $tfReverted['reverted'], $tfGet('wp-content/themes/child/css/template.css'), $tfGet('wp-content/themes/child/css/r.css'), $tfLog->entries('push-3')],
        [true, 3, $tfCss, null, []]);

    // ---- the delete op on a file the site never had, and a stale delete ------------------------------------------
    check('deleting a file that is not there is already done', array_column($tfWrite([['path' => 'wp-content/themes/child/js/none.js', 'delete' => true, 'expected_sha1' => sha1('x')]], 'push-4')['files'], 'op'), ['unchanged']);
    check('deleting a file that changed is a conflict', $tfWrite([['path' => 'wp-content/themes/child/js/a.js', 'delete' => true, 'expected_sha1' => sha1('old')]], 'push-4')['error'], 'conflict');

    // ---- the owner's PHP switch, off unless switched on ----------------------------------------------------------
    $tfNoPhp = (new Engine($tfToken, [], null, null, null, null, $tfWriter, null, $tfLog))->templateFiles($tfMake(null, false));
    check('with PHP switched off info says so', $tfCall($tfNoPhp, 'info', [])['info']['template_files']['php'], false);
    check('and a new door has PHP off', (new TemplateFiles($tfRoot, $tfRoots))->info()['php'], false);
    $tfPhpOff = $tfCall($tfNoPhp, 'template.files.write', ['apply_id' => 'push-nophp', 'files' => [
        $tfFile('wp-content/themes/child/css/nophp.css', 'css', null), $tfFile('wp-content/themes/child/html/new.php', "<?php\n", null)]]);
    check('every .php entry is refused php_disabled, the call with it, and the answer says how to switch it on',
        [$tfPhpOff['error'], $tfPhpOff['refused'], isset($tfPhpOff['next']), $tfGet('wp-content/themes/child/css/nophp.css')],
        ['refused', [['path' => 'wp-content/themes/child/html/new.php', 'reason' => 'php_disabled']], true, null]);
    check('status names the reason too', $tfCall($tfNoPhp, 'template.files.status', ['paths' => ['wp-content/themes/child/template-parts/content.php']])['files'][0], ['path' => 'wp-content/themes/child/template-parts/content.php', 'refused' => 'php_disabled']);
    check('a write without PHP lands', $tfCall($tfNoPhp, 'template.files.write', ['apply_id' => 'push-nophp', 'files' => [$tfFile('wp-content/themes/child/css/nophp.css', 'css', null)]])['changed'], 1);
    $tfPhpWrite = $tfWrite([$tfFile('wp-content/themes/child/template-parts/content.php', "<?php\necho 'v2';\n", sha1($tfPhp))], 'push-php');
    $tfPhpUndo = $tfCall($tfNoPhp, 'template.files.undo', ['apply_id' => 'push-php']);
    check('with PHP switched off an undo keeps a PHP file as it is, naming why', [$tfPhpUndo['kept'][0]['reason'], $tfPhpUndo['kept'][0]['detail'], $tfGet('wp-content/themes/child/template-parts/content.php')],
        ['out-of-scope', 'php_disabled', "<?php\necho 'v2';\n"]);
    check('switched on again, it goes back', [$tfCall($tfEngine, 'template.files.undo', ['apply_id' => 'push-php'])['complete'], $tfGet('wp-content/themes/child/template-parts/content.php')], [true, $tfPhp]);

    // ---- the owner's main switch ---------------------------------------------------------------------------------
    $tfOff = (new Engine($tfToken, [], null, null, null, null, $tfWriter, null, $tfLog))->templateFiles($tfMake()->enabled(false));
    check('switched off, info still names the door, as off', $tfCall($tfOff, 'info', [])['info']['template_files'], ['version' => 1, 'enabled' => false]);
    check('and every action answers disabled, even one that names no apply_id',
        [$tfCall($tfOff, 'template.files.status', [])['error'], $tfCall($tfOff, 'template.files.write', ['files' => [$tfFile('wp-content/themes/child/css/off.css', 'x', null)]])['error'],
            $tfCall($tfOff, 'template.files.undo', ['apply_id' => 'push-nophp'])['error'], $tfGet('wp-content/themes/child/css/off.css')], ['disabled', 'disabled', 'disabled', null]);
    $tfOffRevert = $tfCall($tfOff, 'apply.revert', ['apply_id' => 'push-nophp']);
    check('apply.revert of template files is refused too, and changes nothing', [$tfOffRevert['error'], $tfOffRevert['kept'][0]['reason'], $tfGet('wp-content/themes/child/css/nophp.css')], ['conflict', 'disabled', 'css']);

    // ---- leftovers of a request that died: swept after an hour, only by their exact name, never through a link ------
    $tfStale = static function (string $relative, int $age) use ($tfRoot): void {
        if (!is_dir(dirname($tfRoot . '/' . $relative))) mkdir(dirname($tfRoot . '/' . $relative), 0777, true);
        file_put_contents($tfRoot . '/' . $relative, 'left');
        touch($tfRoot . '/' . $relative, time() - $age);
    };
    $tfStale('wp-content/themes/child/css/.tracy-tf-0123456789abcdef.tmp', 7200);
    $tfStale('wp-content/themes/child/js/deep/.tracy-tf-fedcba9876543210.del', 7200);
    $tfStale('wp-content/themes/child/css/.tracy-tf-1111111111111111.tmp', 60);
    $tfStale('wp-content/themes/child/css/.tracy-tf-notours.tmp', 7200);
    $tfStale('wp-content/themes/child/css/x.tracy-tf-2222222222222222.tmp', 7200);
    $tfStale('wp-content/themes/child/.git/.tracy-tf-3333333333333333.tmp', 7200);
    file_put_contents($tfOutside . '/.tracy-tf-4444444444444444.tmp', 'outside'); touch($tfOutside . '/.tracy-tf-4444444444444444.tmp', time() - 7200);
    symlink($tfRoot . '/wp-config.php', $tfRoot . '/wp-content/themes/child/css/.tracy-tf-5555555555555555.del');
    $tfStale('wp-content/themes/child/../.tracy-tf-6666666666666666.tmp', 7200);
    $tfLeft = static fn(): array => array_map(static fn($p) => is_link($tfRoot . '/' . $p) || file_exists($tfRoot . '/' . $p), [
        'wp-content/themes/child/css/.tracy-tf-0123456789abcdef.tmp', 'wp-content/themes/child/js/deep/.tracy-tf-fedcba9876543210.del', 'wp-content/themes/child/css/.tracy-tf-1111111111111111.tmp',
        'wp-content/themes/child/css/.tracy-tf-notours.tmp', 'wp-content/themes/child/css/x.tracy-tf-2222222222222222.tmp', 'wp-content/themes/child/.git/.tracy-tf-3333333333333333.tmp',
        'wp-content/themes/child/css/.tracy-tf-5555555555555555.del', 'wp-content/themes/child/../.tracy-tf-6666666666666666.tmp']);
    $tfDry = $tfWrite([$tfFile('wp-content/themes/child/css/swept.css', 's', null)], 'push-sweep', ['dry_run' => true]);
    check('a dry run sweeps nothing', [$tfDry['ok'], $tfLeft()], [true, [true, true, true, true, true, true, true, true]]);
    $tfSwept = $tfCall($tfEngine, 'template.files.status', []);
    check('status sweeps the stale ones of this door\'s names, in the roots', $tfSwept['swept'], 2);
    check('and leaves a fresh one, other names, a dot-folder, a link, and anything outside the roots',
        [$tfLeft(), file_exists($tfOutside . '/.tracy-tf-4444444444444444.tmp'), is_file($tfRoot . '/wp-config.php')], [[false, false, true, true, true, true, true, true], true, true]);
    $tfStale('wp-content/themes/child/css/.tracy-tf-7777777777777777.tmp', 7200);
    $tfSweptWrite = $tfWrite([$tfFile('wp-content/themes/child/css/swept.css', 's', null)], 'push-sweep');
    check('a write sweeps too, before it writes', [$tfSweptWrite['swept'], $tfSweptWrite['changed'], file_exists($tfRoot . '/wp-content/themes/child/css/.tracy-tf-7777777777777777.tmp')], [1, 1, false]);
    unlink($tfRoot . '/wp-content/themes/child/../.tracy-tf-6666666666666666.tmp');

    // ---- clean up ------------------------------------------------------------------------------------------------
    $tfRm = static function (string $dir) use (&$tfRm): void {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            if (is_link($path) || !is_dir($path)) unlink($path); else $tfRm($path);
        }
        rmdir($dir);
    };
    $tfRm($tfRoot);
    $tfRm($tfOutside);
}
