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
            $installer = new Installer();
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
            ->select($db->quoteName(['name', 'type', 'element', 'manifest_cache', 'enabled']))
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
                'version' => \is_array($cache) && isset($cache['version']) ? (string) $cache['version'] : null,
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            ];
        }
        return $out;
    }
}
