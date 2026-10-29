<?php
// The oldest PHP the engine runs on (7.4) and what an older one gets: the package refuses to install
// with a sentence naming both versions, and the door answers `php_too_old` JSON instead of an error
// page. Required from run.php (whose check() and checkTrue() it uses), or alone:
// php tests/php-floor.php
//
// Measured before (29/10/2026): installing on Joomla 3.10.6 / PHP 7.3.33 stopped at "syntax error,
// unexpected '?'" in script.php; with the package already there, the front door answered a 500 HTML
// page and the administrator Joomla's 403; on Joomla 4.4.14 / PHP 7.3.33 the plugin took every page
// of the site down with "syntax error, unexpected 'bool'".

if (!defined('_JEXEC')) define('_JEXEC', 1);
if (!function_exists('check')) {
    $pfFailed = 0;
    function check(string $what, $got, $want): void { global $pfFailed; if ($got === $want) { echo "  ok   $what\n"; return; } $pfFailed++; echo "  FAIL $what\n       got : " . json_encode($got) . "\n       want: " . json_encode($want) . "\n"; }
    function checkTrue(string $what, bool $got): void { check($what, $got, true); }
}
require_once __DIR__ . '/../com_claudecowork/administrator/legacy/PhpFloor.php';
require_once __DIR__ . '/../script.php';

check('php floor: 7.3.33 is too old, 7.4.0 and 8.5 are not',
    [ClaudeCoworkPhpFloor::tooOld(70333), ClaudeCoworkPhpFloor::tooOld(70400), ClaudeCoworkPhpFloor::tooOld(80500)], [true, false, false]);
check('php floor: the PHP running these tests is new enough', ClaudeCoworkPhpFloor::tooOld(), false);
check('php floor: the installer and the door name the same minimum', pkg_claudecoworkInstallerScript::PHP_MINIMUM, ClaudeCoworkPhpFloor::MINIMUM);

require_once __DIR__ . '/../lib/Door.php';
$pfAsked = [['com_claudecowork', 'api.exec'], ['com_claudecowork', 'API.EXEC'], ['com_claudecowork', 'api.execx'], ['com_content', 'api.exec'],
    [null, 'api.exec'], ['com_claudecowork', null], [['com_claudecowork'], 'api.exec'], ['COM_CLAUDECOWORK', 'api.exec']];
check('php floor: its door rule answers as lib/Door.php\'s', array_map(static function ($q) { return ClaudeCoworkPhpFloor::wants($q[0], $q[1]); }, $pfAsked),
    array_map(static function ($q) { return Door::wants(['option' => $q[0], 'task' => $q[1]]); }, $pfAsked));
$pfToken = str_repeat('t', 32);
check('php floor: a valid token learns why, with a stable code and both versions',
    ClaudeCoworkPhpFloor::reply(['token' => $pfToken, 'action' => 'info'], $pfToken, '7.2.34'),
    ['ok' => false, 'error' => 'php_too_old', 'code' => 'php_too_old',
        'message' => 'This site runs PHP 7.2.34; Claude Cowork needs PHP 7.4.0 or newer. Ask your host to upgrade PHP on this site, then try again.',
        'php' => '7.2.34', 'min' => '7.4.0']);
$pfUnauthorized = ['ok' => false, 'error' => 'unauthorized', 'message' => 'invalid or missing token'];
check('php floor: a wrong token gets the engine\'s own refusal', ClaudeCoworkPhpFloor::reply(['token' => 'wrong'], $pfToken, '7.2.34'), $pfUnauthorized);
check('php floor: so does a missing one', ClaudeCoworkPhpFloor::reply(['action' => 'info'], $pfToken, '7.2.34'), $pfUnauthorized);
check('php floor: and any token while the site has none configured (shorter than the engine accepts)', ClaudeCoworkPhpFloor::reply(['token' => 'short'], 'short', '7.2.34'), $pfUnauthorized);
check('php floor: a body that is no JSON object', ClaudeCoworkPhpFloor::reply(null, $pfToken, '7.2.34'), $pfUnauthorized);

check('installer: PHP 7.3.33 is refused, naming both versions and what to do',
    pkg_claudecoworkInstallerScript::phpRefusal('7.3.33'),
    'Claude Cowork needs PHP 7.4.0 or newer; this site runs PHP 7.3.33. Ask your host to upgrade PHP on this site, then install the package again.');
check('installer: PHP 7.4.0 and 8.3.1 install', [pkg_claudecoworkInstallerScript::phpRefusal('7.4.0'), pkg_claudecoworkInstallerScript::phpRefusal('8.3.1')], [null, null]);

