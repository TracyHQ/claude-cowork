<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;
use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaApplyLog;
use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaCoreUpgrader;
use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaExtensions;
use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaFilesRestorer;
use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaMediaWriter;
use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter;

/**
 * Wires the engine to this Joomla, once, for both places that answer the API door.
 *
 * The door has two answerers — the site controller, reached after routing, and the system plugin,
 * reached before it — and they must hand the engine exactly the same collaborators. Two copies of
 * this wiring is how one door quietly starts answering with fewer actions than the other. So the
 * wiring lives here, on the administrator side, where both can load it: Joomla registers the
 * component's whole namespace at boot, so this class resolves from a plugin as readily as from
 * the component.
 *
 * Kept thin for the same reason `ApiController` is: this runs on a customer's production site.
 */
final class EngineFactory
{
    /**
     * `lib/` is plain PHP with no namespace, so it is required rather than autoloaded — the
     * component's PSR-4 map covers `src/` only, and putting the engine under that map would
     * mean namespacing it, which is exactly what keeps it testable without Joomla.
     *
     * It lives under `administrator/` (not `media/`) because these are executable files and
     * `media/` is served over HTTP.
     */
    public static function loadEngine(): void
    {
        $lib = self::libDir();

        foreach (['SqlValue', 'RowSource', 'DbDumper', 'FileWalker', 'TarStream', 'Uploader', 'Extensions', 'SiteWriter', 'ChangeStamp', 'CoreUpgrader', 'FilesRestorer', 'Token', 'Engine', 'MysqliRowSource', 'Door'] as $class) {
            require_once $lib . '/' . $class . '.php';
        }
    }

    /**
     * Only the render-stamp rules, for the system plugin on an ordinary page render. Loading the
     * whole engine for that would read a dozen files on a request that uses none of them.
     */
    public static function loadRenderStamps(): void
    {
        require_once self::libDir() . '/RenderStamps.php';
    }

    /** Only the offline bypass rule, for the system plugin on a front-end request while the site is offline. */
    public static function loadSiteOffline(): void
    {
        require_once self::libDir() . '/SiteOffline.php';
    }

    /** Only the home-title rule, for the system plugin titling the home page by the site name alone. */
    public static function loadHomeTitle(): void
    {
        require_once self::libDir() . '/HomeTitle.php';
    }

    /** Only the page-literal rules and the override file reader, for the system plugin saying a layout's literals. */
    public static function loadPageLiterals(): void
    {
        require_once self::libDir() . '/LanguageOverrides.php';
    }

    /** Only the site-settings rules, for the system plugin printing a non-T4 template's favicon. */
    public static function loadTemplateSiteSettings(): void
    {
        require_once self::libDir() . '/TemplateSiteSettings.php';
    }

    /** Only the T3 off-canvas gate (with the site-settings rules it reads), for the system plugin's drawer keyboard. */
    public static function loadT3OffCanvas(): void
    {
        self::loadTemplateSiteSettings();
        require_once self::libDir() . '/T3OffCanvas.php';
    }

