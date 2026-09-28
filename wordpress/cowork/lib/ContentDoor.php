<?php
/**
 * ContentDoor — the two ways into the content reader, and what each answers before it reads.
 *
 * `GET <home>/content.json` with `Authorization: Bearer <site token>`, and the `content.read`
 * action on the existing POST endpoint (token in the body, as every action). Both are the SAME
 * reader behind the same token; the token is the site's service principal, not a person, so the
 * principal recorded in a cursor is `site-token` whichever door was used.
 *
 * Order matters and is fixed here: method, then authentication, then the query. A request with no
 * valid credential learns nothing — not whether an id exists, not a count, not whether the site
 * has a token at all. A token in the query string is never read: it is an unknown query name, and
 * it is refused only after authentication, so the answer to an anonymous caller is always 401.
 *
 * Every answer is `private, no-store`. v1 has no ETag and never answers 304, so no conditional
 * request can be served from a cache before the credential is checked.
 *
 * Plain PHP, no WordPress.
 */

require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/ContentReader.php';

final class ContentDoor
{
    public const PRINCIPAL = 'site-token';
    /** What a direct GET may read. The POST action takes its scope from the relay (below). */
    public const GET_SCOPE = 'published';
    public const SCOPES = ['published', 'editorial'];

    /** @return array<string,string> */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Vary' => 'Authorization',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }

    /** The key a cursor is signed with: bound to the token, so clearing or changing it voids every cursor. */
    public static function cursorSecret(string $token): string
    {
        return hash_hmac('sha256', 'tracy-content-cursor/v1', $token);
    }

    /** The bearer credential of an `Authorization` header, or null when it is not exactly one. */
    public static function bearer(?string $authorization): ?string
    {
        if ($authorization === null || !preg_match('/^Bearer ([\x21-\x7e]+)$/D', trim($authorization), $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Answer `GET /content.json`.
     *
     * @param callable(string $principal, string $scope, string $secret): ContentReader $reader built only
     *        after authentication, so an anonymous request touches no content at all
     * @return array{status:int,headers:array<string,string>,body:array<string,mixed>}
     */
    public static function get(string $method, ?string $authorization, string $rawQuery, ?string $token, callable $reader): array
    {
        $headers = self::headers();
        if ($method !== 'GET') {
            return self::answer(405, $headers + ['Allow' => 'GET'], (new ContentReadError('CONTENT_BAD_QUERY', 405, 'Use GET.'))->body());
        }
        $provided = self::bearer($authorization);
        if (!Token::check($token, $provided)) {
            return self::answer(401, $headers + ['WWW-Authenticate' => 'Bearer realm="content"'],
                (new ContentReadError('CONTENT_UNAUTHENTICATED', 401, 'Authentication required.'))->body());
        }
        try {
            $query = ContentReader::parseQuery($rawQuery);
            $body = $reader(self::PRINCIPAL, self::GET_SCOPE, self::cursorSecret((string) $token))->read($query);
            return self::answer(200, $headers, $body);
        } catch (ContentReadError $e) {
            return self::answer($e->status, $headers, $e->body());
        } catch (Throwable $e) {
            return self::answer(503, $headers, (new ContentReadError('CONTENT_SOURCE_UNAVAILABLE', 503, 'The site could not be read.'))->body());
        }
    }

    /** `site-token`, or one seat as the relay's 64-hex HMAC (integration decision 25/09 §2). */
    public const PRINCIPAL_SHAPE = '/^(site-token|seat:[0-9a-f]{64})$/D';

    /**
     * Answer the `content.read` action in the canonical envelope: `params` is the flat query the
     * GET takes, and the authority sits beside it at the top level — `contentPrincipal`
     * (`site-token` by default, or `seat:<hmac>`) and `contentScope` (`published` by default, or
     * `editorial`). Both are set by the server that holds this site's token and relays a seat,
     * never by an agent: whoever holds the token can already read everything, so this door cannot
     * tell a seat from a claim. A cursor is bound to the principal and scope. The answer is the
     * envelope itself or `{error}`, with the HTTP status the spec gives. The token was already
     * checked by the caller of this method.
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    public static function action(array $request, string $token, callable $reader): array
    {
        try {
            $query = $request['params'] ?? [];
            $scope = $request['contentScope'] ?? self::GET_SCOPE;
            $principal = $request['contentPrincipal'] ?? self::PRINCIPAL;
            if (!is_array($query) || ($query !== [] && array_keys($query) === range(0, count($query) - 1))
                || !is_string($scope) || !in_array($scope, self::SCOPES, true)
                || !is_string($principal) || !preg_match(self::PRINCIPAL_SHAPE, $principal)) {
                throw ContentReader::bad();
            }
            return self::answer(200, [], $reader($principal, $scope, self::cursorSecret($token))->read($query));
        } catch (ContentReadError $e) {
            return self::answer($e->status, [], $e->body());
        } catch (Throwable $e) {
            return self::answer(503, [], (new ContentReadError('CONTENT_SOURCE_UNAVAILABLE', 503, 'The site could not be read.'))->body());
        }
    }

    private static function answer(int $status, array $headers, array $body): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
