<?php
// Loaded by run.php: `site.identity` reads and writes Global Configuration's site name (`sitename`),
// site description (`MetaDesc`) and whether page titles carry the site name (`sitename_pagetitles`)
// — and no other key of configuration.php, which also holds the
// database password, the site secret and the mail credentials (TCH ledger L24).
//
// What is real here: ConfigurationFile writing a real file on disk, read back by a fresh PHP that
// includes it the way Joomla does, so "every other key untouched" is checked on what PHP would load,
// with its types. What stands in: `JConfig` (a fixture array) and Joomla's Registry PHP format
// (`siRegistryPhp`, the same text joomla/registry's `Format\Php` makes for scalar values).
//
// The permission cases run in a child PHP (this file, `--site-identity-case`). A root process can
// write any file whatever its mode, so a child started as root drops to `nobody` first; the case of a
// file owned by somebody else needs root to set up and runs only then.

// Already loaded under run.php; the child needs it before SiChildLog below can name ApplyLog.
require_once __DIR__ . '/../lib/Engine.php';

/** The text joomla/registry's PHP format makes of a configuration (`toString('PHP', ['class' => 'JConfig', 'closingtag' => false])`). */
function siRegistryPhp(array $config): string
{
    $value = static function ($v) use (&$value): string {
        if (is_string($v)) return "'" . addcslashes($v, '\\\'') . "'";
        if (is_bool($v)) return $v ? 'true' : 'false';
        if ($v === null) return 'null';
        if (is_int($v) || is_float($v)) return (string) $v;
        $parts = [];
        foreach ((array) $v as $k => $item) $parts[] = '"' . $k . '" => ' . $value($item);
        return 'array(' . implode(', ', $parts) . ')';
    };
    $vars = '';
    foreach ($config as $k => $v) $vars .= "\tpublic \$$k = " . $value($v) . ";\n";
    return "<?php\nclass JConfig {\n" . $vars . '}';
}

/** A configuration as a Joomla 5 site holds it: secrets, paths, and the template's own identity. */
function siFixture(): array
{
    return [
        'offline' => false, 'offline_message' => 'This site is down for maintenance.<br>Please check back again soon.',
        'display_offline_message' => 1, 'offline_image' => '', 'sitename' => 'ja_vega', 'editor' => 'tinymce', 'captcha' => '0',
        'list_limit' => 20, 'access' => 1, 'debug' => false, 'dbtype' => 'mysqli', 'host' => 'db', 'user' => 'joomla',
        'password' => "DB-SECRET-it's\\here", 'db' => 'joomla', 'dbprefix' => 'jos_', 'secret' => 'SITE-SECRET-0123456789',
        'gzip' => false, 'error_reporting' => 'default', 'live_site' => '',
        'MetaDesc' => 'Discover JA Vega, the ultimate Joomla template for modern business sites.',
        'MetaRights' => '', 'robots' => '', 'sef' => true, 'sitename_pagetitles' => 1,
        'tmp_path' => '/var/www/html/tmp', 'log_path' => '/var/www/html/administrator/logs',
        'mailfrom' => 'admin@example.test', 'fromname' => 'ja_vega', 'smtppass' => 'SMTP-SECRET-9876', 'lifetime' => 15,
        'session_handler' => 'database', 'caching' => 0, 'cors_allow_origin' => '*',
    ];
}

/** The secrets of the fixture: none of them may appear in any answer of the door. */
function siSecrets(): array
{
    return ['DB-SECRET', 'SITE-SECRET-0123456789', 'SMTP-SECRET-9876', '/var/www/html', 'jos_'];
}

/** What PHP loads from a configuration file: a fresh PHP includes it and reads `new JConfig()`. */
function siLoad(string $path): ?array
{
    $code = 'include $argv[1]; echo json_encode(get_object_vars(new JConfig()));';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($code) . ' ' . escapeshellarg($path) . ' 2>&1');
    $decoded = is_string($out) ? json_decode($out, true) : null;
    return is_array($decoded) ? $decoded : null;
}

