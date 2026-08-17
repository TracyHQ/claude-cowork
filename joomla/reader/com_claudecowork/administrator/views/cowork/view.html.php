<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

/**
 * The Joomla 3 connect view — same job as `src/View/Cowork/HtmlView`, in the legacy shape.
 *
 * Reads the same token from the same place and hands it to a template that shows it plainly
 * with a copy button. Joomla 4+ never instantiates this class; it uses the namespaced view.
 */
class ClaudeCoworkViewCowork extends BaseHtmlView
{
    protected $token = '';
    protected $endpoint = '';

    public function display($tpl = null)
    {
        $params = ComponentHelper::getParams('com_claudecowork');

        $this->token    = trim((string) $params->get('token', ''));
        $this->endpoint = rtrim(Uri::root(), '/')
            . '/index.php?option=com_claudecowork&task=api.exec&format=json';

        ToolbarHelper::title(Factory::getLanguage()->_('COM_CLAUDECOWORK_CONNECT_TITLE'), 'link');

        parent::display($tpl);
    }
}
