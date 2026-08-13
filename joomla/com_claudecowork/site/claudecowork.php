<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * The Joomla 3 entry point.
 *
 * Joomla 3 dispatches a component by including this file; Joomla 4+ ignores it entirely and
 * routes through `services/provider.php` instead. So this is the legacy half of a component
 * that carries both: the same endpoint, `index.php?option=com_claudecowork&task=api.exec`,
 * answered by whichever dispatch the running Joomla understands.
 *
 * `BaseController` (not the removed `JControllerLegacy`) because the namespaced class exists on
 * Joomla 3.8+ as well as 4+, and this file only ever runs on 3 — 4 never reaches it.
 */
$controller = BaseController::getInstance('ClaudeCowork');
$controller->execute(Factory::getApplication()->input->get('task', ''));
$controller->redirect();
