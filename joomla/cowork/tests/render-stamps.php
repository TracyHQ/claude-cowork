<?php
// Loaded by run.php: render stamps — which module or article printed which part of a page, for
// Tracy's page picker. The HTML rules are pure (lib/RenderStamps.php); the plugin wiring is pinned
// by reading its source, the way the self-updater is above, because it only runs inside Joomla.

require_once __DIR__ . '/../lib/RenderStamps.php';

// --- who gets stamps --------------------------------------------------------------------------
checkTrue('the trusted header on a paired site asks for stamps', RenderStamps::wanted('pick', 'tok'));
checkTrue('the header value is read case- and space-tolerantly', RenderStamps::wanted(' Pick ', 'tok'));
check('no header, no stamps', RenderStamps::wanted('', 'tok'), false);
check('a missing header, no stamps', RenderStamps::wanted(null, 'tok'), false);
check('another value, no stamps', RenderStamps::wanted('refresh', 'tok'), false);
check('a site whose token was cleared is disconnected: no stamps', RenderStamps::wanted('pick', '  '), false);
check('an array-valued header never matches', RenderStamps::wanted(['pick'], 'tok'), false);

// --- the stamp format (contract with the page runtime and the resolver) -----------------------
check('module stamp', RenderStamps::src(RenderStamps::owner('module', 443), 'mod_ja_acm'), 'module:443 block:mod_ja_acm');
check('article owner carries the alias', RenderStamps::owner('article', '734', 'our-story'), 'article:734:our-story');
check('an alias that would split the value is left off', RenderStamps::owner('article', 734, 'a b'), 'article:734');
check('a colon in an alias would add a part: left off', RenderStamps::owner('article', 734, 'a:b'), 'article:734');
check('a unicode alias is kept (unicodeslugs)', RenderStamps::owner('article', 9, 'giới-thiệu'), 'article:9:giới-thiệu');
check('id 0 is no record', RenderStamps::owner('module', 0), null);
check('marker form the runtime reads', RenderStamps::marker('article:734:our-story'),
    '<template data-tracy-owner="article:734:our-story"></template>');
check('category marker', RenderStamps::marker(RenderStamps::owner('category', 8, 'blog')),
    '<template data-tracy-owner="category:8:blog"></template>');
check('the block name is sanitised', RenderStamps::src('module:1', 'mod_x"><b'), 'module:1 block:mod_xb');

// --- first element ----------------------------------------------------------------------------
check('stamps the first element', RenderStamps::stampFirst("\n  <div class=\"m\">x</div>", 'module:1 block:mod_custom'),
    "\n  <div data-tracy-src=\"module:1 block:mod_custom\" class=\"m\">x</div>");
check('skips a leading comment', RenderStamps::stampFirst('<!-- start --><section>x</section>', 'module:1'),
    '<!-- start --><section data-tracy-src="module:1">x</section>');
check('a fragment opening with text is left alone', RenderStamps::stampFirst('hello <b>x</b>', 'module:1'), 'hello <b>x</b>');
check('an element already stamped is left alone', RenderStamps::stampFirst('<div data-tracy-src="module:2">x</div>', 'module:1'),
    '<div data-tracy-src="module:2">x</div>');
check('a quoted > in an attribute does not end the tag early',
    RenderStamps::stampFirst('<div title="a>b">x</div>', 'module:1'), '<div data-tracy-src="module:1" title="a>b">x</div>');

// --- list modules: each item names its article -----------------------------------------------
// A resolver that knows three articles, the way ArticleLinks answers after building their routes.
$known = [
    '/blog/first-post' => [11, 'first-post'],
    '/blog/second-post' => [12, 'second-post'],
    'index.php?option=com_content&view=article&id=13:third&catid=2' => [13, 'third'],
];
$asked = [];
$resolve = function (array $hrefs) use ($known, &$asked) {
    $asked = $hrefs;
    return array_intersect_key($known, array_flip($hrefs));
};

$news = <<<HTML
<ul class="mod-articlesnews newsflash">
  <li class="newsflash-item">
    <a href="/blog/first-post"><img src="/images/a.jpg" alt=""></a>
    <h4><a href="/blog/first-post">First post</a></h4>
    <p>Intro one</p>
  </li>
  <li class="newsflash-item">
    <h4><a href="/blog/second-post">Second post</a></h4>
    <p>Intro two</p>
  </li>
  <li class="newsflash-item">
    <h4><a href="index.php?option=com_content&amp;view=article&amp;id=13:third&amp;catid=2">Third</a></h4>
  </li>
