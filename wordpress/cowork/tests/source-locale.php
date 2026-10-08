<?php
/**
 * A Polylang site written in one language that is not its source edition's (TracyHQ/tch#1013, D10): the source edition
 * at `/` carries the customer's Vietnamese, but Polylang prints the edition's own locale, so `<html lang>` said en-US
 * (measured 08/10/2026 on dev, bizwp3). Option `tracy_source_locale` names the language the source edition is written
 * in; the front end then speaks it for that edition only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/SourceLocaleHooks.php';

echo "\nSource edition locale\n";

check('no option: Polylang\'s locale stands', SourceLocaleHooks::localeFor('en_US', '', 'en', 'en', false), 'en_US');
check('the source edition speaks the language it is written in', SourceLocaleHooks::localeFor('en_US', 'vi', 'en', 'en', false), 'vi');
check('another edition keeps its own locale', SourceLocaleHooks::localeFor('de_DE', 'vi', 'de', 'en', false), 'de_DE');
check('the admin keeps the user\'s locale', SourceLocaleHooks::localeFor('en_US', 'vi', 'en', 'en', true), 'en_US');
check('no Polylang language known (a site without Polylang): WordPress\'s own WPLANG decides', SourceLocaleHooks::localeFor('vi', 'vi', null, null, false), 'vi');
check('a locale that is not a locale is ignored', SourceLocaleHooks::localeFor('en_US', 'vi"><script>', 'en', 'en', false), 'en_US');
check('hreflang: the source edition is announced in its written language',
    SourceLocaleHooks::hreflangFor(['en-US' => '/', 'x-default' => '/'], 'vi', 'en-US'), ['vi' => '/', 'x-default' => '/']);
check('hreflang: nothing written in another language, nothing changed',
    SourceLocaleHooks::hreflangFor(['en-US' => '/', 'x-default' => '/'], '', 'en-US'), ['en-US' => '/', 'x-default' => '/']);
