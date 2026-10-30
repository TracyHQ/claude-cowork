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
 * No Joomla dependency, like the rest of `lib/`: the system plugin hands in what Joomla holds.
 */

require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/SiteIdentity.php';

final class SiteOffline
{
    /** The `info` entry a caller checks before it turns a site offline: the keys, and how the bypass is asked for. */
    public const VERSION = 1;
    public const BYPASS = 'authorization-bearer';

    /** @return array{version:int,fields:list<string>,bypass:string} */
    public static function capability(): array
    {
        return ['version' => self::VERSION, 'fields' => SiteIdentity::OFFLINE_FIELDS, 'bypass' => self::BYPASS];
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
