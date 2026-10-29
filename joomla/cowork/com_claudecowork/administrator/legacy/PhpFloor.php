<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

/**
 * The oldest PHP the engine runs on, and the door's answer on an older one.
 *
 * The engine (`lib/`) and the Joomla 4+ classes are written for PHP 7.4 (typed properties, arrow
 * functions): on PHP 7.3 they do not even parse. A site whose host moved it to an older PHP after
 * the package was installed used to answer the door with Joomla's HTML error page — a 500 "Class
 * … EngineFactory not found" at the front door, Joomla's own 403 at the administrator (measured on
 * Joomla 3.10.6, PHP 7.3.33) — which a caller cannot tell from a broken site. Every door entry asks
 * this class first, before it loads any engine file, and on an older PHP answers JSON instead:
 *
 *     {"ok":false,"error":"php_too_old","code":"php_too_old","message":"…","php":"7.3.33","min":"7.4.0"}
 *
 * only to a caller holding the site's token, the same as every other answer; anyone else gets the
 * engine's own `unauthorized`. HTTP 200, like every engine answer, so a caller reads the code.
 *
 * Written so PHP 5.3 parses it — Joomla 3 runs on 5.3.10 and up — and loaded by every door entry
 * (Joomla 3's plugin entry and `controllers/api.php`, Joomla 4's `ApiController` and plugin provider)
 * before any other file of the component: the one file that has to run where nothing else does.
 */
final class ClaudeCoworkPhpFloor
{
    /** PHP_VERSION_ID of the oldest PHP the engine runs on. */
    const MINIMUM_ID = 70400;

    /** The same, as a person reads it. */
    const MINIMUM = '7.4.0';

    /** Whether this PHP (or the PHP_VERSION_ID given) is older than the engine needs. */
    public static function tooOld($versionId = null)
    {
        return ($versionId === null ? PHP_VERSION_ID : (int) $versionId) < self::MINIMUM_ID;
    }

    /**
     * Whether a request is the door: `lib/Door.php`'s rule (option `com_claudecowork`, task `api.exec`
     * in any case), copied so an entry can ask it on a PHP that cannot parse the engine's files.
     * tests/php-floor.php holds the two to the same answers.
     */
    public static function wants($option, $task)
    {
        return is_string($option) && is_string($task) && $option === 'com_claudecowork' && strtolower($task) === 'api.exec';
    }

    /** The sentence a site owner is shown: which PHP the site runs, which it needs, and what to do. */
    public static function message($php)
    {
        return 'This site runs PHP ' . $php . '; Claude Cowork needs PHP ' . self::MINIMUM
            . ' or newer. Ask your host to upgrade PHP on this site, then try again.';
    }

    /**
     * The door's answer on a PHP too old for the engine, for `$request` (the decoded JSON body) and
     * the token the component is configured with.
     */
    public static function reply($request, $configured, $php)
    {
        $configured = trim((string) $configured);
        $provided = is_array($request) && isset($request['token']) && is_string($request['token']) ? $request['token'] : '';
        if (strlen($configured) < 16 || $provided === '' || !self::same($configured, $provided)) {
            return array('ok' => false, 'error' => 'unauthorized', 'message' => 'invalid or missing token');
        }

        return array(
            'ok'      => false,
            'error'   => 'php_too_old',
            'code'    => 'php_too_old',
            'message' => self::message($php),
            'php'     => (string) $php,
            'min'     => self::MINIMUM,
        );
    }

    /**
     * Reads the request off Joomla's application, answers it and ends the response. `$app` is
     * Joomla's application (3 to 6); nothing of the engine is loaded.
     */
    public static function answer($app)
    {
        $raw = method_exists($app, 'getInput') ? $app->getInput()->json->getRaw() : $app->input->json->getRaw();
        $request = json_decode((string) $raw, true);
        $token = '';
        try {
            $token = \Joomla\CMS\Component\ComponentHelper::getParams('com_claudecowork')->get('token', '');
        } catch (\Exception $e) {
            $token = '';
        }

        @ini_set('display_errors', '0');
        $app->setHeader('Content-Type', 'application/json', true);
        $app->sendHeaders();
        echo json_encode(self::reply($request, $token, PHP_VERSION));
        $app->close();
    }

    /** hash_equals where PHP has it (5.6+); a constant-time compare where it does not. */
    private static function same($known, $given)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($known, $given);
        }
        if (strlen($known) !== strlen($given)) {
            return false;
        }
        $diff = 0;
        for ($i = 0, $n = strlen($known); $i < $n; $i++) {
            $diff |= ord($known[$i]) ^ ord($given[$i]);
        }
        return $diff === 0;
    }
}
