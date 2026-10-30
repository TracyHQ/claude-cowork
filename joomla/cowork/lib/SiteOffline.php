<?php
/**
 * SiteOffline — who still sees the real site while it is offline.
 *
 * Tracy locks a customer's site on Joomla's offline page while a brief build writes it, and keeps it
 * locked when the build fails (`site.identity` sets `offline`, `offline_message` and
 * `display_offline_message`). During the build Tracy reads the site as an anonymous visitor would, to
 * check what it wrote; with plain Joomla offline mode it would read the offline page instead.
 *
 * So one more request sees the site: a front-end request that carries the site's own cowork token
 * as `Authorization: Bearer <token>`, the credential `content.json` already takes. No new secret,
 * nothing in a URL. The request is served exactly as a visitor sees the site online: nobody is
 * logged in, no session is opened, and the system plugin keeps the page out of every cache. Every
 * other request gets Joomla's own offline page, and the administrator login works as Joomla has it.
 *
 * Or a request carrying the lock's preview key as `X-Tracy-Preview-Key: <key>`: a key Tracy sets with
 * `site.identity` (`offline_preview_key`) for one lock, so a reader that must never hold the site
 * token (a shell inside the site's container) can still read the site. The site stores only the
 * key's SHA-256 and compares in constant time; the key is never taken from the query string or a
 * cookie, and turning the site online clears it. Served exactly as the token bypass is.
 *
 * Never by the request's address: the Joomla image's mod_remoteip rewrites REMOTE_ADDR from
 * X-Forwarded-For for any private peer, so a request through a proxy can claim to be 127.0.0.1.
 *
 * No Joomla dependency, like the rest of `lib/`: the system plugin hands in what Joomla holds.
 */

require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/SiteIdentity.php';

final class SiteOffline
{
    /** The `info` entry a caller checks before it turns a site offline: the keys, and how the bypass is asked for. */
    public const VERSION = 1;
    public const BYPASS = 'authorization-bearer';
    /** Further ways in, beside {@see BYPASS}; a v1 reader that knows only `bypass` is unaffected. */
    public const BYPASS_ALSO = ['preview-key'];

    /** The header a preview key travels in, as PHP names it in `$_SERVER`. */
    public const PREVIEW_HEADER = 'HTTP_X_TRACY_PREVIEW_KEY';

    /** @return array{version:int,fields:list<string>,bypass:string,bypass_also:list<string>} */
    public static function capability(): array
    {
        return ['version' => self::VERSION, 'fields' => SiteIdentity::OFFLINE_FIELDS, 'bypass' => self::BYPASS, 'bypass_also' => self::BYPASS_ALSO];
    }

    /**
     * Whether this request is served the real site although the site is offline, by the lock's
     * preview key: a site-client request, on an offline site holding a key, whose
     * `X-Tracy-Preview-Key` header hashes to it. No header, a malformed one or no stored key: no.
     *
     * @param scalar|null $offline Joomla's `offline` as the application holds it
     * @param array<string,mixed> $server the request's `$_SERVER`
     * @param mixed $storedHash the configuration's {@see SiteIdentity::PREVIEW_HASH}
     */
    public static function previewBypass(bool $siteClient, $offline, array $server, $storedHash): bool
    {
        if (!$siteClient || SiteIdentity::typed('offline', $offline) !== 1) return false;
        $stored = SiteIdentity::typed(SiteIdentity::PREVIEW_HASH, is_scalar($storedHash) ? $storedHash : null);
        if ($stored === '') return false;
        $key = $server[self::PREVIEW_HEADER] ?? null;
        if (!is_string($key) || preg_match(SiteIdentity::PREVIEW_KEY_PATTERN, $key) !== 1) return false;
        return hash_equals($stored, hash('sha256', $key));
    }

    /**
     * The token a request carries as `Authorization: Bearer <token>`, or null. Apache hands PHP the
     * header as `HTTP_AUTHORIZATION` (Joomla's own .htaccess passes it through) or, after a rewrite,
     * as `REDIRECT_HTTP_AUTHORIZATION`.
     *
     * @param array<string,mixed> $server the request's `$_SERVER`
     */
    public static function bearer(array $server): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $header = $server[$key] ?? null;
            if (is_string($header) && $header !== '') {
                return preg_match('/^Bearer ([^\s]+)$/D', trim($header), $m) ? $m[1] : null;
            }
        }
        return null;
    }

    /**
     * Whether this request is served the real site although the site is offline: a site-client
     * request, on a site that is offline, carrying the site's configured token. A site with no token
     * (or one too short to be a token) lets nobody through.
     *
     * @param scalar|null $offline Joomla's `offline` as the application holds it (a boolean, 0/1, "0"/"1")
     * @param array<string,mixed> $server the request's `$_SERVER`
     */
    public static function bypass(bool $siteClient, $offline, array $server, ?string $token): bool
    {
        if (!$siteClient || SiteIdentity::typed('offline', $offline) !== 1) return false;
        return Token::check($token, self::bearer($server));
    }
}
