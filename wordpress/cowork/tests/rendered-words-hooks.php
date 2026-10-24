<?php
/**
 * Rendered words, the front end (lib/RenderedWordsHooks.php): a page with no rules for its scope gets no
 * buffer at all; a page with rules gets them whole or goes out as the theme printed it. Each case runs
 * in a fresh PHP process with WordPress stubbed, since output buffers and headers are process state.
 *
 * Loaded by run.php and by rendered-words-run.php after rendered-words.php (uses check(), $WTOKEN).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/RenderedWords.php';
require_once __DIR__ . '/../lib/RenderedWordsHooks.php';

echo "\nRendered words: the front end\n";

$rhPage = (string) file_get_contents(__DIR__ . '/fixtures/rendered-words/contact.html');
$rhTheme = substr(hash('sha256', 'wp-ja-diner|wp-ja-diner|1.0.4'), 0, 16);
$rhFound = RenderedWords::candidates($rhPage, [['kind' => 'text', 'old' => '(084) 555-0110'], ['kind' => 'attribute', 'name' => 'href', 'old' => 'http://joomlart.com']]);
$rhSet = static function (string $theme, int $links = 4) use ($rhFound): string {
    return RenderedWords::encodeSet(['schema' => 1, 'post' => 41, 'locale' => 'en_US', 'revision' => 3, 'theme' => $theme, 'rules' => RenderedWords::merge([], [
        ['kind' => 'text', 'anchor' => $rhFound[0]['candidates'][0]['anchor'], 'old' => '(084) 555-0110', 'new' => '+84 28 3822 0000', 'expected_matches' => 1],
        ['kind' => 'attribute', 'name' => 'href', 'anchor' => $rhFound[1]['candidates'][0]['anchor'], 'old' => 'http://joomlart.com', 'new' => 'tel:+842838220000', 'expected_matches' => $links],
    ], 'rw-1')]);
};
$rhWant = RenderedWords::render($rhPage, RenderedWords::decodeSet($rhSet($rhTheme))['rules'])['html'];

/**
 * One front-end request in a child process: the hook at template_redirect, then the theme prints the page.
 * Answers [buffers the hook opened, the page as sent, the drift noted or null].
 *
 * @param array<string,mixed> $options
 */
function rhRequest(array $options, string $page, string $extra = '', array $server = [], string $print = 'echo $page;', int $code = 200): array
{
    $code = 'declare(strict_types=1);'
        . '$GLOBALS["opts"] = ' . var_export($options, true) . '; $GLOBALS["transients"] = [];'
        . '$_SERVER = ' . var_export($server + ['REQUEST_METHOD' => 'GET'], true) . ' + $_SERVER;'
        . $extra
        . 'function add_action($h, $c, $p = 10) {}'
        . 'if (!function_exists("is_admin")) { function is_admin() { return false; } }'
        . 'function wp_doing_ajax() { return false; } function wp_doing_cron() { return false; }'
        . 'function is_singular() { return true; } function is_feed() { return false; } function is_preview() { return false; } function is_embed() { return false; }'
        . 'function get_queried_object_id() { return 41; } function determine_locale() { return "en_US"; }'
        . 'function get_option($k, $d = false) { return $GLOBALS["opts"][$k] ?? $d; }'
        . 'function get_stylesheet() { return "wp-ja-diner"; } function get_template() { return "wp-ja-diner"; }'
        . 'function wp_get_theme() { return new class { public function get($k) { return "1.0.4"; } }; }'
        . 'function get_transient($n) { return $GLOBALS["transients"][$n] ?? false; }'
        . 'function set_transient($n, $v, $t) { $GLOBALS["transients"][$n] = $v; return true; }'
        . 'function nocache_headers() { $GLOBALS["nocache"] = true; }'
        . 'require ' . var_export(__DIR__ . '/../lib/ProvenanceStamps.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../lib/RenderedWordsHooks.php', true) . ';'
        . 'http_response_code(' . $code . ');'
        . '$page = ' . var_export($page, true) . ';'
        . 'ob_start(); $base = ob_get_level();'
        . 'RenderedWordsHooks::start(); $opened = ob_get_level() - $base;'
        . $print
        . 'while (ob_get_level() > $base) { ob_end_flush(); }'
        . '$sent = ob_get_clean();'
        . 'echo json_encode([$opened, base64_encode($sent), array_values($GLOBALS["transients"])[0]["problems"] ?? null, $GLOBALS["nocache"] ?? false, $GLOBALS["removed"] ?? []]);';
    $out = (string) shell_exec(PHP_BINARY . ' -d display_errors=1 -d output_buffering=0 -r ' . escapeshellarg($code) . ' 2>&1');
    $got = json_decode($out, true);
    if (!is_array($got)) {
        return ['error' => substr($out, 0, 400)];
    }
    return [$got[0], base64_decode($got[1]), $got[2], $got[3], $got[4]];
}

