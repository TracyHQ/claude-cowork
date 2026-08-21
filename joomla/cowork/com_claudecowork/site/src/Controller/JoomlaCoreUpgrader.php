<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * The Joomla half of {@see \CoreUpgrader}.
 *
 * ⚠️ PROVEN PROTOTYPE, not the production shape. It runs the upgrade through the CLI
 * (`cli/joomla.php core:update`), which works because each exec() is a FRESH process — and that
 * is precisely what sidesteps the crash a single web request hits: the process starts on the old
 * code, copies the new files over itself, and a second process on the new code finalises. Proven
 * on a real Joomla 5.4.8 -> 6.1.3 through this component's own API on 2026-08-21.
 *
 * Why it must be replaced for production: hardened customer hosts disable exec()/shell, and this
 * returns `unavailable` there. The real implementation drives com_joomlaupdate's UpdateModel
 * across the same request boundaries the web updater uses (download -> install -> finalise ->
 * cleanup). That is deeper Joomla-updater work and belongs to whoever owns the com_joomlaupdate
 * integration; this exists to prove the recipe and the action wiring end to end, and to be the
 * exact behaviour the model-based version must reproduce. See TracyHQ/claude-cowork#7 for the
 * recipe and the two findings behind it (the cross-version finalise, the 4.4 channel).
 */
final class JoomlaCoreUpgrader implements \CoreUpgrader
{
    public function upgrade(string $to): array
    {
        if (!\function_exists('exec')) {
            return ['ok' => false, 'error' => 'exec is disabled on this host; this build needs the UpdateModel path'];
        }
        $root = \rtrim(\JPATH_ROOT, '/');
        $cli  = $root . '/cli/joomla.php';
        if (!\is_file($cli)) {
            return ['ok' => false, 'error' => 'joomla CLI not found at ' . $cli];
        }

        $run = static function (string $args) use ($cli): int {
            $out = [];
            $rc  = 0;
            \exec('php ' . \escapeshellarg($cli) . ' ' . $args . ' 2>&1', $out, $rc);
            return $rc;
        };

        $current      = \defined('JVERSION') ? \JVERSION : '';
        $crossesMajor = $to !== '' && \strncmp((string) $current, $to[0], 1) !== 0;
        $steps        = [];

        if ($crossesMajor) {
            // The channel gate. On 5.4 the CLI command exists; on 4.4 it does not (see #7), which
            // is one reason the model-based version is the real target.
            $run('core:update:channel next');
            $steps[] = ['op' => 'open_channel'];
        }

        if ($to === '6.1') {
            // The compat toggle for 5.4 -> 6. `extension:enable` does not exist in 5.4.8, so it
            // is a database write. `#__` is expanded to the site's real prefix by the driver.
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery("UPDATE #__extensions SET enabled = 0 WHERE folder = 'behaviour' AND element = 'compat'")->execute();
            $db->setQuery("UPDATE #__extensions SET enabled = 1 WHERE folder = 'behaviour' AND element = 'compat6'")->execute();
            $steps[] = ['op' => 'set_compat_plugins', 'via' => 'db'];
        }

        // Twice on purpose: the first copies the files and dies at finalise cross-version; the
        // second, a fresh process now on the new code, completes it. The exit codes are noisy and
        // deliberately ignored — the site's own reported version is the only truth trusted.
        $run('core:update');
        $run('core:update');
        $steps[] = ['op' => 'upgrade'];

        $run('maintenance:database --fix');
        $steps[] = ['op' => 'repair_schema'];

        $manifest = @\simplexml_load_file($root . '/administrator/manifests/files/joomla.xml');
        $version  = $manifest ? (string) $manifest->version : '';

        return [
            'ok'      => true,
            'version' => $version,
            'landed'  => $version !== '' && \strncmp($version, $to, \strlen($to)) === 0,
            'steps'   => $steps,
        ];
    }
}
