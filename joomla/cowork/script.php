<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

defined('_JEXEC') or die;

/**
 * The package's installer script, as Joomla finds it: the PHP check first, then `script-install.php`.
 *
 * Joomla 3 runs on PHP 5.3.10 and up, and many Joomla 3 sites are still on PHP 5.6 or 7.0. Joomla
 * parses this file before anything in it can run, so a single PHP 7 construct here meant a site on an
 * old PHP saw "syntax error, unexpected '?'" in place of an answer (measured 29/10/2026 on Joomla 3.10.6
 * and PHP 7.3.33, before this split). So this file is written for PHP 5.3: no short arrays, no type
 * declarations, no `??`, no visibility on constants. It refuses a PHP older than the engine needs
 * (`ClaudeCoworkPhpFloor::MINIMUM`, copied: this script loads nothing of the component) with a
 * sentence naming both versions, before a file is copied; on a PHP new enough it hands every step to
 * `pkg_claudecoworkInstallerSteps` in `script-install.php` beside it (the receipt, the profile prune).
 *
 * The uninstall step is here, not there: Joomla keeps only this file for an uninstall
 * (`administrator/manifests/packages/claudecowork/`), so `script-install.php` is not beside it then.
 * tests/php-floor.php keeps this file free of anything PHP 5.3 cannot parse; CI lints it on the
 * oldest PHP it can run.
 *
 * Named class rather than `return new class`: Joomla 3 resolves the script by this exact name.
 */
class pkg_claudecoworkInstallerScript
{
    /** The oldest PHP the engine runs on. */
    const PHP_MINIMUM = '7.4.0';

    /** The spare folder under tmp_path (ContractFetch::SPARE, copied: tests/contract-fetch.php compares them). */
    const SPARE = 'com_claudecowork/contracts';

    /** @var pkg_claudecoworkInstallerSteps|false|null the steps, false when they cannot run here */
    private $steps = null;

    /** The refusal a site on `$php` gets, or null when `$php` is new enough. */
    public static function phpRefusal($php)
    {
        if (version_compare($php, self::PHP_MINIMUM, '>=')) {
            return null;
        }
        return 'Claude Cowork needs PHP ' . self::PHP_MINIMUM . ' or newer; this site runs PHP ' . $php
            . '. Ask your host to upgrade PHP on this site, then install the package again.';
    }

    public function preflight($route, $parent)
    {
        $refusal = $route === 'uninstall' ? null : self::phpRefusal(PHP_VERSION);
        if ($refusal !== null) {
            try {
                \Joomla\CMS\Factory::getApplication()->enqueueMessage($refusal, 'error');
            } catch (\Throwable $e) {
                // No application to tell (a CLI install): the refusal below still stops it.
            } catch (Exception $e) {
                // The same on PHP 5, which has no Throwable.
            }
            return false;
        }
        $steps = $this->steps();
        return $steps ? $steps->preflight($route, $parent) : true;
    }

    public function postflight($route, $parent)
    {
        $steps = $this->steps();
        return $steps ? $steps->postflight($route, $parent) : true;
    }

    public function uninstall($parent)
    {
        try {
            if (defined('JPATH_ROOT')) {
                $spare = self::spare(self::tmpPath(), JPATH_ROOT);
                self::remove($spare);
                @rmdir(dirname($spare));
            }
        } catch (\Throwable $e) {
            // Swallowed: an uninstall must finish; what is left is public release bytes.
        } catch (Exception $e) {
            // The same on PHP 5, which has no Throwable.
        }

        return true;
    }

    /** The spare folder, as ContractFetch::spareOf() names it: under tmp_path, else the site's tmp/. */
    public static function spare($tmpPath, $root)
    {
        $tmp = trim((string) $tmpPath);

        return rtrim($tmp !== '' ? $tmp : $root . '/tmp', '/') . '/' . self::SPARE;
    }

    /** Joomla's configured tmp_path, or null when there is no application to ask. */
    private static function tmpPath()
    {
        try {
            return (string) \Joomla\CMS\Factory::getApplication()->get('tmp_path');
        } catch (\Throwable $e) {
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function remove($path)
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        $names = @scandir($path);
        foreach ($names ? $names : array() as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    /** The install steps, from script-install.php beside this file, on a PHP that can parse it. */
    private function steps()
    {
        if ($this->steps === null) {
            $this->steps = false;
            if (self::phpRefusal(PHP_VERSION) === null && is_file(__DIR__ . '/script-install.php')) {
                require_once __DIR__ . '/script-install.php';
                $this->steps = new pkg_claudecoworkInstallerSteps();
            }
        }
        return $this->steps;
    }
}
