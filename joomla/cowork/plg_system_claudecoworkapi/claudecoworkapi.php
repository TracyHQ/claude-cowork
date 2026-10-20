<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;

/**
 * The Joomla 3 entry point.
 *
 * Joomla 3 loads a system plugin by including `<element>.php` and instantiating
 * `PlgSystem<element>`; without this file it skips the plugin without a word, so on Joomla 3 the
 * door was only the component's controller — and `/administrator/index.php?…` answered Joomla's own
 * 403. Joomla 4+ never includes this file: `services/provider.php` registers the plugin, and the
 * legacy fallback only runs when no provider did.
 *
 * Only the door is answered here, at `onAfterInitialise` on both clients, through the component's
 * Joomla 3 wiring (`administrator/legacy/LegacyDoor.php`, the same one `controllers/api.php` uses).
 * Render stamps, favicons and the rest of `src/Extension/ClaudeCoworkApi` are Joomla 4+ only.
 */
class PlgSystemClaudecoworkapi extends CMSPlugin
{
    public function onAfterInitialise()
    {
        try {
            // A site that removed the component and kept the plugin must not fatal.
            $door = JPATH_ADMINISTRATOR . '/components/com_claudecowork/legacy/LegacyDoor.php';
            if (!is_file($door)) {
                return;
            }
            require_once $door;
            if (!ClaudeCoworkLegacyDoor::installed() || !ClaudeCoworkLegacyDoor::wanted(Factory::getApplication()->input)) {
                return;
            }
            ClaudeCoworkLegacyDoor::answer();
        } catch (\Throwable $e) {
            // Never let this take a page down: the request carries on to Joomla as if this plugin
            // did not exist, and the component's own controller may still answer it.
            Log::add('claudecoworkapi: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }
}
