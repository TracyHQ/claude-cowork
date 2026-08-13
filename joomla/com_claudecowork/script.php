<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

/**
 * Seeds a token on install and puts the component in the Components menu.
 *
 * Written to run on BOTH Joomla 3 and Joomla 4+, which is why it is a plainly-named class
 * (`com_claudecoworkInstallerScript`) and not the `return new class` an installer-script
 * interface would allow: Joomla 3 resolves the script by that exact name (verified against
 * `InstallerAdapter::getScriptClassName`) and cannot see an anonymous one. It implements no
 * interface — the interface is Joomla 4.4+ only, and requiring it is what made an earlier
 * build fatal on Joomla 3 the moment the file was included.
 *
 * Every Joomla API used here is one that exists on both generations, or is chosen at runtime:
 * `Factory::getDbo()` and `Joomla\Registry\Registry` are shared, and the menu table is picked
 * per version below.
 */
class com_claudecoworkInstallerScript
{
    /** 24 bytes, hex — comfortably past the 16-character floor the engine enforces. */
    private const TOKEN_BYTES = 24;

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

    /**
     * Joomla 3 calls this `postflight($route, $parent)`, Joomla 4+ the same — the two arguments
     * line up, so one signature serves both. Runs after the files are in place, when the
     * extension row exists and its params can be written.
     */
    public function postflight($route, $parent)
    {
        $db = Factory::getDbo();

        // Two independent jobs, each in its own try: a token that could not be seeded must not
        // stop the menu being created, nor the other way round.
        try {
            $this->seedToken($db);
        } catch (\Throwable $e) {
            // Swallowed: a component that installed correctly must not report failure because a
            // setting could not be seeded — a token can still be typed in by hand.
        }
        try {
            $this->ensureAdminMenu($db);
        } catch (\Throwable $e) {
            // Swallowed: the screen is still reachable without a menu item.
        }

        return true;
    }

    /** Gives the component a token if it has none. Leaves an existing one alone — an upgrade
     *  runs this too, and re-minting would cut off every caller already using it. */
    private function seedToken($db): void
    {
        $params = new Registry((string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_claudecowork'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        )->loadResult());

        if (trim((string) $params->get('token', '')) !== '') {
            return;
        }

        $params->set('token', bin2hex(random_bytes(self::TOKEN_BYTES)));

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote($params->toString()))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_claudecowork'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        )->execute();
    }

    /**
     * Puts the component in the admin's Components menu, if it is not already there.
     *
     * The manifest's `<menu>` element does this — but only on a fresh install, and only on
     * Joomla 4+. An upgrade from a version that had no menu, or any install on Joomla 3 (whose
     * legacy admin dispatch this component does not carry an admin view for), leaves no entry.
     * This closes both gaps.
     */
    private function ensureAdminMenu($db): void
    {
        $extensionId = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_claudecowork'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        )->loadResult();

        if ($extensionId === 0) {
            return;
        }

        // The same link on every generation: the connect screen answers at `option=com_claudecowork`
        // on both, through the namespaced admin view on Joomla 4+ and the legacy admin view on
        // Joomla 3. So there is nothing to branch on here.
        $link = 'index.php?option=com_claudecowork';

        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('link') . ' = ' . $db->quote($link))
        )->loadResult();

        if ($exists > 0) {
            return;
        }

        // The Menu table rebuilds the nested set on store, so this needs no hand-computed tree
        // maths — only a parent to sit under. `1` is the admin menu root; Joomla groups every
        // `type=component` item under the Components heading by itself.
        // Older Joomla 3 (pre-3.8) has no namespaced Menu table; fall back to JTable there.
        $table = class_exists('\\Joomla\\CMS\\Table\\Menu')
            ? new \Joomla\CMS\Table\Menu($db)
            : \JTable::getInstance('menu');

        $table->setLocation(1, 'last-child');
        $table->bind([
            'menutype'     => 'main',
            'title'        => 'COM_CLAUDECOWORK',
            'alias'        => 'com-claudecowork',
            'link'         => $link,
            'type'         => 'component',
            'published'    => 1,
            'parent_id'    => 1,
            'component_id' => $extensionId,
            'client_id'    => 1,
            'language'     => '*',
        ]);

        if ($table->check()) {
            $table->store();
        }
    }
}
