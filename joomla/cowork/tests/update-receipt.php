<?php
// Loaded by run.php. The update receipt: the package's own installer script (script.php) names who
// installed it, from the install context a Tracy caller sets or, failing that, the call stack — and
// the two Tracy callers (the self-updater, the API door) set that context around their own install.
// The end-to-end run of the same thing on a real Joomla is tests/e2e/updater.sh.
echo "\nUpdate receipt\n";

if (!defined('_JEXEC')) define('_JEXEC', 1);
require_once __DIR__ . '/../script.php';

$R = 'pkg_claudecoworkInstallerScript';
$updaterFrame = 'Tracy\\Plugin\\System\\ClaudeCoworkUpdate\\Extension\\ClaudeCoworkUpdate::install';
$someFrames = ['Joomla\\CMS\\Installer\\Installer::install', 'Joomla\\CMS\\Installer\\Adapter\\PackageAdapter::install'];

// 1. The context a caller set wins over everything else.
check('receipt: the updater\'s context says auto-updater',
    $R::trigger(['trigger' => 'auto-updater'], $someFrames, 42),
    ['trigger' => 'auto-updater', 'user_id' => 0, 'apply_id' => null]);
check('receipt: the door\'s context says door, with its apply_id',
    $R::trigger(['trigger' => 'door', 'apply_id' => 'task-88'], $someFrames, 0),
    ['trigger' => 'door', 'user_id' => 0, 'apply_id' => 'task-88']);
check('receipt: a door context without an apply_id is still the door',
    $R::trigger(['trigger' => 'door', 'apply_id' => null], [], 0)['trigger'], 'door');
check('receipt: an apply_id longer than the column is cut, not refused',
    strlen((string) $R::trigger(['trigger' => 'door', 'apply_id' => str_repeat('a', 300)], [], 0)['apply_id']), 191);
// An admin who happens to be signed in while the updater runs (onAfterRespond of their own page
// view) did not install anything: the updater did.
check('receipt: a signed-in admin does not take the updater\'s install',
    $R::trigger(['trigger' => 'auto-updater'], [], 7)['trigger'], 'auto-updater');

// 2. No context: the call stack. The self-updater already on a site predates the context, and it is
// the one that installs the first release carrying this receipt.
check('receipt: an updater with no context is found on the call stack',
    $R::trigger(null, array_merge($someFrames, [$updaterFrame]), 7)['trigger'], 'auto-updater');
check('receipt: so is the door engine',
    $R::trigger(null, array_merge($someFrames, ['Engine::extensionInstall', 'Engine::handle']), 0),
    ['trigger' => 'door', 'user_id' => 0, 'apply_id' => null]);
check('receipt: a context naming something else falls through to the evidence',
    $R::trigger(['trigger' => 'cron'], [$updaterFrame], 0)['trigger'], 'auto-updater');
check('receipt: a class that merely starts with the updater\'s name is not it',
    $R::trigger(null, ['Tracy\\Plugin\\System\\ClaudeCoworkUpdate\\Extension\\ClaudeCoworkUpdateX::install'], 0)['trigger'], 'unknown');

// 3. No Tracy caller: a signed-in user is the administrator; anything else is unknown.
check('receipt: a signed-in user with no Tracy caller is the admin',
    $R::trigger(null, $someFrames, 7), ['trigger' => 'admin', 'user_id' => 7, 'apply_id' => null]);
check('receipt: no context, no Tracy frame, no user: unknown',
    $R::trigger(null, $someFrames, 0), ['trigger' => 'unknown', 'user_id' => 0, 'apply_id' => null]);

// The row.
$sha = str_repeat('ab', 32);
$row = $R::row('2026-10-02 08:00:00', '0.20.3', '0.21.0', $sha, $R::trigger(['trigger' => 'auto-updater'], [], 0));
check('receipt row: columns in table order', array_keys($row),
    ['at', 'element', 'from_version', 'to_version', 'tag', 'manifest_sha256', 'trigger', 'user_id', 'apply_id']);
check('receipt row: the tag is the release tag of the version installed', $row['tag'], 'joomla-v0.21.0');
check('receipt row: from and to', [$row['from_version'], $row['to_version']], ['0.20.3', '0.21.0']);
check('receipt row: the manifest hash', $row['manifest_sha256'], $sha);
check('receipt row: a fresh install has no from-version', $R::row('2026-10-02 08:00:00', null, '0.21.0', null, $R::trigger(null, [], 0))['from_version'], null);
check('receipt row: anything but a sha256 is no hash', $R::row('x', null, '1', 'not-a-hash', $R::trigger(null, [], 0))['manifest_sha256'], null);

