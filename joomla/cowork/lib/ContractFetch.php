<?php
require_once __DIR__ . '/PackageDownload.php';

/**
 * ContractFetch — puts the contract profile a site is held to on disk, from the release the site
 * was installed from, before anything reads it (TCH #1568).
 *
 * ## Why profiles are fetched
 *
 * The package used to carry every published profile: 225 of them, 529 MB unpacked, a 70 MB zip.
 * A site that installed or self-updated it went from 182 MB to 628 MB, and an import then packed
 * those bytes back to Tracy. A site needs at most the profile it is held to and the one that
 * profile names in `quickstart.contract`. So the package now carries only an index of every
 * profile (`lib/contracts.json`, written by build.sh), and each profile is a release asset of its
 * own: `<RELEASES>joomla-v<installed version>/<id with "/" as "--">.zip`.
 *
 * ## What is trusted
 *
 * Only the index, which arrived inside the package with the code reading it. An id it does not
 * list is not fetched at all (the caller answers "does not carry", as it always has); a download
 * whose size or sha256 differs from the index is discarded. The zip is read here, not by ext-zip
 * (which hosts and the official PHP images do not all have): entries must be plain files or
 * folders with plain names, so nothing can land outside the profile folder. It is unpacked into a
 * temporary folder beside the target and renamed into place, so a reader never sees half a profile.
 *
 * ## Where a profile goes: lib/contracts/, else the spare folder under tmp_path
 *
 * A profile is read from `<lib>/contracts/<id>` when it is there: a site imported with its profile
 * in place, and a site updated from a package that carried profiles, keep reading theirs. A fetch
 * writes there too when the PHP user can. On a site whose component folder belongs to another user
 * it cannot: Tracy installs the package as root after handing the webroot to www-data, so 0.24.55
 * refused every new quickstart site with "cannot create …/lib/contracts/<slug>/<platform>". Such a
 * profile goes to the SPARE folder, `<tmp_path>/com_claudecowork/contracts/<id>`, and is read from
 * there. directory() is the one answer to "where is profile <id>": EngineFactory hands it to
 * QuickstartContract, named() reads through it, and script.php prunes and removes both folders.
 *
 * Why Joomla's tmp_path. It is the folder Joomla itself needs the PHP user to write: every install
 * and update from the administrator unpacks there, Extensions → Warnings complains when it is not
 * writable, System Information lists it among the folders that must be. And nothing in Joomla empties
 * it: "Clear Cache" and `cache:clean` purge the cache folders only (`cache/`, `administrator/cache/`),
 * and the installer and Joomla Update delete only the package files they put there themselves
 * (read in Joomla 6.1.4: com_cache, InstallerHelper::cleanupInstall, com_joomlaupdate). The cache
 * folders are purged, and `images/` and `media/` belong to the site's content, so none fits. A host that empties tmp costs a fetch, not the profile: it is fetched
 * again, checked against the same index. tmp_path may be served by the web server, which shows
 * nothing new: a profile is a public release asset, byte for byte.
 *
 * When lib/contracts/ is writable the fetch still writes there, as 0.24.55 did: that folder is
 * part of the component, so no host's tmp cleaner touches it, and every site that works today
 * stays exactly as it is. The spare folder is only where a fetch could not otherwise land.
 *
 * ## When it fails
 *
 * A marker `<version>.fetch-failed` beside the profile folder it tried holds the reason, and no new
 * attempt is made for BACKOFF seconds: a site that cannot reach GitHub must not spend TIMEOUT
 * seconds of every request finding that out again. The caller appends the reason to its
 * "does not carry" refusal.
 *
 * Plain PHP with no CMS and no namespace, like the rest of lib/, so tests/contract-fetch.php runs it
 * on its own.
 */
final class ContractFetch
{
    /** A profile id, as EngineFactory accepts it: nothing in it can climb out of `contracts/`. */
    public const ID = '~^[a-z][a-z0-9-]{1,40}/[a-z][a-z0-9]{0,9}/[0-9]+\.[0-9]+\.[0-9]+$~D';