    /**
     * The published contract profile this site is held to.
     *
     * One base archive now carries more than one design — Airbnb is the same Apple quickstart with
     * a different template activated on top — so the profile can no longer be a path literal. It is
     * read from the component's own params, which only the provisioner writes (the same place, and
     * the same trust, as the write token); a request can never reach it, because every action that
     * writes an extension row is refused on a bound site.
     *
     * A site with no param has no contract of its own and is UNBOUND (null): it is never held to a
     * profile merely because that profile ships in `lib/contracts/`. Holding such sites to the Apple
     * profile verified their files against Apple's lock, so every content.read on them threw inside
     * the presentation check and answered a masked 503 (TCH #722). Unbound, content.read answers its
     * honest 501 "Content mapping is unavailable" and the caller falls back to the open writes.
     *
     * Swapping the param on an already-bound site does NOT rebind it: `inspect()` compares the
     * stored `contractHash` against the loaded profile and fails closed, which is what a design
     * change on a live site has to do until a migration workflow exists to do it properly.
     */
    private static function buildContract(): ?\QuickstartContract
    {
        // An imported site bound by `content.contract derive` is held to the map its own rows make,
        // whatever profile the params name: the binding is what the site IS. Built lazily, and read
        // without calibrating again: the nested leaves the derive's pages showed are the binding's `keep`.
        $derived = self::derivedBinding();
        if ($derived !== null) {
            $writer = self::buildWriter();
            if (!$writer) return null;
            $db = self::database();
            // Built batch by batch, and kept between requests while the tables' fingerprint stands.
            $cache = JoomlaDerivedRows::siteCache($db, (string) self::installedVersion());
            return \QuickstartContract::derived($writer, new \Tracy\Component\ClaudeCowork\Site\Controller\JoomlaContractStore($db), JPATH_ROOT,
                static fn(): array => JoomlaDerivedRows::built($db, $derived, $cache));
        }
        $configured = trim((string) ComponentHelper::getParams('com_claudecowork')->get('contract', ''));
        // No `contract` means no contract of this site's own: unbound. That includes a site under
        // construction — provisioned from the Base archive with `tracy_build_baseline` and no
        // `contract`, because its template is still to be built; the engine answers the contract
        // door for it instead (`Engine::underConstruction`). Whatever profiles ship on disk, none is
        // this site's: Apple's, once the default here, verified Base's files against Apple's lock and
        // refused the first inspection of every such site ("Presentation asset changed:
        // templates/tracy/acm/accordion/css/style.css", measured 14/09).
        if ($configured === '') return null;
        // Three segments of the shape the published profiles use, and nothing that could climb out
        // of `lib/contracts/` — this string becomes a directory. A param that is set but malformed
        // is deliberately NOT read as unbound: it names a site whose design nobody can account for,
        // and that has to refuse. So does a well-formed profile this receiver does not carry: the
        // contract handed back refuses, so a receiver older than the site's profile cannot read as
        // an unbound site.
        $valid = preg_match('~^[a-z][a-z0-9-]{1,40}/[a-z][a-z0-9]{0,9}/[0-9]+\.[0-9]+\.[0-9]+$~D', $configured) === 1;
        // The package carries no profile since TCH #1568: the one this site is held to is fetched
        // from the release's assets the first time it is needed, before it is read, and read from
        // wherever it is (lib/contracts/, or the spare folder under tmp_path).
        [$directory, $missing] = $valid ? self::ensureContract($configured) : [self::libDir() . '/contracts/unconfigured', null];
        $writer = self::buildWriter();
        if (!$writer) return null;
        $db = self::database();
        return (new \QuickstartContract($writer, new \Tracy\Component\ClaudeCowork\Site\Controller\JoomlaContractStore($db), JPATH_ROOT, $directory, $missing, self::libDir()))
            ->withFileProofs(new \Tracy\Component\ClaudeCowork\Site\Controller\JoomlaFileProofStore($db));
    }

    /**
     * Contract profile `$id` on disk (and the profiles it names), fetched from this release's assets
     * when it is not: the one place a profile folder comes from (lib/ContractFetch.php). Answers
     * `[folder, missing]`: the folder ContractFetch::directory() names for it, and null when it is
     * there; otherwise why not, for the "does not carry" refusal (empty: the release does not list
     * it, refused as before).
     *
     * @return array{0:string,1:?string}
     */
    private static function ensureContract(string $id): array
    {
        try {
            require_once self::libDir() . '/ContractFetch.php';
            $proxy = [];
            $tmpPath = null;
            try {
                $app = Factory::getApplication();
                $proxy = \PackageDownload::proxyOptions($app->get('proxy_enable'), $app->get('proxy_host'), $app->get('proxy_port'), $app->get('proxy_user'), $app->get('proxy_pass'));
                $tmpPath = (string) $app->get('tmp_path');
            } catch (\Throwable $e) {
                // No application to ask (a CLI caller): no proxy, and the site's own tmp/.
            }
            $fetch = new \ContractFetch(self::libDir(), \ContractFetch::releaseOf(self::installedVersion()), $proxy, null, null, \ContractFetch::spareOf($tmpPath, JPATH_ROOT));
            $missing = $fetch->ensure($id);
            return [$fetch->directory($id), $missing];
        } catch (\Throwable $e) {
            self::logFailure('ensureContract', $e);
            return [self::libDir() . '/contracts/' . $id, 'fetch failed: ' . get_class($e)];
        }
    }

