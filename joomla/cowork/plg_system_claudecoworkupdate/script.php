<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Joomla installs a plugin disabled, and a self-updater installed disabled is a self-updater that
 * never runs — the exact failure it exists to end, wearing a different hat. So on every install and
 * update: enabled.
 *
 * Ordering is left alone, unlike the API door beside it. This one acts at `onAfterRespond`, after
 * the page has already gone to the browser, so nothing is racing it for a turn.
 */
class plgSystemClaudeCoworkUpdateInstallerScript
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
                ->where($db->quoteName('element') . ' = ' . $db->quote('claudecoworkupdate'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
        )->execute();

        return true;
    }
}
