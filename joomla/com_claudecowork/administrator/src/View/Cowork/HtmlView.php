<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Administrator\View\Cowork;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

/**
 * The connect screen: the token to copy, and the endpoint it belongs to.
 *
 * It exists because the token used to live only in the Options dialog, as a password field —
 * hidden behind dots, buried under a settings button, with no way to copy it. For a pairing
 * flow that is the whole task, so it gets a screen of its own where the token is plain text
 * with a copy button beside it.
 */
class HtmlView extends BaseHtmlView
{
    protected string $token = '';
    protected string $endpoint = '';

    public function display($tpl = null)
    {
        $params = ComponentHelper::getParams('com_claudecowork');

        $this->token    = trim((string) $params->get('token', ''));
        // The exact address a caller posts to — shown so a person pairing by hand can see the
        // site is answering on the URL they expect, not a guess about it.
        $this->endpoint = rtrim(Uri::root(), '/')
            . '/index.php?option=com_claudecowork&task=api.exec&format=json';

        ToolbarHelper::title(\JText::_('COM_CLAUDECOWORK'), 'plug');

        parent::display($tpl);
    }
}
