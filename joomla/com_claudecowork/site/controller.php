<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

/**
 * The default controller Joomla 3's dispatch expects to find. It does nothing itself — every
 * request that matters carries `task=api.exec`, which Joomla routes to `controllers/api.php`.
 * This exists only so `BaseController::getInstance('ClaudeCowork')` has a default to load.
 *
 * Joomla 4+ never loads this file; it uses the namespaced controllers under `src/`.
 */
class ClaudeCoworkController extends BaseController
{
}