    /** ensure()'s answer for an id the index does not list: refused as before, with no reason added. */
    public const UNKNOWN = '';

    /** Seconds for the whole download. Tracy's door calls give up at about 20 s; a profile zip is 50–500 KB. */
    public const TIMEOUT = 15;

    /** Seconds after a failure during which no new attempt is made. */
    public const BACKOFF = 300;

    /** How many `quickstart.contract` links are followed from the profile asked for. */
    public const MAX_NAMED = 4;

    /** Bounds on what one profile zip may hold; the largest published profile is 9.9 MB in 6 files. */
    public const MAX_ZIP_BYTES = 32 * 1024 * 1024;
    public const MAX_UNPACKED = 128 * 1024 * 1024;
    public const MAX_ENTRIES = 256;

    /** Where the spare folder is, under Joomla's tmp_path: {@see spareOf()}. script.php keeps a copy. */
    public const SPARE = 'com_claudecowork/contracts';

    private string $lib;
    private ?string $spare;
    private ?string $release;
    /** @var callable(string,string,int,string):array{ok:bool,error:?string} */
    private $download;
    /** @var callable():int */
    private $now;
    private ?array $index = null;

    /**
     * @param string      $lib     the component's lib/: `contracts.json` and `contracts/` are in it
     * @param string|null $release the release's asset folder with a trailing slash, or null when the
     *                             installed version is unknown (nothing can then be fetched)
     * @param callable|null $download fn(url, target, bytes, sha256): PackageDownload::toFile's answer
     * @param string|null $spare   the spare folder ({@see spareOf()}), or null for lib/contracts/ alone
     */
    public function __construct(string $lib, ?string $release, array $curlOptions = [], ?callable $download = null, ?callable $now = null, ?string $spare = null)
    {
        $this->lib = rtrim($lib, '/');
        $this->spare = $spare === null ? null : rtrim($spare, '/');
        $this->release = $release;
        $this->download = $download ?? static function (string $url, string $target, int $bytes, string $sha256) use ($curlOptions): array {
            return \PackageDownload::toFile($url, $target, $bytes, $sha256, $curlOptions, null, self::TIMEOUT);
        };
        $this->now = $now ?? static fn(): int => time();
    }

    /** The release asset of a profile: its id with "/" as "--", zipped. build.sh names it the same way. */
    public static function asset(string $id): string
    {
        return str_replace('/', '--', $id) . '.zip';
    }

    /** The release asset folder of an installed component version, or null when the version is unknown. */
    public static function releaseOf(?string $version): ?string
    {
        return $version !== null && preg_match('/^[0-9A-Za-z.+-]+$/D', $version) ? \PackageDownload::RELEASES . 'joomla-v' . $version . '/' : null;
    }

    /** The spare folder of a site: under Joomla's `tmp_path`, or the site's `tmp/` when none is set (Joomla's own default). */
    public static function spareOf(?string $tmpPath, string $siteRoot): string
    {
        $tmp = trim((string) $tmpPath);
        return rtrim($tmp !== '' ? $tmp : $siteRoot . '/tmp', '/') . '/' . self::SPARE;
    }

    /** The folders profiles are read from, in order: the component's own, then the spare one. */
    public function roots(): array
    {
        return $this->spare === null ? [$this->lib . '/contracts'] : [$this->lib . '/contracts', $this->spare];
    }

    /**
     * Where profile `$id` is on this site: the first folder holding its manifest, else where it
     * belongs (lib/contracts/), which a reader then finds empty. Every reader asks here.
     */
    public function directory(string $id): string
    {
        foreach ($this->roots() as $root)
            if (is_file($root . '/' . $id . '/manifest.json')) return $root . '/' . $id;
        return $this->lib . '/contracts/' . $id;
    }

