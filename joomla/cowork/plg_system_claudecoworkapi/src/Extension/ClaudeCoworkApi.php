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
 *
 * ## A favicon for a template without T4
 *
 * T4 prints the favicon its site profile names; a T3 template (or any other) has no such setting,
 * and Joomla prints `templates/<t>/favicon.ico`. `template.siteSettings` keeps the customer's
 * favicon for those in `templates/<t>/local/etc/site/tracy-favicon.json`; `onBeforeCompileHead`
 * swaps the head's favicon links for it, and `onAfterRender` takes out the template icon Joomla
 * adds after that event. A template without that file costs one `is_file`.
 *
 * ## A share image for a template without T4
 *
 * The same file may name the customer's logo as the site's share image (`other_shareImage`):
 * `onBeforeCompileHead` prints it as `og:image` on every page, and `onAfterRender` takes out any
 * other `og:image` the template or an extension printed, so a link shared to Facebook or Zalo shows
 * the customer's logo instead of a demo picture (TCH #1013, D5).
 *
 * ## A share image for a T4 template
 *
 * A T4 template keeps the share image in the same file, and only that key: T4 prints its own
 * Open Graph only when `system_opengraph` is on, for a menu item given an og image, and never on an
 * article. So there the customer's picture is a fallback: `onBeforeRespond` adds it as `og:image`
 * to a printed head that carries none, and a page with its own keeps it. Not `onAfterRender`: T4
 * prints the head inside its own `onAfterRender` (`onBeforeCompileHead` fires from there, measured
 * on JA Spa, Joomla 6), which runs after this plugin's. T4's favicon is its own setting.
 *
 * ## The home tab reads the site name alone
 *
 * Once Tracy has put the site name after every page title (`site.identity` `sitename_pagetitles` = 2),
 * Joomla titles the home page "Home - Name" (or "Name - Name"); `onBeforeCompileHead` gives the home
 * page the site name alone, on a site where Tracy set the switch (`lib/HomeTitle.php`). Every other
 * page keeps "Page - Name". It costs one comparison on every other page.
 */
final class ClaudeCoworkApi extends CMSPlugin implements SubscriberInterface
{
    /** Whether this request prints render stamps. Decided once, at `onAfterInitialise`. */
    private bool $stamping = false;

    /** The customer's favicon as `onBeforeCompileHead` printed it, or null: `onAfterRender` keeps only it. */
    private ?string $faviconHref = null;

    /** The customer's share image as `onBeforeCompileHead` printed it, or null: `onAfterRender` keeps only it. */
    private ?string $shareImage = null;

