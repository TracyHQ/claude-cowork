<?php
// The offline lock: Tracy turns a site offline while a brief build writes it, keeps it offline when
// the build fails, and still reads the site as a visitor would while it is offline.
//
// 1. `site.identity` sets and clears `offline`, `offline_message` and `display_offline_message`
//    through a real configuration.php (the helpers of tests/site-identity.php), under an apply_id
//    `apply.revert` takes back, and refuses every value Joomla's form would not save.
// 2. `info` advertises `site_offline` only on an engine that has both the door and the bypass.
// 3. The bypass rule (lib/SiteOffline.php), and the shipped system plugin itself against Joomla
//    stand-ins, each request in its own PHP process: the site's token as a Bearer header is served
//    the site, kept out of every cache; no header, a wrong token, the token in the query string, a
//    Basic header or a site with no token get the offline page; the administrator is untouched.
//    Which page Joomla prints is decided as Joomla's SiteApplication::render decides it: offline.php
//    while `offline` is set and the visitor (anonymous here) lacks core.login.offline.
//
// Loaded by run.php after tests/site-identity.php, whose helpers it uses.

namespace {
    if (isset($argv) && ($argv[1] ?? null) === '--site-offline-case' && isset($argv[2])
        && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        define('SO_CASE', json_decode($argv[2], true));
        define('_JEXEC', 1);
    }
}

namespace Joomla\Event {
    if (\defined('SO_CASE')) {
        interface SubscriberInterface { public static function getSubscribedEvents(): array; }
        interface EventInterface { public function getArgument($name, $default = null); }
    }
}

namespace Joomla\CMS\Log {
    if (\defined('SO_CASE')) {
        final class Log
        {
            public const WARNING = 16;
            public static function add($entry, int $priority = 64, string $category = ''): void { \SoRecord::$logs[] = (string) $entry; }
        }
    }
}

namespace Joomla\CMS\Plugin {
    if (\defined('SO_CASE')) {
        abstract class CMSPlugin
        {
            private $app;
            public function __construct($subject = null, array $config = []) {}
            public function setApplication($app): void { $this->app = $app; }
            public function getApplication() { return $this->app; }
        }
    }
}

namespace Joomla\CMS\Component {
    if (\defined('SO_CASE')) {
        final class ComponentHelper
        {
            public static function getParams(string $component) { return new \SoParams(['token' => SO_CASE['token']]); }
        }
    }
}

namespace Joomla\CMS {
    if (\defined('SO_CASE')) {
        final class Factory
        {
            public static $config;
            public static function getConfig() { return self::$config; }
        }
    }
}

namespace Joomla\CMS\Uri {
    if (\defined('SO_CASE')) {
        final class Uri { public static function root(bool $pathOnly = false): string { return ''; } }
    }
}

namespace Tracy\Component\ClaudeCowork\Administrator\Service {
    if (\defined('SO_CASE')) {
        /** The component's wiring, as far as the plugin asks it: the real lib/ files, and no door answered. */
        final class EngineFactory
        {
            public static function installed(): bool { return true; }
            public static function loadEngine(): void { require_once __DIR__ . '/../lib/Door.php'; }
            public static function loadRenderStamps(): void { require_once __DIR__ . '/../lib/RenderStamps.php'; }
            public static function loadSiteOffline(): void { require_once __DIR__ . '/../lib/SiteOffline.php'; }
            public static function answer($app): void { \SoRecord::$logs[] = 'door answered'; }
            public static function answerContent($app): void { \SoRecord::$logs[] = 'content answered'; }
        }
    }
}

