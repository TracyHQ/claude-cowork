<?php
// Loaded by run.php, after language-overrides-plugins-dates.php: the English a Joomla site written in vi-VN still printed
// although no language key holds it (dev g60-ess-j-full, JA Essence j6 1.0.4, template 1.3.3, plugin 0.24.13, 11/10/2026):
//
// - `aria-label="search"` on every page: Joomla 6.1's own `modules/mod_finder/tmpl/default.php` writes it as a literal;
// - "Watch the video" (7 article pages): `<span class="sr-only">Watch the video</span>` written into the template's
//   `html/layouts/joomla/content/video_play.php`.
// Neither is an article's words nor a language string, so no translation and no pack reached them. The door now offers each
// front-end literal under a key of its own (`TRACY_LITERAL_*`, reason `literal`), and the system plugin's output filter says
// the page's literal in the override file's words (lib/PageLiterals.php).
//
// What is real here: the two lines as Joomla 6.1.1 and JA Essence 1.3.3 ship them.

require_once __DIR__ . '/../lib/PageLiterals.php';

if (function_exists('check')) {
    $plToken = 'language-literals-token-16';
    $plRoot = sys_get_temp_dir() . '/cowork-language-literals-' . bin2hex(random_bytes(6));
    $plPut = static function (string $relative, string $bytes) use ($plRoot): void {
        $path = $plRoot . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes);
    };
    $plPut('templates/ja_essence/templateDetails.xml', "<?xml version=\"1.0\"?>\n<extension type=\"template\" client=\"site\"><name>ja_essence</name></extension>\n");
    $plPut('templates/ja_essence/language/en-GB/en-GB.tpl_ja_essence.ini', "TPL_SHARE_ARTICLE=\"Share article:\"\n");
    $plPut('templates/ja_essence/html/layouts/joomla/content/video_play.php',
        "<?php\n\$play_button = ' data-group=\"myvideo-' . \$ctm_id . '\" href=\"' . trim(\$ctm_url) . '\" id=\"ja-btn-play\" class=\"html5lightbox btn btn-border btn-border-inverse btn-rounded\"><span class=\"sr-only\">Watch the video</span><i class=\"fa fa-play\"></i> ';\n");
    // Code that is no visitor's words: a query's comparison, an arrow, a string that is a key.
    $plPut('templates/ja_essence/helper.php', "<?php\n\$q = 'a.lft > this.lft AND sub.rgt < x'; \$o->a => 'Not words'; echo Text::_('TPL_SHARE_ARTICLE');\n");
    $plPut('modules/mod_finder/tmpl/default.php',
        "<search>\n    <form class=\"mod-finder js-finder-searchform form-search\" action=\"<?php echo Route::_(\$route); ?>\" method=\"get\" aria-label=\"search\">\n");
    // An administrator's layout under a module: not a visitor's.
    $plPut('modules/mod_ja_acm/admin/tmpl/field-jalist.php', "<a title=\"Clone Row\" class=\"btn\">x</a>\n");
    $plPut('language/en-GB/joomla.ini', "DATE_FORMAT_LC3=\"d F Y\"\n");
    $plPut('language/vi-VN/joomla.ini', "DATE_FORMAT_LC3=\"d F Y\"\n");

    check('a literal\'s key: its words upper-cased, under the prefix', [
        PageLiterals::keyOf('Watch the video'), PageLiterals::keyOf('search'), PageLiterals::keyOf('<?php echo $x ?>'), PageLiterals::keyOf('x'),
    ], ['TRACY_LITERAL_WATCH_THE_VIDEO', 'TRACY_LITERAL_SEARCH', null, null]);

    $plFiles = new LanguageOverrides($plRoot);
    $plEngine = (new Engine($plToken, [], null, null, null, null, null, null, new FakeApplyLog()))->languageOverrides($plFiles);
    $plCall = static fn(array $params) => $plEngine->handle(['token' => $plToken, 'action' => 'template.languageOverrides', 'params' => $params]);
    $plRead = $plCall(['template' => 'ja_essence', 'locale' => 'vi-VN']);
    $plRows = [];
    foreach ($plRead['strings'] ?? [] as $row) $plRows[$row['key']] = $row;
    check('g60: core mod_finder\'s aria-label literal is offered, with its words as the source', [
        $plRows['TRACY_LITERAL_SEARCH']['reason'] ?? null, $plRows['TRACY_LITERAL_SEARCH']['source'] ?? null, $plRows['TRACY_LITERAL_SEARCH']['file'] ?? null,
    ], ['literal', 'search', 'modules/mod_finder/tmpl/default.php']);
    check('g60: the template layout\'s screen-reader words are offered', $plRows['TRACY_LITERAL_WATCH_THE_VIDEO']['source'] ?? null, 'Watch the video');
    checkTrue('code and an administrator\'s layout offer nothing', !isset($plRows['TRACY_LITERAL_NOT_WORDS'])
        && !isset($plRows['TRACY_LITERAL_THIS_LFT_AND_SUB_RGT']) && !isset($plRows['TRACY_LITERAL_CLONE_ROW']));

    $plSet = $plCall(['operation' => 'set', 'apply_id' => 'lover-pl-a', 'template' => 'ja_essence', 'locale' => 'vi-VN', 'strings' => [
        'TRACY_LITERAL_SEARCH' => 'tìm kiếm',
        'TRACY_LITERAL_WATCH_THE_VIDEO' => 'Xem video',
    ]]);
    check('set writes both literals', [$plSet['ok'] ?? null, $plSet['written'] ?? null], [true, 2]);
    $plWords = PageLiterals::fromOverrides(LanguageOverrides::parse((string) file_get_contents($plRoot . '/language/overrides/vi-VN.override.ini')));
    check('the output filter reads only the literal keys of the override file', array_keys($plWords), ['TRACY_LITERAL_SEARCH', 'TRACY_LITERAL_WATCH_THE_VIDEO']);

    $plPage = '<html><head><title>Trang chủ</title><script>var s = "search"; var t = "<b>Watch the video</b>";</script></head><body>'
        . '<form class="mod-finder" method="get" aria-label="search"><label>Tìm</label></form>'
        . '<a id="ja-btn-play"><span class="sr-only">Watch the video</span><i class="fa fa-play"></i> </a>'
        . '<p>We search every day.</p><img alt="search results" src="x.png"><div title="Search">x</div></body></html>';
    $plOut = PageLiterals::translate($plPage, $plWords);
    checkTrue('g60: the search form is labelled in the site language', strpos($plOut, 'aria-label="tìm kiếm"') !== false);
    checkTrue('g60: the screen-reader words are in the site language', strpos($plOut, '<span class="sr-only">Xem video</span>') !== false);
    checkTrue('a sentence that holds the word, another attribute value and a script body are left as they are',
        strpos($plOut, '<p>We search every day.</p>') !== false && strpos($plOut, 'alt="search results"') !== false
        && strpos($plOut, 'var s = "search"; var t = "<b>Watch the video</b>";') !== false);
    check('the same words in another case are the same literal', strpos($plOut, 'title="tìm kiếm"') !== false, true);
    check('a translation is escaped into the page', PageLiterals::translate('<a title="search">x</a>', ['TRACY_LITERAL_SEARCH' => 'a "b" & c']), '<a title="a &quot;b&quot; &amp; c">x</a>');
    check('no literal translations: the page is the same bytes', PageLiterals::translate($plPage, []), $plPage);
}