    /** Whether that share image only fills a head without one (T4), instead of replacing every other. */
    private bool $shareImageFallback = false;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onPageCacheSetCaching' => 'onPageCacheSetCaching',
            'onPageCacheIsExcluded' => 'onPageCacheIsExcluded',
            'onAfterRenderModule' => 'onAfterRenderModule',
            'onAfterDispatch' => 'onAfterDispatch',
            'onContentBeforeDisplay' => 'onContentBeforeDisplay',
            'onAfterRender' => 'onAfterRender',
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            'onBeforeRespond' => 'onBeforeRespond',
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
     * each item (why over the HTML and not a hook: `RenderStamps::stampListItems`). Each link to a
     * category or tag page gets that record (`RenderStamps::stampLinks`), except in a menu module:
     * a menu prints menu item titles, whatever page the item opens.
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
            if ($type !== 'mod_menu') {
                $content = \RenderStamps::stampLinks($content, [TaxonomyLinks::class, 'resolve'], $type);
            }
            $owner = \RenderStamps::owner('module', $module->id);
            $module->content = \RenderStamps::stampFirst($content, \RenderStamps::src($owner, $type));
        } catch (\Throwable $e) {
            Log::add('claudecoworkapi stamps: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    /**
     * The component's output: each link to a category or tag page gets that record — the filter
     * chips of a blog, the category line of each card, an article's tag list. Nothing else is
     * stamped here: articles name themselves through `onContentBeforeDisplay`.
     */
    public function onAfterDispatch(): void
    {
        if (!$this->stamping) {
            return;
        }
        try {
            $document = $this->getApplication()->getDocument();
            if (!$document || $document->getType() !== 'html') {
                return;
            }
            $buffer = $document->getBuffer('component');
            if (\is_string($buffer) && $buffer !== '') {
                $document->setBuffer(\RenderStamps::stampLinks($buffer, [TaxonomyLinks::class, 'resolve']), ['type' => 'component']);
            }
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
        $this->keepOnlyCustomerFavicon();
        $this->keepOnlyCustomerShareImage();
        if (!$this->stamping) {
            return;
        }
        $app = $this->getApplication();
        $app->allowCache(false);
        $app->setHeader('Vary', 'Cookie', false);
    }

    /**
     * The customer's favicon and share image on a page of a template without T4 (see the class
     * comment): every favicon link Joomla or the template added goes, and the one
     * `template.siteSettings` set is added; the share image is set as `og:image`. A page whose
     * template has no setting is left exactly as it was.
     */
    public function onBeforeCompileHead(): void
    {
        $this->homeTitleAlone();
        try {
            $app = $this->getApplication();
            if (!$app->isClient('site') || !class_exists(EngineFactory::class) || !EngineFactory::installed()) {
                return;
            }
            $template = (string) $app->getTemplate();
            if ($template === '' || !is_file(JPATH_ROOT . '/templates/' . basename($template) . '/local/etc/site/tracy-favicon.json')) {
                return;
            }
            $document = $app->getDocument();
            if (!$document || $document->getType() !== 'html' || !property_exists($document, '_links')) {
                return;
            }
            EngineFactory::loadTemplateSiteSettings();
            $t4 = \TemplateSiteSettings::frameworkOf(JPATH_ROOT, $template) === 't4';
            $this->printShareImage($document, $template, $t4);
            if ($t4) {
                return;
            }
            $link = \TemplateSiteSettings::faviconLink(JPATH_ROOT, \Joomla\CMS\Uri\Uri::root(true), $template);
            if ($link === null) {
                return;
            }
            $document->_links = \TemplateSiteSettings::withoutFavicons($document->_links);
            $document->addFavicon($link['href'], $link['type'], 'icon');
            $this->faviconHref = $link['href'];
        } catch (\Throwable $e) {
            // A favicon is never worth a page: the page keeps Joomla's own.
            Log::add('claudecoworkapi favicon: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    /**
     * The home page's `<title>` as the site name alone (`HomeTitle::of`), on the site's home entry and
     * nowhere else: the active menu entry is a home entry, and the request is that entry's own page,
     * not another view reached under its Itemid.
     */
    private function homeTitleAlone(): void
    {
        try {
            $app = $this->getApplication();
            if (!$app->isClient('site') || (int) $app->get('sitename_pagetitles', 0) !== 2) {
                return;
            }
            $active = $app->getMenu()->getActive();
            if (!$active || (int) $active->home !== 1 || !class_exists(EngineFactory::class) || !EngineFactory::installed()) {
                return;
            }
            $input = $app->getInput();
            foreach (['option', 'view', 'layout', 'id'] as $key) {
                $want = (string) ($active->query[$key] ?? '');
                $got = $key === 'id' ? (string) ($input->getInt('id') ?: '') : (string) $input->getCmd($key, '');
                if ($want !== '' && $got !== '' && $want !== $got) {
                    return;
                }
                if ($want === '' && $got !== '' && \in_array($key, ['view', 'id'], true)) {
                    return;
                }
            }
            $document = $app->getDocument();
            if (!$document || $document->getType() !== 'html') {
                return;
            }
            EngineFactory::loadHomeTitle();
            $title = \HomeTitle::of(
                (string) $document->getTitle(),
                (string) $app->get('sitename', ''),
                $app->get('sitename_pagetitles'),
                $app->get(\HomeTitle::MARK),
                \Joomla\CMS\Language\Text::_('JPAGETITLE')
            );
            if ($title !== null) {
                $document->setTitle($title);
            }
        } catch (\Throwable $e) {
            // A title is never worth a page: the page keeps Joomla's own.
            Log::add('claudecoworkapi home title: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    /**
     * `og:image` from the template's settings file, when it names a share image on the site. On T4
     * it is only noted here: `onBeforeRespond` adds it to a printed head that has none, because T4
     * may still print its own og tags after this event.
     */
    private function printShareImage($document, string $template, bool $fallback): void
    {
        try {
            $url = \TemplateSiteSettings::shareImageUrl(JPATH_ROOT, \Joomla\CMS\Uri\Uri::root(), $template);
            if ($url === null) {
                return;
            }
            $this->shareImage = $url;
            $this->shareImageFallback = $fallback;
            if (!$fallback) {
                $document->setMetaData('og:image', $url, 'property');
            }
        } catch (\Throwable $e) {
            // A share image is never worth a page: the page keeps what it had.
            Log::add('claudecoworkapi share image: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    /** The page as printed keeps only the share image `onBeforeCompileHead` set (`TemplateSiteSettings::withoutOtherShareImageTags`). */
    private function keepOnlyCustomerShareImage(): void
    {
        if ($this->shareImage === null || $this->shareImageFallback) {
            return;
        }
        $this->rewriteBody(fn(string $body): string => \TemplateSiteSettings::withoutOtherShareImageTags($body, (string) $this->shareImage));
    }

    /**
     * A T4 page, every plugin's `onAfterRender` done: its own og:image when it printed one, else the
     * customer's (`TemplateSiteSettings::withShareImageFallback`). Any other page is left as it is.
     */
    public function onBeforeRespond(): void
    {
        if ($this->shareImage === null || !$this->shareImageFallback) {
            return;
        }
        $this->rewriteBody(fn(string $body): string => \TemplateSiteSettings::withShareImageFallback($body, (string) $this->shareImage));
    }

    /** The response body passed through `$edit`, set back only when it changed; a failure leaves the page as it was. */
    private function rewriteBody(callable $edit): void
    {
        try {
            $app = $this->getApplication();
            $body = (string) $app->getBody();
            $clean = $edit($body);
            if ($clean !== $body) {
                $app->setBody($clean);
            }
        } catch (\Throwable $e) {
            Log::add('claudecoworkapi share image: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
    }

    /**
     * The page as printed keeps only the favicon `onBeforeCompileHead` set: Joomla adds the
     * template's `favicon.ico` after that event whenever no head link is typed as an ICO file
     * (`TemplateSiteSettings::withoutOtherFaviconTags`), and a browser may show that one instead.
     */
    private function keepOnlyCustomerFavicon(): void
    {
        if ($this->faviconHref === null) {
            return;
        }
        try {
            $app = $this->getApplication();
            $body = (string) $app->getBody();
            $clean = \TemplateSiteSettings::withoutOtherFaviconTags($body, $this->faviconHref);
            if ($clean !== $body) {
                $app->setBody($clean);
            }
        } catch (\Throwable $e) {
            // A favicon is never worth a page: the page keeps both icons.
            Log::add('claudecoworkapi favicon: ' . $e->getMessage(), Log::WARNING, 'plg_system_claudecoworkapi');
        }
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
