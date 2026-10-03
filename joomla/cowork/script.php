<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * The package's own installer script: one receipt per install of the package, whoever started it.
 *
 * ## Why the receipt is written here
 *
 * Every way this package reaches a site ends in this `postflight`: the self-updater, the API door's
 * `extension.install`, Extensions → Update or Install in the administrator, the CLI, provisioning.
 * One place that every path passes through cannot be bypassed by a path nobody thought of; a receipt
 * written by each caller could, and the old ones (already on sites) write nothing.
 *
 * ## What it records, and how it knows
 *
 * `#__claudecowork_update_log` gets one row: when (UTC), from which version to which, the release tag,
 * the sha256 of the `tracy-release.json` the package just put on disk, and which path started it —
 * decided by {@see trigger()} from evidence the request carries, never from the time of day:
 *
 * 1. an install context a Tracy caller set around its own `Installer::install()` call
 *    (`$GLOBALS['claudecowork_install_context']`): the self-updater says `auto-updater`, the API door
 *    says `door` with the caller's `apply_id`;
 * 2. failing that, the call stack: the self-updater's class or the door engine's `extensionInstall`
 *    is on it. This is what names a self-updater that predates the context — the copy already on a
 *    site is the one that installs the first release carrying this file;
 * 3. failing that, a signed-in user: `admin`, with the user id;
 * 4. otherwise `unknown` (CLI, provisioning, anything else).
 *
 * The receipt proves nothing on its own — anyone with the database can write a row. What it adds is
 * the path; what the files are is proven by the manifest's hashes against the release asset on
 * GitHub, and a receipt counts only where its tag and manifest hash match those.
 *
 * Named class rather than `return new class`, like the component's script: Joomla 3 resolves the
 * script by this exact name. Nothing here loads the component's lib: in the same request the
 * installed (older) lib may already be loaded, and the new one could not be declared beside it.
 */
class pkg_claudecoworkInstallerScript
{
    /** The global a Tracy caller sets around `Installer::install()`. A literal in every caller, by design. */
    public const CONTEXT = 'claudecowork_install_context';

    public const TABLE = '#__claudecowork_update_log';

    /** Where the release manifest lands, relative to the site root. */
    public const MANIFEST = 'administrator/components/com_claudecowork/tracy-release.json';

    public const UPDATER_CLASS = 'Tracy\\Plugin\\System\\ClaudeCoworkUpdate\\Extension\\ClaudeCoworkUpdate';

    /** The version installed before this run, read in preflight while it is still the old one. */
    private ?string $fromVersion = null;

    public function preflight($route, $parent)
    {
        try {
            $this->fromVersion = $this->installedVersion(Factory::getDbo());
        } catch (\Throwable $e) {
            // Swallowed: a receipt without a from-version is still a receipt.
        }

        return true;
    }

    public function postflight($route, $parent)
    {
        try {
            $this->writeReceipt(Factory::getDbo(), $parent);
        } catch (\Throwable $e) {
            // Swallowed: a package that installed correctly must not report failure because its
            // receipt could not be written.
        }

        return true;
    }

