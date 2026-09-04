<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Plugin\System\ClaudeCoworkApi\Extension;

use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Tracy\Component\ClaudeCowork\Administrator\Service\EngineFactory;

\defined('_JEXEC') or die;

/**
 * Answers the API door before Joomla routes the request.
 *
 * The component already answers `index.php?option=com_claudecowork&task=api.exec` — but only after
 * routing, at the end of a chain every other system plugin gets to run first. On a site behind a
 * "coming soon" page, an offline switch, a maintenance screen or a firewall extension, one of those
 * plugins answers every front-end request itself and the component is never reached: the door
 * exists and nobody can knock on it. Measured 2026-09-04 on a Joomla 6.0.3 site where every
 * `index.php?option=…` URL, core `com_ajax` included, returned the same coming-soon page.
 *
 * `onAfterInitialise` is the earliest event a system plugin gets, before the router and before any
 * such gatekeeper, and it fires on the administrator client too — where those front-end gatekeepers
 * do not act at all, and before the administrator decides whether the caller is logged in. So the
 * same door opens at two addresses, `/index.php?…` and `/administrator/index.php?…`, and a caller
 * whose front door is blocked simply uses the back one. The token in the request body is the only
 * credential at either address, exactly as the component's controller has always had it.
 *
 * Nothing here knows about any particular gatekeeper. It reads one request, hands it to the same
 * engine wiring the component uses, prints the answer and ends the response.
 */
final class ClaudeCoworkApi extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'onAfterInitialise'];
    }

    public function onAfterInitialise(): void
    {
        try {
            $this->answerIfAsked();
        } catch (\Throwable $e) {
            // Never let this take a page down. If the door cannot be answered here, the request
            // carries on to Joomla as if this plugin did not exist — the component's own controller
            // may still answer it, and a normal page is never the casualty.
            Log::add('claudecoworkapi: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    private function answerIfAsked(): void
    {
        // The component's engine has to be on disk: this plugin ships inside the component's
        // package, but a site that removed the component and kept the plugin must not fatal.
        if (!class_exists(EngineFactory::class) || !EngineFactory::installed()) {
            return;
        }

        $app = $this->getApplication();
        $input = $app->getInput();

        // Before routing, `option` and `task` are read straight off the query string — which is
        // what the door has always been: the oldest routing contract Joomla has. Any array-valued
        // parameter fails the match rather than being coerced.
        EngineFactory::loadEngine();
        if (!\Door::wants(['option' => $input->get('option', null, 'raw'), 'task' => $input->get('task', null, 'raw')])) {
            return;
        }

        EngineFactory::answer($app);
    }
}
