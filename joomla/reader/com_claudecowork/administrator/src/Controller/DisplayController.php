<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

/**
 * Opening the component lands on the one screen it has: the connect page, which shows the
 * token to copy. There is nothing to list or edit here, so there is one view and it is the
 * default.
 */
class DisplayController extends BaseController
{
    protected $default_view = 'cowork';
}
