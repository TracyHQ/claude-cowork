<?php
/**
 * Extensions — the one thing on this site that Tracy may WRITE.
 *
 * Everything else in this component reads: it dumps a database, it packs a webroot, it counts
 * files. Installing an extension changes the site, so the surface is deliberately narrow — one
 * verb that takes one URL, and a listing so a caller can see what is already there without
 * guessing. There is no uninstall, no file write, no configuration change: those are jobs a
 * person does in an admin panel they can see.
 *
 * An interface for the same two reasons as {@see Uploader}. It keeps Joomla out of the engine,
 * so the engine stays testable without a CMS. And it keeps the shape of the trust visible in
 * one place: whatever the real implementation can do is exactly what a token can reach.
 */
interface ExtensionManager
{
    /**
     * Installs from a package the SITE downloads itself.
     *
     * A URL rather than an upload because that is the path hardened hosts leave open:
     * `file_uploads = Off` is a normal setting, and it stops an upload before Joomla ever sees
     * it. The caller is trusted to have signed or vetted the address — this component's own
     * token is what stands between a stranger and this method.
     *
     * @return array{ok:bool, error?:string, name?:string, type?:string, version?:string}
     */
    public function installFromUrl(string $url): array;

    /**
     * What is installed, as the site itself reports it — enough to tell "already there" from
     * "there in another version", which is the question a caller actually has.
     *
     * `folder` is the plugin group and `package_id` the package that installed the row, both
     * straight from `#__extensions`. They are here because a caller cannot reconstruct either
     * one: two products ship a plugin whose element is `com_k2`, and a package arrives as many
     * rows with nothing else to say they were one purchase.
     *
     * @return array<int, array{name:string, type:string, element:string, folder:string,
     *                          package_id:int, version:?string, enabled:bool}>
     */
    public function listInstalled(): array;

    /**
     * Publish or unpublish one installed extension — the `enabled` column of `#__extensions`.
     *
     * Added in 0.9.0, and it reverses a line this file used to hold: the surface was "one verb
     * that takes one URL", with enable/disable left to an admin panel. That held while the only
     * job was standing a copy up. It stopped holding the moment the job became finishing a piece
     * of work on a customer's site, where three ordinary steps — switch a cache plugin on, turn a
     * coming-soon page off, publish the plugin an install just laid down — all sit behind this one
     * column and nothing else in the catalog can reach it. `extensionParams` cannot: its whitelist
     * is `params` alone, by design, because everything else in that row belongs to the installer.
     *
     * Still narrower than install. It flips one boolean on a row that already exists, it creates
     * nothing and removes nothing, and it is perfectly reversible — which is why, unlike
     * `installFromUrl`, it belongs in the undo log.
     *
     * `folder` is the plugin group and is null for everything that is not a plugin. Returns the
     * value the row held BEFORE the write, which is what the caller records to undo it.
     *
     * @return array{ok:bool, error?:string, before?:bool}
     */
    public function setEnabled(string $type, string $element, ?string $folder, bool $enabled): array;

    /**
     * What this install says is core (ADR 0070 addendum: the per-site core source).
     *
     * The flag is computed HERE, because only the platform adapter knows its own semantics:
     * Joomla 4+ marks core rows with `locked` (added in 4.0 precisely because `protected` had
     * come to mean only "cannot be disabled"), Joomla 3 has only `protected`. A caller gets a
     * neutral `core` boolean and never re-derives it — measured on a real 3→4→5 site, where
     * `protected` called com_contact third-party and `locked` was right about every row.
     *
     * @return array{platform:string, platformVersion:string,
     *               extensions: array<int, array{type:string, element:string, folder:?string,
     *                                            core:bool, enabled:bool, version:?string}>}
     */
    public function coreManifest(): array;
}

/**
 * What a package URL has to look like before it is fetched at all.
 *
 * Not a security boundary on its own — the token is that — but a cheap way to fail early and
 * loudly on the two mistakes that would otherwise fail deep inside Joomla's installer with a
 * message nobody can act on: a plain-HTTP address, and something that is not an archive.
 *
 * `.zip` only, because Joomla names the downloaded file after the URL and derives the archive
 * type from that name. A URL with a query string reads as a file with no extension and fails
 * with "Unable to detect manifest file" — see `componentPackageUrl` on the Tracy side, which
 * exists because of exactly that.
 */
final class PackageUrl
{
    /** @return array{ok:true}|array{ok:false, error:string} */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'not a URL'];
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            return ['ok' => false, 'error' => 'https required'];
        }
        $path = isset($parts['path']) ? strtolower((string) $parts['path']) : '';
        if (substr($path, -4) !== '.zip') {
            return ['ok' => false, 'error' => 'package URL must end in .zip'];
        }
        return ['ok' => true];
    }
}
