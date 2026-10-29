<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Plugin\System\ClaudeCoworkApi\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

/**
 * This plugin on a PHP older than the engine needs (`ClaudeCoworkPhpFloor`, PHP 7.4). Joomla 4 runs on
 * PHP 7.2.5, and `ClaudeCoworkApi` — typed properties — does not parse there: built on every request,
 * it would take every page of the site down with it. `services/provider.php` registers this class
 * instead, which only answers the door, with `php_too_old`, and leaves every page alone.
 *
 * Written so PHP 7.2 parses it.
 */
final class PhpFloorDoor extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'onAfterInitialise'];
    }

    public function onAfterInitialise(): void
    {
        try {
            $component = JPATH_ADMINISTRATOR . '/components/com_claudecowork';
            if (!is_file($component . '/legacy/PhpFloor.php') || !is_file($component . '/lib/Door.php')) {
                return;
            }
            require_once $component . '/legacy/PhpFloor.php';
            require_once $component . '/lib/Door.php';
            $app = $this->getApplication();
            $input = $app->getInput();
            if (!\Door::wants(['option' => $input->get('option', null, 'raw'), 'task' => $input->get('task', null, 'raw')])) {
                return;
            }
            \ClaudeCoworkPhpFloor::answer($app);
        } catch (\Throwable $e) {
            // Never let this take a page down: the request carries on to Joomla.
        }
    }
}