    /**
     * Profile `$id` on disk, and the profiles it names after it. Never throws.
     *
     * @return string|null null when `$id` and every profile it names are on disk now; UNKNOWN when
     *                     the index does not list `$id`; otherwise why one could not be fetched. A
     *                     named profile that cannot be made present fails `$id` too (`$id` itself
     *                     stays on disk once fetched).
     */
    public function ensure(string $id): ?string
    {
        $failure = $this->one($id);
        if ($failure !== null) return $failure;
        $seen = [$id => true];
        for ($at = $id, $step = 0; $step < self::MAX_NAMED; $step++) {
            $named = $this->named($at);
            if ($named === null || isset($seen[$named])) break;
            $seen[$named] = true;
            $failure = $this->one($named);
            if ($failure === self::UNKNOWN) return 'fetch failed: ' . $named . ', which it names, is not listed in contracts.json';
            if ($failure !== null) return 'fetch failed: ' . $named . ', which it names: ' . preg_replace('/^fetch failed: /', '', $failure);
            $at = $named;
        }
        return null;
    }

    /** The profile `$id`'s manifest names in `quickstart.contract`, when it is another well-formed id. */
    public function named(string $id): ?string
    {
        $raw = @file_get_contents($this->directory($id) . '/manifest.json');
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        $named = is_array($manifest) ? ($manifest['quickstart']['contract'] ?? null) : null;
        return is_string($named) && $named !== $id && preg_match(self::ID, $named) ? $named : null;
    }

    private function one(string $id): ?string
    {
        if (!preg_match(self::ID, $id)) return self::UNKNOWN;
        if (is_file($this->directory($id) . '/manifest.json')) return null;

        $index = $this->index();
        if ($index === null) return 'contracts.json is unreadable';
        $entry = $index[$id] ?? null;
        if ($entry === null) return self::UNKNOWN;
        if (!is_array($entry) || ($entry['asset'] ?? null) !== self::asset($id) || !is_string($entry['sha256'] ?? null)
            || !preg_match('/^[0-9a-f]{64}$/D', $entry['sha256']) || !is_int($entry['bytes'] ?? null)
            || $entry['bytes'] <= 0 || $entry['bytes'] > self::MAX_ZIP_BYTES)
            return 'contracts.json lists it malformed';

        foreach ($this->roots() as $root) {
            $previous = is_file($marker = $root . '/' . $id . '.fetch-failed') ? json_decode((string) @file_get_contents($marker), true) : null;
            if (is_array($previous) && is_int($previous['at'] ?? null) && ($this->now)() - $previous['at'] < self::BACKOFF)
                return 'fetch failed: ' . (is_string($previous['reason'] ?? null) ? $previous['reason'] : 'unknown') . ' (retried after ' . self::BACKOFF . ' s)';
        }

        // Nowhere to write costs no download, and leaves no marker: there is no place for one.
        $unwritable = null;
        $target = $this->place($id, $unwritable);
        if ($target === null) return 'fetch failed: ' . $unwritable;
        $reason = $this->fetch($id, $entry, $target);
        if ($reason === null) {
            foreach ($this->roots() as $root) @unlink($root . '/' . $id . '.fetch-failed');
            return null;
        }
        // The marker goes beside the profile even when the fetch failed before making its folder.
        @file_put_contents($target . '.fetch-failed', json_encode(['at' => ($this->now)(), 'reason' => $reason]));
        return 'fetch failed: ' . $reason;
    }