namespace {
    if (\defined('SO_CASE')) {
        final class SoRecord { public static array $logs = []; }

        /** A registry: Joomla's application configuration, and Factory::getConfig(). */
        final class SoParams
        {
            public array $values;
            public function __construct(array $values) { $this->values = $values; }
            public function get(string $key, $default = null) { return $this->values[$key] ?? $default; }
            public function set(string $key, $value): void { $this->values[$key] = $value; }
        }

        final class SoServer { public function getString(string $key, $default = '') { return is_string($_SERVER[$key] ?? null) ? $_SERVER[$key] : $default; } }

        final class SoInput
        {
            public SoServer $server;
            public function __construct() { $this->server = new SoServer(); }
            public function get(string $name, $default = null, string $filter = 'cmd') { return $_GET[$name] ?? $default; }
        }

        final class SoApp
        {
            public SoParams $config;
            public string $client;
            public ?bool $cacheAllowed = null;
            public function __construct(string $client, array $config) { $this->client = $client; $this->config = new SoParams($config); }
            public function isClient(string $client): bool { return $this->client === $client; }
            public function get(string $key, $default = null) { return $this->config->get($key, $default); }
            public function set(string $key, $value): void { $this->config->set($key, $value); }
            public function getInput(): SoInput { return new SoInput(); }
            public function allowCache(bool $allow): void { $this->cacheAllowed = $allow; }
            public function setHeader(string $name, string $value, bool $replace = false): void {}
        }

        final class SoEvent implements \Joomla\Event\EventInterface
        {
            public array $results = [];
            public function getArgument($name, $default = null) { return $default; }
            public function addResult($value): void { $this->results[] = $value; }
        }

        $case = SO_CASE;
        $_SERVER = ['REQUEST_URI' => $case['uri'] ?? '/', 'REQUEST_METHOD' => 'GET'] + ($case['server'] ?? []);
        parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $_GET);
        $config = ['offline' => $case['offline'], 'caching' => 2] + (isset($case['previewHash']) ? ['tracy_offline_preview_key' => $case['previewHash']] : []);
        $app = new SoApp($case['client'], $config);
        \Joomla\CMS\Factory::$config = new SoParams($config);

        require __DIR__ . '/../plg_system_claudecoworkapi/src/Extension/ClaudeCoworkApi.php';
        $plugin = new \Tracy\Plugin\System\ClaudeCoworkApi\Extension\ClaudeCoworkApi();
        $plugin->setApplication($app);
        $plugin->onAfterInitialise();
        $setCaching = new SoEvent();
        $plugin->onPageCacheSetCaching($setCaching);
        $excluded = new SoEvent();
        $plugin->onPageCacheIsExcluded($excluded);
        $plugin->onAfterRender();

        // SiteApplication::render: offline.php while `offline` is set and the visitor may not log in offline.
        $page = $app->isClient('site') && $app->get('offline') ? 'offline' : 'site';
        echo json_encode([
            'page' => $page,
            'offline' => $app->get('offline'),
            'globalOffline' => \Joomla\CMS\Factory::$config->get('offline'),
            'caching' => [$app->get('caching'), \Joomla\CMS\Factory::$config->get('caching')],
            'pageCache' => [$setCaching->results, $excluded->results],
            'cacheAllowed' => $app->cacheAllowed,
            'logs' => SoRecord::$logs,
        ]);
        exit(0);
    }
}

