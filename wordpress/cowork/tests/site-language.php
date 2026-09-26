<?php
/**
 * The site's default language on a sealed multilingual site: `siteLanguage.plan`, `set` and
 * `revert` on the content-contract door, and the `siteLanguage` undo step `apply.revert` reads
 * on an unbound site.
 *
 * Loaded by run.php after multilingual.php. Uses `check()` / `checkTrue()`, the `$WTOKEN` it
 * defines, and `multilingualSite()` / `contractSite()` from the two files before it.
 */
declare(strict_types=1);

echo "\nSite language\n";

$REAL_ID = 'tracy-business/wp7/1.1.0';
$EDITIONS = json_decode((string) file_get_contents(__DIR__ . '/../lib/contracts/' . $REAL_ID . '/editions.json'), true);
$contractsDir = __DIR__ . '/../lib/contracts';

/** The real 41-edition site, with Polylang's settings and a front page the English edition owns and the others translate. */
$siteLanguageSite = static function (bool $bind = true, bool $polylang = true) use ($EDITIONS, $REAL_ID, $contractsDir): array {
    $s = multilingualSite($EDITIONS, $REAL_ID, $contractsDir, $bind, $polylang);
    WP_Fake::$options['polylang'] = ['default_lang' => 'en', 'force_lang' => 1, 'hide_default' => 1, 'rewrite' => 1, 'browser' => 0];
    WP_Fake::$options['page_on_front'] = $s['rows']['en'][0];
    WP_Fake::$options['page_for_posts'] = $s['rows']['en'][1];
    foreach ($EDITIONS['locales'] as $slug => $edition) {
        if ($slug === 'en') {
            continue;
        }
        WP_Fake::$translations[$s['rows']['en'][0]][$slug] = $s['rows'][$slug][0];
        // The posts page translates in one edition only: the other option must then stay put.
        if ($slug === 'de') {
            WP_Fake::$translations[$s['rows']['en'][1]][$slug] = $s['rows'][$slug][1];
        }
    }
    return $s;
};
$sdoor = static function (Engine $engine, string $operation, array $params = []) use ($WTOKEN): array {
    return $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};

// ── refusals that write nothing ────────────────────────────────────────────────────────────

$u = $siteLanguageSite(false);
check('an unbound site cannot choose its language', $sdoor($u['engine'], 'siteLanguage.set', ['apply_id' => 'slang-u', 'request_id' => 'u1', 'language' => 'vi'])['error'], 'contract_failed');
check('nor plan it', $sdoor($u['engine'], 'siteLanguage.plan')['error'], 'contract_failed');
check('and its default is untouched', WP_Fake::$options['polylang']['default_lang'], 'en');

$np = $siteLanguageSite(true, false);
check('without Polylang there is no edition to choose', $sdoor($np['engine'], 'siteLanguage.plan')['error'], 'unavailable');