    /**
     * The stored binding when it is a derived one, else null. The database only fetches a binding that
     * holds `"mode":"derived"` (LOCATE: no JSON functions needed, and a quickstart's baseline of
     * megabytes is neither sent nor decoded); PHP then decodes it and checks the field itself. The
     * binding is written with json_encode, so the text is exactly that, and the same words inside a
     * stored string would read `\"mode\"`. A site without the table yet is no derived site.
     */
    private static function derivedBinding(): ?array
    {
        try {
            $db = self::database();
            $raw = $db->setQuery('SELECT binding FROM #__claudecowork_content_contract WHERE id=1 AND LOCATE(' . $db->quote(self::DERIVED_MARK) . ', binding) > 0')->loadResult();
            $binding = $raw === null ? null : json_decode((string) $raw, true);
        } catch (\Throwable $e) {
            self::logFailure('derivedBinding', $e);
            return null;
        }
        return is_array($binding) && ($binding['mode'] ?? null) === 'derived' && is_string($binding['contract'] ?? null) && strpos($binding['contract'], 'derived/') === 0 ? $binding : null;
    }

    /** What a derived binding's JSON holds, as json_encode writes it; also JoomlaContentReader's pre-filter. */
    public const DERIVED_MARK = '"mode":"derived"';

    /**
     * The baseline profile of a site under construction, or null when the site is not one. Same
     * shape rule as `contract`: a malformed value is not a baseline.
     */
    private static function constructionBaseline(): ?string
    {
        $baseline = trim((string) ComponentHelper::getParams('com_claudecowork')->get('tracy_build_baseline', ''));
        if ($baseline === '' || !preg_match('~^[a-z][a-z0-9-]{1,40}/[a-z][a-z0-9]{0,9}/[0-9]+\.[0-9]+\.[0-9]+$~D', $baseline)) return null;
        return $baseline;
    }

    /** Whether the component's engine is on disk — false on a site where only the plugin survived. */
    public static function installed(): bool
    {
        return is_file(self::libDir() . '/Engine.php');
    }

    private static function libDir(): string
    {
        return JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib';
    }

    /**
     * This component's own version, read from the manifest Joomla copied in when it installed.
     *
     * Read rather than held as a constant so there is one place a version lives and nothing to
     * keep in step. It exists because a caller has to be able to tell an old copy from a new
     * one: an already-installed component is deliberately not reinstalled on every run — doing
     * that answers HTTP 500 with no message at all — and without a version, "already installed"
     * silently means "will never be updated", so every action added later is dead code on every
     * site that already has this.
     *
     * Null when the manifest cannot be read. The caller treats that as "too old to say", which
     * is what it is.
     */
    public static function installedVersion(): ?string
    {
        $manifest = JPATH_ADMINISTRATOR . '/components/com_claudecowork/claudecowork.xml';
        if (!is_file($manifest)) {
            return null;
        }
        $xml = @simplexml_load_file($manifest);
        if ($xml === false) {
            return null;
        }
        $version = trim((string) $xml->version);
        return $version === '' ? null : $version;
    }