    /**
     * Where a fetch of `$id` lands: under the first root whose `<slug>/<platform>/` folder exists or
     * can be made, and is writable. Null when none, with `$why` naming every folder tried.
     */
    private function place(string $id, ?string &$why): ?string
    {
        $failed = [];
        foreach ($this->roots() as $root) {
            $parent = dirname($root . '/' . $id);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) $failed[] = 'cannot create ' . $this->shown($parent);
            elseif (!is_writable($parent)) $failed[] = $this->shown($parent) . ' is not writable';
            else return $root . '/' . $id;
        }
        $why = implode('; ', $failed);
        return null;
    }

    /** @return string|null why the profile is not on disk, or null when it now is */
    private function fetch(string $id, array $entry, string $target): ?string
    {
        if ($this->release === null) return 'the installed version is unknown';
        $parent = dirname($target);

        $temp = $parent . '/.' . basename($target) . '.' . bin2hex(random_bytes(6));
        $zip = $temp . '.zip';
        try {
            $got = ($this->download)($this->release . $entry['asset'], $zip, $entry['bytes'], $entry['sha256']);
            if (!is_array($got) || empty($got['ok'])) return self::short((string) ($got['error'] ?? 'download failed'));
            // Checked again here whatever the downloader said: the index is the only thing trusted.
            clearstatcache(true, $zip);
            $bytes = is_file($zip) ? (int) filesize($zip) : 0;
            if ($bytes !== $entry['bytes']) return 'size ' . $bytes . ' is not the listed ' . $entry['bytes'];
            if (!hash_equals($entry['sha256'], (string) hash_file('sha256', $zip))) return 'sha256 is not the listed one';

            self::extract($zip, $temp);
            $manifest = json_decode((string) @file_get_contents($temp . '/manifest.json'), true);
            if (!is_array($manifest) || ($manifest['id'] ?? null) !== $id) return 'the asset holds no manifest for ' . $id;

            if (is_dir($target)) {
                // A folder without a manifest is half of something; it is set aside, never read.
                if (is_file($target . '/manifest.json')) return null;
                $aside = $temp . '.aside';
                if (!@rename($target, $aside)) return 'cannot replace ' . $this->shown($target);
                self::remove($aside);
            }
            if (!@rename($temp, $target) && !is_file($target . '/manifest.json')) return 'cannot move the profile into place';
            return null;
        } catch (\RuntimeException $e) {
            return self::short($e->getMessage());
        } finally {
            @unlink($zip);
            self::remove($temp);
        }
    }

    /** contracts.json's `contracts` map, or null when it cannot be read. */
    private function index(): ?array
    {
        if ($this->index === null) {
            $raw = @file_get_contents($this->lib . '/contracts.json');
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($json) || !is_array($json['contracts'] ?? null)) return null;
            $this->index = $json['contracts'];
        }
        return $this->index;
    }

    /**
     * Unpacks a zip that build.sh wrote into `$into`, which must not exist yet. Throws on anything
     * else: an entry that is not a plain file or folder (a symlink above all), a name that is not
     * plain segments (`..`, absolute, backslashes, drive letters), encryption, zip64, a method other
     * than store or deflate, a size or CRC that does not match, a duplicate.
     */
    public static function extract(string $zip, string $into): void
    {
        $data = @file_get_contents($zip);
        if (!is_string($data) || strlen($data) > self::MAX_ZIP_BYTES) throw new \RuntimeException('the zip cannot be read');
        $end = strrpos($data, "PK\x05\x06");
        if ($end === false || strlen($data) - $end < 22) throw new \RuntimeException('the zip has no directory');
        $eocd = unpack('vdisk/vstart/vhere/ventries/Vsize/Voffset/vcomment', substr($data, $end + 4, 18));
        if ($eocd['disk'] !== 0 || $eocd['start'] !== 0 || $eocd['here'] !== $eocd['entries'] || $end + 22 + $eocd['comment'] !== strlen($data)
            || $eocd['entries'] === 0xFFFF || $eocd['offset'] === 0xFFFFFFFF)
            throw new \RuntimeException('the zip is split or zip64');
        if ($eocd['entries'] === 0 || $eocd['entries'] > self::MAX_ENTRIES) throw new \RuntimeException('the zip holds ' . $eocd['entries'] . ' entries');
        if (!@mkdir($into, 0755)) throw new \RuntimeException('cannot create the temporary folder');

        $at = $eocd['offset'];
        $seen = [];
        $unpacked = 0;
        for ($i = 0; $i < $eocd['entries']; $i++) {
            if (substr($data, $at, 4) !== "PK\x01\x02") throw new \RuntimeException('the zip directory is damaged');
            $c = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr($data, $at + 4, 42));
            $name = substr($data, $at + 46, $c['name']);
            $at += 46 + $c['name'] + $c['extra'] + $c['comment'];

            $folder = substr($name, -1) === '/';
            $path = $folder ? substr($name, 0, -1) : $name;
            foreach (explode('/', $path) as $segment)
                if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,99}$/D', $segment)) throw new \RuntimeException('unsafe entry ' . self::short($name, 60));
            if (isset($seen[$path])) throw new \RuntimeException('duplicate entry ' . $path);
            $seen[$path] = true;
            // Made on unix: the file type is in the high bits. Only a regular file or a folder.
            $type = ($c['external'] >> 16) & 0170000;
            if (($c['made'] >> 8) === 3 && $type !== 0 && $type !== ($folder ? 0040000 : 0100000)) throw new \RuntimeException('entry ' . $path . ' is not a plain file');
            if ($c['flags'] & 1) throw new \RuntimeException('entry ' . $path . ' is encrypted');

            if ($folder) {
                if (!is_dir($into . '/' . $path) && !@mkdir($into . '/' . $path, 0755, true)) throw new \RuntimeException('cannot create ' . $path);
                continue;
            }
            $unpacked += $c['usize'];
            if ($unpacked > self::MAX_UNPACKED) throw new \RuntimeException('the profile unpacks to more than ' . self::MAX_UNPACKED . ' bytes');
            if (substr($data, $c['local'], 4) !== "PK\x03\x04") throw new \RuntimeException('entry ' . $path . ' is damaged');
            $l = unpack('vname/vextra', substr($data, $c['local'] + 26, 4));
            $packed = substr($data, $c['local'] + 30 + $l['name'] + $l['extra'], $c['csize']);
            if (strlen($packed) !== $c['csize']) throw new \RuntimeException('entry ' . $path . ' is truncated');
            if ($c['method'] === 0) $bytes = $packed;
            elseif ($c['method'] === 8) $bytes = $c['usize'] === 0 ? '' : @gzinflate($packed, $c['usize']);
            else throw new \RuntimeException('entry ' . $path . ' uses compression method ' . $c['method']);
            if (!is_string($bytes) || strlen($bytes) !== $c['usize'] || hash('crc32b', $bytes) !== sprintf('%08x', $c['crc']))
                throw new \RuntimeException('entry ' . $path . ' does not match its checksum');

            $file = $into . '/' . $path;
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) throw new \RuntimeException('cannot create ' . dirname($path));
            if (@file_put_contents($file, $bytes) !== strlen($bytes)) throw new \RuntimeException('cannot write ' . $path);
        }
    }

    /** Removes a file or a folder tree; symlinks are removed, never followed. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry)
            if ($entry !== '.' && $entry !== '..') self::remove($path . '/' . $entry);
        @rmdir($path);
    }

    /** A reason short enough to append to a refusal. */
    private static function short(string $reason, int $max = 160): string
    {
        $reason = trim(preg_replace('/\s+/', ' ', $reason));
        return strlen($reason) > $max ? substr($reason, 0, $max - 3) . '...' : $reason;
    }

    /**
     * A folder as the site knows it: from `administrator/` on, or from `tmp_path/` on in the spare
     * folder. A refusal never shows the server's own path.
     */
    private function shown(string $path): string
    {
        if ($this->spare !== null && strpos($path . '/', $this->spare . '/') === 0) return 'tmp_path/' . self::SPARE . substr($path, strlen($this->spare));
        $at = strpos($path, '/administrator/');
        return $at === false ? basename($path) : substr($path, $at + 1);
    }
}
