<?php

/**
 * tpl_tracy — one template, 152 looks.
 *
 * The look is a template style parameter: `inspiration` names one of the OpenDesign design systems
 * bundled under media/css/inspirations/, and that file's :root is the only source of colour, type,
 * spacing, corners and shadows (tracy.css is written against those 56 names and declares none of
 * its own). `nav` and `hero` pick the layout; `auto` follows what inspirations.json says about the
 * chosen design system. Layout is CSS keyed on <body data-nav data-hero>, so switching the style
 * parameter changes the whole site without touching content.
 *
 * @package tpl_tracy
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/** @var Joomla\CMS\Document\HtmlDocument $this */

$app   = Factory::getApplication();
$input = $app->getInput();
$wa    = $this->getWebAssetManager();

require_once __DIR__ . '/resolve.php';
$look = tpl_tracy_resolve($this->params);

// The component renders before index.php, so a Tracy layout has already told us which page kind
// this is. Anything else is derived from the request.
$page = (string) $app->get('tracy.page', '');
if ($page === '') {
    $view = $input->getCmd('view', '');
    $page = $view === 'category' ? 'listing' : ($view === 'article' ? 'article' : 'page');
}

// Joomla names a template's language file tpl_<element>.ini, and this template's element is
// tpl_tracy — so the file is tpl_tpl_tracy.ini. The admin style form derives that name itself;
// loading anything else here would translate the site and leave the admin form on raw keys.
$app->getLanguage()->load('tpl_tpl_tracy', JPATH_BASE);

// A root-relative path (no leading slash): the asset manager prefixes the site root itself.
if ($page === 'fixture' || $page === 'artifact') {
    // A design page (the system's own fixture, or an engine artifact): its fenced stylesheet is
    // registered by the layout; here only fixture-page.css, nothing of the template's own.
    $wa->registerAndUseStyle('template.fixture-page', 'media/templates/site/tpl_tracy/css/fixture-page.css');
} else {
    $wa->useStyle('template.tracy')
        ->useStyle('template.layout')
        ->useStyle('template.sections')
        ->useScript('template.tracy')
        ->registerAndUseStyle(
            'template.inspiration',
            'media/templates/site/tpl_tracy/css/inspirations/' . $look['id'] . '.css',
            [],
            [],
            ['template.tracy']
        );
}

$this->setMetaData('viewport', 'width=device-width, initial-scale=1');

$sitename = htmlspecialchars((string) $app->get('sitename'), ENT_QUOTES, 'UTF-8');
$hasNav   = $this->countModules('nav', true);
$hasSide  = $this->countModules('sidebar', true);
?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">
<head>
    <jdoc:include type="metas" />
    <jdoc:include type="styles" />
    <jdoc:include type="scripts" />
</head>
<body class="tracy tracy-page-<?php echo $page; ?>" data-inspiration="<?php echo $look['id']; ?>" data-nav="<?php echo $look['nav']; ?>" data-hero="<?php echo $look['hero']; ?>" data-page="<?php echo $page; ?>">
    <a class="tracy-skip" href="#tracy-main"><?php echo Text::_('TPL_TRACY_SKIP_TO_CONTENT'); ?></a>

    <header class="tracy-header">
        <div class="tracy-header__inner tracy-container">
            <a class="tracy-brand" href="<?php echo Uri::root(); ?>"><?php echo $sitename; ?></a>
            <?php if ($hasNav) : ?>
                <button class="tracy-nav-toggle" type="button" aria-expanded="false" aria-controls="tracy-nav">
                    <?php echo Text::_('TPL_TRACY_MENU'); ?>
                </button>
                <nav id="tracy-nav" class="tracy-nav" aria-label="<?php echo Text::_('TPL_TRACY_MENU'); ?>">
                    <jdoc:include type="modules" name="nav" style="none" />
                </nav>
            <?php endif; ?>
        </div>
    </header>

    <main id="tracy-main" class="tracy-main">
        <jdoc:include type="message" />
        <jdoc:include type="component" />
    </main>

    <?php if ($hasSide) : ?>
        <aside class="tracy-sidebar">
            <div class="tracy-container">
                <jdoc:include type="modules" name="sidebar" style="card" />
            </div>
        </aside>
    <?php endif; ?>

    <footer class="tracy-footer">
        <?php if ($this->countModules('footer', true)) : ?>
            <div class="tracy-footer__modules tracy-container">
                <jdoc:include type="modules" name="footer" style="card" />
            </div>
        <?php endif; ?>
        <div class="tracy-footer__inner tracy-container">
            <a class="tracy-footer__brand" href="<?php echo Uri::root(); ?>"><?php echo $sitename; ?></a>
            <p class="tracy-footer__note"><?php echo Text::_('TPL_TRACY_BUILT_WITH'); ?></p>
        </div>
    </footer>

    <jdoc:include type="modules" name="debug" style="none" />
</body>
</html>
