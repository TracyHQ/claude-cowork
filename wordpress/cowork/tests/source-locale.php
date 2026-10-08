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

// The language switcher (Polylang's standard block, widget and template tag) named the source edition "English" with a US
// flag over a site written in Vietnamese (measured 08/10/2026 on dev, tamq-8w-bizwp). It names the written language.
$item = "\t<li class=\"lang-item lang-item-14 lang-item-en current-lang lang-item-first\"><a lang=\"en-US\" hreflang=\"en-US\" href=\"https://x.test/\" aria-current=\"true\"><img decoding=\"async\" src=\"data:image/png;base64,US\" alt=\"\" width=\"16\" height=\"11\" style=\"width: 16px; height: 11px;\" /><span style=\"margin-left:0.3em;\">English</span></a></li>\n";
$other = "\t<li class=\"lang-item lang-item-15 lang-item-en-gb\"><a lang=\"en-GB\" hreflang=\"en-GB\" href=\"https://x.test/gb/\"><span style=\"margin-left:0.3em;\">English</span></a></li>\n";
check('switcher: the source edition is named and flagged in the language it is written in',
    SourceLocaleHooks::switcherFor($item . $other, 'en', 'English', 'en-US', 'Tiếng Việt', 'vi', 'data:image/png;base64,VN'),
    "\t<li class=\"lang-item lang-item-14 lang-item-en current-lang lang-item-first\"><a lang=\"vi\" hreflang=\"vi\" href=\"https://x.test/\" aria-current=\"true\"><img decoding=\"async\" src=\"data:image/png;base64,VN\" alt=\"\" width=\"16\" height=\"11\" style=\"width: 16px; height: 11px;\" /><span style=\"margin-left:0.3em;\">Tiếng Việt</span></a></li>\n" . $other);
check('switcher: with no flag for the written language the source edition\'s flag goes',
    SourceLocaleHooks::switcherFor("<li class=\"lang-item lang-item-en\"><a lang=\"en-US\" hreflang=\"en-US\" href=\"/\"><img src=\"US\" alt=\"English\" />English</a></li>", 'en', 'English', 'en-US', 'Tiếng Việt', 'vi', ''),
    "<li class=\"lang-item lang-item-en\"><a lang=\"vi\" hreflang=\"vi\" href=\"/\">Tiếng Việt</a></li>");
check('switcher: a dropdown names it too',
    SourceLocaleHooks::switcherFor("\t<option value=\"/\" lang=\"en-US\" selected=\"selected\" data-lang=\"{}\">English</option>\n\t<option value=\"/de/\" lang=\"de-DE\" data-lang=\"{}\">Deutsch</option>\n", 'en', 'English', 'en-US', 'Tiếng Việt', 'vi', ''),
    "\t<option value=\"/\" lang=\"vi\" selected=\"selected\" data-lang=\"{}\">Tiếng Việt</option>\n\t<option value=\"/de/\" lang=\"de-DE\" data-lang=\"{}\">Deutsch</option>\n");
check('switcher: a name with markup in it is written as text',
    SourceLocaleHooks::switcherFor("<li class=\"lang-item lang-item-en\"><a lang=\"en-US\" hreflang=\"en-US\" href=\"/\">English</a></li>", 'en', 'English', 'en-US', '<b>x</b>', 'vi', ''),
    "<li class=\"lang-item lang-item-en\"><a lang=\"vi\" hreflang=\"vi\" href=\"/\">&lt;b&gt;x&lt;/b&gt;</a></li>");
check('switcher: nothing to name it, nothing changed',
    SourceLocaleHooks::switcherFor($item, 'en', 'English', 'en-US', '', 'vi', ''), $item);
check('switcher: the written language\'s code from its locale', SourceLocaleHooks::codeOf('de_DE_formal'), 'de-DE');
