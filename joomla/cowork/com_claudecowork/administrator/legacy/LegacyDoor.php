<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

/**
 * The Joomla 3 twin of `src/Service/EngineFactory` — the engine wired the way Joomla 3 allows, for
 * both places that answer the door there: the site's `controllers/api.php`, reached after routing,
 * and the legacy entry of `plg_system_claudecoworkapi`, reached at `onAfterInitialise` on the site
 * and the administrator alike.
 *
 * It lives on the administrator side for the same reason `EngineFactory` does: the administrator
 * door has to answer on a site whose `components/com_claudecowork` is gone or broken, and a wiring
 * kept in the site folder would go with it.
 *
 * Two differences from the Joomla 4 wiring, both forced by the platform:
 *
 * - The database connection comes from `Factory::getDbo()`, not the DI container Joomla 3 does
 *   not have.
 * - The extension installer AND the write side (SiteWriter, MediaWriter, ApplyLog) are left out
 *   (`null`), so `extension.install`, `content.update` and `media.upload` answer 'unavailable'
 *   here. Those actions are for the live sites Tracy manages on current Joomla; a Joomla 3 site
 *   is almost always one being read to be migrated OFF, where the actions that matter are the
 *   reads — the database and the files. Wiring Joomla 3's older APIs for a case that does not
 *   arise would be code to carry for no one.
 *
 * The engine itself (`lib/`) is byte-identical to the Joomla 4 path: the value is shared, only
 * this thin Joomla-facing shell is doubled.
 */
final class ClaudeCoworkLegacyDoor
{
    /** Whether the component's engine is on disk — false on a site where only the plugin survived. */
    public static function installed()
    {
        return is_file(self::libDir() . '/Engine.php');
    }

    /**
     * Is this request knocking on the door? Read straight off the query string, as the plugin
     * has to before routing; `lib/Door.php` holds the rule.
     */
    public static function wanted($input)
    {
        require_once self::libDir() . '/Door.php';

        return \Door::wants(['option' => $input->get('option', null, 'raw'), 'task' => $input->get('task', null, 'raw')]);
    }

    /** Reads one request off the application, answers it, and ends the response. */
    public static function answer()
    {
        $lib = self::libDir();

        foreach (['SqlValue', 'RowSource', 'DbDumper', 'FileWalker', 'TarStream', 'Uploader', 'Extensions', 'SiteWriter', 'Token', 'Engine', 'MysqliRowSource'] as $class) {
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
                'component' => self::installedVersion(),
                'upload'    => \AutoUploader::capability(),
                'db_prefix' => self::dbPrefix(),
            ],
            self::buildDumper(),
            self::buildWalker($params),
            new \AutoUploader(120),
            null
        );

        $app->setHeader('Content-Type', 'application/json', true);
        $app->sendHeaders();
        echo json_encode($engine->handle($request));
        $app->close();
    }

    private static function libDir()
    {
        return JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib';
    }

    /** Reads through the connection Joomla already opened. `Factory::getDbo()` because Joomla 3
     *  has no DI container to ask; the engine never sees credentials either way. */
    private static function buildDumper()
    {
        try {
            $driver = Factory::getDbo()->getConnection();
        } catch (\Throwable $e) {
            return null;
        }

        return $driver instanceof \mysqli ? new \DbDumper(new \MysqliRowSource($driver)) : null;
    }

    /** The database driver's table prefix (`#__`), so a caller can pick this site's tables. */
    private static function dbPrefix()
    {
        try {
            return (string) Factory::getDbo()->getPrefix();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** An unreadable webroot leaves `files.*` reporting 'unavailable', not fatal. */
    private static function buildWalker($params)
    {
        $configured = trim((string) $params->get('webroot', ''));

        try {
            return new \FileWalker($configured !== '' ? $configured : JPATH_ROOT);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** This component's own version, from the manifest Joomla copied in when it installed. */
    private static function installedVersion()
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
