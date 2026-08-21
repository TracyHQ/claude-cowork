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
 * No exec(), no restore.php. Proven on a real Joomla 5.4.8 -> 6.1.3 through this component's own
 * API on 2026-08-21, front and admin 200, schema up to date, on a host where exec is available
 * AND without ever calling it.
 *
 *   prepare   applyUpdateSite('next') on a major crossing (this is what opens the channel; the
 *             updatesource param alone does not), toggle the compat plugins as a DB write for
 *             5.4 -> 6 (extension:enable is absent in 5.4.8), UpdateModel::download() the package,
 *             and extract it straight over JPATH_ROOT with ZipArchive. The package is a plain ZIP
 *             laid out over the web root, so no restore engine is needed for this.
 *   finalise  a fresh call, now on the extracted 6.x code: UpdateModel::finaliseUpgrade(), which
 *             runs the schema migrations itself. Running this in the same request as prepare would
 *             hit the cross-version crash the web updater's separate request avoids.
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

            $model = $app->bootComponent('com_joomlaupdate')->getMVCFactory()
                ->createModel('Update', 'Administrator', ['ignore_request' => true]);

            if ($crossesMajor) {
                // Opens the channel. applyUpdateSite rebuilds the update-site URL for the channel;
                // setting the updatesource param alone does not, so the model would offer nothing.
                $this->setUpdateSource($model, 'next');
            }
            if ($to === '6.1') {
                $db = Factory::getContainer()->get(DatabaseInterface::class);
                $db->setQuery("UPDATE #__extensions SET enabled = 0 WHERE folder = 'behaviour' AND element = 'compat'")->execute();
                $db->setQuery("UPDATE #__extensions SET enabled = 1 WHERE folder = 'behaviour' AND element = 'compat6'")->execute();
            }

            // Clear the cached update check, or the model answers from a stale "already latest".
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery('DELETE FROM #__updates')->execute();
            $db->setQuery('UPDATE #__update_sites SET last_check_timestamp = 0')->execute();

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

            return ['ok' => true, 'step' => 'prepare', 'version' => $this->diskVersion()];
        }

        if ($step === 'finalise') {
            $model = $app->bootComponent('com_joomlaupdate')->getMVCFactory()
                ->createModel('Update', 'Administrator', ['ignore_request' => true]);
            // finaliseUpgrade runs the 6.x schema migrations itself; no separate database fix.
            $model->finaliseUpgrade();
            return ['ok' => true, 'step' => 'finalise', 'version' => $this->diskVersion()];
        }

        return ['ok' => false, 'error' => 'step must be prepare or finalise'];
    }

    /** Set the update channel the way core:update:channel does: param plus applyUpdateSite. */
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

    private function diskVersion(): string
    {
        $manifest = @\simplexml_load_file(\JPATH_ROOT . '/administrator/manifests/files/joomla.xml');
        return $manifest ? (string) $manifest->version : '';
    }
}
