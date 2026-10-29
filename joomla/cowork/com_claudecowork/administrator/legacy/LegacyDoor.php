<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * The Joomla 3 way in to the engine, for both places that answer the door there: the site's
 * `controllers/api.php`, reached after routing, and the legacy entry of `plg_system_claudecoworkapi`,
 * reached at `onAfterInitialise` on the site and the administrator alike.
 *
 * It lives on the administrator side for the same reason `src/Service/EngineFactory` does: the
 * administrator door has to answer on a site whose `components/com_claudecowork` is gone or broken,
 * and a wiring kept in the site folder would go with it.
 *
 * Joomla 3 is supported as Joomla 4–6 are, reads and writes alike: this class only does what Joomla 3
 * cannot do for itself and then hands over to the SAME `EngineFactory::build()` the Joomla 4+ door
 * uses, so both generations answer every action with the same collaborators.
 *
 * - The component's `src/` namespaces are registered here: Joomla 3 never reads the manifest's
 *   `<namespace>`.
 * - The database is `Factory::getDbo()` in the Joomla 4 shape (`LegacyDatabase.php`, through
 *   `EngineFactory::database()`).
 * - PHP 8's `str_starts_with`, `str_contains`, `str_ends_with` and `array_is_list`, which the
 *   `src/` classes call and Joomla 4 ships polyfills for, are defined when PHP lacks them.
 * - The request is read off `$app->input`; Joomla 3 has no `getInput()`.
 *
 * The one action Joomla 3 does not get is `core.upgrade` (EngineFactory says why).
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
        // Before anything of the engine is loaded: on a PHP too old for it, it does not parse.
        require_once __DIR__ . '/PhpFloor.php';
        if (\ClaudeCoworkPhpFloor::tooOld()) {
            \ClaudeCoworkPhpFloor::answer(Factory::getApplication());
            return;
        }
        self::prepare();
        @ini_set('display_errors', '0');

        $engineFactory = '\\Tracy\\Component\\ClaudeCowork\\Administrator\\Service\\EngineFactory';
        $engineFactory::loadEngine();
        \Door::reserveMemory();
        \Door::reserveTime();

        $app     = Factory::getApplication();
        $request = json_decode((string) $app->input->json->getRaw(), true);

        if (!\is_array($request)) {
            $request = [];
        }

        if (($request['action'] ?? null) === 'content.read') {
            $engineFactory::answerContent($app, $request);
            return;
        }

        echo \Timing::body($request, static function () use ($app, $request, $engineFactory) {
            $t = \Timing::begin();
            $engine = $engineFactory::build();
            \Timing::end('boot', $t);

            $app->setHeader('Content-Type', 'application/json', true);
            $app->sendHeaders();
            return $engine->handle($request);
        });
        $app->close();
    }

    /** What Joomla 3 does not do for the `src/` classes: their namespaces, and PHP 8's functions. */
    private static function prepare()
    {
        // Only a folder that is there: Joomla 3's loader throws for one that is not, and the site folder
        // may be gone (the administrator door answers without it, its writes `unavailable`).
        $namespaces = [
            'Tracy\\Component\\ClaudeCowork\\Administrator' => JPATH_ADMINISTRATOR . '/components/com_claudecowork/src',
            'Tracy\\Component\\ClaudeCowork\\Site'          => JPATH_SITE . '/components/com_claudecowork/src',
        ];
        foreach ($namespaces as $namespace => $path) {
            if (is_dir($path)) {
                \JLoader::registerNamespace($namespace, $path, false, false, 'psr4');
            }
        }
        require_once __DIR__ . '/LegacyPolyfills.php';
    }

    private static function libDir()
    {
        return JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib';
    }
}
