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

    /** A principal the relay names: the site token itself, or one seat under an opaque label. */
    public const PRINCIPAL_SHAPE = '/^(site-token|seat:[A-Za-z0-9_-]{8,64})$/D';

    /**
     * Answer the `content.read` action. `params.query` is the same name → value map the GET takes.
     * `params.scope` (`published` by default, or `editorial`) and `params.principal` (`site-token`
     * by default, or `seat:<opaque label>`) are set by the SERVER that holds this site's token and
     * relays a seat — never by an agent: whoever holds the token can already read everything, so
     * this door cannot tell a seat from a claim, and the relay must decide both from its own grant
     * record. A cursor is bound to the principal, so one seat cannot continue another's scan.
     * Any other parameter is refused. The token was already checked by the caller of this method.
     *
     * @return array<string,mixed> `{ok:true, status:200, content}` or `{ok:false, error, status, message, body}`
     */
    public static function action(array $params, string $token, callable $reader): array
    {
        try {
            $query = $params['query'] ?? [];
            $scope = $params['scope'] ?? self::GET_SCOPE;
            $principal = $params['principal'] ?? self::PRINCIPAL;
            if (array_diff(array_keys($params), ['query', 'scope', 'principal']) !== []
                || !is_array($query) || !is_string($scope) || !in_array($scope, self::SCOPES, true)
                || !is_string($principal) || !preg_match(self::PRINCIPAL_SHAPE, $principal)) {
                throw ContentReader::bad();
            }
            $content = $reader($principal, $scope, self::cursorSecret($token))->read($query);
            return ['ok' => true, 'status' => 200, 'content' => $content];
        } catch (ContentReadError $e) {
            return ['ok' => false, 'error' => $e->reason, 'status' => $e->status, 'message' => $e->getMessage(), 'body' => $e->body()];
        } catch (Throwable $e) {
            $error = new ContentReadError('CONTENT_SOURCE_UNAVAILABLE', 503, 'The site could not be read.');
            return ['ok' => false, 'error' => $error->reason, 'status' => 503, 'message' => $error->getMessage(), 'body' => $error->body()];
        }
    }

    private static function answer(int $status, array $headers, array $body): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