$s = $siteLanguageSite();
$S = $s['engine'];
$enHome = $s['rows']['en'][0];
$enPosts = $s['rows']['en'][1];
$plan = $sdoor($S, 'siteLanguage.plan');
check('the plan says which edition is the default now', $plan['current'], 'en');
check('and lists the 41 editions', count($plan['editions']), 41);
check('with nothing on record', $plan['onRecord'], null);
check('set needs a slang- apply_id', $sdoor($S, 'siteLanguage.set', ['apply_id' => 'x-1', 'request_id' => 'r1', 'language' => 'vi'])['error'], 'bad_params');
check('and a request_id', $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-1', 'language' => 'vi'])['error'], 'bad_params');
check('and a language', $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-1', 'request_id' => 'r1'])['error'], 'bad_params');
$unknown = $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-1', 'request_id' => 'r1', 'language' => 'xx-yy']);
check('a tag the archive ships no edition of is refused', $unknown['error'], 'bad_params');
checkTrue('naming the tag', strpos($unknown['message'], 'xx-yy') !== false);
check('an unknown operation is refused', $sdoor($S, 'siteLanguage.flip', ['apply_id' => 'slang-1', 'request_id' => 'r1'])['error'], 'bad_params');
check('the default it already has is a no-op that says so', $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-1', 'request_id' => 'r1', 'language' => 'en-us'])['alreadySet'], true);
check('nothing was written for any of those', [WP_Fake::$options['polylang']['default_lang'], array_key_exists('WPLANG', WP_Fake::$options), $s['log']->log], ['en', false, []]);

// ── set vi on the real 41-edition site ─────────────────────────────────────────────────────

$set = $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-1', 'request_id' => 'r1', 'language' => 'vi']);
check('set makes Vietnamese the default', [$set['ok'], $set['status'], $set['language'], $set['from'], $set['wplang']], [true, 'completed', 'vi', 'en', 'vi']);
check('and says how Polylang writes its urls now: every edition keeps its prefix', $set['rewrite'], ['force_lang' => 1, 'hide_default' => 0, 'rewrite' => 1]);
check('Polylang default_lang is vi', WP_Fake::$options['polylang']['default_lang'], 'vi');
check('hide_default is off, so the links the archive baked into its navigation keep answering', WP_Fake::$options['polylang']['hide_default'], 0);
check('the rest of its settings are as they were', [WP_Fake::$options['polylang']['force_lang'], WP_Fake::$options['polylang']['browser']], [1, 0]);
check('WPLANG is the edition\'s wpLocale', WP_Fake::$options['WPLANG'], 'vi');
check('the front page is the Vietnamese copy', WP_Fake::$options['page_on_front'], $s['rows']['vi'][0]);
check('the posts page has no Vietnamese copy and stays', WP_Fake::$options['page_for_posts'], $enPosts);
check('the rewrite rules were flushed', WP_Fake::$flushed, 1);
$record = json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true)['siteLanguage'];
check('the binding records it', [$record['language'], $record['from'], $record['wplangFrom'], $record['pageOnFrontFrom'], $record['pageForPostsFrom'], $record['hideDefaultFrom'], $record['applyId'], $record['requestId']], ['vi', 'en', null, $enHome, $enPosts, 1, 'slang-1', 'r1']);
checkTrue('with a time', is_string($record['at'] ?? null));
check('and the log holds the undo', $s['log']->log['slang-1'][0]['op'] ?? null, 'siteLanguage');
check('with the five values as they were', $s['log']->log['slang-1'][0]['before'], ['default_lang' => 'en', 'WPLANG' => null, 'page_on_front' => $enHome, 'page_for_posts' => $enPosts, 'hide_default' => 1]);
check('the plan now says vi', $sdoor($S, 'siteLanguage.plan')['current'], 'vi');
check('with the record', $sdoor($S, 'siteLanguage.plan')['onRecord']['language'], 'vi');
check('a retry with the same apply_id and language is alreadySet', $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-1', 'request_id' => 'r1', 'language' => 'vi'])['alreadySet'], true);
check('the primary subtag names the same edition', $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-1', 'request_id' => 'r1', 'language' => 'VI'])['alreadySet'], true);
$second = $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-2', 'request_id' => 'r2', 'language' => 'de-de']);
check('a second language must take the first back', $second['error'], 'conflict');
checkTrue('and the message says how', strpos($second['message'], 'siteLanguage.revert') !== false);
check('nothing moved for it', [WP_Fake::$options['polylang']['default_lang'], WP_Fake::$options['WPLANG']], ['vi', 'vi']);
check('apply.revert is not the way back on a sealed site', $S->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'slang-1']])['error'], 'bad_params');
checkTrue('and says which is', strpos($S->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'slang-1']])['message'], 'siteLanguage.revert') !== false);
check('so the default is still vi', WP_Fake::$options['polylang']['default_lang'], 'vi');

// ── revert ─────────────────────────────────────────────────────────────────────────────────

$back = $sdoor($S, 'siteLanguage.revert', ['apply_id' => 'slang-1']);
check('revert puts English back', [$back['ok'], $back['status'], $back['language']], [true, 'reverted', 'en']);
check('in Polylang', WP_Fake::$options['polylang']['default_lang'], 'en');
check('with hide_default back on, as the archive was captured', WP_Fake::$options['polylang']['hide_default'], 1);
check('WPLANG as it was: absent', array_key_exists('WPLANG', WP_Fake::$options), false);
check('the front and posts pages as they were', [WP_Fake::$options['page_on_front'], WP_Fake::$options['page_for_posts']], [$enHome, $enPosts]);
check('the log is cleared', $s['log']->log, []);
check('nothing on record', json_decode((string) WP_Fake::$options[QuickstartContract::STORE_OPTION], true)['siteLanguage'], null);
check('and the rules flushed again', WP_Fake::$flushed, 2);
check('reverting again has nothing to take back', $sdoor($S, 'siteLanguage.revert', ['apply_id' => 'slang-3'])['error'], 'contract_failed');
check('after a revert another language may be chosen', $sdoor($S, 'siteLanguage.set', ['apply_id' => 'slang-2', 'request_id' => 'r2', 'language' => 'de'])['language'], 'de');
check('WPLANG follows it', WP_Fake::$options['WPLANG'], 'de_DE');
check('and both pages have German copies', [WP_Fake::$options['page_on_front'], WP_Fake::$options['page_for_posts']], [$s['rows']['de'][0], $s['rows']['de'][1]]);