    /**
     * The engine, wired to this site. Call `loadEngine()` first.
     */
    public static function build(): \Engine
    {
        $params = ComponentHelper::getParams('com_claudecowork');
        $token = trim((string) $params->get('token', ''));
        $contract = self::buildContract();

        $engine = new \Engine(
            $token === '' ? null : $token,
            [
                'php'       => PHP_VERSION,
                'joomla'    => \defined('JVERSION') ? JVERSION : null,
                'component' => self::installedVersion(),
                'upload'    => \AutoUploader::capability(),
                // The table prefix of THIS site, so a caller can pick its tables out of a database
                // it shares with other sites; `db.tables` answers it as `prefix`.
                'db_prefix' => self::dbPrefix(),
            ],
            self::buildDumper(),
            self::buildWalker($params),
            new \AutoUploader(120),
            self::orNull('extensions', static fn() => new JoomlaExtensions(null, self::database())),
            self::buildWriter(),
            self::buildMedia(),
            self::buildLog(),
            // JPATH_ROOT and not the configured walker root: the stamp has to sit where the site
            // is SERVED from, because a preview reads it over HTTP as `/tracy-changed.json`.
            new \ChangeStamp(JPATH_ROOT),
            // Joomla 3 has no com_joomlaupdate MVC to drive (`bootComponent`): `core.upgrade` answers
            // 'unavailable' there rather than failing half way through an upgrade.
            method_exists(Factory::class, 'getContainer') ? self::orNull('coreUpgrader', static fn() => new JoomlaCoreUpgrader()) : null,
            self::orNull('filesRestorer', static fn() => new JoomlaFilesRestorer()),
            $contract
        );
        // Apply's `expected_content_revisions` are checked against the very projection content.read
        // serves, read through the same connection as the write, so inside the apply's transaction
        // the receipt's new revisions see the rows it just wrote.
        if ($contract) $engine->contentRevisions(static function () use ($contract): array {
            require_once self::libDir() . '/ContentProjection.php';
            return (new JoomlaContentReader(self::database(), static fn() => $contract, JPATH_ROOT, \Joomla\CMS\Uri\Uri::root()))->revisions();
        });
        // Every write refuses a row an administrator has open in the Joomla editor — on any site,
        // bound to a contract or not, since the admin's next Save would put the old words back.
        $engine->locks(static function (array $rows): array {
            return (new JoomlaContentReader(self::database(), static fn() => null, JPATH_ROOT, \Joomla\CMS\Uri\Uri::root()))->locks($rows);
        });
        // `site.identity`: Global Configuration's site name and site description, and no other key.
        $engine->siteIdentity(self::buildSiteIdentity());
        // The system plugin serves the real site to the token while the site is offline on Joomla 4+
        // (ClaudeCoworkApi::decideOfflineBypass); the Joomla 3 entry does not, so there `info` does not
        // advertise `site_offline` and Tracy does not lock a site it could not read.
        $engine->offlineBypass(method_exists(Factory::class, 'getContainer'));
        // Joomla 3 drives no core upgrade (see the upgrader above): the answer says why.
        if (!method_exists(Factory::class, 'getContainer')) {
            $engine->coreUpgradeUnavailable('core upgrade is not available on Joomla 3: Tracy upgrades its own copy of the site, not the live site');
        }
        // `template.siteSettings`: a template's logo, name, slogan and favicon, in its etc/site profiles.
        $engine->templateSiteSettings(self::buildTemplateSiteSettings());
        // `template.languageOverrides`: the site's language override files, for the strings that read in en-GB.
        $engine->languageOverrides(new \LanguageOverrides(JPATH_ROOT));
        // `template.files.*`: the files of the installed site templates, as the owner's two Options switches allow.
        $engine->templateFiles(self::buildTemplateFiles($params));
        $contract = trim((string) ComponentHelper::getParams('com_claudecowork')->get('contract', ''));
        $quickstart = $contract !== '';
        $baseline = self::constructionBaseline();
        if ($contract === '' && $baseline !== null) $engine->underConstruction($baseline);
        // `content.contract derive`: this site's public rows, and its rendered pages over loopback.
        try {
            $db = self::database();
            // Params naming a quickstart contract (a provision whose bind is still to come) refuse derive while unbound.
            // The rows as a generator of batches, read while the map is built; it returns its own unresolved lines.
            $engine->derivedSource(new \Tracy\Component\ClaudeCowork\Site\Controller\JoomlaContractStore($db), JPATH_ROOT, static function () use ($db): array {
                return ['rows' => JoomlaDerivedRows::batches($db), 'pages' => JoomlaDerivedRows::pages($db), 'unresolved' => []];
            }, static fn(array $paths): array => JoomlaDerivedRows::fetch($paths, JoomlaDerivedRows::CHECK_SECONDS),
                // After a derived apply: T4's optimize cache, and the map kept between requests (made of the rows just written).
                static function () use ($db): void { JoomlaDerivedRows::clearOptimize(JPATH_ROOT); JoomlaDerivedRows::forget($db); },
                static fn(int $id): ?string => JoomlaDerivedRows::modulePage($db, $id), $quickstart);
        } catch (\Throwable $e) {
            // No database: derive answers 'unavailable', like every other write without one.
            self::logFailure('derivedSource', $e);
        }
        return $engine;
    }

