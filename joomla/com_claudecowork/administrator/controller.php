<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

/**
 * The Joomla 3 admin default controller. One screen, so it is the default view and there is
 * nothing else to route. Joomla 4+ never loads this — it uses the namespaced controllers.
 */
class ClaudeCoworkController extends BaseController
{
    protected $default_view = 'cowork';
}