$rhOptions = ['blog_charset' => 'UTF-8', 'claude_cowork_token' => $WTOKEN];
$rhKey = RenderedWords::optionKey(41, 'en_US');

[$opened, $sent, $drift] = rhRequest($rhOptions, $rhPage);
check('no rules for the scope: no buffer, the page byte-for-byte as printed, nothing noted', [$opened, $sent === $rhPage, $drift], [0, true, null]);
[$opened, $sent] = rhRequest($rhOptions + [RenderedWords::optionKey(41, 'vi') => $rhSet($rhTheme)], $rhPage);
check('rules for another locale of the page: no buffer', [$opened, $sent === $rhPage], [0, true]);
[$opened, $sent, $drift] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage);
check('rules for the scope: one buffer, the page with the rules applied', [$opened, $sent === $rhWant, $drift], [1, true, null]);
check('the applied page keeps the form and its nonce byte-for-byte', strpos($sent, '<input type="hidden" name="jm_nonce" value="0a1b2c3d4e">') !== false, true);
[$opened, $sent, $drift] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', [], 'echo substr($page, 0, 300); ob_flush(); echo substr($page, 300);');
check('a page flushed before it ends goes out as printed, the drift noted', [$opened, $sent === $rhPage, is_array($drift)], [1, true, true]);
[$opened, $sent, $drift] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme, 5)], $rhPage);
check('a page the rules no longer fit (4 links, not 5) goes out as printed, the drift noted', [$sent === $rhPage, $drift[0] ?? null],
    [true, 'rule ' . RenderedWords::decodeSet($rhSet($rhTheme, 5))['rules'][1]['id'] . ': expected 5 match(es), found 4']);
[$opened, $sent, $drift] = rhRequest($rhOptions + [$rhKey => $rhSet('another-theme-000')], $rhPage);
check('another active theme: no buffer, the drift noted', [$opened, $sent === $rhPage, $drift], [0, true, ['the active theme changed since these rules were written']]);
[$opened, $sent] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', [], 'echo $page;', 404);
check('a 404 answer is not rewritten', $sent === $rhPage, true);
[$opened, $sent] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', [], 'header("Content-Type: application/json"); echo $page;');
check('a page whose rules hold, served as another content type, is not rewritten (CLI keeps no headers: checked directly)',
    [RenderedWordsHooks::htmlAnswer(200, ['Content-Type: application/json']), RenderedWordsHooks::htmlAnswer(200, ['Content-Type: text/html; charset=UTF-8']), RenderedWordsHooks::htmlAnswer(200, [])],
    [false, true, true]);
[$opened, $sent] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', ['REQUEST_METHOD' => 'POST']);
check('a POST: no buffer', [$opened, $sent === $rhPage], [0, true]);
[$opened, $sent] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, 'function is_admin() { return true; }');
check('an admin request: no buffer', [$opened, $sent === $rhPage], [0, true]);
[$opened, $sent] = rhRequest(['blog_charset' => 'ISO-8859-1'] + $rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage);
check('a site not in UTF-8: no buffer', [$opened, $sent === $rhPage], [0, true]);
[$opened, $sent] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', [], 'echo $page; ob_clean(); echo "<p>error page</p>";');
check('a buffer the theme cleaned and replaced: only what was printed after, and rules that do not fit it leave it', $sent, '<p>error page</p>');

