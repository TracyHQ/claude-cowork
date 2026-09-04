<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Joomla installs a plugin disabled and last in line. This plugin is useless in either state.
 *
 * Disabled, the door is only the component's controller again — reachable on a clear site, blocked
 * on a gated one, which is the situation the plugin exists for. Last in line, a gatekeeper plugin
 * that also acts at `onAfterInitialise` runs first and this one never gets its turn. So on every
 * install and update: enabled, and ordered ahead of every other system plugin.
 */
class plgSystemClaudeCoworkApiInstallerScript
{
    public function install($parent)
    {
        return true;
    }

    public function update($parent)
    {
        return true;
    }

    public function uninstall($parent)
    {
        return true;
    }

    public function preflight($route, $parent)
    {
        return true;
    }

    public function postflight($route, $parent)
    {
        $db = Factory::getDbo();
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                // Plugins run in ascending `ordering`; core system plugins sit at small positive
                // numbers. A large negative value puts this one first without renumbering anything.
                ->set($db->quoteName('ordering') . ' = -100')
                ->where($db->quoteName('element') . ' = ' . $db->quote('claudecoworkapi'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
        )->execute();

        return true;
    }
}
