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
     * @return array<int, array{name:string, type:string, element:string, version:?string, enabled:bool}>
     */
    public function listInstalled(): array;
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
