<?php
/**
 * RenderedWordsHooks — where a page's rendered-words rules (RenderedWords) meet WordPress.
 *
 * On `template_redirect` at PHP_INT_MAX (after redirects, canonical fixes and the page caches'
 * own start), a public singular page answers its scope: the queried post and the locale the front
 * end speaks (Polylang's and SourceLocaleHooks' choice, never an admin's). Only when that scope's
 * option holds rules for the active theme is the response buffered; every other request is left
 * alone, with no buffer at all. The buffer is bounded: a page that flushes early or outgrows
 * RenderedWords::MAX_HTML goes out as it was printed.
 *
 * What the rules could not apply to (a drifted page) is noted, throttled, for `content.renderedWords
 * {operation:'status'}` to read; the page itself goes out unchanged.
 *
 * A request carrying a valid signed probe (RenderedWords::PROBE_HEADER, minted from the site token
 * by the door's inspection) gets the page WITHOUT the rules, kept out of page caches, and its scope
 * in a response header: what the door matches and checks rules against.
 */
declare(strict_types=1);

require_once __DIR__ . '/RenderedWords.php';

final class RenderedWordsHooks
{
    private const TOKEN_OPTION = 'claude_cowork_token';
    private const DRIFT_PREFIX = 'claude_cowork_rwd_';
    private const DRIFT_SECONDS = 600;

    /** @var array<string,mixed> */
    private array $set;
    private string $key;
    /** Set once output left the buffer before the page ended: the rest passes through. */
    private bool $passing = false;

    private function __construct(array $set, string $key)
    {
        $this->set = $set;
        $this->key = $key;
    }

    public static function register(): void
    {
        if (function_exists('add_action')) {
            add_action('template_redirect', [self::class, 'start'], PHP_INT_MAX);
        }
    }

    /** The active theme as rules remember it: a change of theme or of its version skips them. */
    public static function themeFingerprint(): string
    {
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        $version = is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Version') : '';
        $parts = [function_exists('get_stylesheet') ? (string) get_stylesheet() : '', function_exists('get_template') ? (string) get_template() : '', $version];
        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /** `template_redirect`: this request's scope, and a buffer only when that scope has rules. */
    public static function start(): void
    {
        if ((function_exists('is_admin') && is_admin()) || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (function_exists('wp_doing_cron') && wp_doing_cron()) || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI) || !in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
            return;
        }
        $probe = self::probed($_SERVER);
        if (!function_exists('is_singular') || !is_singular() || is_feed() || is_preview() || is_embed()
            || (function_exists('is_customize_preview') && is_customize_preview())) {
            return;
        }
        $post = (int) get_queried_object_id();
        $locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        if ($post <= 0 || !RenderedWords::validLocale($locale)) {
            return;
        }
        if ($probe) {
            ProvenanceStamps::keepOutOfPageCaches();
            if (!headers_sent()) {
                nocache_headers();
                foreach (ProvenanceStamps::headers() as [$line, $replace]) {
                    header($line, $replace);
                }
                header(RenderedWords::SCOPE_HEADER . ': ' . $post . ';' . $locale . ';' . self::themeFingerprint());
            }
            return;
        }
        if (strtoupper((string) get_option('blog_charset', 'UTF-8')) !== 'UTF-8') {
            return;
        }
        $key = RenderedWords::optionKey($post, $locale);
        $set = RenderedWords::decodeSet(get_option($key, ''));
        if ($set === [] || $set['rules'] === [] || $set['post'] !== $post || $set['locale'] !== $locale) {
            return;
        }
        $hooks = new self($set, $key);
        if ($set['theme'] !== self::themeFingerprint()) {
            $hooks->noteDrift(['the active theme changed since these rules were written']);
            return;
        }
        // The chunk size bounds the buffer: past it PHP flushes, and filter() lets the page through.
        ob_start([$hooks, 'filter'], RenderedWords::MAX_HTML);
    }

    /** Whether this request is the door's own signed probe. @param array<string,mixed> $server */
    public static function probed(array $server): bool
    {
        $value = $server['HTTP_' . strtoupper(str_replace('-', '_', RenderedWords::PROBE_HEADER))] ?? null;
        if (!is_string($value) || !function_exists('get_option')) {
            return false;
        }
        $token = trim((string) get_option(self::TOKEN_OPTION, ''));
        return strlen($token) >= 16 && RenderedWords::probeValid(RenderedWords::key($token), $value, time());
    }