// ── on the fixture site: inspect reports it, and stays clean ───────────────────────────────

$FIXTURES = __DIR__ . '/fixtures/contracts';
$SITE = json_decode((string) file_get_contents($FIXTURES . '/site.json'), true);
$b = contractSite($SITE, $FIXTURES, true);
$B = $b['engine'];
WP_Fake::$options['polylang'] = ['default_lang' => 'en', 'force_lang' => 1, 'hide_default' => 1];
$sdoor($B, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
check('a bound site starts with no site language on record', $sdoor($B, 'inspect')['siteLanguage'], null);
$fs = $sdoor($B, 'siteLanguage.set', ['apply_id' => 'slang-f', 'request_id' => 'f1', 'language' => 'de-de']);
check('German becomes the default on the fixture site', [$fs['status'], $fs['language'], $fs['from']], ['completed', 'de', 'en']);
check('WPLANG falls back to the locale when the profile names no wpLocale', WP_Fake::$options['WPLANG'], 'de_DE');
$insp = $sdoor($B, 'inspect');
check('inspect stays clean', $insp['problems'], []);
check('and reports the site language', $insp['siteLanguage'], ['language' => 'de', 'from' => 'en', 'applyId' => 'slang-f']);

// ── an edition the archive ships but the site lacks ────────────────────────────────────────

$m = $siteLanguageSite();
unset(WP_Fake::$languages['af']);
$gone = $sdoor($m['engine'], 'siteLanguage.set', ['apply_id' => 'slang-9', 'request_id' => 'r9', 'language' => 'af']);
check('an edition Polylang does not carry is refused', $gone['error'], 'contract_failed');
checkTrue('naming the language', strpos($gone['message'], 'af') !== false);
check('and nothing was written', [WP_Fake::$options['polylang']['default_lang'], $m['log']->log], ['en', []]);

// ── unbound: apply.revert undoes the same step from the log ────────────────────────────────

$v = $siteLanguageSite(false);
WP_Fake::$options['polylang']['default_lang'] = 'vi';
WP_Fake::$options['WPLANG'] = 'vi';
WP_Fake::$options['page_on_front'] = $v['rows']['vi'][0];
WP_Fake::$options['polylang']['hide_default'] = 0;
$v['log']->record('slang-x', ['op' => 'siteLanguage', 'language' => 'vi', 'before' => ['default_lang' => 'en', 'WPLANG' => null, 'page_on_front' => $v['rows']['en'][0], 'page_for_posts' => $v['rows']['en'][1], 'hide_default' => 1]]);
$undo = $v['engine']->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'slang-x']]);
check('an unbound site reverts the step', [$undo['ok'], $undo['reverted']], [true, 1]);
check('putting the default back', WP_Fake::$options['polylang']['default_lang'], 'en');
check('and hide_default', WP_Fake::$options['polylang']['hide_default'], 1);
// A step logged before hide_default was part of it says nothing about it, and leaves it alone.
WP_Fake::$options['polylang']['hide_default'] = 0;
$v['log']->record('slang-y', ['op' => 'siteLanguage', 'language' => 'vi', 'before' => ['default_lang' => 'en', 'WPLANG' => null, 'page_on_front' => $v['rows']['en'][0], 'page_for_posts' => $v['rows']['en'][1]]]);
$v['engine']->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'slang-y']]);
check('an older undo entry leaves hide_default as it is', WP_Fake::$options['polylang']['hide_default'], 0);
check('WPLANG absent again', array_key_exists('WPLANG', WP_Fake::$options), false);
check('and the front page back', WP_Fake::$options['page_on_front'], $v['rows']['en'][0]);

// Leave the fake as the next file expects it.
WP_Fake::reset();
