<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Plugin\System\ClaudeCoworkUpdate\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Takes the updates Tracy announces, instead of showing them and waiting.
 *
 * ## Why this exists at all
 *
 * WordPress installs a plugin update by itself once the plugin says where to look; the Cowork
 * plugin does exactly that and every WordPress site Tracy runs is on the announced version within
 * hours. Joomla does not: core ships the update NOTIFICATION (plg_task_updatenotification, the
 * quick icon) and nothing that installs. So a Joomla site keeps whatever version was installed the
 * day it was built until a person opens Extensions → Update and presses a button — which, on a site
 * nobody administers by hand, is never. Measured 08/09/2026 across the sites Tracy runs: four
 * Joomla sites carried 0.9.0 because somebody had updated them that morning, and one carried
 * 0.8.20, three weeks of releases behind, with nothing anywhere saying so.
 *
 * That is not a missing convenience. What this extension accepts, refuses and records is defined by
 * its own version, so a site left behind is a site whose safety rules are a different set from the
 * ones the caller is written against — and the refusal reads like a bug in something else.
 *
 * ## What it will install, and what it will not
 *
 * Only the extensions in MANAGED, only from a release asset of Tracy's own repository, and only
 * forwards. Everything else on the site is somebody else's business: this plugin never asks Joomla
 * for "all available updates" and never touches an extension it did not ship with.
 *
 * ## Never inside a visitor's request (since 0.24.28)
 *
 * A visitor's request only CLAIMS a run (at most once every EVERY seconds) and knocks on the site's
 * own door over the loopback ({@see kick}); the check, the download and the install run in that
 * second request, which nobody is looking at. Before 0.24.28 all of it ran in `onAfterRespond` of
 * the visitor's own page: under mod_php that page is still being written when the event fires, so
 * when the download died of "Allowed memory size exhausted" (61 times in a day on 20 of 24 fleet
 * demos, 14/10/2026) Joomla appended its "500 Whoops" page to the visitor's page, with status 200.
 * A fatal error is out of reach of any try/catch; the only cure is not to be there.
 *
 * The download itself goes to a file a block at a time ({@see PackageDownload}) and a version that
 * failed waits before it is tried again ({@see UpdateBackoff}), so the worker request is neither
 * the memory hog nor the endless retry the old path was.
 *
 * When the site cannot reach itself (no curl, no route), nothing is installed: the claim records
 * `pending` and the update stays where Joomla shows it, in Extensions → Update.
 *
 * ## The two ways out, both deliberately left open
 *
 * The plugin's own `autoupdate` parameter turns this off and Joomla goes back to showing the update
 * and waiting. And `joomla/update.xml` in the repository is the single place a release is
 * announced: a version that should not spread is un-announced by editing one file, without touching
 * a single site.
 */
