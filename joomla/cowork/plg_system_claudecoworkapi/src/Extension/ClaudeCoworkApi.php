<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Plugin\System\ClaudeCoworkApi\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
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
 *
 * ## Render stamps
 *
 * The same plugin, being the one system plugin the package already enables and orders first, also
 * prints render stamps (`lib/RenderStamps.php`: which module or article printed which part of the
 * page) — but only for a request carrying `X-Tracy-Preview: pick` on a site with a cowork token.
 * Every other request takes the early return in each handler below, and its bytes are exactly what
 * they were without this plugin. A stamped response is kept out of every cache: Joomla's page cache
 * would otherwise store it and serve the stamps to the public (or serve a public copy, unstamped,
 * to the picker), and the conservative view and module caches would do the same one layer down.
 */
final class ClaudeCoworkApi extends CMSPlugin implements SubscriberInterface
{
    /** Whether this request prints render stamps. Decided once, at `onAfterInitialise`. */
    private bool $stamping = false;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onPageCacheSetCaching' => 'onPageCacheSetCaching',
            'onPageCacheIsExcluded' => 'onPageCacheIsExcluded',
            'onAfterRenderModule' => 'onAfterRenderModule',
            'onContentBeforeDisplay' => 'onContentBeforeDisplay',
            'onAfterRender' => 'onAfterRender',
        ];
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

        try {
            $this->decideStamping();
        } catch (\Throwable $e) {
            $this->stamping = false;
            Log::add('claudecoworkapi stamps: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    /**
     * Joomla's page cache (plg_system_cache) asks this at `onAfterRoute` before serving a stored
     * page or deciding to store this one: any `false` turns it off for the request. Answering here
     * covers both directions — the picker never gets a public copy without stamps, and a stamped
     * page is never stored for the public.
     */
    public function onPageCacheSetCaching(EventInterface $event): void
    {
        if ($this->stamping) {
            self::addResult($event, false);
        }
    }

    /** Asked again at `onAfterRender`; the same answer, so no later decision can store the page. */
    public function onPageCacheIsExcluded(EventInterface $event): void
    {
        if ($this->stamping) {
            self::addResult($event, true);
        }
    }

    /**
     * Every module, whatever its type or position: `module:<id> block:<type>` on its first element,
     * and — for the modules that print a list of articles — `article:<id>:<alias> block:<type>` on
     * each item (why over the HTML and not a hook: `RenderStamps::stampListItems`).
     */
    public function onAfterRenderModule(EventInterface $event): void
    {
        if (!$this->stamping) {
            return;
        }
        try {
            $module = self::argument($event, ['subject', 'module'], 0);
            if (!\is_object($module) || (int) ($module->id ?? 0) <= 0 || trim((string) ($module->content ?? '')) === '') {
                // Id 0 is a module a template renders on the fly (T4's megamenu): no record to name.
                return;
            }
            $type = (string) ($module->module ?? '');
            $content = \RenderStamps::stampListItems((string) $module->content, $type, [ArticleLinks::class, 'resolve']);
            $owner = \RenderStamps::owner('module', $module->id);
            $module->content = \RenderStamps::stampFirst($content, \RenderStamps::src($owner, $type));
        } catch (\Throwable $e) {
            Log::add('claudecoworkapi stamps: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    /**
     * Every article render — the article page and each item of a blog or featured listing — prints
     * this event's output INSIDE the article's own markup, so a marker there names the article for
     * every element around it. The category view fires the same event for the category itself
     * (context `com_content.categories`), printed inside its description: that marker names the
     * category, never an article with the category's id.
     */
    public function onContentBeforeDisplay(EventInterface $event): void
    {
        if (!$this->stamping) {
            return;
        }
        try {
            $context = (string) self::argument($event, ['context'], 0);
            $item = self::argument($event, ['subject', 'item'], 1);
            if (!\is_object($item)) {
                return;
            }
            if ($context === 'com_content.categories') {
                $owner = \RenderStamps::owner('category', $item->id ?? 0, $item->alias ?? null);
            } elseif (\in_array($context, ['com_content.article', 'com_content.featured', 'com_content.category', 'com_content.archive'], true)) {
                $owner = \RenderStamps::owner('article', $item->id ?? 0, $item->alias ?? null);
            } else {
                return;
            }
            if ($owner !== null) {
                self::addResult($event, \RenderStamps::marker($owner));
            }
        } catch (\Throwable $e) {
            Log::add('claudecoworkapi stamps: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    /**
     * Headers for whatever sits between this site and the browser: a stamped page is for one
     * viewer, now, and never for a shared cache.
     *
     * `allowCache(false)` rather than a Cache-Control of our own: Joomla's `respond()` then ADDS
     * `no-store, no-cache, must-revalidate` after every plugin has run, and PHP sends the last value
     * of a repeated header — a `private, no-store` set here was measured (Joomla 6.1.3) to be
     * replaced by it. Joomla's value already forbids any cache to store the page. Set late, at
     * `onAfterRender`, so no plugin running earlier (the page cache's browser-cache option) turns
     * caching back on after this.
     */
    public function onAfterRender(): void
    {
        if (!$this->stamping) {
            return;
        }
        $app = $this->getApplication();
        $app->allowCache(false);
        $app->setHeader('Vary', 'Cookie', false);
    }

    /**
     * Stamp this request? Only on the site client, only for the trusted header, only on a site
     * with a cowork token (why both: `RenderStamps::wanted`). When yes, Joomla's own caching is
     * switched off for the request here, at the earliest event, before any cache object is created
     * — the conservative view cache would otherwise store an article's HTML WITH its marker and
     * print it to the next ordinary visitor.
     */
    private function decideStamping(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            return;
        }
        $header = $app->getInput()->server->getString('HTTP_X_TRACY_PREVIEW', '');
        // The common case, every ordinary visitor: no header, and nothing is loaded or read.
        if ($header === '' || !class_exists(EngineFactory::class) || !EngineFactory::installed()) {
            return;
        }
        EngineFactory::loadRenderStamps();
        $token = (string) ComponentHelper::getParams('com_claudecowork')->get('token', '');
        if (!\RenderStamps::wanted($header, $token)) {
            return;
        }

        $this->stamping = true;
        $app->set('caching', 0);
        // Older code paths read the global configuration object rather than the application.
        if (method_exists(Factory::class, 'getConfig')) {
            Factory::getConfig()->set('caching', 0);
        }
    }

    /**
     * An event argument by name (Joomla 5+ concrete events) or by position (Joomla 4 generic events
     * built from a legacy argument list).
     */
    private static function argument(EventInterface $event, array $names, int $position)
    {
        foreach ($names as $name) {
            $value = $event->getArgument($name);
            if ($value !== null) {
                return $value;
            }
        }
        return $event->getArgument($position);
    }

    /** Add a result the way both generations collect them: `addResult` (5+) or the `result` array (4). */
    private static function addResult(EventInterface $event, $value): void
    {
        if (method_exists($event, 'addResult')) {
            $event->addResult($value);
            return;
        }
        $results = $event->getArgument('result') ?: [];
        $results[] = $value;
        $event->setArgument('result', $results);
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
        $root = rtrim(\Joomla\CMS\Uri\Uri::root(true), '/');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($app->isClient('site') && $path === $root . '/content.json') {
            EngineFactory::answerContent($app);
            return;
        }


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