    private function writeReceipt($db, $parent): void
    {
        $to = $this->manifestVersion($parent);
        if ($to === '') {
            return;
        }

        $manifest = (\defined('JPATH_ROOT') ? JPATH_ROOT : '') . '/' . self::MANIFEST;
        $sha = is_file($manifest) ? hash_file('sha256', $manifest) : null;

        $frames = array_map(
            static fn(array $f): string => ($f['class'] ?? '') . '::' . ($f['function'] ?? ''),
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        );
        $context = $GLOBALS[self::CONTEXT] ?? null;
        $who = self::trigger(\is_array($context) ? $context : null, $frames, $this->userId());

        self::ensureTable($db);
        $row = self::row(gmdate('Y-m-d H:i:s'), $this->fromVersion, $to, $sha, $who);
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName(self::TABLE) . ' ('
            . implode(', ', array_map([$db, 'quoteName'], array_keys($row)))
            . ') VALUES ('
            . implode(', ', array_map(static fn($v) => $v === null ? 'NULL' : (\is_int($v) ? (string) $v : $db->quote($v)), $row))
            . ')'
        )->execute();
    }

    /**
     * Which path started this install. Pure: the context a caller set, the call stack as
     * `Class::function` strings, and the signed-in user id (0 for none).
     *
     * @return array{trigger:string, user_id:int, apply_id:?string}
     */
    public static function trigger(?array $context, array $frames, int $userId): array
    {
        $said = \is_string($context['trigger'] ?? null) ? $context['trigger'] : '';
        if ($said === 'auto-updater') {
            return ['trigger' => 'auto-updater', 'user_id' => 0, 'apply_id' => null];
        }
        if ($said === 'door') {
            $apply = $context['apply_id'] ?? null;
            $apply = \is_string($apply) && $apply !== '' ? substr($apply, 0, 191) : null;
            return ['trigger' => 'door', 'user_id' => 0, 'apply_id' => $apply];
        }
        foreach ($frames as $frame) {
            if (strpos($frame, self::UPDATER_CLASS . '::') === 0) {
                return ['trigger' => 'auto-updater', 'user_id' => 0, 'apply_id' => null];
            }
        }
        foreach ($frames as $frame) {
            // The door engine is the global `Engine` of this package's lib.
            if ($frame === 'Engine::extensionInstall') {
                return ['trigger' => 'door', 'user_id' => 0, 'apply_id' => null];
            }
        }
        if ($userId > 0) {
            return ['trigger' => 'admin', 'user_id' => $userId, 'apply_id' => null];
        }

        return ['trigger' => 'unknown', 'user_id' => 0, 'apply_id' => null];
    }

    /** The row, column => value, in table order. Pure. */
    public static function row(string $at, ?string $from, string $to, ?string $manifestSha, array $who): array
    {
        return [
            'at'              => $at,
            'element'         => 'pkg_claudecowork',
            'from_version'    => $from !== null && $from !== '' ? substr($from, 0, 50) : null,
            'to_version'      => substr($to, 0, 50),
            'tag'             => 'joomla-v' . substr($to, 0, 50),
            'manifest_sha256' => $manifestSha !== null && preg_match('/^[a-f0-9]{64}$/D', $manifestSha) ? $manifestSha : null,
            'trigger'         => $who['trigger'],
            'user_id'         => (int) $who['user_id'],
            'apply_id'        => $who['apply_id'],
        ];
    }

    /**
     * `trigger` is a reserved word in MySQL and MariaDB: every statement that names the column must
     * quote it, as this one does.
     */
    public static function createTableSql(callable $q): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . $q(self::TABLE) . ' ('
            . $q('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . $q('at') . ' DATETIME NOT NULL, '
            . $q('element') . ' VARCHAR(100) NOT NULL, '
            . $q('from_version') . ' VARCHAR(50) NULL, '
            . $q('to_version') . ' VARCHAR(50) NOT NULL, '
            . $q('tag') . ' VARCHAR(100) NOT NULL, '
            . $q('manifest_sha256') . ' CHAR(64) NULL, '
            . $q('trigger') . ' VARCHAR(20) NOT NULL, '
            . $q('user_id') . ' INT NOT NULL DEFAULT 0, '
            . $q('apply_id') . ' VARCHAR(191) NULL, '
            . 'PRIMARY KEY (' . $q('id') . '), '
            . 'KEY ' . $q('idx_at') . ' (' . $q('at') . ')'
            . ') ENGINE=InnoDB';
    }

    private static function ensureTable($db): void
    {
        $db->setQuery(self::createTableSql([$db, 'quoteName']))->execute();
    }

    private function installedVersion($db): ?string
    {
        $cache = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('pkg_claudecowork'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('package'))
        )->loadResult();
        $manifest = json_decode((string) $cache, true);
        $version = \is_array($manifest) ? ($manifest['version'] ?? null) : null;

        return \is_string($version) && $version !== '' ? $version : null;
    }

    private function manifestVersion($parent): string
    {
        $manifest = null;
        if (\is_object($parent) && method_exists($parent, 'getManifest')) {
            $manifest = $parent->getManifest();
        } elseif (\is_object($parent) && method_exists($parent, 'get')) {
            $manifest = $parent->get('manifest');
        }

        return $manifest instanceof \SimpleXMLElement ? trim((string) $manifest->version) : '';
    }

    private function userId(): int
    {
        try {
            $app = Factory::getApplication();
            $user = method_exists($app, 'getIdentity') ? $app->getIdentity() : Factory::getUser();
            return $user !== null && empty($user->guest) ? (int) $user->id : 0;
        } catch (\Throwable $e) {
            // A console application has no identity to ask.
            return 0;
        }
    }
}
