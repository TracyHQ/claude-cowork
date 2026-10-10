<?php
/**
 * The site language, set through `content.update {kind:'option', key:'WPLANG'}` (Tracy's language step,
 * tch `setWordPressLocale`), also moves what WordPress keeps per site in the OLD language (TracyHQ/tch#1013):
 *
 * - every Contact Form 7 form's `_locale`. CF7 6.1 renders a form inside `wpcf7_switch_locale($form->locale())`, so a
 *   form saved under `en_US` printed "Send a copy to yourself", `lang="en-US"` and its own messages in English on a
 *   vi site whose other words were Vietnamese (measured 10/10/2026 on dev g59-ess-wp-full, JA Essence WP 1.0.12);
 * - `date_format` and `time_format`, left at the English install's `F j, Y` / `g:i a` in all 8 published WordPress
 *   quickstarts, so a vi site printed "Tháng 6 9, 2023". The locale's own defaults are what WordPress itself would have
 *   installed (`__('F j, Y')` under that locale: `j F, Y` for vi).
 *
 * Each is recorded under the same apply id, so `apply.revert` takes the whole language step back.
 * Loaded by run.php; uses check() and the WordPress stubs.
 */
declare(strict_types=1);

echo "\nSite locale follow-ups\n";

$slfSite = static function (): array {
    WP_Fake::reset();
    WP_Fake::$posts[71] = ['ID' => 71, 'post_type' => 'wpcf7_contact_form', 'post_status' => 'publish', 'post_title' => 'Contact form'];
    WP_Fake::$posts[72] = ['ID' => 72, 'post_type' => 'wpcf7_contact_form', 'post_status' => 'publish', 'post_title' => 'Newsletter'];
    WP_Fake::$posts[73] = ['ID' => 73, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact'];
    WP_Fake::$meta['71:_locale'] = 'en_US';
    // Tracy Business ships one form saved under ru_RU.
    WP_Fake::$meta['72:_locale'] = 'ru_RU';
    WP_Fake::$meta['73:_locale'] = 'en_US';
    WP_Fake::$options['date_format'] = 'F j, Y';
    WP_Fake::$options['time_format'] = 'g:i a';
    $log = new FakeApplyLog();
    $engine = (new Engine('slf-token-at-least-16chars', [], null, null, null, null, new Claude_Cowork_Site_Writer(), null, $log))
        // What WordPress's own install would pick under each locale (`__('F j, Y')`, `__('g:i a')`), as the core
        // translations ship them: vi `j F, Y` / `H:i`, de_DE `j. F Y` / `G:i`; a locale with no translation answers null.
        ->localeFormats(static fn(string $locale): ?array => ['vi' => ['date' => 'j F, Y', 'time' => 'H:i'], 'de_DE' => ['date' => 'j. F Y', 'time' => 'G:i']][$locale] ?? null);
    $call = static fn(string $action, array $params): array => $engine->handle(['token' => 'slf-token-at-least-16chars', 'action' => $action, 'params' => $params]);
    return [$call, $log];
};

[$call] = $slfSite();
$set = $call('content.update', ['apply_id' => 'lang-1', 'kind' => 'option', 'id' => 0, 'key' => 'WPLANG', 'fields' => ['value' => 'vi']]);
check('WPLANG is written', [$set['ok'] ?? null, WP_Fake::$options['WPLANG'] ?? null], [true, 'vi']);
check('every CF7 form now renders in the site language, whatever it was saved in', [WP_Fake::$meta['71:_locale'], WP_Fake::$meta['72:_locale']], ['vi', 'vi']);
check('a post that is not a form keeps its meta', WP_Fake::$meta['73:_locale'], 'en_US');
check('the dates and times read in the locale\'s own format', [WP_Fake::$options['date_format'], WP_Fake::$options['time_format']], ['j F, Y', 'H:i']);
check('the answer says what followed the language', $set['follow'] ?? null, ['forms' => 2, 'date_format' => 'j F, Y', 'time_format' => 'H:i']);

$back = $call('apply.revert', ['apply_id' => 'lang-1']);
check('revert takes the whole language step back', [$back['ok'] ?? null, array_key_exists('WPLANG', WP_Fake::$options), WP_Fake::$meta['71:_locale'], WP_Fake::$meta['72:_locale'],
    WP_Fake::$options['date_format'], WP_Fake::$options['time_format']], [true, false, 'en_US', 'ru_RU', 'F j, Y', 'g:i a']);

// A format the site chose itself, day first or numeric, is the site's: only an English-order or default one moves.
[$call] = $slfSite();
WP_Fake::$options['date_format'] = 'd/m/Y';
WP_Fake::$options['time_format'] = 'H:i:s';
$set = $call('content.update', ['apply_id' => 'lang-2', 'kind' => 'option', 'id' => 0, 'key' => 'WPLANG', 'fields' => ['value' => 'de_DE']]);
check('a site\'s own day-first date and 24-hour time are kept', [WP_Fake::$options['date_format'], WP_Fake::$options['time_format'], $set['follow'] ?? null],
    ['d/m/Y', 'H:i:s', ['forms' => 2]]);
[$call] = $slfSite();
WP_Fake::$options['date_format'] = 'M d, Y';
$call('content.update', ['apply_id' => 'lang-3', 'kind' => 'option', 'id' => 0, 'key' => 'WPLANG', 'fields' => ['value' => 'de_DE']]);
check('an English-order format the theme set ("M d, Y") takes the locale\'s own', WP_Fake::$options['date_format'], 'j. F Y');

// No translation of the formats for that locale: the forms still follow, the formats stay, nothing pretends.
[$call] = $slfSite();
$set = $call('content.update', ['apply_id' => 'lang-4', 'kind' => 'option', 'id' => 0, 'key' => 'WPLANG', 'fields' => ['value' => 'xx_YY']]);
check('a locale without its own formats keeps the dates and says so', [WP_Fake::$meta['71:_locale'], WP_Fake::$options['date_format'], $set['follow'] ?? null],
    ['xx_YY', 'F j, Y', ['forms' => 2, 'formats' => 'the xx_YY translation of WordPress names no date format of its own']]);

// Back to English (WPLANG empty is en_US): the forms are told en_US.
[$call] = $slfSite();
WP_Fake::$meta['71:_locale'] = 'vi';
$call('content.update', ['apply_id' => 'lang-5', 'kind' => 'option', 'id' => 0, 'key' => 'WPLANG', 'fields' => ['value' => '']]);
check('an empty WPLANG is en_US for the forms', WP_Fake::$meta['71:_locale'], 'en_US');

// Another option written through the same door is only that option.
[$call] = $slfSite();
$call('content.update', ['apply_id' => 'opt-x', 'kind' => 'option', 'id' => 0, 'key' => 'blogname', 'fields' => ['value' => 'Bánh']]);
check('writing another option moves no form and no format', [WP_Fake::$meta['71:_locale'], WP_Fake::$options['date_format']], ['en_US', 'F j, Y']);
