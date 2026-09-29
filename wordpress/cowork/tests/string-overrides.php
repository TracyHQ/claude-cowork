<?php
/**
 * String overrides: words a theme or plugin prints through gettext (`__('Read More', 'astra')`),
 * replaced per locale from one option, written through `content.contract {operation:'string'}` under
 * an apply_id and taken back by `apply.revert`. On a quickstart site and a derived one alike; a site
 * with no override adds no filter at all.
 *
 * Loaded by run.php after derived-contract.php (uses contractSite(), `$SITE`, `$FIXTURES`).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/StringOverrides.php';

echo "\nString overrides\n";

// One callback per hook, as WP_Fake::$filters keeps them (apply_filters runs it).
if (!function_exists('add_filter')) {
    function add_filter(string $tag, $callback, int $priority = 10, int $args = 1): bool
    {
        WP_Fake::$filters[$tag] = $callback;
        $GLOBALS['soPriorities'][$tag] = [$priority, $args];
        return true;
    }
}

$soLocale = 'en_US';
$soRegister = static function () use (&$soLocale): void {
    WP_Fake::$filters = [];
    $GLOBALS['soPriorities'] = [];
    StringOverrides::register(static function () use (&$soLocale): string {
        return $soLocale;
    });
};

// No option, or an empty one: no filter, so every page is exactly what it was.
WP_Fake::reset();
$soRegister();
check('overrides: no option adds no gettext filter', [isset(WP_Fake::$filters['gettext']), isset(WP_Fake::$filters['gettext_with_context'])], [false, false]);
WP_Fake::$options[StringOverrides::OPTION] = '';
$soRegister();
check('overrides: an empty option adds none either', isset(WP_Fake::$filters['gettext']), false);
WP_Fake::$options[StringOverrides::OPTION] = '{}';
$soRegister();
check('overrides: nor an option holding no override', isset(WP_Fake::$filters['gettext']), false);

// One override: its domain and locale only.
WP_Fake::$options[StringOverrides::OPTION] = (string) json_encode(['en_US' => ['astra' => ["Read More" => 'Xem thêm', "button\u{4}Send" => 'Gửi đi']]]);
$soRegister();
check('overrides: the filters are added at priority 20', [$GLOBALS['soPriorities']['gettext'] ?? null, $GLOBALS['soPriorities']['gettext_with_context'] ?? null,
    $GLOBALS['soPriorities']['ngettext'] ?? null, $GLOBALS['soPriorities']['ngettext_with_context'] ?? null], [[20, 3], [20, 4], [20, 5], [20, 6]]);
check('overrides: the string is replaced in its domain and locale', apply_filters('gettext', 'Read More', 'Read More', 'astra'), 'Xem thêm');
check('overrides: another string is left as translated', apply_filters('gettext', 'Weiterlesen', 'Continue', 'astra'), 'Weiterlesen');
check('overrides: the same string of another domain is left', apply_filters('gettext', 'Read More', 'Read More', 'woocommerce'), 'Read More');
check('overrides: a string with a context is matched with that context', [apply_filters('gettext_with_context', 'Send', 'Send', 'button', 'astra'),
    apply_filters('gettext_with_context', 'Send', 'Send', 'verb', 'astra'), apply_filters('gettext', 'Send', 'Send', 'astra')], ['Gửi đi', 'Send', 'Send']);
$soLocale = 'de_DE';
$soRegister();
check('overrides: another locale is left', apply_filters('gettext', 'Mehr lesen', 'Read More', 'astra'), 'Mehr lesen');
$soLocale = 'en_US';
// Plural strings: each form is its own msgid; the number picks which one is looked up.
WP_Fake::$options[StringOverrides::OPTION] = (string) json_encode(['en_US' => ['astra' => ['%s comment' => '%s bình luận', '%s comments' => '%s bình luận nữa',
    "list\u{4}%s item" => '%s mục']]]);
$soRegister();
check('overrides: ngettext looks up the singular for one and the plural otherwise',
    [apply_filters('ngettext', '%s comment', '%s comment', '%s comments', 1, 'astra'), apply_filters('ngettext', '%s comments', '%s comment', '%s comments', 5, 'astra')],
    ['%s bình luận', '%s bình luận nữa']);
check('overrides: ngettext_with_context keys on the context too, and a form with no override is left',
    [apply_filters('ngettext_with_context', '%s item', '%s item', '%s items', 1, 'list', 'astra'), apply_filters('ngettext_with_context', '%s items', '%s item', '%s items', 2, 'list', 'astra')],
    ['%s mục', '%s items']);
// The locale is asked once per request, and never for a domain with no override.
$soAsked = 0;
WP_Fake::$filters = [];
StringOverrides::register(static function () use (&$soAsked): string {
    $soAsked++;
    return 'en_US';
});
apply_filters('gettext', 'Hello', 'Hello', 'woocommerce');
apply_filters('gettext', 'Bye', 'Bye', 'default');
check('overrides: a domain with no override never asks the locale', $soAsked, 0);
apply_filters('gettext', '%s comment', '%s comment', 'astra');
check('overrides: an overridden domain asks the locale', $soAsked, 1);
// switch_to_locale() mid-request (an e-mail in the reader's language): the next lookup follows it.
WP_Fake::$options[StringOverrides::OPTION] = (string) json_encode(['en_US' => ['astra' => ['Read More' => 'Xem thêm']], 'de_DE' => ['astra' => ['Read More' => 'Weiter']]]);
$soSwitched = 'en_US';
WP_Fake::$filters = [];
StringOverrides::register(static function () use (&$soSwitched): string {
    return $soSwitched;
});
$soFirst = apply_filters('gettext', 'Read More', 'Read More', 'astra');
$soSwitched = 'de_DE';
check('overrides: a locale switched mid-request is followed', [$soFirst, apply_filters('gettext', 'Read More', 'Read More', 'astra')], ['Xem thêm', 'Weiter']);

// The door: content.contract {operation:'string'}, on a quickstart site, bound.
$soSite = contractSite($SITE, $FIXTURES, false, 'test-design/wp7/1.0.0');
$soEngine = $soSite['engine'];
$soDoor = static fn(array $params) => $soEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'string'] + $params]);
$soEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'bind']]);
$soBinding = WP_Fake::$options[QuickstartContract::STORE_OPTION] ?? null;
$soWrite = $soDoor(['apply_id' => 'so-1', 'domain' => 'astra', 'msgid' => 'Read More', 'locale' => 'en_US', 'value' => 'Xem thêm']);
check('door: a string override is written', [$soWrite['ok'] ?? $soWrite, json_decode((string) WP_Fake::$options[StringOverrides::OPTION], true)],
    [true, ['en_US' => ['astra' => ['Read More' => 'Xem thêm']]]]);
check('door: under its apply_id, as a content write of the option', array_map(static fn($e) => [$e['op'], $e['kind'], $e['key'], $e['before']], $soSite['log']->entries('so-1')),
    [['content', 'option', StringOverrides::OPTION, null]]);
check('door: the quickstart binding is untouched', WP_Fake::$options[QuickstartContract::STORE_OPTION] ?? null, $soBinding);
$soRegister();
check('door: and the site shows it', apply_filters('gettext', 'Read More', 'Read More', 'astra'), 'Xem thêm');
$soSecond = $soDoor(['apply_id' => 'so-2', 'domain' => 'astra', 'msgid' => 'Send', 'context' => 'button', 'locale' => 'en_US', 'value' => 'Gửi']);
check('door: a second override, with a context, sits beside the first', [$soSecond['ok'] ?? $soSecond, json_decode((string) WP_Fake::$options[StringOverrides::OPTION], true)],
    [true, ['en_US' => ['astra' => ['Read More' => 'Xem thêm', "button\u{4}Send" => 'Gửi']]]]);
$soReverted = $soEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'so-2']]);
check('revert: apply.revert takes the second back', [$soReverted['ok'] ?? $soReverted, json_decode((string) WP_Fake::$options[StringOverrides::OPTION], true)],
    [true, ['en_US' => ['astra' => ['Read More' => 'Xem thêm']]]]);
$soRemove = $soDoor(['apply_id' => 'so-3', 'domain' => 'astra', 'msgid' => 'Read More', 'locale' => 'en_US', 'value' => '']);
check('door: an empty value removes the override, and an empty map leaves no filter', [$soRemove['ok'] ?? $soRemove, StringOverrides::decode(WP_Fake::$options[StringOverrides::OPTION] ?? '')], [true, []]);
$soRegister();
check('door: so the site adds no filter again', isset(WP_Fake::$filters['gettext']), false);
$soEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'so-3']]);
$soEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'so-1']]);
check('revert: taking the first back leaves the site with no option at all', array_key_exists(StringOverrides::OPTION, WP_Fake::$options), false);
foreach ([
    'no apply_id' => ['domain' => 'astra', 'msgid' => 'Read More', 'locale' => 'en_US', 'value' => 'x'],
    'no msgid' => ['apply_id' => 'so-x', 'domain' => 'astra', 'locale' => 'en_US', 'value' => 'x'],
    'a bad locale' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'Read More', 'locale' => 'english', 'value' => 'x'],
    'a bad domain' => ['apply_id' => 'so-x', 'domain' => 'astra/../x', 'msgid' => 'Read More', 'locale' => 'en_US', 'value' => 'x'],
    'markup the string does not have' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'Read More', 'locale' => 'en_US', 'value' => '<script>x</script>'],
    'a script where the string has other markup' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'Read <strong>more</strong>', 'locale' => 'en_US', 'value' => 'Read <script>x</script>'],
    'an attribute the string does not use' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => 'See <a href="%s" onerror="x()">terms</a>'],
    'a tag the string lacks' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => 'See <a href="%s"><img src="x">terms</a>'],
    'a script link' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => 'See <a href="javascript:alert(1)">%s</a>'],
    'a script link spelled with an entity and no semicolon' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => 'See <a href="&#106avascript:alert(1)">%s</a>'],
    'a script link split by an encoded tab' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => 'See <a href="java&#x09;script:alert(1)">%s</a>'],
    'a script link split by an encoded newline' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => 'See <a href="jav&#10;ascript:alert(1)">%s</a>'],
    'a script link split by a raw tab' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => "See <a href=\"java\tscript:alert(1)\">%s</a>"],
    'a protocol-relative off-site link' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => 'See <a href="//evil.com">%s</a>'],
    'a backslash off-site link' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%s">terms</a>', 'locale' => 'en_US', 'value' => 'See <a href="/\\evil.com">%s</a>'],
    'a link to another placeholder' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'See <a href="%1$s">%2$s</a>', 'locale' => 'en_US', 'value' => 'Xem <a href="%3$s">%2$s</a>%1$s'],
    'a stray angle bracket' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'Read <strong>more</strong>', 'locale' => 'en_US', 'value' => 'Read < more'],
    'a percent printf would read as a placeholder' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => 'Sale', 'locale' => 'en_US', 'value' => 'Save 50% off'],
    'a lone percent' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => '%s done', 'locale' => 'en_US', 'value' => '%s xong 100%'],
    'a dropped placeholder' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => '%s comments', 'locale' => 'en_US', 'value' => 'bình luận'],
    'another placeholder' => ['apply_id' => 'so-x', 'domain' => 'astra', 'msgid' => '%s comments', 'locale' => 'en_US', 'value' => '%d bình luận'],
    'a contract- apply_id' => ['apply_id' => 'contract-so', 'domain' => 'astra', 'msgid' => 'Read More', 'locale' => 'en_US', 'value' => 'x'],
] as $soWhy => $soParams) {
    $soBad = $soDoor($soParams);
    check('door: refuses ' . $soWhy, [$soBad['ok'], $soBad['error'] ?? null], [false, 'bad_params']);
}
check('door: a refusal writes nothing', array_key_exists(StringOverrides::OPTION, WP_Fake::$options), false);
foreach ([
    'the same placeholder' => ['%s comments', '%s bình luận'],
    'reordered positional placeholders' => ['%1$s by %2$s', '%2$s của %1$s'],
    'a literal percent written %%' => ['Save %s', 'Giảm %s, tới 50%%'],
    'the markup the string itself has' => ['Read <strong>more</strong>', 'Đọc <strong>thêm</strong>'],
    'the link the string itself has' => ['See <a href="%s">terms</a>', 'Xem <a href="%s">điều khoản</a>'],
    'an https link where the string has a link' => ['See <a href="%1$s">%2$s</a>', 'Xem <a href="https://northwind.test/terms?x=1">%2$s</a> %1$s'],
] as $soWhy => [$soMsgid, $soValue]) {
    $soOk = $soDoor(['apply_id' => 'so-ok', 'domain' => 'astra', 'msgid' => $soMsgid, 'locale' => 'en_US', 'value' => $soValue]);
    check('door: accepts ' . $soWhy, $soOk['ok'] ?? $soOk, true);
}
check('door: accepted values are stored as sent', StringOverrides::decode(WP_Fake::$options[StringOverrides::OPTION] ?? '')['en_US']['astra']['%1$s by %2$s'] ?? null, '%2$s của %1$s');
$soEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'so-ok']]);
check('revert: one apply_id holding several writes takes them all back', array_key_exists(StringOverrides::OPTION, WP_Fake::$options), false);
$soUpdate = $soEngine->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'so-u', 'kind' => 'option', 'key' => StringOverrides::OPTION, 'fields' => ['value' => '{}']]]);
check('door: content.update cannot reach the option', [$soUpdate['ok'], $soUpdate['error'] ?? null], [false, 'bad_params']);

// The same door on a derived site.
$dwSeed();
$soDerivedWriter = new Claude_Cowork_Site_Writer();
$soDerivedLog = new FakeApplyLog();
$dwEngineOf($soDerivedWriter, $soDerivedLog, $dwContractOf($soDerivedWriter), $dwPages)->handle($dwDerive('derive-strings'));
$soDerived = $dwEngineOf($soDerivedWriter, $soDerivedLog, $dwContractOf($soDerivedWriter), $dwPages);
$soOnDerived = $soDerived->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'string', 'apply_id' => 'so-d', 'domain' => 'northwind',
    'msgid' => 'Book now', 'locale' => 'en_US', 'value' => 'Reserve']]);
check('derived: the string door works there too', [$soOnDerived['ok'] ?? $soOnDerived, StringOverrides::decode(WP_Fake::$options[StringOverrides::OPTION] ?? '')],
    [true, ['en_US' => ['northwind' => ['Book now' => 'Reserve']]]]);
$soBack = $soDerived->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'so-d']]);
check('derived: and apply.revert takes it back', [$soBack['ok'] ?? $soBack, array_key_exists(StringOverrides::OPTION, WP_Fake::$options)], [true, false]);
$GLOBALS['wpdb'] = $dwPrevDb;
WP_Fake::$filters = [];