$rhProbe = ['HTTP_X_TRACY_RENDERED_WORDS_PROBE' => RenderedWords::probeValue(RenderedWords::key($WTOKEN), time())];
[$opened, $sent, $drift, $nocache] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', $rhProbe);
check('the door\'s signed probe: no buffer, the page without rules, kept out of caches', [$opened, $sent === $rhPage, $nocache], [0, true, true]);
[$opened, $sent, , $nocache] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', ['HTTP_X_TRACY_RENDERED_WORDS_PROBE' => 'pick']);
check('a forged probe is an ordinary visitor: the rules apply', [$opened, $sent === $rhWant, $nocache], [1, true, false]);
[$opened, $sent, , $nocache] = rhRequest(['claude_cowork_token' => 'short'] + $rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '',
    ['HTTP_X_TRACY_RENDERED_WORDS_PROBE' => RenderedWords::probeValue(RenderedWords::key('short'), time())]);
check('a probe on a site whose token is too short to sign with is an ordinary visitor', [$opened, $nocache], [1, false]);

// The answer's headers: the media type itself, the last header of a name, no encoded body.
check('a JSON answer that names text/html in a parameter is not HTML', RenderedWordsHooks::htmlAnswer(200, ['Content-Type: application/json; profile=text/html']), false);
check('text/html in any case, with parameters, is HTML', RenderedWordsHooks::htmlAnswer(200, ['content-type:  TEXT/HTML ; charset=UTF-8']), true);
check('the last Content-Type sent is the one that counts', [RenderedWordsHooks::htmlAnswer(200, ['Content-Type: text/html', 'Content-Type: text/plain']),
    RenderedWordsHooks::htmlAnswer(200, ['Content-Type: text/plain', 'Content-Type: text/html'])], [false, true]);
check('an encoded body is not rewritten; identity is no encoding', [RenderedWordsHooks::htmlAnswer(200, ['Content-Type: text/html', 'Content-Encoding: br']),
    RenderedWordsHooks::htmlAnswer(200, ['Content-Encoding: identity'])], [false, true]);

// A declared Content-Length is for the page as printed: dropped when the rules change the body, or the page goes as printed.
$rhHeaders = static fn(string $headers, string $sent): string => 'RenderedWordsHooks::useHeaderFunctions(static fn() => ' . $headers . ', static fn() => ' . $sent
    . ', static function ($n) { $GLOBALS["removed"][] = $n; }); echo $page;';
[$opened, $sent, $drift, , $removed] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', [], $rhHeaders('["Content-Type: text/html", "Content-Length: ' . strlen($rhPage) . '"]', 'false'));
check('Content-Length declared, headers not sent: the rules apply and the length is dropped', [$sent === $rhWant, $removed, $drift], [true, ['Content-Length'], null]);
[$opened, $sent, $drift, , $removed] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', [], $rhHeaders('["content-length: ' . strlen($rhPage) . '"]', 'true'));
check('Content-Length declared, headers already sent: the page as printed, the drift noted', [$sent === $rhPage, $removed, is_array($drift)], [true, [], true]);
[$opened, $sent, $drift, , $removed] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', [], $rhHeaders('["Content-Type: text/html"]', 'false'));
check('no Content-Length: the rules apply, no header touched', [$sent === $rhWant, $removed], [true, []]);
[$opened, $sent, , , $removed] = rhRequest($rhOptions + [$rhKey => $rhSet($rhTheme)], $rhPage, '', [], $rhHeaders('["Content-Type: application/json; profile=text/html"]', 'false'));
check('a JSON answer whose body matches the rules goes as printed', [$sent === $rhPage, $removed], [true, []]);
