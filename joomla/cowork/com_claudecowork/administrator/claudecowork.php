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
 * The Joomla 3 admin entry point — the backend twin of the site's `claudecowork.php`.
 *
 * Joomla 3 includes this to open the component's admin screen; Joomla 4+ ignores it and routes
 * through `services/provider.php` to the namespaced `src/View`. Each generation renders the same
 * connect screen — the token to copy and the endpoint — through the dispatch it understands.
 */
$controller = BaseController::getInstance('ClaudeCowork');
$controller->execute(Factory::getApplication()->input->get('task', 'display'));
$controller->redirect();