    /** The output handler: the finished page with the rules, or as it was. */
    public function filter($buffer, $phase)
    {
        $buffer = (string) $buffer;
        if ($this->passing) {
            return $buffer;
        }
        $phase = (int) $phase;
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
            return $buffer;   // discarded by ob_clean/ob_end_clean: nothing goes out, nothing to do
        }
        if (($phase & PHP_OUTPUT_HANDLER_FINAL) === 0) {
            // Part of the page leaves before the rest exists: no rule can be checked against the whole.
            $this->passing = true;
            $this->noteDrift(['the page was sent before it ended (flushed, or larger than ' . RenderedWords::MAX_HTML . ' bytes)']);
            return $buffer;
        }
        if (!self::htmlAnswer(function_exists('http_response_code') ? http_response_code() : false, headers_list())
            || strncmp($buffer, "\x1f\x8b", 2) === 0) {
            return $buffer;
        }
        $result = RenderedWords::render($buffer, $this->set['rules']);
        if (!$result['applied']) {
            $this->noteDrift($result['problems']);
            return $buffer;
        }
        return $result['html'];
    }

    /** A 200 HTML answer, the only kind rules apply to. @param int|false $code @param list<string> $headers */
    public static function htmlAnswer($code, array $headers): bool
    {
        if ($code !== 200) {
            return false;
        }
        foreach ($headers as $line) {
            if (stripos($line, 'content-type:') === 0) {
                return stripos($line, 'text/html') !== false;
            }
        }
        return true;   // PHP's own default is text/html
    }

    /** Remember the latest drift of this scope, at most once per DRIFT_SECONDS. @param list<string> $problems */
    private function noteDrift(array $problems): void
    {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return;
        }
        try {
            $name = self::driftName((int) $this->set['post'], (string) $this->set['locale']);
            $seen = get_transient($name);
            $now = time();
            if (is_array($seen) && ($seen['revision'] ?? null) === $this->set['revision'] && (int) ($seen['at'] ?? 0) > $now - self::DRIFT_SECONDS) {
                return;
            }
            $problems = array_map(static fn($p): string => substr((string) $p, 0, 200), array_slice($problems, 0, 8));
            set_transient($name, ['at' => $now, 'revision' => $this->set['revision'], 'problems' => $problems], 86400);
        } catch (Throwable $ignored) {
            // A note that cannot be written must not change the page.
        }
    }

    public static function driftName(int $post, string $locale): string
    {
        return self::DRIFT_PREFIX . $post . '_' . $locale;
    }
}

/** The site the door works on: its own loopback, its options table, its page caches. Loaded with the engine. */
final class WordPressRenderedWordsSite implements RenderedWordsSite
{
    private const SECONDS = 10;
    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function probe(string $path): array
    {
        $answer = $this->get($path, [RenderedWords::PROBE_HEADER => RenderedWords::probeValue(RenderedWords::key($this->token), time())], true);
        $scope = explode(';', (string) ($answer['headers'][strtolower(RenderedWords::SCOPE_HEADER)] ?? ''));
        return ['code' => $answer['code'], 'body' => $answer['body'], 'post' => count($scope) === 3 ? (int) $scope[0] : 0,
            'locale' => count($scope) === 3 ? $scope[1] : '', 'theme' => count($scope) === 3 ? $scope[2] : ''];
    }

    public function visit(string $path): array
    {
        $answer = $this->get($path, [], false);
        return ['code' => $answer['code'], 'body' => $answer['body']];
    }

    public function theme(): string
    {
        return RenderedWordsHooks::themeFingerprint();
    }

    public function storedBytes(): int
    {
        global $wpdb;
        $like = $wpdb->esc_like(RenderedWords::OPTION_PREFIX) . '%';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
            $like, RenderedWords::RECEIPTS_OPTION));
    }

    public function purge(string $optionKey): void
    {
        Claude_Cowork_Site_Writer::purgePageCaches([['kind' => 'option', 'id' => 0, 'key' => $optionKey]]);
    }

    public function drift(int $post, string $locale): ?array
    {
        $seen = function_exists('get_transient') ? get_transient(RenderedWordsHooks::driftName($post, $locale)) : false;
        return is_array($seen) ? $seen : null;
    }

    /** One loopback GET of a site path, through the routes and the curl the derive's render check uses. */
    private function get(string $path, array $headers, bool $wantHeaders): array
    {
        $home = (string) home_url('/');
        $base = rtrim((string) parse_url($home, PHP_URL_PATH), '/') . '/';
        $page = strpos($path, $base) === 0 ? substr($path, strlen($base)) : ltrim($path, '/');
        $deadline = microtime(true) + self::SECONDS;
        foreach (LoopbackRoute::routes($home, function_exists('curl_init') && defined('CURLOPT_RESOLVE'), LoopbackRoute::tls()) as $route) {
            $left = (int) floor($deadline - microtime(true));
            if ($left < 1) {
                break;
            }
            $received = [];
            try {
                $answer = WordPressDerivedRows::httpGet($route['url'] . $page, ['Host' => $route['host']] + $headers, $left, $route['resolve'], $received);
            } catch (Throwable $e) {
                continue;
            }
            $code = (int) ($answer['code'] ?? 0);
            if (LoopbackRoute::outcome($code) === 'next') {
                continue;
            }
            return ['code' => $code, 'body' => (string) ($answer['body'] ?? ''), 'headers' => $wantHeaders ? $received : []];
        }
        return ['code' => 0, 'body' => '', 'headers' => []];
    }
}
