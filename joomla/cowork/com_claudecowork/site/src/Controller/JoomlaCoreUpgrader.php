<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * The Joomla half of {@see \CoreUpgrader}, driving com_joomlaupdate's UpdateModel.
 *
 * No exec(), no restore.php. The whole chain 4.3.4 -> 4.4 -> 5.4 -> 6.1 was driven through this
 * component's own HTTP API and proven front and admin 200 at each landing, on hosts where exec is
 * available AND without ever calling it (a plain Joomla in a container, and a real JoomlArt
 * template site on the fleet, both 2026-08).
 *
 *   prepare   set the update channel FIRST (before the model boots, or applyUpdateSite reads a
 *             stale cached param), open the core update site, refresh, download, and extract the
 *             package straight over JPATH_ROOT. For a 6.x target the compat behaviour plugins are
 *             toggled AFTER the refresh, and the cached class map and opcache are dropped, so the
 *             finalise call loads the new code and not the old.
 *   finalise  a fresh call, now on the extracted code: UpdateModel::finaliseUpgrade(), then the
 *             pending schema migrations, because a site that crossed several majors can land with
 *             some still owed and the next hop's checkSchema would refuse to start.
 *
 * The site's own reported version is the only truth trusted; a caller verifies it against $to.
 */
final class JoomlaCoreUpgrader implements \CoreUpgrader
{
    public function upgrade(string $to, string $step): array
    {
        $app = Factory::getApplication();

        if ($step === 'prepare') {
            $current      = \defined('JVERSION') ? \JVERSION : '';
            $crossesMajor = $to !== '' && \strncmp((string) $current, $to[0], 1) !== 0;
            $db           = Factory::getContainer()->get(DatabaseInterface::class);

            // Open the CMS core update site. It is the one getUpdateInformation reads, and a host
            // or a provisioning step that left it disabled makes every check answer "already
            // latest". Clear the cached check while here.
            $db->setQuery(
                'UPDATE #__update_sites AS us'
                . ' INNER JOIN #__update_sites_extensions AS map ON map.update_site_id = us.update_site_id'
                . ' INNER JOIN #__extensions AS e ON e.extension_id = map.extension_id'
                . " SET us.enabled = 1 WHERE e.element = 'joomla' AND e.type = 'file'"
            )->execute();
            $db->setQuery('DELETE FROM #__updates')->execute();
            $db->setQuery('UPDATE #__update_sites SET last_check_timestamp = 0')->execute();

            $model = $app->bootComponent('com_joomlaupdate')->getMVCFactory()
                ->createModel('Update', 'Administrator', ['ignore_request' => true]);

            // Set the channel and rebuild the update-site URL from it. A crossing needs 'next' to
            // reach the next major; a same-major hop (4.3 -> 4.4) needs 'default', because a real
            // site's update site is often left pointing at the updater's own extension feed, which
            // reports the installed version as latest and offers nothing. applyUpdateSite rebuilds
            // the URL from the param; setting the param alone does not.
            $this->setUpdateSource($model, $crossesMajor ? 'next' : 'default');

            $model->refreshUpdates(true);
            $info = $model->getUpdateInformation();
            if (($info['hasUpdate'] ?? false) !== true) {
                return ['ok' => false, 'error' => 'the update source offered nothing (installed '
                    . ($info['installed'] ?? '?') . ', latest ' . ($info['latest'] ?? '?') . ')'];
            }

            $download = $model->download();
            $file     = $download['basename'] ?? '';
            if ($file === '' || ($download['check'] ?? false) !== true) {
                return ['ok' => false, 'error' => 'download or checksum failed'];
            }

            $zipPath = \rtrim((string) $app->getConfig()->get('tmp_path'), '/') . '/' . $file;
            $zip     = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return ['ok' => false, 'error' => 'could not open the downloaded package'];
            }
            $extracted = $zip->extractTo(\JPATH_ROOT);
            $zip->close();
            if (!$extracted) {
                return ['ok' => false, 'error' => 'extracting the package over the site root failed'];
            }

            // Toggle the compat behaviour plugins for a 6.x target, and only now — after the
            // refresh above. Disabling compat earlier strips the legacy class aliases that a 5.x
            // site's own extensions load during the update check, fatalling the request. Done
            // here it takes effect for the finalise call, on the 6.x code. extension:enable is
            // absent on 5.4, so this is a direct write.
            if ($to === '6.1') {
                $db->setQuery("UPDATE #__extensions SET enabled = 0 WHERE folder = 'behaviour' AND element = 'compat'")->execute();
                $db->setQuery("UPDATE #__extensions SET enabled = 1 WHERE folder = 'behaviour' AND element = 'compat6'")->execute();
            }

            // Make the extracted code the code the next request runs. finalise is a fresh request,
            // but opcache and the cached PSR-4 map still point at the version that was here when
            // prepare began, so finalise would load old classes against new files and fatal. A
            // fleet clone gets a container restart for this; a customer's host gets this.
            @\unlink(\JPATH_ADMINISTRATOR . '/cache/autoload_psr4.php');
            if (\function_exists('opcache_reset')) {
                @\opcache_reset();
            }

            return ['ok' => true, 'step' => 'prepare', 'version' => $this->diskVersion()];
        }

        if ($step === 'finalise') {
            $model = $app->bootComponent('com_joomlaupdate')->getMVCFactory()
                ->createModel('Update', 'Administrator', ['ignore_request' => true]);
            $model->finaliseUpgrade();
            // finaliseUpgrade runs the schema changes, but a site upgraded across several majors
            // can land with migrations still owed; apply them so the next hop's checkSchema does
            // not refuse to start. The same work as `maintenance:database --fix`.
            $this->fixSchema();
            return ['ok' => true, 'step' => 'finalise', 'version' => $this->diskVersion()];
        }

        return ['ok' => false, 'error' => 'step must be prepare or finalise'];
    }

    /**
     * Persist the update channel the way core:update:channel does, but without touching the model:
     * writes the channel param and rebuilds the update-site URL from it, the way
     * core:update:channel does.
     */
    private function setUpdateSource($model, string $channel): void
    {
        $table = \Joomla\CMS\Table\Table::getInstance('extension');
        $table->load(['type' => 'component', 'element' => 'com_joomlaupdate']);
        $params = new \Joomla\Registry\Registry($table->params);
        $params->set('updatesource', $channel);
        $table->params = $params->toString();
        $table->store();
        $model->applyUpdateSite($channel);
    }

    /** Apply any owed core schema migrations. Best-effort: a site that cannot locate its schema
     *  files still upgraded, and the caller verifies the version and the front end regardless. */
    private function fixSchema(): void
    {
        try {
            $db        = Factory::getContainer()->get(DatabaseInterface::class);
            $folder    = \JPATH_ADMINISTRATOR . '/components/com_admin/sql/updates/mysql';
            $changeSet = \Joomla\CMS\Schema\ChangeSet::getInstance($db, $folder);
            $changeSet->fix();
        } catch (\Throwable $e) {
            // left to the caller's verify step
        }
    }

    private function diskVersion(): string
    {
        $manifest = @\simplexml_load_file(\JPATH_ROOT . '/administrator/manifests/files/joomla.xml');
        return $manifest ? (string) $manifest->version : '';
    }
}