/** A configuration.php written from the fixture, as the store sees it, and the file itself. */
function siSite(string $dir, string $name, array $config, int $mode = 0644, ?callable $owns = null): array
{
    $path = $dir . '/' . $name;
    file_put_contents($path, siRegistryPhp($config));
    chmod($path, $mode);
    return [$path, new ConfigurationFile($path, static fn(): array => $config, 'siRegistryPhp', $owns)];
}

/** A log for the child, which has none of run.php's doubles. */
final class SiChildLog implements ApplyLog
{
    public array $log = [];
    public function record(string $applyId, array $entry): void { $this->log[$applyId][] = $entry; }
    public function recordMany(string $applyId, array $entries): void { foreach ($entries as $entry) $this->record($applyId, $entry); }
    public function entries(string $applyId): array { return $this->log[$applyId] ?? []; }
    public function clear(string $applyId): void { unset($this->log[$applyId]); }
}

// ---------------------------------------------------------------- the child -----------------------
if (isset($argv) && ($argv[1] ?? null) === '--site-identity-case' && isset($argv[2])
    && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $case = json_decode($argv[2], true);
    $report = ['uid' => function_exists('posix_geteuid') ? posix_geteuid() : null];
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        posix_setgid(65534);
        posix_setuid(65534);
        $report['uid'] = posix_geteuid();
    }
    $path = $case['path'];
    $config = siFixture();
    $owns = $case['owns'] === null ? null : static fn(): bool => (bool) $case['owns'];
    $store = new ConfigurationFile($path, static fn(): array => $config, 'siRegistryPhp', $owns);
    $log = new SiChildLog();
    $engine = (new Engine('child-token-at-least-16', [], null, null, null, null, null, null, $log))->siteIdentity($store);
    $bytes = (string) file_get_contents($path);
    $report['read'] = $engine->handle(['token' => 'child-token-at-least-16', 'action' => 'site.identity', 'params' => []]);
    $report['set'] = $engine->handle(['token' => 'child-token-at-least-16', 'action' => 'site.identity',
        'params' => ['operation' => 'set', 'apply_id' => 'si-child', 'fields' => ['sitename' => 'Hanoi Roofing', 'MetaDesc' => 'Roofs repaired in Hanoi.']]]);
    clearstatcache();
    $report['mode'] = fileperms($path) & 07777;
    $report['unchanged'] = file_get_contents($path) === $bytes;
    $report['logged'] = count($log->entries('si-child'));
    echo json_encode($report);
    exit(0);
}

