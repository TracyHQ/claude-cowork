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
use Joomla\Database\DatabaseInterface;
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

        return new \Engine(
            $token === '' ? null : $token,
            [
                'php'       => PHP_VERSION,
                'joomla'    => \defined('JVERSION') ? JVERSION : null,
                'component' => self::installedVersion(),
            ],
            self::buildDumper(),
            self::buildWalker($params),
            new \AutoUploader(120),
            new JoomlaExtensions(),
            self::buildWriter(),
            self::buildMedia(),
            self::buildLog(),
            // JPATH_ROOT and not the configured walker root: the stamp has to sit where the site
            // is SERVED from, because a preview reads it over HTTP as `/tracy-changed.json`.
            new \ChangeStamp(JPATH_ROOT),
            new JoomlaCoreUpgrader(),
            new JoomlaFilesRestorer()
        );
    }

    /**
     * Reads one request off the application, answers it, and ends the response.
     *
     * The whole HTTP surface of the door, shared by both answerers: a JSON body in, a JSON body
     * out, and `close()` so nothing downstream — a template, another plugin, an error page — gets
     * to append to what the caller will parse.
     */
    public static function answer(CMSApplicationInterface $app): void
    {
        self::loadEngine();

        $request = json_decode((string) $app->getInput()->json->getRaw(), true);
        if (!\is_array($request)) {
            $request = [];
        }

        $engine = self::build();

        $app->setHeader('Content-Type', 'application/json', true);
        $app->sendHeaders();
        echo json_encode($engine->handle($request));
        $app->close();
    }

    /**
     * Reads through the connection Joomla already opened. The engine never receives database
     * credentials, and no action can point it at another server.
     */
    private static function buildDumper(): ?\DbDumper
    {
        try {
            $driver = Factory::getContainer()->get(DatabaseInterface::class)->getConnection();
        } catch (\Throwable $e) {
            return null;
        }

        // Only mysqli is wired. On anything else `db.*` answers 'unavailable' rather than
        // half-working, which is the honest result.
        return $driver instanceof \mysqli ? new \DbDumper(new \MysqliRowSource($driver)) : null;
    }

    /** An unreadable webroot leaves `files.*` reporting 'unavailable', not fatal. */
    private static function buildWalker($params): ?\FileWalker
    {
        $configured = trim((string) $params->get('webroot', ''));

        try {
            return new \FileWalker($configured !== '' ? $configured : JPATH_ROOT);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The write side of an Apply, wired only on Joomla 4+. A site whose database cannot be reached
     * leaves `content.update` reporting 'unavailable', the same honest result the read side gives
     * — never a fatal.
     */
    private static function buildWriter(): ?\SiteWriter
    {
        try {
            return new JoomlaSiteWriter(Factory::getContainer()->get(DatabaseInterface::class));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Media writes land under the same webroot the walker reads; unreadable leaves media.upload 'unavailable'. */
    private static function buildMedia(): ?\MediaWriter
    {
        try {
            return new JoomlaMediaWriter(JPATH_ROOT);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The undo log every write records to. Without it wired, the write actions refuse to run at
     * all — the engine will not make a change it cannot record how to reverse.
     */
    private static function buildLog(): ?\ApplyLog
    {
        try {
            return new JoomlaApplyLog(Factory::getContainer()->get(DatabaseInterface::class));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
