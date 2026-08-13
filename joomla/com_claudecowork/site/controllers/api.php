<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * The Joomla 3 twin of `src/Controller/ApiController` — same endpoint, same engine, wired the
 * way Joomla 3 allows.
 *
 * Two differences from the Joomla 4 version, both forced by the platform:
 *
 * - The database connection comes from `Factory::getDbo()`, not the DI container Joomla 3 does
 *   not have.
 * - The extension installer is left out (`null`), so `extension.install` answers 'unavailable'
 *   here. That action is for the live sites Tracy manages on current Joomla; a Joomla 3 site is
 *   almost always one being read to be migrated OFF, where the actions that matter are the
 *   reads — the database and the files. Wiring Joomla 3's older installer for a case that does
 *   not arise would be code to carry for no one.
 *
 * The engine itself (`lib/`) is byte-identical to the Joomla 4 path: the value is shared, only
 * this thin Joomla-facing shell is doubled.
 */
class ClaudeCoworkControllerApi extends BaseController
{
    public function exec()
    {
        $lib = JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib';

        foreach (['SqlValue', 'RowSource', 'DbDumper', 'FileWalker', 'TarStream', 'Uploader', 'Extensions', 'Token', 'Engine', 'MysqliRowSource'] as $class) {
            require_once $lib . '/' . $class . '.php';
        }

        $app    = Factory::getApplication();
        $params = ComponentHelper::getParams('com_claudecowork');

        $request = json_decode((string) $app->input->json->getRaw(), true);

        if (!\is_array($request)) {
            $request = [];
        }

        $token = trim((string) $params->get('token', ''));

        $engine = new \Engine(
            $token === '' ? null : $token,
            [
                'php'       => PHP_VERSION,
                'joomla'    => \defined('JVERSION') ? JVERSION : null,
                'component' => $this->installedVersion(),
            ],
            $this->buildDumper(),
            $this->buildWalker($params),
            new \CurlUploader(120),
            null
        );

        $app->setHeader('Content-Type', 'application/json', true);
        $app->sendHeaders();
        echo json_encode($engine->handle($request));
        $app->close();
    }

    /** Reads through the connection Joomla already opened. `Factory::getDbo()` because Joomla 3
     *  has no DI container to ask; the engine never sees credentials either way. */
    private function buildDumper()
    {
        try {
            $driver = Factory::getDbo()->getConnection();
        } catch (\Throwable $e) {
            return null;
        }

        return $driver instanceof \mysqli ? new \DbDumper(new \MysqliRowSource($driver)) : null;
    }

    /** An unreadable webroot leaves `files.*` reporting 'unavailable', not fatal. */
    private function buildWalker($params)
    {
        $configured = trim((string) $params->get('webroot', ''));

        try {
            return new \FileWalker($configured !== '' ? $configured : JPATH_ROOT);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** This component's own version, from the manifest Joomla copied in when it installed. */
    private function installedVersion()
    {
        $manifest = JPATH_ADMINISTRATOR . '/components/com_claudecowork/claudecowork.xml';

        if (!is_file($manifest)) {
            return null;
        }

        $xml = @simplexml_load_file($manifest);

        if ($xml === false) {
            return null;
        }

        $version = trim((string) $xml->version);

        return $version === '' ? null : $version;
    }
}