// The files an old PHP has to parse for the above to happen at all. '5.3': what Joomla 3 parses
// before the PHP check, on any PHP from 5.3.10 (the installer shell, the floor, Joomla 3's door
// entries and component entries) — nothing newer than PHP 5.3. '7.1': what runs only on Joomla 4
// (PHP 7.2.5 and up) or after the check — no typed property, no arrow function, no `??=`. CI lints
// the 5.3 files on the oldest PHP it can run too; this keeps the rule beside the code.
$pfOldPhp = [
    'script.php' => '5.3', 'com_claudecowork/administrator/legacy/PhpFloor.php' => '5.3',
    'plg_system_claudecoworkapi/claudecoworkapi.php' => '5.3', 'com_claudecowork/site/controllers/api.php' => '5.3',
    'com_claudecowork/site/controllers/api.json.php' => '5.3', 'com_claudecowork/site/claudecowork.php' => '5.3',
    'com_claudecowork/site/controller.php' => '5.3',
    'com_claudecowork/administrator/legacy/LegacyDoor.php' => '7.1', 'script-install.php' => '7.1',
    'com_claudecowork/site/src/Controller/ApiController.php' => '7.1',
    'plg_system_claudecoworkapi/services/provider.php' => '7.1', 'plg_system_claudecoworkapi/src/Extension/PhpFloorDoor.php' => '7.1',
];
// PHP 5.4 and later, by token: what a PHP 5.3 file must not hold.
$pfLater = static function (array $sig): array {
    $found = [];
    $named = static function ($t, int $id): bool { return is_array($t) && $t[0] === $id; };
    $line = 0;
    foreach ($sig as $i => $t) {
        $prev = $sig[$i - 1] ?? null;
        if (is_array($t)) $line = $t[2];
        // `[` opening an array literal: not an index after a variable, a call, an index or a name.
        if ($t === '[' && !(is_array($prev) && in_array($prev[0], [T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING], true))
            && !in_array($prev, [']', ')', '}'], true)) $found[] = 'short array';
        foreach (['T_COALESCE' => '??', 'T_ELLIPSIS' => '...', 'T_FINALLY' => 'finally', 'T_YIELD' => 'yield', 'T_TRAIT' => 'trait', 'T_NULLSAFE_OBJECT_OPERATOR' => '?->', 'T_FN' => 'fn'] as $name => $what)
            if (defined($name) && $named($t, constant($name))) $found[] = $what;
        if ($named($t, T_CLASS) && $named($prev, T_DOUBLE_COLON)) $found[] = '::class';
        if ($named($t, T_FUNCTION) && $named($prev, T_STATIC) && ($sig[$i + 1] ?? null) === '(') $found[] = 'static closure';
        // A function's parameters and return: a scalar type, `?`, or `: type` after the list.
        if ($named($t, T_FUNCTION)) {
            $j = $i + 1;
            while (isset($sig[$j]) && $sig[$j] !== '(') $j++;
            for ($depth = 0; isset($sig[$j]); $j++) {
                if ($sig[$j] === '(') $depth++;
                if ($sig[$j] === ')' && --$depth === 0) break;
                if ($depth === 1 && is_array($sig[$j]) && $sig[$j][0] === T_STRING && in_array(strtolower($sig[$j][1]), ['string', 'int', 'float', 'bool', 'iterable', 'object', 'mixed'], true)
                    && $named($sig[$j + 1] ?? null, T_VARIABLE)) $found[] = 'scalar parameter type';
                if ($depth === 1 && $sig[$j] === '?') $found[] = 'nullable type';
            }
            if (($sig[$j + 1] ?? null) === ':') $found[] = 'return type';
        }
        foreach ($found as $k => $what) if (strpos($what, ' line ') === false) $found[$k] .= ' on line ' . $line;
    }
    return $found;
};
$pfModifiers = array_merge([T_STATIC], defined('T_READONLY') ? [constant('T_READONLY')] : []);
$pfTypes = array_merge([T_STRING, T_ARRAY], defined('T_NAME_QUALIFIED') ? [constant('T_NAME_QUALIFIED'), constant('T_NAME_FULLY_QUALIFIED')] : []);
foreach ($pfOldPhp as $pfFile => $pfPhp) {
    $pfTokens = token_get_all((string) file_get_contents(__DIR__ . '/../' . $pfFile));
    $pfFound = [];
    $pfSignificant = array_values(array_filter($pfTokens, static function ($t) { return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); }));
    foreach ($pfSignificant as $i => $t) {
        if (is_array($t) && defined('T_FN') && $t[0] === T_FN) $pfFound[] = 'fn on line ' . $t[2];
        if (is_array($t) && defined('T_COALESCE_EQUAL') && $t[0] === T_COALESCE_EQUAL) $pfFound[] = '??= on line ' . $t[2];
        // A property declaration whose modifiers are followed by a type before the variable.
        if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
            $j = $i + 1;
            while (isset($pfSignificant[$j]) && is_array($pfSignificant[$j]) && in_array($pfSignificant[$j][0], $pfModifiers, true)) $j++;
            $next = $pfSignificant[$j] ?? null;
            if ($next === '?' || (is_array($next) && in_array($next[0], $pfTypes, true)
                && is_array($pfSignificant[$j + 1] ?? null) && $pfSignificant[$j + 1][0] === T_VARIABLE)) $pfFound[] = 'typed property on line ' . $t[2];
        }
    }
    if ($pfPhp === '5.3') $pfFound = array_merge($pfFound, $pfLater($pfSignificant));
    check("old PHP: $pfFile has nothing PHP $pfPhp cannot parse", $pfFound, []);
}

if (isset($pfFailed) && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    echo $pfFailed ? "$pfFailed check(s) failed\n" : "every check passed\n";
    exit($pfFailed ? 1 : 0);
}
