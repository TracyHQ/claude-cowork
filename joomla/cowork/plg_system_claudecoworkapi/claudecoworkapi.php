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
 *
 * Joomla 3 parses this file on every request, on any PHP from 5.3.10: it is written for PHP 5.3, and
 * asks `legacy/PhpFloor.php` (PHP 5.3 too) before it loads anything else of the component.
 */
class PlgSystemClaudecoworkapi extends CMSPlugin
{
    public function onAfterInitialise()
    {
        try {
            // A site that removed the component and kept the plugin must not fatal.
            $component = JPATH_ADMINISTRATOR . '/components/com_claudecowork';
            if (!is_file($component . '/legacy/LegacyDoor.php')) {
                return;
            }
            // The PHP first, before any other file of the component is parsed: this runs on every page,
            // and on PHP 5 a file that does not parse is a fatal error no catch sees. On a PHP too old
            // for the engine the door answers php_too_old and every page is left alone.
            if (is_file($component . '/legacy/PhpFloor.php')) {
                require_once $component . '/legacy/PhpFloor.php';
                if (ClaudeCoworkPhpFloor::tooOld()) {
                    $input = Factory::getApplication()->input;
                    if (ClaudeCoworkPhpFloor::wants($input->get('option', null, 'raw'), $input->get('task', null, 'raw'))) {
                        ClaudeCoworkPhpFloor::answer(Factory::getApplication());
                    }
                    return;
                }
            }
            require_once $component . '/legacy/LegacyDoor.php';
            if (!ClaudeCoworkLegacyDoor::installed() || !ClaudeCoworkLegacyDoor::wanted(Factory::getApplication()->input)) {
                return;
            }
            ClaudeCoworkLegacyDoor::answer();
        } catch (\Throwable $e) {
            // Never let this take a page down: the request carries on to Joomla as if this plugin
            // did not exist, and the component's own controller may still answer it.
            Log::add('claudecoworkapi: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        } catch (Exception $e) {
            // The same on PHP 5, which has no Throwable.
            Log::add('claudecoworkapi: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }
}