    /**
     * Reads one request off the application, answers it, and ends the response.
     *
     * The whole HTTP surface of the door, shared by both answerers: a JSON body in, a JSON body
     * out, and `close()` so nothing downstream — a template, another plugin, an error page — gets
     * to append to what the caller will parse.
     */
    /**
     * The engine's answer is JSON and nothing else: a notice PHP survives (a site whose Global
     * Configuration shows errors, a deprecation on a newer PHP) is printed ahead of the JSON and the
     * caller can no longer read the reply — the import stopped on PHP 8.5's curl_close() deprecation,
     * 27/10/2026. It still reaches the PHP error log.
     */
    private static function quietNotices(): void
    {
        @ini_set('display_errors', '0');
    }

    /** The same authenticated reader at both doors; the site token is a service credential. */
    public static function answerContent($app, ?array $request = null): void
    {
        self::quietNotices();
        self::loadEngine();
        require_once self::libDir() . '/ContentProjection.php';
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'private, no-store', true);
        $status=200;
        try {
            $configured=(string) ComponentHelper::getParams('com_claudecowork')->get('token','');
            $provided=$request['token']??null;
            if ($request===null) {
                $header=$_SERVER['HTTP_AUTHORIZATION']??'';
                $provided=preg_match('/^Bearer ([^\s]+)$/D',$header,$m)?$m[1]:null;
            }
            if (!is_string($provided) || !\Token::check($configured,$provided))
                throw new \ContentReadError('CONTENT_UNAUTHENTICATED',401,'Authentication required');
            if ($request===null && ($_SERVER['REQUEST_METHOD']??'')!=='GET') \ContentReader::bad();
            $query=$request===null?\ContentReader::parse($_SERVER['QUERY_STRING']??''):($request['params']??[]);
            if (!is_array($query) || (array_is_list($query) && $query!==[])) \ContentReader::bad();
            // The relay identity is server-owned at Tracy's door. It scopes continuations only;
            // it grants no CMS/write privileges. Direct Bearer calls use a distinct service scope.
            $principal=$request===null?'service':($request['contentPrincipal']??'service');
            if (!is_string($principal) || !preg_match('/^(service|seat:[a-f0-9]{64})$/D',$principal)) \ContentReader::bad();
            $scope=$request===null?'published':($request['contentScope']??'published');
            if ($scope!=='published') throw new \ContentReadError('CONTENT_ADAPTER_UNSUPPORTED',501,'Content scope is not supported');
            $principal=hash_hmac('sha256',$principal.':'.$scope,$configured);
            \Door::reserveMemory();
            \Door::reserveTime();
            $reader=new JoomlaContentReader(self::database(),static fn()=>self::buildContract(),JPATH_ROOT,\Joomla\CMS\Uri\Uri::root());
            $result=$reader->read($query,$principal);
        } catch (\ContentReadError $e) { $status=$e->status; $result=$e->body(); }
        catch (\Throwable $e) { self::logFailure('content.read',$e); $status=503; $result=['error'=>['code'=>'CONTENT_SOURCE_UNAVAILABLE','message'=>'Content source or binding could not be read']]; }
        http_response_code($status);
        $app->sendHeaders();
        echo \ContentReader::encode($result);
        $app->close();
    }

    public static function answer(CMSApplicationInterface $app): void
    {
        self::quietNotices();
        self::loadEngine();
        // Before `build()`: the contract profile is decoded in the engine's constructor, and a
        // 43-language quickstart's lock is ~8 MB with ~6k ACL chains — more than PHP's default
        // 128M survives. Why, and what it never lowers: `Door::MEMORY_LIMIT`.
        \Door::reserveMemory();
        // The same reasoning for time: a contract inspect outlives PHP's stock 30 s. `Door::TIME_LIMIT`.
        \Door::reserveTime();

        $request = json_decode((string) $app->getInput()->json->getRaw(), true);
        if (!\is_array($request)) {
            $request = [];
        }

        if (($request['action'] ?? null) === 'content.read') { self::answerContent($app, $request); return; }
        // Built inside the timed answer so a timed call counts it (`boot` decodes the profile), in
        // the order the door always had: build, headers, handle, encode.
        echo \Timing::body($request, static function () use ($app, $request) {
            $t = \Timing::begin();
            $engine = self::build();
            \Timing::end('boot', $t);

            $app->setHeader('Content-Type', 'application/json', true);
            $app->sendHeaders();
            return $engine->handle($request);
        });
        $app->close();
    }

    /**
     * The site's database, the one handle every collaborator below is given. Joomla 4+ hands it out
     * of its DI container. Joomla 3 has no container: there it is `Factory::getDbo()` in the Joomla 4
     * shape (`legacy/LegacyDatabase.php`: `bind()` and the three-argument `join()`), so
     * Joomla 3 runs this same wiring and the same writer, log and extension manager — not a copy.
     *
     * @return DatabaseInterface
     */
    public static function database()
    {
        if (method_exists(Factory::class, 'getContainer')) {
            return Factory::getContainer()->get(DatabaseInterface::class);
        }
        require_once JPATH_ADMINISTRATOR . '/components/com_claudecowork/legacy/LegacyDatabase.php';
        $db = \ClaudeCoworkLegacyDatabase::of(Factory::getDbo());
        if ($db === null) {
            throw new \RuntimeException('only the mysqli database driver is wired on Joomla 3');
        }
        return $db;
    }

    /**
     * A collaborator from the site folder (`site/src/Controller`), or null — logged — when it cannot
     * be built, like every builder below. The administrator door is the way in when the front end is
     * broken, so it has to answer without that folder: what needs it answers 'unavailable', and the
     * reads keep working. Measured 19/10/2026 on Joomla 6.1: with `components/com_claudecowork`
     * removed, `new JoomlaExtensions()` threw "Class not found" out of `build()`, and the
     * administrator door answered Joomla's 403 to every token call.
     */
    private static function orNull(string $where, callable $make): ?object
    {
        try {
            return $make();
        } catch (\Throwable $e) {
            self::logFailure($where, $e);
            return null;
        }
    }

    /**
     * Reads through the connection Joomla already opened. The engine never receives database
     * credentials, and no action can point it at another server.
     */
    private static function buildDumper(): ?\DbDumper
    {
        try {
            $driver = self::database()->getConnection();
        } catch (\Throwable $e) {
            self::logFailure('buildDumper', $e);
            return null;
        }

        // Only mysqli is wired. On anything else `db.*` answers 'unavailable' rather than
        // half-working, which is the honest result.
        return $driver instanceof \mysqli ? new \DbDumper(new \MysqliRowSource($driver)) : null;
    }

    /** The database driver's table prefix (`#__`), or null when there is no database to ask. */
    private static function dbPrefix(): ?string
    {
        try {
            return (string) self::database()->getPrefix();
        } catch (\Throwable $e) {
            self::logFailure('dbPrefix', $e);
            return null;
        }
    }

    /** An unreadable webroot leaves `files.*` reporting 'unavailable', not fatal. */
    private static function buildWalker($params): ?\FileWalker
    {
        $configured = trim((string) $params->get('webroot', ''));

        try {
            return new \FileWalker($configured !== '' ? $configured : JPATH_ROOT);
        } catch (\Throwable $e) {
            self::logFailure('buildWalker', $e);
            return null;
        }
    }

    /**
     * The write side of an Apply, on Joomla 3 to 6 alike. A site whose database cannot be reached
     * leaves `content.update` reporting 'unavailable', the same honest result the read side gives
     * — never a fatal.
     */
    private static function buildWriter(): ?\SiteWriter
    {
        try {
            return new JoomlaSiteWriter(self::database());
        } catch (\Throwable $e) {
            self::logFailure('buildWriter', $e);
            return null;
        }
    }

    /** Media writes land under the same webroot the walker reads; unreadable leaves media.upload 'unavailable'. */
    private static function buildMedia(): ?\MediaWriter
    {
        try {
            return new JoomlaMediaWriter(JPATH_ROOT);
        } catch (\Throwable $e) {
            self::logFailure('buildMedia', $e);
            return null;
        }
    }

    /**
     * configuration.php for `site.identity`, read and written as Joomla's own Global Configuration
     * save does (`ApplicationModel::save()` / `writeConfigFile()`): the configuration Joomla loaded
     * (`new JConfig()`, through `ArrayHelper::fromObject`), and Joomla's `Registry` formatting it back
     * as the `JConfig` class without a closing tag. `ConfigurationFile` merges only the `site.identity` keys
     * (`SiteIdentity::FIELDS`) over it and does the write. Null leaves `site.identity` answering 'unavailable'.
     */
    private static function buildSiteIdentity(): ?\SiteIdentityStore
    {
        try {
            if (!class_exists('JConfig', false) || !class_exists(Registry::class) || !class_exists(ArrayHelper::class)) {
                throw new \RuntimeException('JConfig or Joomla\'s Registry is not loaded');
            }
            return new \ConfigurationFile(
                (\defined('JPATH_CONFIGURATION') ? JPATH_CONFIGURATION : JPATH_ROOT) . '/configuration.php',
                static fn(): array => ArrayHelper::fromObject(new \JConfig()),
                static fn(array $config): string => (new Registry($config))->toString('PHP', ['class' => 'JConfig', 'closingtag' => false])
            );
        } catch (\Throwable $e) {
            self::logFailure('buildSiteIdentity', $e);
            return null;
        }
    }

    /**
     * The files `template.siteSettings` reads and writes under the site root, and the profile each
     * site style of a template names (`typelist-site` in its params; T4's default when absent).
     * Null leaves the door answering 'unavailable'.
     */
    private static function buildTemplateSiteSettings(): ?\TemplateSiteFiles
    {
        try {
            $db = self::database();
            return new \TemplateSiteFiles(JPATH_ROOT, static function (string $template) use ($db): array {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__template_styles'))
                    ->where($db->quoteName('client_id') . ' = 0')
                    ->where($db->quoteName('template') . ' = ' . $db->quote($template));
                $profiles = [];
                foreach ($db->setQuery($query)->loadColumn() ?: [] as $params) {
                    $decoded = json_decode((string) $params, true);
                    $profiles[] = \is_array($decoded) && \is_string($decoded['typelist-site'] ?? null) ? $decoded['typelist-site'] : null;
                }
                return $profiles;
            });
        } catch (\Throwable $e) {
            self::logFailure('buildTemplateSiteSettings', $e);
            return null;
        }
    }

    /**
     * `template.files.*`: the files of each installed site template — `templates/<name>/`, where PHP
     * may be written (overrides, the layout), and its assets in `media/templates/site/<name>/`, where
     * it may not — as `#__extensions` lists them. Two Options switches: `template_files` (on unless
     * switched off) turns the door off — the actions answer 'disabled' and `info` says
     * `enabled: false`; `template_files_php` (off unless switched on) lets PHP be written at all.
     * After a change T4's combined CSS is emptied, and every write and undo goes into Joomla's User
     * Actions Log.
     */
    private static function buildTemplateFiles($params): ?\TemplateFiles
    {
        try {
            $db = self::database();
            return (new \TemplateFiles(JPATH_ROOT, static function () use ($db): array {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('element'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
                    ->where($db->quoteName('client_id') . ' = 0');
                $roots = [];
                foreach ($db->setQuery($query)->loadColumn() ?: [] as $name) {
                    if (!\is_string($name) || !preg_match('~^[A-Za-z0-9_-]+$~D', $name)) continue;
                    $roots[] = ['path' => 'templates/' . $name, 'kind' => 'template', 'name' => $name, 'php' => true];
                    $roots[] = ['path' => 'media/templates/site/' . $name, 'kind' => 'template-media', 'name' => $name, 'php' => false];
                }
                return $roots;
            }, static function (array $paths): void {
                JoomlaDerivedRows::clearOptimize(JPATH_ROOT);
            }, static function (array $event): void {
                self::logTemplateFiles($event);
            }))->enabled((string) $params->get('template_files', '1') === '1')
                ->phpAllowed((string) $params->get('template_files_php', '0') === '1');
        } catch (\Throwable $e) {
            self::logFailure('buildTemplateFiles', $e);
            return null;
        }
    }

    /**
     * One row of Joomla's User Actions Log (Users › User Actions Log) for a template file write or
     * undo: what the administrator sees of it, with the ids that take it back. Best-effort: a site
     * whose action log cannot be reached still has the Apply log.
     */
    private static function logTemplateFiles(array $event): void
    {
        try {
            $app = Factory::getApplication();
            if (!method_exists($app, 'bootComponent')) return;
            $model = $app->bootComponent('com_actionlogs')->getMVCFactory()->createModel('Actionlog', 'Administrator', ['ignore_request' => true]);
            if (!$model || !method_exists($model, 'addLog')) return;
            $files = array_map(static fn($f): string => (string) ($f['path'] ?? '') . ' (' . (string) ($f['op'] ?? '') . ')', (array) ($event['files'] ?? []));
            $undo = ($event['action'] ?? '') === 'template.files.undo';
            $model->addLog([[
                'count'    => (string) \count($files),
                'files'    => implode(', ', \array_slice($files, 0, 20)) . (\count($files) > 20 ? ', ...' : ''),
                'apply_id' => (string) ($event['apply_id'] ?? ''),
                'undo_id'  => (string) ($event['undo_id'] ?? ''),
                'kept'     => (string) ($event['kept'] ?? 0),
            ]], $undo ? 'COM_CLAUDECOWORK_ACTIONLOG_TEMPLATE_FILES_UNDO' : 'COM_CLAUDECOWORK_ACTIONLOG_TEMPLATE_FILES_WRITE', 'com_claudecowork.template.files', 0);
        } catch (\Throwable $e) {
            self::logFailure('logTemplateFiles', $e);
        }
    }

    /**
     * The undo log every write records to. Without it wired, the write actions refuse to run at
     * all — the engine will not make a change it cannot record how to reverse.
     */
    private static function buildLog(): ?\ApplyLog
    {
        try {
            return new JoomlaApplyLog(self::database());
        } catch (\Throwable $e) {
            self::logFailure('buildLog', $e);
            return null;
        }
    }

    /** Whether this request registered the component's log file with Joomla yet. */
    private static bool $loggerAdded = false;

    /**
     * Writes down a Throwable that a catch here answers with an honest fallback (content.read's 503,
     * a builder's null), so the caller's generic answer has a cause someone can read — TCH #722 was
     * a 503 nothing anywhere could explain. Joomla's log first, in `com_claudecowork.php` under the
     * site's log path; PHP's `error_log` when Joomla's Log is missing or cannot write.
     *
     * Only the Throwable's class, message, location and stack — never the request, and the stack
     * without its arguments: a frame's arguments can be the token. Never throws: a log that cannot be
     * written must not turn the fallback into a fatal.
     */
    private static function logFailure(string $where, \Throwable $e): void
    {
        $entry = $where . ': ' . self::describe($e);
        for ($previous = $e->getPrevious(), $depth = 0; $previous && $depth < 5; $previous = $previous->getPrevious(), $depth++) {
            $entry .= "\nCaused by " . self::describe($previous);
        }
        try {
            if (!class_exists(Log::class)) throw new \RuntimeException('Joomla Log is unavailable');
            if (!self::$loggerAdded) {
                Log::addLogger(['text_file' => 'com_claudecowork.php'], Log::ALL, ['com_claudecowork']);
                self::$loggerAdded = true;
            }
            Log::add($entry, Log::ERROR, 'com_claudecowork');
            return;
        } catch (\Throwable $unlogged) {
        }
        try {
            @error_log('com_claudecowork ' . $entry);
        } catch (\Throwable $unlogged) {
        }
    }

    /** One Throwable as `Class: message at file:line` and its stack, each frame without arguments. */
    private static function describe(\Throwable $e): string
    {
        $message = $e->getMessage();
        $text = get_class($e) . ': ' . ($message === '' ? '(no message)' : $message) . ' at ' . $e->getFile() . ':' . $e->getLine();
        foreach ($e->getTrace() as $i => $frame) {
            $text .= "\n#{$i} " . (isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? 0) . '): ' : '[internal function]: ')
                . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
        }
        return $text;
    }
}
