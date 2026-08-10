<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Database\DatabaseInterface;

/**
 * The whole HTTP surface, and deliberately the only Joomla-aware file of any size.
 *
 * Everything real — routing an action, bounding the work, dumping the database, walking the
 * webroot, writing tar, uploading a part — lives in `lib/`, which knows nothing about Joomla
 * and is unit tested on its own (`tests/run.php`). This file reads one request, hands it to
 * the engine as a plain array, and prints the answer.
 *
 * Keeping it thin is a safety property, not tidiness: this runs on a customer's production
 * site, so the less that has to be trusted there, the better.
 *
 * ## Why a component and not the `ajax` plugin group
 *
 * The previous shape of this code was `plg_ajax_tracymigration`, reached through `com_ajax`.
 * That works, but `com_ajax` only exists from **Joomla 3.2** (verified against the Joomla
 * repository: absent at tags 2.5.0, 3.0.0 and 3.1.5, present at 3.2.0), which cannot cover
 * the generations ADR 0032 commits to. `index.php?option=com_x&task=y` is the oldest routing
 * contract Joomla has and is stable from 1.5 through 6, so a component needs nobody's
 * permission for an entry point — `com_ajax` exists precisely to lend one to plugins, which
 * have none of their own.
 */
class ApiController extends BaseController
{
    /**
     * `lib/` is plain PHP with no namespace, so it is required rather than autoloaded — the
     * component's PSR-4 map covers `src/` only, and putting the engine under that map would
     * mean namespacing it, which is exactly what keeps it testable without Joomla.
     *
     * It lives under `administrator/` (not `media/`) because these are executable files and
     * `media/` is served over HTTP.
     */
    private function loadEngine(): void
    {
        $lib = JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib';

        foreach (['SqlValue', 'RowSource', 'DbDumper', 'FileWalker', 'TarStream', 'Uploader', 'Token', 'Engine', 'MysqliRowSource'] as $class) {
            require_once $lib . '/' . $class . '.php';
        }
    }

    /**
     * This component's own version, read from the manifest Joomla copied in when it installed.
     *
     * Read rather than held as a constant so there is one place a version lives and nothing to
     * keep in step. It exists because a caller has to be able to tell an old copy from a new
     * one: an already-installed component is deliberately not reinstalled on every run — doing
     * that answers HTTP 500 with no message at all — and without a version, "already installed"
     * silently means "will never be updated", so every action added later is dead code on every
     * site that already has this.
     *
     * Null when the manifest cannot be read. The caller treats that as "too old to say", which
     * is what it is.
     */
    private static function installedVersion(): ?string
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

    /**
     * The single entry point: `index.php?option=com_claudecowork&task=api.exec&format=json`.
     */
    public function exec(): void
    {
        $this->loadEngine();
        $app = $this->app ?? Factory::getApplication();
        $params = ComponentHelper::getParams('com_claudecowork');

        $request = json_decode((string) $app->getInput()->json->getRaw(), true);
        if (!\is_array($request)) {
            $request = [];
        }

        $token = trim((string) $params->get('token', ''));

        $engine = new \Engine(
            $token === '' ? null : $token,
            [
                'php'       => PHP_VERSION,
                'joomla'    => \defined('JVERSION') ? JVERSION : null,
                'component' => self::installedVersion(),
            ],
            $this->buildDumper(),
            $this->buildWalker($params),
            new \CurlUploader(120)
        );

        $app->setHeader('Content-Type', 'application/json', true);
        $app->sendHeaders();
        echo json_encode($engine->handle($request));
        $app->close();
    }

    /**
     * Reads through the connection Joomla already opened. The engine never receives database
     * credentials, and no action can point it at another server.
     */
    private function buildDumper(): ?\DbDumper
    {
        try {
            $driver = Factory::getContainer()->get(DatabaseInterface::class)->getConnection();
        } catch (\Throwable $e) {
            return null;
        }

        // Only mysqli is wired. On anything else `db.*` answers 'unavailable' rather than
        // half-working, which is the honest result.
        return $driver instanceof \mysqli ? new \DbDumper(new \MysqliRowSource($driver)) : null;
    }

    /** An unreadable webroot leaves `files.*` reporting 'unavailable', not fatal. */
    private function buildWalker($params): ?\FileWalker
    {
        $configured = trim((string) $params->get('webroot', ''));

        try {
            return new \FileWalker($configured !== '' ? $configured : JPATH_ROOT);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