</ul>
HTML;
$stamped = RenderStamps::stampListItems($news, 'mod_articles_news', $resolve);
check('three article links give three item marks', substr_count($stamped, 'data-tracy-src="article:'), 3);
checkTrue('the whole item is marked, not the bare link',
    str_contains($stamped, '<li data-tracy-src="article:11:first-post block:mod_articles_news" class="newsflash-item">'));
checkTrue('second item', str_contains($stamped, '<li data-tracy-src="article:12:second-post block:mod_articles_news" class="newsflash-item">'));
checkTrue('a raw index.php link, entity-decoded, names the third',
    str_contains($stamped, '<li data-tracy-src="article:13:third block:mod_articles_news" class="newsflash-item">'));
checkTrue('the list itself is left for the module stamp', str_contains($stamped, '<ul class="mod-articlesnews newsflash">'));
check('removing the inserted attributes gives back the original bytes',
    preg_replace('/ data-tracy-src="[^"]*"/', '', $stamped), $news);
check('each distinct href is asked once, decoded', $asked,
    ['/blog/first-post', '/blog/second-post', 'index.php?option=com_content&view=article&id=13:third&catid=2']);

$full = RenderStamps::stampFirst($stamped, RenderStamps::src('module:91', 'mod_articles_news'));
checkTrue('the module stamp still lands on the list', str_contains($full, '<ul data-tracy-src="module:91 block:mod_articles_news" class="mod-articlesnews newsflash">'));

// A module showing ONE article: its top element keeps the module stamp, the item goes inside.
$single = '<div class="latest"><article><h3><a href="/blog/first-post">First</a></h3><p>x</p></article></div>';
check('a one-article module marks the item below its top element',
    RenderStamps::stampListItems($single, 'mod_articles_latest', $resolve),
    '<div class="latest"><article data-tracy-src="article:11:first-post block:mod_articles_latest"><h3><a href="/blog/first-post">First</a></h3><p>x</p></article></div>');

// JA ACM: a list only when it links to two articles or more.
$acmList = '<div class="acm-projects"><div class="row"><div class="col"><a href="/blog/first-post">A</a></div><div class="col"><a href="/blog/second-post">B</a></div></div></div>';
check('an ACM listing two articles is a list', substr_count(RenderStamps::stampListItems($acmList, 'mod_ja_acm', $resolve), 'article:'), 2);
$acmCta = '<section class="acm-hero"><h2>Our words</h2><p><a class="btn" href="/blog/first-post">Read more</a></p></section>';
check('an ACM with one call-to-action is the module\'s own copy: untouched',
    RenderStamps::stampListItems($acmCta, 'mod_ja_acm', $resolve), $acmCta);

// Everything else is byte-identical.
$menu = '<ul class="mod-menu"><li class="item-101"><a href="/blog/first-post">Home</a></li><li class="item-102"><a href="/blog/second-post">B</a></li></ul>';
check('a menu linking to articles is the menu\'s, not the articles\'', RenderStamps::stampListItems($menu, 'mod_menu', $resolve), $menu);
$custom = '<div><a href="/blog/first-post">x</a><a href="/blog/second-post">y</a></div>';
check('a custom module is its own copy', RenderStamps::stampListItems($custom, 'mod_custom', $resolve), $custom);
$unknown = '<ul><li><a href="/nowhere">x</a></li></ul>';
check('links nobody can tie to an article change nothing', RenderStamps::stampListItems($unknown, 'mod_articles_news', $resolve), $unknown);

// Tolerance: markup inside raw text is not markup; stray and missing end tags do not derail it.
$messy = '<div class="w"><script>var s = "<li><a href=\'/blog/second-post\'>";</script>'
    . '<div class="i"><a href="/blog/first-post">A</a></span></div>'
    . '<div class="i"><img src="x.png"><br><a href=\'/blog/second-post\'>B</a>'
    . '</div>';
$messyOut = RenderStamps::stampListItems($messy, 'mod_articles_category', $resolve);
checkTrue('a link inside a script is not an item', !str_contains($messyOut, '<script data-tracy-src'));
checkTrue('the item after a stray end tag is found',
    str_contains($messyOut, '<div data-tracy-src="article:11:first-post block:mod_articles_category" class="i"><a href="/blog/first-post">'));
