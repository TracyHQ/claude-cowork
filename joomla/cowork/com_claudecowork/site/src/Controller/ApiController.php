<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Tracy\Component\ClaudeCowork\Administrator\Service\EngineFactory;

/**
 * The door as Joomla's router reaches it: `index.php?option=com_claudecowork&task=api.exec`.
 *
 * Everything real — routing an action, bounding the work, dumping the database, walking the
 * webroot, writing tar, uploading a part — lives in `lib/`, which knows nothing about Joomla
 * and is unit tested on its own (`tests/run.php`). Wiring that engine to this site lives in
 * `EngineFactory`, shared with the system plugin that answers the same door BEFORE routing
 * (see `plg_system_claudecoworkapi`): on a site whose front end is gated by another plugin the
 * request never gets this far, and the plugin answers instead. Two answerers, one wiring.
 *
 * Keeping it thin is a safety property, not tidiness: this runs on a customer's production
 * site, so the less that has to be trusted there, the better.
 *
 * ## Why a component and not the `ajax` plugin group
 *
 * The previous shape of this code was `plg_ajax_tracymigration`, reached through `com_ajax`.
 * That works, but `com_ajax` only exists from **Joomla 3.2** (verified against the Joomla
 * repository: absent at tags 2.5.0, 3.0.0 and 3.1.5, present at 3.2.0), which cannot cover
 * the generations ADR 0032 commits to. `index.php?option=com_x&task=y` is the oldest routing
 * contract Joomla has and is stable from 1.5 through 6, so a component needs nobody's
 * permission for an entry point — `com_ajax` exists precisely to lend one to plugins, which
 * have none of their own.
 */
class ApiController extends BaseController
{
    /**
     * The single entry point: `index.php?option=com_claudecowork&task=api.exec&format=json`.
     */
    public function exec(): void
    {
        EngineFactory::answer($this->app ?? Factory::getApplication());
    }
}
