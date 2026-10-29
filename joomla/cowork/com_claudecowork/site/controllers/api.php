<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

/**
 * The Joomla 3 twin of `src/Controller/ApiController` — same endpoint, same engine, wired the
 * way Joomla 3 allows.
 *
 * The wiring itself is `administrator/legacy/LegacyDoor.php`, shared with the legacy entry of
 * `plg_system_claudecoworkapi`, which answers the same door before routing — on the
 * administrator too, so it still answers when this site folder is gone. Two answerers, one wiring.
 */
class ClaudeCoworkControllerApi extends BaseController
{
    public function exec()
    {
        // The PHP first: on a PHP too old for the engine LegacyDoor.php does not even parse (and on PHP
        // 5 that is a fatal error), so the door answers php_too_old before it is loaded.
        require_once JPATH_ADMINISTRATOR . '/components/com_claudecowork/legacy/PhpFloor.php';
        if (ClaudeCoworkPhpFloor::tooOld()) {
            ClaudeCoworkPhpFloor::answer(\Joomla\CMS\Factory::getApplication());
            return;
        }
        require_once JPATH_ADMINISTRATOR . '/components/com_claudecowork/legacy/LegacyDoor.php';

        ClaudeCoworkLegacyDoor::answer();
    }
}