final class ClaudeCoworkUpdate extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * What this plugin keeps current: element in `#__extensions` => the manifest that announces it.
     *
     * The template is here for the same reason the package is — it is Tracy's, it is released from
     * the same repository, and a site drawing its pages with a template three releases old is the
     * same silent drift in a different place. A site that does not have one of these installed is
     * simply skipped.
     */
    private const MANAGED = [
        'pkg_claudecowork' => 'https://raw.githubusercontent.com/TracyHQ/claude-cowork/main/joomla/update.xml',
        'tpl_tracy'        => 'https://raw.githubusercontent.com/TracyHQ/claude-cowork/main/joomla/template/update.xml',
    ];

    /**
     * A package is downloaded by URL and installed on somebody's server. It has to come from the
     * repository this plugin was built from — a manifest that has been tampered with, or one day
     * moved, cannot point a site at anything else.
     */
    private const ASSET_PREFIX = 'https://github.com/TracyHQ/claude-cowork/releases/download/';

    /**
     * How often the manifests are read at all.
     *
     * A quarter of an hour, so a release reaches a site the next time somebody opens it in Tracy
     * rather than up to six hours later. It was six hours first, which meant a site checked just
     * before a release kept the old version for the rest of that working session, while the fix
     * written for it sat announced.
     * A check is one GET for a small file, and a package is downloaded only when a newer version
     * is announced. Not lower: raw.githubusercontent caches for about five minutes, so asking
     * more often than that cannot see a release any sooner.
     */
    private const EVERY = 900;

    /**
     * The global the package's installer script reads to tell this caller from the others. A
     * literal, not a reference to that script's class: the script is the one inside the package
     * being installed, and is not loaded until the install runs.
     */
    private const CONTEXT = 'claudecowork_install_context';

    /**
     * The query argument that turns a request into the worker, and how long a claim's key is good.
     * The key is made by the claim, sent once over the loopback and crossed out by the worker that
     * presents it, so nobody outside can start a download by asking for this URL.
     */
    private const WORKER  = 'claudecoworkupdate';
    private const KEY_TTL = 300;

    /**
     * How long the visitor's request waits for its knock. Enough for the worker to answer "nothing
     * new" (one small GET); a worker that goes on to download keeps going after the knock gives up,
     * because it ignores the abort ({@see onAfterInitialise}).
     */
    private const KICK_MS = 1000;

    /** A package this large is a mistake somewhere; the door's installer has the same limit. */
    private const MAX_PACKAGE_BYTES = 104857600;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterRespond' => 'onAfterRespond',
        ];
    }

    /**
     * The visitor's side: claim a run and knock. No download, no install, no manifest read here.
     */
    public function onAfterRespond(): void
    {
        try {
            if ((int) $this->params->get('autoupdate', 1) !== 1) {
                return;
            }

            $key = $this->claim();

            if ($key !== null && !$this->kick($key)) {
                $this->mutateParams(static function (array $params): array {
                    $params['pending'] = time();
                    return $params;
                });
                $this->log('could not reach this site over the loopback; the update waits in Extensions → Update', Log::WARNING);
            }
        } catch (\Throwable $e) {
            // An update check is never worth a site. The next claim tries again after the interval.
            $this->log('failed: ' . $e->getMessage(), Log::WARNING);
        }
    }

    /**
     * The worker's side: a request carrying `claudecoworkupdate=run` and a live key does the check,
     * the download and the install, prints one line and ends. Nothing else answers it.
     */
    public function onAfterInitialise(): void
    {
        $app = Factory::getApplication();

        if ($app->getInput()->get->getCmd(self::WORKER) !== 'run') {
            return;
        }

        // The knock gives up after KICK_MS; the work must not stop with it.
        ignore_user_abort(true);
        @set_time_limit(900);
        self::roomFor(512 * 1024 * 1024);

        $result = 'refused';

        try {
            if ((int) $this->params->get('autoupdate', 1) === 1 && $this->consume((string) $app->getInput()->get->getAlnum('key'))) {
                $result = $this->run();
            }
        } catch (\Throwable $e) {
            $result = 'failed';
            $this->log('failed: ' . $e->getMessage(), Log::WARNING);
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo self::WORKER . ': ' . $result;
        $app->close();
    }

    /** @return string what happened, one word per managed extension */
    private function run(): string
    {
        $done = [];

        foreach (self::MANAGED as $element => $manifestUrl) {
            $installed = $this->installedVersion($element);

            if ($installed === null) {
                continue;
            }

            $release = $this->announced($manifestUrl);

            if ($release === null || version_compare($release['version'], $installed, '<=')) {
                $done[] = $element . '=current';
                continue;
            }

            $attempt = $this->readParams()[1]['attempts'][$element] ?? null;

            if (!UpdateBackoff::due(\is_array($attempt) ? $attempt : null, $release['version'], time())) {
                $done[] = $element . '=waiting';
                continue;
            }

            // Written BEFORE the download: an attempt that dies in a fatal error still counts.
            $this->mutateParams(static function (array $params) use ($element, $release, $attempt): array {
                $params['attempts'][$element] = UpdateBackoff::begin(\is_array($attempt) ? $attempt : null, $release['version'], time());
                unset($params['pending']);
                return $params;
            });

            $ok = $this->install($element, $installed, $release);

            if ($ok) {
                $this->mutateParams(static function (array $params) use ($element): array {
                    unset($params['attempts'][$element]);
                    if (empty($params['attempts'])) {
                        unset($params['attempts']);
                    }
                    return $params;
                });
            }

            $done[] = $element . '=' . ($ok ? 'installed' : 'failed');
        }

        return $done ? implode(' ', $done) : 'nothing';
    }

    /**
     * Knock on this site's own front door with the claim's key, over the loopback.
     *
     * The routes are the ones the component already calibrates with ({@see \LoopbackRoute}): plain
     * http to 127.0.0.1 with the site's Host first, then https resolved to 127.0.0.1. True when the
     * worker answered, or was reached and is still working when the knock gives up.
     */
    private function kick(string $key): bool
    {
        $lib = JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib/LoopbackRoute.php';

        if (!\function_exists('curl_init') || !is_file($lib)) {
            return false;
        }

        require_once $lib;

        $routes = \LoopbackRoute::routes(Uri::root(), \defined('CURLOPT_RESOLVE'), \LoopbackRoute::tls());

        foreach ($routes as $route) {
            $ch = curl_init($route['url'] . 'index.php?' . self::WORKER . '=run&key=' . $key);
            curl_setopt_array($ch, \LoopbackRoute::curlOptions($route) + [
                CURLOPT_HTTPHEADER     => ['Host: ' . $route['host']],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS     => self::KICK_MS,
                CURLOPT_NOSIGNAL       => true,
            ]);
            $body    = curl_exec($ch);
            $errno   = curl_errno($ch);
            $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $reached = (float) curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) > 0;
            if (\PHP_VERSION_ID < 80000) {
                curl_close($ch); // a no-op since PHP 8.0, deprecated in 8.5
            }

            if ($code === 200 && \is_string($body) && strpos($body, self::WORKER . ':') === 0) {
                return true;
            }

            // A timeout after the request went out: the worker has it and is busy.
            if ($errno === 28 && $reached) {
                return true;
            }
        }

        return false;
    }

    /**
     * Claim this run: a key at most once every EVERY seconds, and to exactly one request.
     *
     * Both the interval and the key live in the plugin's own `#__extensions` row, written with a
     * compare-and-swap ({@see mutateParams}), so two visitors arriving together cannot both claim.
     *
     * It was a stamp file under JPATH_CACHE first. That constant is not the site's cache directory:
     * measured 08/09/2026 on Joomla 6, a front-end request reported JPATH_CACHE as
     * `administrator/cache`, which the web user cannot write — so `mkdir` failed, the method
     * returned, and the plugin did nothing at all without one line anywhere saying so. A row this
     * plugin already owns has no permissions to guess at.
     */
    private function claim(): ?string
    {
        $key = bin2hex(random_bytes(16));

        $claimed = $this->mutateParams(static function (array $params) use ($key): ?array {
            if ((time() - (int) ($params['last_check'] ?? 0)) < self::EVERY) {
                return null;
            }

            $params['last_check'] = time();
            $params['run_key']    = $key;
            $params['run_key_at'] = time();

            return $params;
        }, 1);

        return $claimed === null ? null : $key;
    }

    /** Cross out the claim's key if $key is it and still fresh: true to exactly one worker. */
    private function consume(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        return $this->mutateParams(static function (array $params) use ($key): ?array {
            $held = (string) ($params['run_key'] ?? '');

            if ($held === '' || !hash_equals($held, $key) || time() - (int) ($params['run_key_at'] ?? 0) > self::KEY_TTL) {
                return null;
            }

            unset($params['run_key'], $params['run_key_at']);

            return $params;
        }, 1) !== null;
    }

    /** The plugin row's raw params and their decoded form. */
    private function readParams(): array
    {
        $db = $this->getDatabase();

        $raw = (string) $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))->where($this->ownRow())
        )->loadResult();

        $params = json_decode($raw === '' ? '{}' : $raw, true);

        return [$raw, \is_array($params) ? $params : []];
    }

    /**
     * Change the plugin row's params by compare-and-swap: the write lands only if the row still
     * holds what was read. $change returns the new params, or null to leave the row alone.
     *
     * @return array|null the params written, or null when nothing was
     */
    private function mutateParams(callable $change, int $tries = 5): ?array
    {
        $db = $this->getDatabase();

        for ($i = 0; $i < $tries; $i++) {
            [$raw, $params] = $this->readParams();
            $next = $change($params);

            if ($next === null) {
                return null;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($next)))
                    ->where(array_merge($this->ownRow(), [$db->quoteName('params') . ' = ' . $db->quote($raw)]))
            )->execute();

            if ($db->getAffectedRows() === 1) {
                return $next;
            }
        }

        return null;
    }

    private function ownRow(): array
    {
        $db = $this->getDatabase();

        return [
            $db->quoteName('type') . ' = ' . $db->quote('plugin'),
            $db->quoteName('folder') . ' = ' . $db->quote('system'),
            $db->quoteName('element') . ' = ' . $db->quote('claudecoworkupdate'),
        ];
    }

    /** Raise memory_limit to $bytes for this worker when it is lower and the host allows it. */
    private static function roomFor(int $bytes): void
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return;
        }

        $units = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];
        $unit  = strtolower(substr($limit, -1));
        $have  = (int) $limit * ($units[$unit] ?? 1);

        if ($have < $bytes) {
            @ini_set('memory_limit', (string) $bytes);
        }
    }

    /** The version of an installed extension, from its own manifest cache, or null when absent. */
    private function installedVersion(string $element): ?string
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('manifest_cache'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':element', $element);

        $cache = $db->setQuery($query)->loadResult();

        if (!$cache) {
            return null;
        }

        $manifest = json_decode((string) $cache, true);
        $version  = \is_array($manifest) ? ($manifest['version'] ?? '') : '';

        return \is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The newest release an update server announces, as `['version' => …, 'url' => …, 'sha256' => …]`, or null.
     *
     * Joomla's own updater picks the highest version whose targetplatform matches the site; the
     * files these read list every release newest-first, so the first entry carrying a real release
     * asset is the current one. A URL that is not such an asset is not a candidate at all.
     */
    private function announced(string $manifestUrl): ?array
    {
        $response = HttpFactory::getHttp([], ['curl', 'stream'])->get($manifestUrl, [], 10);

        if ((int) $response->code !== 200) {
            return null;
        }

        $xml = @simplexml_load_string((string) $response->body);

        if ($xml === false) {
            return null;
        }

        $best = null;

        foreach ($xml->update as $update) {
            $version = trim((string) $update->version);
            $url     = trim((string) ($update->downloads->downloadurl ?? ''));

            if ($version === '' || !str_starts_with($url, self::ASSET_PREFIX)) {
                continue;
            }

            if ($best === null || version_compare($version, $best['version'], '>')) {
                // Joomla's own update format carries the asset's hash as <sha256>; when it is
                // there the download must match it, when it is not the size limit still holds.
                $sha256 = strtolower(trim((string) ($update->sha256 ?? '')));
                $best   = ['version' => $version, 'url' => $url, 'sha256' => preg_match('/^[0-9a-f]{64}$/', $sha256) ? $sha256 : null];
            }
        }

        return $best;
    }

    /**
     * Download to a file, verify, unpack, install, clean up. Every outcome is written to the log,
     * including success. True only when the installer took the package.
     */
    private function install(string $element, string $installed, array $release): bool
    {
        $app     = Factory::getApplication();
        $tmp     = $app->get('tmp_path');
        $path    = $tmp . '/tracy-update-' . $element . '-' . bin2hex(random_bytes(4)) . '.zip';
        $package = null;

        $got = PackageDownload::toFile($release['url'], $path, self::MAX_PACKAGE_BYTES, $release['sha256'] ?? null, $this->proxyOptions());

        if (!$got['ok']) {
            $this->log(\sprintf('%s: could not download %s: %s', $element, $release['url'], $got['error']), Log::WARNING);
            return false;
        }

        try {
            $package = InstallerHelper::unpack($path, true);

            if ($package === false || empty($package['extractdir'])) {
                $this->log(\sprintf('%s: %s did not unpack', $element, $release['version']), Log::WARNING);
                return false;
            }

            // `method="upgrade"` in every Tracy manifest, so installing over the top IS the update.
            // The context tells the package's own installer script who is installing it, so its
            // receipt says `auto-updater` (joomla/cowork/script.php). Set for this call only.
            $previous               = $GLOBALS[self::CONTEXT] ?? null;
            $GLOBALS[self::CONTEXT] = ['trigger' => 'auto-updater', 'element' => $element, 'from' => $installed, 'to' => $release['version']];
            try {
                $installer = Installer::getInstance();
                // Joomla 6's shared installer is not database-aware on every path (see the door's
                // JoomlaExtensions::installFromUrl); this worker runs at onAfterInitialise.
                if (method_exists($installer, 'setDatabase')) {
                    $installer->setDatabase($this->getDatabase());
                }
                $ok = $installer->install($package['extractdir']);
            } finally {
                $GLOBALS[self::CONTEXT] = $previous;
            }

            $this->log(
                \sprintf(
                    '%s: %s → %s %s (%d bytes, sha256 %s)',
                    $element,
                    $installed,
                    $release['version'],
                    $ok ? 'installed' : 'REFUSED by the installer',
                    $got['bytes'],
                    substr((string) $got['sha256'], 0, 12)
                ),
                $ok ? Log::INFO : Log::WARNING
            );

            return (bool) $ok;
        } finally {
            InstallerHelper::cleanupInstall($path, $package['extractdir'] ?? null);
        }
    }

    /** The site's own proxy (Global Configuration → Server), the one Joomla's HTTP client would use. */
    private function proxyOptions(): array
    {
        $app = Factory::getApplication();

        if (!(int) $app->get('proxy_enable') || (string) $app->get('proxy_host') === '') {
            return [];
        }

        $options = [CURLOPT_PROXY => $app->get('proxy_host') . ':' . (int) $app->get('proxy_port')];

        if ((string) $app->get('proxy_user') !== '') {
            $options[CURLOPT_PROXYUSERPWD] = $app->get('proxy_user') . ':' . $app->get('proxy_pass');
        }

        return $options;
    }

    /**
     * Written to `<log_path>/plg_system_claudecoworkupdate.php`. `Log::add` alone goes nowhere: Joomla
     * keeps no logger for a category nobody registered, so until this every "installed" and every
     * "REFUSED" this plugin wrote was dropped (measured on a stand, 02/10/2026: no such file).
     */
    private function log(string $message, int $priority): void
    {
        static $registered = false;

        if (!$registered) {
            $registered = true;
            Log::addLogger(['text_file' => 'plg_system_claudecoworkupdate.php'], Log::ALL, ['plg_system_claudecoworkupdate']);
        }

        Log::add($message, $priority, 'plg_system_claudecoworkupdate');
    }
}
