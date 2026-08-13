<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Gives the component a token of its own the moment it is installed.
 *
 * Without this, a site owner who installs by hand has to invent a random string themselves,
 * type it into Options, and type the same string again wherever they are pairing the site —
 * a step that is easy to get wrong and pointless to ask for. With it, the token is simply
 * there to be copied, and installing by hand becomes a first-class way to connect a site:
 * the owner signs in to their own admin however their site demands (passkey, MFA, SSO,
 * whatever their host puts in front of it), installs this, and copies one string out.
 *
 * Generated here rather than by whoever installs it, because a shared package must never
 * carry a usable token — the same reason Tracy mints a fresh one when IT does the install.
 *
 * An existing token is never overwritten. Upgrades run this too, and replacing the token on
 * upgrade would break every caller already using it.
 */
return new class () implements InstallerScriptInterface {
    /** 24 bytes, hex — comfortably past the 16-character floor the engine enforces. */
    private const TOKEN_BYTES = 24;

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Runs after the files are in place, which is when the extension row exists and its
     * params can be written.
     *
     * Never fatal: a component that installed correctly must not be reported as failed
     * because a setting could not be seeded. The owner can still type a token in by hand,
     * which is exactly the state this replaces.
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Two independent jobs, each in its own try: a token that could not be seeded must not
        // stop the menu being created, nor the other way round.
        try {
            $this->seedToken($db);
        } catch (\Throwable $e) {
            // Deliberately swallowed — see the class note.
        }
        try {
            $this->ensureAdminMenu($db);
        } catch (\Throwable $e) {
            // Deliberately swallowed — the screen is still reachable by URL without a menu item.
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
     * The manifest's `<menu>` element does this — but ONLY on a fresh install. An upgrade from
     * a version that had no menu (0.4.0 shipped without one) never gets the entry, so the screen
     * exists and answers but appears nowhere. This closes that gap, and is a no-op on a fresh
     * install where the manifest already made the row.
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

        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('link') . ' = ' . $db->quote('index.php?option=com_claudecowork'))
        )->loadResult();

        if ($exists > 0) {
            return;
        }

        // The Menu table rebuilds the nested set (lft/rgt) on store, so this needs no hand-computed
        // tree maths — only a parent to sit under. `1` is the admin menu root; Joomla groups every
        // `type=component` item under the Components heading by itself.
        $table = new \Joomla\CMS\Table\Menu($db);
        $table->setLocation(1, 'last-child');
        $table->bind([
            'menutype'     => 'main',
            'title'        => 'COM_CLAUDECOWORK',
            'alias'        => 'com-claudecowork',
            'link'         => 'index.php?option=com_claudecowork',
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
};