// ---------------------------------------------------------------- run by run.php ------------------
namespace {
    if (function_exists('check') && !\defined('SO_CASE')) {
        require_once __DIR__ . '/../lib/SiteOffline.php';
        // The configuration helpers (siFixture, siSite, siLoad); already loaded, and its checks run, under run.php.
        require_once __DIR__ . '/site-identity.php';
        $soToken = 'offline-token-at-least-16';
        $soDir = sys_get_temp_dir() . '/cowork-site-offline-' . bin2hex(random_bytes(6));
        mkdir($soDir, 0777, true);
        $soCall = static fn(Engine $engine, array $params) => $engine->handle(['token' => $soToken, 'action' => 'site.identity', 'params' => $params]);

        // ---- what a value may be -------------------------------------------------------------
        check('offline takes 0 or 1, as an integer or the digit, and stores the integer',
            [SiteIdentity::clean('offline', 1), SiteIdentity::clean('offline', '0')], [['value' => 1], ['value' => 0]]);
        $soOfflineError = ['error' => 'offline must be 0 (site online) or 1 (site offline)'];
        check('any other offline value is refused: 2, a word, a boolean, a float, null',
            [SiteIdentity::clean('offline', 2), SiteIdentity::clean('offline', 'yes'), SiteIdentity::clean('offline', true), SiteIdentity::clean('offline', 1.0), SiteIdentity::clean('offline', null)],
            array_fill(0, 5, $soOfflineError));
        check('display_offline_message takes 0, 1 or 2',
            [SiteIdentity::clean('display_offline_message', 0), SiteIdentity::clean('display_offline_message', '2')], [['value' => 0], ['value' => 2]]);
        check('and refuses anything else', isset(SiteIdentity::clean('display_offline_message', 3)['error']), true);
        check('the offline message keeps its lines and is stored as the escaped HTML Joomla prints',
            SiteIdentity::clean('offline_message', "  We are rebuilding <b>Lakeside Bakery</b> & Co.\r\n\r\nBack <i>soon</i>.<br/>Thanks  "),
            ['value' => 'We are rebuilding Lakeside Bakery &amp; Co.<br><br>Back soon.<br>Thanks']);
        $soDefault = 'This site is down for maintenance.<br>Please check back again soon.';
        check('Joomla\'s own default message cleans to itself', SiteIdentity::clean('offline_message', $soDefault), ['value' => $soDefault]);
        $soOnce = SiteIdentity::clean('offline_message', 'Tom\'s "Bakery" <script>x</script>& more')['value'];
        check('cleaning a stored message again gives the same value', SiteIdentity::clean('offline_message', $soOnce)['value'], $soOnce);
        checkTrue('no tag survives, an encoded one included', strpos(SiteIdentity::clean('offline_message', '&lt;script&gt;alert(1)&lt;/script&gt;Closed')['value'], '<script') === false);
        check('an empty message is allowed', SiteIdentity::clean('offline_message', ' <br> '), ['value' => '']);
        check('a message past 1000 characters is refused', SiteIdentity::clean('offline_message', str_repeat('a', 1001)), ['error' => 'offline_message is longer than 1000 characters (1001 after cleaning)']);
        check('a message that is not a string is refused', SiteIdentity::clean('offline_message', ['x']), ['error' => 'offline_message must be a string']);
        check('a configuration\'s boolean offline reads as the integer', [SiteIdentity::typed('offline', true), SiteIdentity::typed('offline', false), SiteIdentity::typed('offline', '1')], [1, 0, 1]);
        check('a missing display_offline_message reads as Joomla\'s default, 1', SiteIdentity::typed('display_offline_message', null), 1);

        // ---- the door: set, clear, revert, through a real file -------------------------------
        $soConfig = siFixture();
        [$soPath, $soStore] = siSite($soDir, 'configuration.php', $soConfig);
        $soLog = new FakeApplyLog();
        $soWriter = new FakeSiteWriter();
        $soEngine = (new Engine($soToken, [], null, null, null, null, $soWriter, null, $soLog))->siteIdentity($soStore);
        $soBytes = (string) file_get_contents($soPath);

        $soSet = static fn(array $fields, string $apply) => $soCall($soEngine, ['operation' => 'set', 'apply_id' => $apply, 'fields' => $fields]);
        check('a bad offline value is refused by the door', [$soSet(['offline' => 2], 'apply-lock')['error'], $soSet(['offline' => true], 'apply-lock')['error']], ['bad_params', 'bad_params']);
        check('a bad display value is refused', $soSet(['offline' => 1, 'display_offline_message' => 5], 'apply-lock')['error'], 'bad_params');
        check('the offline image is not one of the door\'s keys', $soSet(['offline' => 1, 'offline_image' => 'images/x.png'], 'apply-lock')['error'], 'unsupported');
        check('a refused set wrote nothing and recorded nothing', [file_get_contents($soPath) === $soBytes, $soLog->entries('apply-lock')], [true, []]);

        $soLock = $soSet(['offline' => 1, 'offline_message' => "Lakeside Bakery is being rebuilt.\nBack shortly.", 'display_offline_message' => '1'], 'apply-lock');
        check('a set turns the site offline with the message', [$soLock['ok'], $soLock['changed'], $soLock['fields']['offline'], $soLock['fields']['offline_message']],
            [true, ['offline', 'offline_message'], 1, 'Lakeside Bakery is being rebuilt.<br>Back shortly.']);
        $soLoaded = siLoad($soPath);
        check('the file PHP loads is offline, as an integer, with the escaped message, every other key untouched', $soLoaded,
            array_merge($soConfig, ['offline' => 1, 'offline_message' => 'Lakeside Bakery is being rebuilt.<br>Back shortly.']));
        check('one undo step holding what was there', $soLog->entries('apply-lock'),
            [['op' => 'siteIdentity', 'before' => ['offline' => 0, 'offline_message' => $soConfig['offline_message']]]]);
        check('apply.list names the fields, never the words', $soEngine->handle(['token' => $soToken, 'action' => 'apply.list', 'params' => ['apply_id' => 'apply-lock']])['steps'],
            [['op' => 'siteIdentity', 'created' => false, 'fields' => ['offline', 'offline_message']]]);
        check('the cache is purged: no cached page is served past the lock', $soWriter->purges, 1);
        check('a read sees the lock', array_intersect_key($soCall($soEngine, [])['fields'], array_flip(SiteIdentity::OFFLINE_FIELDS)),
            ['offline' => 1, 'offline_message' => 'Lakeside Bakery is being rebuilt.<br>Back shortly.', 'display_offline_message' => 1]);
        check('the same lock again writes and records nothing', [$soSet(['offline' => 1], 'apply-lock')['unchanged'] ?? null, count($soLog->entries('apply-lock'))], [true, 1]);

        $soOpen = $soSet(['offline' => 0], 'apply-open');
        check('a set of offline 0 clears the lock', [$soOpen['changed'], siLoad($soPath)['offline'] ?? null], [['offline'], 0]);
        $soEngine->handle(['token' => $soToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-open']]);
        check('reverting the clear puts the lock back', siLoad($soPath)['offline'] ?? null, 1);

        check('apply.revert takes the lock back', $soEngine->handle(['token' => $soToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-lock']]), ['ok' => true, 'reverted' => 1]);
        check('the site is online with its own message again (offline as the integer Joomla\'s form saves)', siLoad($soPath), array_merge($soConfig, ['offline' => 0]));
        $soLog->record('apply-forged-lock', ['op' => 'siteIdentity', 'before' => ['offline' => 'on', 'display_offline_message' => 9]]);
        $soEngine->handle(['token' => $soToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-forged-lock']]);
        check('an undo row holding values Joomla does not offer writes Joomla\'s defaults', array_intersect_key(siLoad($soPath), ['offline' => 1, 'display_offline_message' => 1]),
            ['offline' => 0, 'display_offline_message' => 1]);

        // ---- the preview key: write-only, cleared with the lock --------------------------------
        $soKey = 'preview_Key-0123456789abcdefghijKLMN';
        $soHash = hash('sha256', $soKey);
        check('a preview key is 32 to 128 URL-safe characters, stored as its SHA-256',
            [SiteIdentity::clean('offline_preview_key', $soKey), isset(SiteIdentity::clean('offline_preview_key', str_repeat('a', 128))['value'])], [['value' => $soHash], true]);
        $soKeyError = ['error' => 'offline_preview_key must be 32 to 128 characters of A-Z, a-z, 0-9, _ and -'];
        check('too short, too long, a space, a dot, a number or null is refused',
            [SiteIdentity::clean('offline_preview_key', str_repeat('a', 31)), SiteIdentity::clean('offline_preview_key', str_repeat('a', 129)), SiteIdentity::clean('offline_preview_key', str_repeat('a', 31) . ' '),
                SiteIdentity::clean('offline_preview_key', str_repeat('a', 31) . '.'), SiteIdentity::clean('offline_preview_key', 12345678901234567890), SiteIdentity::clean('offline_preview_key', null)],
            array_fill(0, 6, $soKeyError));
        $soKeyBytes = (string) file_get_contents($soPath);
        check('the door refuses a bad key and writes nothing', [$soSet(['offline' => 1, 'offline_preview_key' => 'short'], 'apply-key')['error'], file_get_contents($soPath) === $soKeyBytes], ['bad_params', true]);
        check('a read says no key is set', $soCall($soEngine, [])['fields']['offline_preview_key_set'] ?? null, false);
        $soKeyed = $soSet(['offline' => 1, 'offline_preview_key' => $soKey], 'apply-key');
        check('a set locks the site with a key, naming the key as changed and never answering it',
            [$soKeyed['ok'], $soKeyed['changed'], $soKeyed['fields']['offline_preview_key_set'] ?? null, array_key_exists('offline_preview_key', $soKeyed['fields']), str_contains(json_encode($soKeyed), $soKey), str_contains(json_encode($soKeyed), $soHash)],
            [true, ['offline', 'offline_preview_key'], true, false, false, false]);
        check('configuration.php holds the hash, never the key', [siLoad($soPath)['tracy_offline_preview_key'] ?? null, str_contains((string) file_get_contents($soPath), $soKey)], [$soHash, false]);
        $soRead = $soCall($soEngine, []);
        check('a read answers only whether a key is set', [$soRead['fields']['offline_preview_key_set'] ?? null, str_contains(json_encode($soRead), $soHash)], [true, false]);
        check('the undo step and apply.list name the key, the listing never its hash',
            [$soLog->entries('apply-key'), $soEngine->handle(['token' => $soToken, 'action' => 'apply.list', 'params' => ['apply_id' => 'apply-key']])['steps']],
            [[['op' => 'siteIdentity', 'before' => ['offline' => 0, 'tracy_offline_preview_key' => '']]], [['op' => 'siteIdentity', 'created' => false, 'fields' => ['offline', 'offline_preview_key']]]]);
        check('a key with offline 0 is refused', $soSet(['offline' => 0, 'offline_preview_key' => $soKey], 'apply-key-open')['error'], 'bad_params');
        $soKeyOpen = $soSet(['offline' => 0], 'apply-key-open');
        check('offline 0 clears the key', [$soKeyOpen['changed'], siLoad($soPath)['tracy_offline_preview_key'] ?? null, $soKeyOpen['fields']['offline_preview_key_set']], [['offline', 'offline_preview_key'], '', false]);
        $soEngine->handle(['token' => $soToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-key-open']]);
        check('reverting the clear puts the lock and its key back', [siLoad($soPath)['offline'] ?? null, siLoad($soPath)['tracy_offline_preview_key'] ?? null], [1, $soHash]);
        $soEngine->handle(['token' => $soToken, 'action' => 'apply.revert', 'params' => ['apply_id' => 'apply-key']]);
        check('reverting the lock takes the key away with it', [siLoad($soPath)['offline'] ?? null, siLoad($soPath)['tracy_offline_preview_key'] ?? null], [0, '']);
        check('a caller cannot write or read the hash key directly',
            [$soSet(['tracy_offline_preview_key' => $soHash], 'apply-key')['error'], $soCall($soEngine, ['fields' => ['tracy_offline_preview_key']])['error'], $soCall($soEngine, ['fields' => ['offline_preview_key']])['error']],
            ['unsupported', 'unsupported', 'unsupported']);

        // ---- the capability ------------------------------------------------------------------
        $soInfo = static fn(Engine $engine) => $engine->handle(['token' => $soToken, 'action' => 'info'])['info'];
        $soAdvertised = (new Engine($soToken, [], null, null, null, null, null, null, $soLog))->siteIdentity($soStore)->offlineBypass(true);
        check('info advertises site_offline: its keys and the bypass', $soInfo($soAdvertised)['site_offline'] ?? null,
            ['version' => 1, 'fields' => ['offline', 'offline_message', 'display_offline_message'], 'bypass' => 'authorization-bearer', 'bypass_also' => ['preview-key']]);
        check('not where the host serves no bypass (Joomla 3)', array_key_exists('site_offline', $soInfo((new Engine($soToken, [], null, null, null, null, null, null, $soLog))->siteIdentity($soStore))), false);
        check('nor without the door', array_key_exists('site_offline', $soInfo((new Engine($soToken))->offlineBypass(true))), false);
        $soFactory = (string) file_get_contents(__DIR__ . '/../com_claudecowork/administrator/src/Service/EngineFactory.php');
        checkTrue('the component wires the bypass on Joomla 4+', str_contains($soFactory, "\$engine->offlineBypass(method_exists(Factory::class, 'getContainer'))"));

        // ---- the bypass rule -------------------------------------------------------------------
        $soBearer = ['HTTP_AUTHORIZATION' => 'Bearer ' . $soToken];
        check('the token as a Bearer header is read, after a rewrite too',
            [SiteOffline::bearer($soBearer), SiteOffline::bearer(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $soToken]), SiteOffline::bearer(['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz']), SiteOffline::bearer([])],
            [$soToken, $soToken, null, null]);
        check('offline, the site client and the right token: the site', [SiteOffline::bypass(true, 1, $soBearer, $soToken), SiteOffline::bypass(true, true, $soBearer, $soToken), SiteOffline::bypass(true, '1', $soBearer, $soToken)], [true, true, true]);
        check('no header, a wrong token, an online site, the administrator, a site without a token: no bypass', [
            SiteOffline::bypass(true, 1, [], $soToken),
            SiteOffline::bypass(true, 1, ['HTTP_AUTHORIZATION' => 'Bearer wrong-token-at-least-16'], $soToken),
            SiteOffline::bypass(true, 0, $soBearer, $soToken),
            SiteOffline::bypass(false, 1, $soBearer, $soToken),
            SiteOffline::bypass(true, 1, ['HTTP_AUTHORIZATION' => 'Bearer '], ''),
        ], [false, false, false, false, false]);

        // ---- the shipped plugin, one request per process ---------------------------------------
        $soRequest = static function (array $case) use ($soToken): array {
            $case += ['token' => $soToken, 'client' => 'site', 'offline' => 1, 'server' => []];
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --site-offline-case ' . escapeshellarg(json_encode($case)) . ' 2>&1');
            $decoded = is_string($out) ? json_decode($out, true) : null;
            return is_array($decoded) ? $decoded : ['broken' => $out];
        };
        $soServed = $soRequest(['server' => ['HTTP_AUTHORIZATION' => 'Bearer ' . $soToken]]);
        check('plugin: the token as a Bearer header is served the site', [$soServed['broken'] ?? null, $soServed['page'] ?? null, $soServed['globalOffline'] ?? null], [null, 'site', 0]);
        check('plugin: and the page is kept out of every cache', [$soServed['caching'] ?? null, $soServed['pageCache'] ?? null, $soServed['cacheAllowed'] ?? null],
            [[0, 0], [[false], [true]], false]);
        check('plugin: and the request reaches no door and logs nothing', $soServed['logs'] ?? null, []);
        $soNone = $soRequest([]);
        check('plugin: no credential gets the offline page, caching left alone', [$soNone['page'] ?? null, $soNone['caching'] ?? null, $soNone['pageCache'] ?? null], ['offline', [2, 2], [[], []]]);
        check('plugin: a wrong token gets the offline page', $soRequest(['server' => ['HTTP_AUTHORIZATION' => 'Bearer wrong-token-at-least-16']])['page'] ?? null, 'offline');
        check('plugin: the token in the query string gets the offline page', $soRequest(['uri' => '/index.php?token=' . $soToken])['page'] ?? null, 'offline');
        check('plugin: the token as Basic credentials gets the offline page', $soRequest(['server' => ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('tracy:' . $soToken)]])['page'] ?? null, 'offline');
        check('plugin: a site with no token lets nobody through', $soRequest(['token' => '', 'server' => ['HTTP_AUTHORIZATION' => 'Bearer ']])['page'] ?? null, 'offline');
        $soAdmin = $soRequest(['client' => 'administrator', 'server' => ['HTTP_AUTHORIZATION' => 'Bearer ' . $soToken]]);
        check('plugin: the administrator is left as Joomla has it', [$soAdmin['offline'] ?? null, $soAdmin['caching'] ?? null], [1, [2, 2]]);
        $soOnline = $soRequest(['offline' => 0, 'server' => ['HTTP_AUTHORIZATION' => 'Bearer ' . $soToken]]);
        check('plugin: an online site is untouched, even for the token', [$soOnline['page'] ?? null, $soOnline['caching'] ?? null, $soOnline['pageCache'] ?? null], ['site', [2, 2], [[], []]]);

        // ---- the preview key's rule and the plugin ---------------------------------------------
        $soHeader = ['HTTP_X_TRACY_PREVIEW_KEY' => $soKey];
        check('rule: offline, the site client and the key\'s header: the site', SiteOffline::previewBypass(true, 1, $soHeader, $soHash), true);
        check('rule: a wrong key, no header, no stored key, a forged hash, online, the administrator: no', [
            SiteOffline::previewBypass(true, 1, ['HTTP_X_TRACY_PREVIEW_KEY' => strrev($soKey)], $soHash),
            SiteOffline::previewBypass(true, 1, [], $soHash),
            SiteOffline::previewBypass(true, 1, $soHeader, ''),
            SiteOffline::previewBypass(true, 1, $soHeader, 'not-a-hash'),
            SiteOffline::previewBypass(true, 0, $soHeader, $soHash),
            SiteOffline::previewBypass(false, 1, $soHeader, $soHash),
            SiteOffline::previewBypass(true, 1, ['HTTP_X_TRACY_PREVIEW_KEY' => $soHash], $soHash),
        ], array_fill(0, 7, false));
        $soPreview = $soRequest(['previewHash' => $soHash, 'server' => $soHeader]);
        check('plugin: the preview key header is served the site, out of every cache, reaching no door',
            [$soPreview['broken'] ?? null, $soPreview['page'] ?? null, $soPreview['caching'] ?? null, $soPreview['pageCache'] ?? null, $soPreview['cacheAllowed'] ?? null, $soPreview['logs'] ?? null],
            [null, 'site', [0, 0], [[false], [true]], false, []]);
        check('plugin: a wrong preview key gets the offline page', $soRequest(['previewHash' => $soHash, 'server' => ['HTTP_X_TRACY_PREVIEW_KEY' => strrev($soKey)]])['page'] ?? null, 'offline');
        check('plugin: no preview header gets the offline page', $soRequest(['previewHash' => $soHash])['page'] ?? null, 'offline');
        check('plugin: the key in the query string gets the offline page', $soRequest(['previewHash' => $soHash, 'uri' => '/index.php?offline_preview_key=' . $soKey . '&preview_key=' . $soKey])['page'] ?? null, 'offline');
        check('plugin: the key as a cookie gets the offline page', $soRequest(['previewHash' => $soHash, 'server' => ['HTTP_COOKIE' => 'X-Tracy-Preview-Key=' . $soKey]])['page'] ?? null, 'offline');
        check('plugin: the header on a site holding no key gets the offline page', $soRequest(['server' => $soHeader])['page'] ?? null, 'offline');
        check('plugin: a loopback address without the key is not a way in', $soRequest(['previewHash' => $soHash, 'server' => ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']])['page'] ?? null, 'offline');
        check('plugin: the bearer token still works beside a stored key', $soRequest(['previewHash' => $soHash, 'server' => ['HTTP_AUTHORIZATION' => 'Bearer ' . $soToken]])['page'] ?? null, 'site');

        foreach (glob($soDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($soDir);
    }
}
