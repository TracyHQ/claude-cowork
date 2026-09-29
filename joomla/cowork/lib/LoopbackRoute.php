<?php
/**
 * LoopbackRoute — how this site asks itself for one of its own pages, in the order to try.
 *
 * Plain http to 127.0.0.1, port 80, with the site's Host (port included) first: a Tracy fleet copy serves plain http inside its
 * container even when its public address (the site root / home URL) is https, and a copy behind a TLS proxy has no
 * certificate of its own. Only when that fails or redirects (a site that forces https) is the https
 * route tried, with curl resolving the site's own name to 127.0.0.1 so the TLS name and the virtual
 * host are the site's. Redirects are never followed: a 3xx is not the page.
 *
 * Every route is curl, with the proxy switched off ({@see curlOptions}): a proxy — Joomla's own
 * `proxy_enable`, or http_proxy/https_proxy in the environment — would carry the call to the
 * customer's real host instead of this copy. PHP streams honour that proxy config and have no way
 * to skip the loopback's TLS check, so without curl there is no route, and nothing is calibrated.
 *
 * Plain PHP, no CMS; the calls are made by JoomlaDerivedRows::fetch and WordPressDerivedRows::httpGet.
 */
declare(strict_types=1);

final class LoopbackRoute
{
    /**
     * @param bool $curl whether curl is there: without it, no route
     * @param bool $tls whether that curl speaks TLS ({@see tls}): without it an https site keeps only the plain http route
     * @return list<array{url:string,host:string,resolve:?string}> base URLs (ending in /) to append a page path to
     */
    public static function routes(string $root, bool $curl = true, bool $tls = true): array
    {
        if (!$curl) return [];
        $parts = parse_url($root) ?: [];
        $https = strtolower((string) ($parts['scheme'] ?? 'http')) === 'https';
        $name = (string) ($parts['host'] ?? 'localhost');
        $path = rtrim((string) ($parts['path'] ?? ''), '/') . '/';
        // The Host is the address as named, port included; the connection is port 80 first. A named
        // port is often not this server's (a fleet copy's `…tracy.test:51710` is the proxy outside the
        // container, where nothing inside listens), and the site's server answers its Host on 80.
        $host = $name . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        $plain = ['url' => 'http://127.0.0.1' . $path, 'host' => $host, 'resolve' => null];
        if (!$https) {
            $routes = [$plain];
            // Then the named port itself, for a site that really listens there. Not for https: plain
            // http to a TLS port answers 400, which would end the page before the https route is tried.
            if (isset($parts['port']) && (int) $parts['port'] !== 80) $routes[] = ['url' => 'http://127.0.0.1:' . (int) $parts['port'] . $path, 'host' => $host, 'resolve' => null];
            return $routes;
        }
        if (!$tls) return [$plain];
        $port = (int) ($parts['port'] ?? 443);
        return [$plain, ['url' => 'https://' . $host . $path, 'host' => $host, 'resolve' => $name . ':' . $port . ':127.0.0.1']];
    }

    /**
     * Whether this PHP's curl was built with TLS. A curl without it fails every https request, so the
     * https route would only spend the budget: {@see routes} leaves it out when this says no.
     */
    public static function tls(): bool
    {
        return function_exists('curl_version') && defined('CURL_VERSION_SSL') && ((int) (curl_version()['features'] ?? 0) & CURL_VERSION_SSL) !== 0;
    }

    /**
     * The curl options of one route, used by Joomla's `transport.curl` and WordPress' httpGet: applied after the transport's own
     * proxy options and on the same handle (Joomla 6.1 CurlTransport::request), so they win.
     * An empty CURLOPT_PROXY and a `*` CURLOPT_NOPROXY leave out every proxy, environment ones too.
     */
    public static function curlOptions(array $route): array
    {
        $options = [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
        if ($route['resolve'] !== null) $options += [CURLOPT_RESOLVE => [$route['resolve']], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
        return $options;
    }

    /** What one answer means: 'page' (200), 'next' (a redirect, or no answer: code 0) or 'none' (the route works, the page does not). */
    public static function outcome(int $code): string
    {
        if ($code === 200) return 'page';
        return $code === 0 || ($code >= 300 && $code < 400) ? 'next' : 'none';
    }
}