$ddl = $R::createTableSql(fn(string $n) => '`' . $n . '`');
checkTrue('receipt table: created only if missing', strpos($ddl, 'CREATE TABLE IF NOT EXISTS `#__claudecowork_update_log`') === 0);
// `trigger` is reserved in MySQL and MariaDB; unquoted, the CREATE fails and no receipt is ever kept.
checkTrue('receipt table: the reserved column name is quoted', str_contains($ddl, '`trigger` VARCHAR(20) NOT NULL'));
foreach (array_keys($row) as $column) {
    checkTrue("receipt table: has a column for {$column}", str_contains($ddl, '`' . $column . '`'));
}

// The two callers set the context under the name the script reads. Literals on both sides, by
// design (each side may be a different release), so the names are held equal here.
$updaterSrc = file_get_contents(__DIR__ . '/../plg_system_claudecoworkupdate/src/Extension/ClaudeCoworkUpdate.php');
checkTrue('receipt: the updater sets the context the script reads',
    str_contains($updaterSrc, "private const CONTEXT = '" . $R::CONTEXT . "';")
    && str_contains($updaterSrc, "\$GLOBALS[self::CONTEXT] = ['trigger' => 'auto-updater'"));
checkTrue('receipt: and restores it in a finally', (bool) preg_match('/finally \{\s*\$GLOBALS\[self::CONTEXT\] = \$previous;/', $updaterSrc));
check('receipt: the door engine uses the same name', Engine::INSTALL_CONTEXT, $R::CONTEXT);
checkTrue('receipt: the updater class the script looks for is the updater',
    str_contains($updaterSrc, 'namespace Tracy\\Plugin\\System\\ClaudeCoworkUpdate\\Extension;')
    && str_contains($updaterSrc, 'final class ClaudeCoworkUpdate ')
    && $R::UPDATER_CLASS === 'Tracy\\Plugin\\System\\ClaudeCoworkUpdate\\Extension\\ClaudeCoworkUpdate');
checkTrue('receipt: the updater logs somewhere', str_contains($updaterSrc, "Log::addLogger(['text_file' => 'plg_system_claudecoworkupdate.php']"));

// The door: extension.install runs the install inside a `door` context carrying the caller's
// apply_id, and leaves no context behind.
final class ContextRecordingExtensions implements ExtensionManager
{
    public $seen = 'not called';
    public function installFromUrl(string $url): array
    {
        $this->seen = $GLOBALS['claudecowork_install_context'] ?? null;
        return ['ok' => true, 'name' => 'Joomla Claude Cowork', 'type' => 'package', 'version' => '0.21.0'];
    }
    public function listInstalled(): array { return []; }
    public function coreManifest(): array { return []; }
    public function setEnabled(string $type, string $element, ?string $folder, bool $enabled): array { return ['ok' => false]; }
}
$ctxExt = new ContextRecordingExtensions();
$ctxEngine = new Engine('a-token-at-least-16', [], null, null, null, $ctxExt);
unset($GLOBALS['claudecowork_install_context']);
$ctxEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'extension.install',
    'params' => ['url' => 'https://github.com/TracyHQ/claude-cowork/releases/download/joomla-v0.21.0/pkg_claudecowork-0.21.0.zip', 'apply_id' => 'task-88']]);
check('receipt: the door installs inside a door context with the apply_id', $ctxExt->seen, ['trigger' => 'door', 'apply_id' => 'task-88']);
check('receipt: and leaves none behind', $GLOBALS['claudecowork_install_context'] ?? null, null);
$ctxEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'extension.install',
    'params' => ['url' => 'https://github.com/TracyHQ/claude-cowork/releases/download/joomla-v0.21.0/pkg_claudecowork-0.21.0.zip']]);
check('receipt: no apply_id is a door context without one', $ctxExt->seen, ['trigger' => 'door', 'apply_id' => null]);

// The package carries the script, and the component carries the manifest the receipt hashes.
$pkgXml = simplexml_load_file(__DIR__ . '/../pkg_claudecowork.xml');
check('receipt: the package names its installer script', trim((string) $pkgXml->scriptfile), 'script.php');
checkTrue('receipt: build.sh puts it in the package', str_contains(file_get_contents(__DIR__ . '/../build.sh'), 'cp script.php build/'));
$comXml = file_get_contents(__DIR__ . '/../com_claudecowork/claudecowork.xml');
checkTrue('release manifest: the component installs it', str_contains($comXml, '<filename>tracy-release.json</filename>'));
check('release manifest: where the receipt looks for it', $R::MANIFEST, 'administrator/components/com_claudecowork/tracy-release.json');
