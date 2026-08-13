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
        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $query  = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_claudecowork'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
            $params = new Registry((string) $db->setQuery($query)->loadResult());

            if (trim((string) $params->get('token', '')) !== '') {
                return true;
            }

            $params->set('token', bin2hex(random_bytes(self::TOKEN_BYTES)));

            $update = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote($params->toString()))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_claudecowork'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
            $db->setQuery($update)->execute();
        } catch (\Throwable $e) {
            // Deliberately swallowed — see the note above.
        }

        return true;
    }
};
