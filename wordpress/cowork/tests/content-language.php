<?php
/**
 * content.language linking: the page being filed joins its own translation group.
 *
 * Polylang links a group only from two or more languages. A caller who named just the OTHER page in
 * `translations` used to get ok:true while nothing was linked and no hreflang was emitted (benchmark
 * v6). Loaded by run.php, last, because it defines the Polylang writers that an earlier test relies
 * on being absent. Uses `check()`, `$wEngine`, `$WTOKEN`.
 */
declare(strict_types=1);

echo "\ncontent.language linking\n";

/** @var array<int,array{0:int,1:string}> */
$GLOBALS['pllSetCalls'] = [];
/** @var array<int,array<string,int>> */
$GLOBALS['pllSaveCalls'] = [];
function pll_set_post_language(int $id, string $lang): void
{
    $GLOBALS['pllSetCalls'][] = [$id, $lang];
}
function pll_save_post_translations(array $translations): void
{
    $GLOBALS['pllSaveCalls'][] = $translations;
}

WP_Fake::$polylang = true;
foreach (['en' => 3, 'vi' => 4] as $slug => $termId) {
    WP_Fake::$languages[$slug] = ['term_id' => $termId, 'name' => $slug, 'slug' => $slug, 'locale' => $slug, 'is_rtl' => 0, 'term_group' => 0, 'flag_code' => $slug];
}
$callLang = fn(array $params) => $wEngine->handle(['token' => $WTOKEN, 'action' => 'content.language',
    'params' => ['apply_id' => 'a-link-' . count($GLOBALS['pllSetCalls']) . mt_rand()] + $params]);

$r = $callLang(['id' => 10, 'lang' => 'en', 'translations' => ['vi' => 11]]);
check('the page own language is added to the group and it links', $r['ok'] ?? null, true);
check('linked names both languages', $r['linked'] ?? null, ['vi' => 11, 'en' => 10]);
check('the group saved has both pages', $GLOBALS['pllSaveCalls'][0] ?? null, ['vi' => 11, 'en' => 10]);

$r = $callLang(['id' => 10, 'lang' => 'en', 'translations' => ['en' => 99, 'vi' => 11]]);
check('the page being filed wins over a stale own-language entry', $r['linked'] ?? null, ['en' => 10, 'vi' => 11]);

$saved = count($GLOBALS['pllSaveCalls']);
$set = count($GLOBALS['pllSetCalls']);
$r = $callLang(['id' => 10, 'lang' => 'en', 'translations' => ['xx' => 5]]);
check('an unknown language is an error', $r['error'] ?? null, 'bad_params');
check('nothing was written for it', [count($GLOBALS['pllSaveCalls']), count($GLOBALS['pllSetCalls'])], [$saved, $set]);

$r = $callLang(['id' => 10, 'lang' => 'en', 'translations' => ['e1!' => 5]]);
check('a malformed code is an error', $r['error'] ?? null, 'bad_params');

$r = $callLang(['id' => 10, 'lang' => 'en', 'translations' => ['en' => 10]]);
check('fewer than two languages is an error', $r['error'] ?? null, 'bad_params');
check('and links nothing', count($GLOBALS['pllSaveCalls']), $saved);

$r = $callLang(['id' => 10, 'lang' => 'en']);
check('marking a page without translations still works', $r['ok'] ?? null, true);
check('and reports no link', isset($r['linked']), false);
check('and saves no group', count($GLOBALS['pllSaveCalls']), $saved);

WP_Fake::$polylang = false;
WP_Fake::$languages = [];
