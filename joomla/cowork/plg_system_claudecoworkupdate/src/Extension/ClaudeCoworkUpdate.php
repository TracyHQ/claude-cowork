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
 * ## Why `onAfterRespond` and not `onAfterInitialise`
 *
 * Downloading and unpacking a package takes seconds. `onAfterRespond` runs after the response has
 * been sent to the browser, so no visitor ever waits behind an update. The API door plugin uses the
 * earliest event for the opposite reason — it must answer BEFORE anything else can.
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

    /** How often the manifests are read at all. Short enough that a release lands the same day. */
    private const EVERY = 21600;

    public static function getSubscribedEvents(): array
    {
        return ['onAfterRespond' => 'onAfterRespond'];
    }

    public function onAfterRespond(): void
    {
        try {
            $this->run();
        } catch (\Throwable $e) {
            // An update check is never worth a site. The next request will try again after the
            // interval; until then the site runs exactly as it did.
            $this->log('failed: ' . $e->getMessage(), Log::WARNING);
        }
    }

    private function run(): void
    {
        if ((int) $this->params->get('autoupdate', 1) !== 1) {
            return;
        }

        if (!$this->claim()) {
            return;
        }

        foreach (self::MANAGED as $element => $manifestUrl) {
            $installed = $this->installedVersion($element);

            if ($installed === null) {
                continue;
            }

            $release = $this->announced($manifestUrl);

            if ($release === null || version_compare($release['version'], $installed, '<=')) {
                continue;
            }

            $this->install($element, $installed, $release);
        }
    }

    /**
     * Claim this run: true at most once every EVERY seconds, and to exactly one request.
     *
     * Both the interval and the lock live in the plugin's own `#__extensions` row, written with a
     * compare-and-swap — the update only lands if the row still holds the value that was read, so
     * two visitors arriving together cannot both start downloading the same package.
     *
     * It was a stamp file under JPATH_CACHE first. That constant is not the site's cache directory:
     * measured 08/09/2026 on Joomla 6, a front-end request reported JPATH_CACHE as
     * `administrator/cache`, which the web user cannot write — so `mkdir` failed, the method
     * returned, and the plugin did nothing at all without one line anywhere saying so. A row this
     * plugin already owns has no permissions to guess at.
     *
     * The claim is written BEFORE the work, so an update that fails is not retried on every single
     * request until it starts working.
     */
    private function claim(): bool
    {
        $db    = $this->getDatabase();
        $where = [
            $db->quoteName('type') . ' = ' . $db->quote('plugin'),
            $db->quoteName('folder') . ' = ' . $db->quote('system'),
            $db->quoteName('element') . ' = ' . $db->quote('claudecoworkupdate'),
        ];

        $current = (string) $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))->where($where)
        )->loadResult();

        $params = json_decode($current === '' ? '{}' : $current, true);

        if (!\is_array($params)) {
            $params = [];
        }

        if ((time() - (int) ($params['last_check'] ?? 0)) < self::EVERY) {
            return false;
        }

        $params['last_check'] = time();
        $next                 = json_encode($params);

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote($next))
                ->where(array_merge($where, [$db->quoteName('params') . ' = ' . $db->quote($current)]))
        )->execute();

        return $db->getAffectedRows() === 1;
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
     * The newest release an update server announces, as `['version' => …, 'url' => …]`, or null.
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
                $best = ['version' => $version, 'url' => $url];
            }
        }

        return $best;
    }

    /** Download, unpack, install, clean up. Every outcome is written to the log, including success. */
    private function install(string $element, string $installed, array $release): void
    {
        $tmp     = Factory::getApplication()->get('tmp_path');
        $package = null;
        $file    = InstallerHelper::downloadPackage($release['url']);

        if ($file === false) {
            $this->log(\sprintf('%s: could not download %s', $element, $release['url']), Log::WARNING);
            return;
        }

        try {
            $package = InstallerHelper::unpack($tmp . '/' . $file, true);

            if ($package === false || empty($package['extractdir'])) {
                $this->log(\sprintf('%s: %s did not unpack', $element, $release['version']), Log::WARNING);
                return;
            }

            // `method="upgrade"` in every Tracy manifest, so installing over the top IS the update.
            $ok = Installer::getInstance()->install($package['extractdir']);

            $this->log(
                \sprintf(
                    '%s: %s → %s %s',
                    $element,
                    $installed,
                    $release['version'],
                    $ok ? 'installed' : 'REFUSED by the installer'
                ),
                $ok ? Log::INFO : Log::WARNING
            );
        } finally {
            InstallerHelper::cleanupInstall($tmp . '/' . $file, $package['extractdir'] ?? null);
        }
    }

    private function log(string $message, int $priority): void
    {
        Log::add($message, $priority, 'plg_system_claudecoworkupdate');
    }
}
