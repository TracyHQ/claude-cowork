<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\Database\DatabaseInterface;

/**
 * The Joomla half of {@see \ExtensionManager} — everything the engine deliberately does not know.
 *
 * Install-from-URL rather than upload, and Joomla's own `Installer` rather than anything
 * hand-rolled: a package is a manifest, a set of SQL updates and a post-install script, and a
 * component that unzipped one itself would be a second, worse installer running on someone's
 * production site.
 *
 * The download is bounded and the temporary file removed whatever happens. A package that
 * refuses to install is reported with the installer's own words, because "install failed" with
 * no reason is the message this exists to avoid.
 */
final class JoomlaExtensions implements \ExtensionManager
{
    /** A package this large on a shared host is a mistake somewhere, not a template. */
    private const MAX_PACKAGE_BYTES = 104857600; // 100 MiB

    public function installFromUrl(string $url): array
    {
        $file = InstallerHelper::downloadPackage($url);
        if ($file === false) {
            return ['ok' => false, 'error' => 'could not download the package'];
        }

        $path = Factory::getApplication()->get('tmp_path') . '/' . basename((string) $file);
        if (is_file($path) && filesize($path) > self::MAX_PACKAGE_BYTES) {
            @unlink($path);
            return ['ok' => false, 'error' => 'package is larger than this site allows'];
        }

        $package = InstallerHelper::unpack($path, true);
        if ($package === false || empty($package['dir'])) {
            InstallerHelper::cleanupInstall($path, '');
            return ['ok' => false, 'error' => 'package could not be unpacked'];
        }

        try {
            // Joomla 4 gave `new Installer()` a database off the global container; Joomla 6 no
            // longer does, and install() then dies with "Database not set in ...Installer". Set it
            // explicitly so this works across generations. `Installer::getInstance()` would do the
            // same on 4, but the shared instance it hands back is not database-aware on 6 either.
            $installer = new Installer();
            $installer->setDatabase(Factory::getContainer()->get(DatabaseInterface::class));
            $installed = $installer->install($package['dir']);
            if (!$installed) {
                $message = (string) $installer->getError();
                return ['ok' => false, 'error' => $message === '' ? 'the installer refused the package' : $message];
            }

            $manifest = $installer->getManifest();
            return [
                'ok'      => true,
                'name'    => $manifest !== null ? (string) $manifest->name : ($package['packagefile'] ?? ''),
                'type'    => (string) ($package['type'] ?? ''),
                'version' => $manifest !== null ? (string) $manifest->version : null,
            ];
        } finally {
            // Left behind, these accumulate in tmp_path on every run — on a shared host that is
            // someone's disk quota.
            InstallerHelper::cleanupInstall($path, $package['dir']);
        }
    }

    public function listInstalled(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(
                ['name', 'type', 'element', 'folder', 'package_id', 'manifest_cache', 'enabled']
            ))
            ->from($db->quoteName('#__extensions'))
            ->order($db->quoteName('name') . ' ASC');

        $rows = $db->setQuery($query)->loadAssocList() ?: [];

        $out = [];
        foreach ($rows as $row) {
            // The version lives inside a JSON blob Joomla caches at install time. A row whose
            // cache is missing or unreadable still belongs in the list — the caller asked what
            // is installed, not what is fully described.
            $cache = json_decode((string) ($row['manifest_cache'] ?? ''), true);
            $out[] = [
                'name'    => (string) ($row['name'] ?? ''),
                'type'    => (string) ($row['type'] ?? ''),
                'element' => (string) ($row['element'] ?? ''),
                // The plugin group. Two products ship a plugin whose element is `com_k2` —
                // Xmap's per-product plugin and K2's own — and the group is the only thing in
                // this table that tells them apart. Empty for every type except plugin.
                'folder' => (string) ($row['folder'] ?? ''),
                // Which package installed this row, 0 when nothing did. A package arrives as
                // many rows and this column is the only record that they were one purchase:
                // Xmap is eight rows, RSForm! Pro six. Without it a caller counts one product
                // eight times, in exactly the number a readiness report is built around.
                'package_id' => (int) ($row['package_id'] ?? 0),
                'version' => \is_array($cache) && isset($cache['version']) ? (string) $cache['version'] : null,
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    public function coreManifest(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Joomla 4 added `locked` as the honest core marker; `protected` had drifted into
        // "cannot be disabled" and on upgraded sites disagrees with reality row by row.
        // A Joomla 3 table has no `locked` column, so the first query fails there and the
        // second asks the only question that version can answer.
        $columns = ['type', 'element', 'folder', 'enabled', 'manifest_cache', 'locked'];
        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName($columns))
                ->from($db->quoteName('#__extensions'));
            $rows = $db->setQuery($query)->loadAssocList() ?: [];
            $coreOf = static fn(array $row): bool => (int) ($row['locked'] ?? 0) === 1;
        } catch (Throwable $e) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['type', 'element', 'folder', 'enabled', 'manifest_cache', 'protected']))
                ->from($db->quoteName('#__extensions'));
            $rows = $db->setQuery($query)->loadAssocList() ?: [];
            $coreOf = static fn(array $row): bool => (int) ($row['protected'] ?? 0) === 1;
        }

        $extensions = [];
        foreach ($rows as $row) {
            $element = (string) ($row['element'] ?? '');
            if ($element === '') {
                continue;
            }
            $cache = json_decode((string) ($row['manifest_cache'] ?? ''), true);
            $folder = (string) ($row['folder'] ?? '');
            $extensions[] = [
                'type'    => (string) ($row['type'] ?? ''),
                'element' => $element,
                'folder'  => $folder === '' ? null : $folder,
                'core'    => $coreOf($row),
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
                'version' => \is_array($cache) && isset($cache['version']) ? (string) $cache['version'] : null,
            ];
        }

        return [
            'platform'        => 'joomla',
            'platformVersion' => \defined('JVERSION') ? JVERSION : '',
            'extensions'      => $extensions,
        ];
    }
}