// ---------------------------------------------------------------- run by run.php ------------------
if (function_exists('check')) {
    $siToken = 'identity-token-at-least-16';
    $siDir = sys_get_temp_dir() . '/cowork-site-identity-' . bin2hex(random_bytes(6));
    mkdir($siDir, 0777, true);
    chmod($siDir, 0777);
    $siCall = static fn(Engine $engine, array $params) => $engine->handle(['token' => $siToken, 'action' => 'site.identity', 'params' => $params]);
    $siLeaks = static function ($answer): bool {
        $text = json_encode($answer);
        foreach (siSecrets() as $secret) if (strpos((string) $text, str_replace('/', '\/', $secret)) !== false || strpos((string) $text, $secret) !== false) return true;
        return false;
    };

    // ---- what a value may be -----------------------------------------------------------------
    check('a value must be a string', [SiteIdentity::clean('sitename', 5), SiteIdentity::clean('MetaDesc', null), SiteIdentity::clean('MetaDesc', ['x'])],
        [['error' => 'sitename must be a string'], ['error' => 'MetaDesc must be a string'], ['error' => 'MetaDesc must be a string']]);
    check('tags are removed, as Joomla\'s own form removes them', SiteIdentity::clean('sitename', '<b>Hanoi</b> Roofing &amp; Sons'), ['value' => 'Hanoi Roofing & Sons']);
    checkTrue('an encoded tag is removed too, not decoded into one', strpos(SiteIdentity::clean('MetaDesc', '&lt;script&gt;x&lt;/script&gt;Roofs')['value'], '<') === false);
    check('one line: breaks and tabs are spaces, other control characters go', SiteIdentity::clean('MetaDesc', "  Roofs\r\nand\tgutters\x00 repaired.  "), ['value' => 'Roofs and gutters repaired.']);
    check('MetaDesc holds Joomla\'s 300 characters, counted as characters', SiteIdentity::clean('MetaDesc', str_repeat('ế', 300)), ['value' => str_repeat('ế', 300)]);
    check('and not one more', SiteIdentity::clean('MetaDesc', str_repeat('a', 301)), ['error' => 'MetaDesc is longer than 300 characters (301 after cleaning)']);
    check('a sitename past 200 characters is refused', isset(SiteIdentity::clean('sitename', str_repeat('a', 201))['error']), true);
    check('a huge value is refused before it is parsed', SiteIdentity::clean('MetaDesc', str_repeat('a', 4097)), ['error' => 'MetaDesc is longer than 4096 bytes']);
    check('bytes that are not UTF-8 are refused', SiteIdentity::clean('sitename', "Caf\xE9"), ['error' => 'sitename is not valid UTF-8']);
    check('an empty sitename is refused, as Joomla\'s form refuses it', SiteIdentity::clean('sitename', ' <b></b> '), ['error' => 'sitename cannot be empty: Joomla requires a site name']);
    check('an empty MetaDesc is allowed: no site description', SiteIdentity::clean('MetaDesc', ''), ['value' => '']);
    check('no other key is a site identity field', SiteIdentity::clean('password', 'x'), ['error' => 'password is not a site identity field']);
    check('sitename_pagetitles takes 0, 1 or 2, as Joomla\'s form offers them, and stores an integer',
        [SiteIdentity::clean('sitename_pagetitles', 0), SiteIdentity::clean('sitename_pagetitles', 2), SiteIdentity::clean('sitename_pagetitles', '1')],
        [['value' => 0], ['value' => 2], ['value' => 1]]);
    $siFlagError = ['error' => 'sitename_pagetitles must be 0 (no site name in page titles), 1 (before) or 2 (after)'];
    check('any other value of sitename_pagetitles is refused',
        [SiteIdentity::clean('sitename_pagetitles', 3), SiteIdentity::clean('sitename_pagetitles', '2 '), SiteIdentity::clean('sitename_pagetitles', true), SiteIdentity::clean('sitename_pagetitles', 2.0), SiteIdentity::clean('sitename_pagetitles', null)],
        [$siFlagError, $siFlagError, $siFlagError, $siFlagError, $siFlagError]);

    // ---- read: exactly two keys ----------------------------------------------------------------
    $siConfig = siFixture();
    [$siPath, $siStore] = siSite($siDir, 'configuration.php', $siConfig, 0640);
    $siLog = new FakeApplyLog();
    $siWriter = new class extends FakeSiteWriter {
        public array $serialized = [];
        public function serialize(callable $work, array $holder = []) { $this->serialized[] = $holder; return $work(); }
    };
    $siEngine = (new Engine($siToken, [], null, null, null, null, $siWriter, null, $siLog))->siteIdentity($siStore);

    $siRead = $siCall($siEngine, []);
    check('read answers the three values and whether a set would land', $siRead,
        ['ok' => true, 'fields' => ['sitename' => 'ja_vega', 'MetaDesc' => $siConfig['MetaDesc'], 'sitename_pagetitles' => 1], 'writable' => true]);
    check('operation read is the default', $siCall($siEngine, ['operation' => 'read']), $siRead);
    check('no secret is in a read', $siLeaks($siRead), false);
    check('asking to read another key is refused, not answered', [$siCall($siEngine, ['fields' => ['password']])['error'], $siCall($siEngine, ['fields' => ['sitename', 'secret']])['error']], ['unsupported', 'unsupported']);
    check('asking for the identity keys is a read', $siCall($siEngine, ['fields' => ['sitename', 'MetaDesc', 'sitename_pagetitles']])['fields'], $siRead['fields']);
    check('a read takes no write lock', $siWriter->serialized, []);

    // ---- set: refusals -------------------------------------------------------------------------
    $siBytes = (string) file_get_contents($siPath);
    $siSet = static fn(array $fields, string $apply = 'apply-identity') => $siCall($siEngine, ['operation' => 'set', 'apply_id' => $apply, 'fields' => $fields]);
    check('an unknown operation is refused by name', $siCall($siEngine, ['operation' => 'write']), ['ok' => false, 'error' => 'bad_params', 'message' => 'Unknown site.identity operation: use read or set']);
    check('a set needs an apply_id', $siCall($siEngine, ['operation' => 'set', 'fields' => ['sitename' => 'X']])['error'], 'bad_params');
    check('a set needs fields as an object', [$siCall($siEngine, ['operation' => 'set', 'apply_id' => 'a'])['error'], $siSet([])['error'], $siSet(['Hanoi Roofing'])['error']],
        ['bad_params', 'bad_params', 'bad_params']);
    $siOther = $siSet(['sitename' => 'Hanoi Roofing', 'password' => 'mine']);
    check('any other key is refused whole, naming the boundary', [$siOther['error'], $siOther['message']],
        ['unsupported', 'password cannot be written through site.identity: only sitename, MetaDesc and sitename_pagetitles can. Nothing was written']);
    check('a page-title setting Joomla does not offer is refused', $siSet(['sitename' => 'Hanoi Roofing', 'sitename_pagetitles' => 5])['error'], 'bad_params');
    check('a value that is not a string is refused', $siSet(['sitename' => ['Hanoi']])['error'], 'bad_params');
    check('a description past Joomla\'s limit is refused', $siSet(['MetaDesc' => str_repeat('a', 301)])['error'], 'bad_params');
    check('a refused set wrote nothing and recorded nothing', [file_get_contents($siPath) === $siBytes, $siLog->entries('apply-identity')], [true, []]);

    // ---- set: the round trip -------------------------------------------------------------------
    $siWriter->serialized = [];
    $siDone = $siSet(['sitename' => 'Hanoi <i>Roofing</i>', 'MetaDesc' => "Roof repairs in Hanoi,\nsince 1998."]);
    check('a set writes both and answers them as stored', $siDone,
        ['ok' => true, 'fields' => ['sitename' => 'Hanoi Roofing', 'MetaDesc' => 'Roof repairs in Hanoi, since 1998.', 'sitename_pagetitles' => 1], 'changed' => ['sitename', 'MetaDesc']]);
    check('a set takes the write lock, naming itself', $siWriter->serialized, [['action' => 'site.identity', 'operation' => 'set', 'applyId' => 'apply-identity']]);
    $siLoaded = siLoad($siPath);
    check('the file PHP loads holds the new values', [$siLoaded['sitename'] ?? null, $siLoaded['MetaDesc'] ?? null], ['Hanoi Roofing', 'Roof repairs in Hanoi, since 1998.']);
    $siExpected = array_merge($siConfig, ['sitename' => 'Hanoi Roofing', 'MetaDesc' => 'Roof repairs in Hanoi, since 1998.']);
    check('every other key is untouched: same value, same type, same order', $siLoaded, $siExpected);
    clearstatcache();
    check('the file keeps its mode', fileperms($siPath) & 07777, 0640);
    check('a read afterwards sees the new values', $siCall($siEngine, [])['fields'], ['sitename' => 'Hanoi Roofing', 'MetaDesc' => 'Roof repairs in Hanoi, since 1998.', 'sitename_pagetitles' => 1]);
    check('one undo step, holding only what was there', $siLog->entries('apply-identity'), [['op' => 'siteIdentity', 'before' => ['sitename' => 'ja_vega', 'MetaDesc' => $siConfig['MetaDesc']]]]);
    check('the cache is purged: a cached page still has the old <title>', $siWriter->purges, 1);
    check('no secret is in a set\'s answer', $siLeaks($siDone), false);

    $siAgain = $siSet(['sitename' => 'Hanoi Roofing']);
    check('the same value again writes and records nothing', [$siAgain['unchanged'] ?? null, $siAgain['changed'], count($siLog->entries('apply-identity'))], [true, [], 1]);
    check('apply.list names the step and its fields, never the words', $siEngine->handle(['token' => $siToken, 'action' => 'apply.list', 'params' => ['apply_id' => 'apply-identity']])['steps'],
        [['op' => 'siteIdentity', 'created' => false, 'fields' => ['sitename', 'MetaDesc']]]);

    $siBack = $siEngine->handle(['token' => $siToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-identity']]);
    check('apply.revert takes it back', $siBack, ['ok' => true, 'reverted' => 1]);
    check('the file is the site\'s own again, every key as it was', siLoad($siPath), $siConfig);
    check('and the undo log is cleared', $siLog->entries('apply-identity'), []);

    // One field alone, then the other: each undo step holds only its own field.
    $siCall($siEngine, ['operation' => 'set', 'apply_id' => 'apply-desc', 'fields' => ['MetaDesc' => 'Roofs.']]);
    check('a set of one field changes only that field', array_intersect_key(siLoad($siPath), ['sitename' => 1, 'MetaDesc' => 1]), ['sitename' => 'ja_vega', 'MetaDesc' => 'Roofs.']);
    check('and records only that field', $siLog->entries('apply-desc'), [['op' => 'siteIdentity', 'before' => ['MetaDesc' => $siConfig['MetaDesc']]]]);
    $siEngine->handle(['token' => $siToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-desc']]);
    check('its revert restores it', siLoad($siPath), $siConfig);

    // The page-title setting: an integer in the file, as Joomla's own form writes it, and back.
    $siTitles = $siCall($siEngine, ['operation' => 'set', 'apply_id' => 'apply-titles', 'fields' => ['sitename' => 'Hanoi Roofing', 'sitename_pagetitles' => '2']]);
    check('a set turns on "Page - Site" with the name', [$siTitles['changed'], $siTitles['fields']['sitename_pagetitles']], [['sitename', 'sitename_pagetitles'], 2]);
    $siTitled = siLoad($siPath);
    check('the file PHP loads holds it as an integer, and Tracy\'s mark beside it, every other key untouched', $siTitled,
        array_merge($siConfig, ['sitename' => 'Hanoi Roofing', 'sitename_pagetitles' => 2, HomeTitle::MARK => 2]));
    check('the undo step holds the integer it replaced, and that there was no mark', $siLog->entries('apply-titles'),
        [['op' => 'siteIdentity', 'before' => ['sitename' => 'ja_vega', 'sitename_pagetitles' => 1, HomeTitle::MARK => null]]]);
    check('the mark never leaves the door: not in the answer, not in a read, not in apply.list',
        [array_keys($siTitles['fields']), array_keys($siCall($siEngine, [])['fields']),
            $siEngine->handle(['token' => $siToken, 'action' => 'apply.list', 'params' => ['apply_id' => 'apply-titles']])['steps'][0]['fields']],
        [SiteIdentity::FIELDS, SiteIdentity::FIELDS, ['sitename', 'sitename_pagetitles']]);
    check('a caller cannot write the mark', $siSet([HomeTitle::MARK => 2], 'apply-mark')['error'], 'unsupported');
    check('the same setting again writes nothing', $siCall($siEngine, ['operation' => 'set', 'apply_id' => 'apply-titles', 'fields' => ['sitename_pagetitles' => 2]])['unchanged'] ?? null, true);
    $siEngine->handle(['token' => $siToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-titles']]);
    check('its revert puts the integer back, same type', siLoad($siPath), $siConfig);
    $siLog->record('apply-forged-titles', ['op' => 'siteIdentity', 'before' => ['sitename_pagetitles' => '9']]);
    $siEngine->handle(['token' => $siToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-forged-titles']]);
    check('an undo row holding a value Joomla does not offer writes Joomla\'s default, 0', siLoad($siPath)['sitename_pagetitles'] ?? null, 0);
    $siCall($siEngine, ['operation' => 'set', 'apply_id' => 'apply-tidy-titles', 'fields' => ['sitename_pagetitles' => 1]]);
    $siBare = siFixture();
    unset($siBare['sitename_pagetitles']);
    [, $siBareStore] = siSite($siDir, 'configuration-bare.php', $siBare);
    check('a configuration without the key reads as Joomla\'s default, 0', $siBareStore->read()['sitename_pagetitles'], 0);
    check('every set of the switch leaves the mark of what Tracy set', siLoad($siPath)[HomeTitle::MARK] ?? null, 1);
    $siLog->record('apply-unmark', ['op' => 'siteIdentity', 'before' => [HomeTitle::MARK => null]]);
    $siEngine->handle(['token' => $siToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-unmark']]);
    check('an undo row holding no mark takes the key out of the file', siLoad($siPath), $siConfig);

    // A template that already ships "after": Tracy's set changes nothing Joomla reads, but marks the switch as Tracy's.
    [$siAfterPath, $siAfterStore] = siSite($siDir, 'configuration-after.php', array_merge($siConfig, ['sitename_pagetitles' => 2]));
    $siAfterEngine = (new Engine($siToken, [], null, null, null, null, null, null, $siLog))->siteIdentity($siAfterStore);
    $siAfter = $siCall($siAfterEngine, ['operation' => 'set', 'apply_id' => 'apply-after', 'fields' => ['sitename_pagetitles' => 2]]);
    check('a switch already on is marked, the answer naming no change', [$siAfter['changed'], siLoad($siAfterPath)[HomeTitle::MARK] ?? null], [[], 2]);
    $siAfterEngine->handle(['token' => $siToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-after']]);
    check('and its revert takes only the mark away', siLoad($siAfterPath), array_merge($siConfig, ['sitename_pagetitles' => 2]));

    // The same apply_id as the content writes of an Apply: one revert takes back both.
    $siWriter->store['article'][7] = ['title' => 'Template article'];
    $siEngine->handle(['token' => $siToken, 'action' => 'content.update', 'params' => ['apply_id' => 'apply-both', 'kind' => 'article', 'id' => 7, 'fields' => ['title' => 'Our roofs']]]);
    $siCall($siEngine, ['operation' => 'set', 'apply_id' => 'apply-both', 'fields' => ['sitename' => 'Hanoi Roofing']]);
    $siBoth = $siEngine->handle(['token' => $siToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-both']]);
    check('one apply_id holding a content write and the identity reverts both', [$siBoth['reverted'], $siWriter->store['article'][7]['title'], siLoad($siPath)['sitename'] ?? null],
        [2, 'Template article', 'ja_vega']);

    // A log row is data: an undo entry carrying another key writes the identity fields only.
    $siLog->record('apply-forged', ['op' => 'siteIdentity', 'before' => ['sitename' => 'Restored', 'password' => 'forged', 'secret' => 'forged']]);
    $siEngine->handle(['token' => $siToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-forged']]);
    $siForged = siLoad($siPath);
    check('an undo row never writes a key outside the two', [$siForged['sitename'] ?? null, $siForged['password'] ?? null, $siForged['secret'] ?? null],
        ['Restored', $siConfig['password'], $siConfig['secret']]);
    $siCall($siEngine, ['operation' => 'set', 'apply_id' => 'apply-tidy', 'fields' => ['sitename' => 'ja_vega']]);
    check('the file is the fixture again', siLoad($siPath), $siConfig);

    // ---- failures that must leave the file as it was --------------------------------------------
    [$siPath2, $siStore2] = siSite($siDir, 'configuration-2.php', $siConfig);
    $siBytes2 = (string) file_get_contents($siPath2);
    $siNoLog = (new Engine($siToken, [], null, null, null, null, null, null, new FailingApplyLog()))->siteIdentity($siStore2);
    $siLost = $siCall($siNoLog, ['operation' => 'set', 'apply_id' => 'a', 'fields' => ['sitename' => 'Hanoi Roofing']]);
    check('a change whose undo cannot be recorded is rolled back', [$siLost['error'], $siLost['message'], siLoad($siPath2)], ['write_failed', 'change was rolled back: could not record its undo', $siConfig]);

    $siBroken = new ConfigurationFile($siPath2, static fn(): array => $siConfig, static fn(array $c): string => "<?php\nclass JConfig {\n\tpublic \$sitename = 'x\n}");
    $siBrokenEngine = (new Engine($siToken, [], null, null, null, null, null, null, new FakeApplyLog()))->siteIdentity($siBroken);
    $siNoParse = $siCall($siBrokenEngine, ['operation' => 'set', 'apply_id' => 'a', 'fields' => ['sitename' => 'Hanoi Roofing']]);
    check('text that does not parse as PHP never reaches the disk', [$siNoParse['error'], file_get_contents($siPath2) === $siBytes2], ['write_failed', true]);
    check('and the refusal does not quote the configuration', $siLeaks($siNoParse), false);

    $siMissing = (new Engine($siToken, [], null, null, null, null, null, null, new FakeApplyLog()))->siteIdentity(new ConfigurationFile($siDir . '/none.php', static fn(): array => $siConfig, 'siRegistryPhp'));
    $siGone = $siCall($siMissing, ['operation' => 'set', 'apply_id' => 'a', 'fields' => ['sitename' => 'Hanoi Roofing']]);
    check('a missing configuration.php is a refusal', [$siGone['error'], $siGone['code'], $siCall($siMissing, [])['writable']], ['unsupported', 'CONFIG_NOT_WRITABLE', false]);

    $siUnwired = new Engine($siToken, [], null, null, null, null, null, null, new FakeApplyLog());
    check('a receiver without the store answers unavailable, not unknown action', $siCall($siUnwired, [])['error'], 'unavailable');
    check('a receiver without the action answers this exact refusal', (new Engine($siToken))->handle(['token' => $siToken, 'action' => 'site.nope'])['message'], 'unknown action: site.nope');

    // ---- permissions, in a child that is not root ----------------------------------------------
    $siRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    $siChild = static function (string $path, ?bool $owns): array {
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --site-identity-case ' . escapeshellarg(json_encode(['path' => $path, 'owns' => $owns])) . ' 2>&1');
        $decoded = is_string($out) ? json_decode($out, true) : null;
        return is_array($decoded) ? $decoded : ['broken' => $out];
    };
    $siPrepare = static function (string $name, int $mode, bool $toNobody) use ($siDir, $siRoot): string {
        $path = $siDir . '/' . $name;
        file_put_contents($path, siRegistryPhp(siFixture()));
        if ($siRoot && $toNobody) chown($path, 65534);
        chmod($path, $mode);
        return $path;
    };

    // Joomla's installer and its own Global Configuration save leave the file 0444; the web server owns it.
    $siOwned = $siChild($siPrepare('owned-0444.php', 0444, true), null);
    check('child: runs as a process that file permissions bind', [$siOwned['broken'] ?? null, ($siOwned['uid'] ?? 0) !== 0], [null, true]);
    check('a 0444 file the web server owns is written, as Joomla\'s save writes it', [$siOwned['read']['writable'] ?? null, $siOwned['set']['ok'] ?? null, $siOwned['set']['changed'] ?? null],
        [true, true, ['sitename', 'MetaDesc']]);
    check('and is left 0444, not opened up', $siOwned['mode'] ?? null, 0444);
    check('what PHP loads from it is the new identity over the same configuration', siLoad($siDir . '/owned-0444.php'),
        array_merge(siFixture(), ['sitename' => 'Hanoi Roofing', 'MetaDesc' => 'Roofs repaired in Hanoi.']));

    // A file the web server can neither write nor chmod: a clear refusal, and nothing written.
    $siRefused = $siChild($siPrepare('not-owned-0444.php', 0444, true), false);
    check('a file it cannot write and does not own reads as not writable', $siRefused['read']['writable'] ?? null, false);
    check('a set on it is refused, saying why', [$siRefused['set']['error'] ?? null, $siRefused['set']['code'] ?? null, strpos((string) ($siRefused['set']['message'] ?? ''), 'not writable') !== false],
        ['unsupported', 'CONFIG_NOT_WRITABLE', true]);
    check('nothing was written, its mode is the same, no undo step was recorded', [$siRefused['unchanged'] ?? null, $siRefused['mode'] ?? null, $siRefused['logged'] ?? null], [true, 0444, 0]);

    // Root can set up a file that really belongs to someone else: no seam, the OS says no.
    if ($siRoot) {
        $siForeign = $siChild($siPrepare('root-0644.php', 0644, false), null);
        check('a file owned by another user is refused by the OS itself', [$siForeign['set']['code'] ?? null, $siForeign['unchanged'] ?? null, $siForeign['mode'] ?? null],
            ['CONFIG_NOT_WRITABLE', true, 0644]);
    }

    foreach (glob($siDir . '/*') ?: [] as $file) { @chmod($file, 0644); @unlink($file); }
    @rmdir($siDir);
}