checkTrue('the item inside a wrapper left unclosed is found',
    str_contains($messyOut, '<div data-tracy-src="article:12:second-post block:mod_articles_category" class="i"><img'));
check('and nothing else changed', preg_replace('/ data-tracy-src="[^"]*"/', '', $messyOut), $messy);

// An item whose element is already stamped (a nested module) is left alone.
$nested = '<div><div data-tracy-src="module:5 block:mod_x"><a href="/blog/first-post">A</a></div><div><a href="/blog/second-post">B</a></div></div>';
check('an element already stamped keeps its stamp', substr_count(RenderStamps::stampListItems($nested, 'mod_articles_news', $resolve), 'data-tracy-src'), 2);

// --- what an href says before the database is asked -------------------------------------------
check('a raw article link names its id', RenderStamps::hrefHints('/index.php?option=com_content&view=article&id=42:hello&catid=3', 'site.test'),
    ['nonSef' => 42, 'path' => null, 'id' => 42, 'alias' => null]);
check('a SEF link without ids names an alias', RenderStamps::hrefHints('https://site.test/en/blog/hello-world.html', 'site.test'),
    ['nonSef' => null, 'path' => '/en/blog/hello-world.html', 'id' => null, 'alias' => 'hello-world']);
check('a SEF link with ids names both', RenderStamps::hrefHints('/index.php/blog/42-hello', 'site.test'),
    ['nonSef' => null, 'path' => '/index.php/blog/42-hello', 'id' => 42, 'alias' => 'hello']);
check('another host is not this site', RenderStamps::hrefHints('https://elsewhere.test/blog/hello', 'site.test'), null);
check('mailto is not a page', RenderStamps::hrefHints('mailto:a@b.test', 'site.test'), null);
check('a fragment is not a page', RenderStamps::hrefHints('#top', 'site.test'), null);
check('another component is not an article', RenderStamps::hrefHints('/index.php?option=com_contact&view=contact&id=1', 'site.test'), null);
check('the home page names nothing', RenderStamps::hrefHints('/', 'site.test'), null);
check('paths compare in one spelling', RenderStamps::normaliseLink('/en/blog/caf%C3%A9/'), '/en/blog/café');
check('the query counts, in one order', RenderStamps::normaliseLink('https://site.test/index.php/component/content/article/a?lang=en&catid=2#x'),
    RenderStamps::normaliseLink('/index.php/component/content/article/a/?catid=2&lang=en'));
checkTrue('two articles sharing an alias differ by their query',
    RenderStamps::normaliseLink('/component/content/article/a?catid=2') !== RenderStamps::normaliseLink('/component/content/article/a?catid=3'));
check('an unrouted SEF link keeps its query for the comparison',
    RenderStamps::hrefHints('/index.php/component/content/article/first-post?catid=2', 'site.test')['path'],
    '/index.php/component/content/article/first-post?catid=2');

// --- the plugin wiring --------------------------------------------------------------------------
$apiSrc = file_get_contents(__DIR__ . '/../plg_system_claudecoworkapi/src/Extension/ClaudeCoworkApi.php');
foreach (['onAfterRenderModule', 'onContentBeforeDisplay', 'onPageCacheSetCaching', 'onPageCacheIsExcluded', 'onAfterRender'] as $ev) {
    checkTrue("the plugin listens to {$ev}", str_contains($apiSrc, "'{$ev}' => '{$ev}'"));
}
checkTrue('stamps are gated on the trusted header', str_contains($apiSrc, "getString('HTTP_X_TRACY_PREVIEW'") && RenderStamps::SERVER_KEY === 'HTTP_X_TRACY_PREVIEW');
checkTrue('and on the cowork token', str_contains($apiSrc, "RenderStamps::wanted(\$header, \$token)"));
checkTrue('a stamped request switches Joomla caching off', str_contains($apiSrc, "\$app->set('caching', 0)"));
checkTrue('the page cache is refused for it', str_contains($apiSrc, 'self::addResult($event, false)'));
checkTrue('and it is sent as uncachable (Joomla then adds no-store)', str_contains($apiSrc, '$app->allowCache(false)'));
checkTrue('the category view names a category, never an article with its id', str_contains($apiSrc, "'com_content.categories'"));
checkTrue('build.sh ships the rules with the engine', str_contains(file_get_contents(__DIR__ . '/../build.sh'), 'cp lib/*.php'));
