<?php
/**
 * ContentReader — the `tracy-content/v1` protocol over a projection a site has already built.
 *
 * Content API v1 (TCH `docs/references/content-api-v1.md`) is one read-only listing: summaries by
 * default, one content in full by `id`, filters that AND, continuation by opaque cursor. This class
 * owns everything in that sentence that is not WordPress: which query is valid, how a page is cut
 * to the byte budget, how a cursor is signed and when it is refused. The site's side — which rows
 * exist, what they say, who may read them — arrives as a `ContentSource`.
 *
 * A cursor is bound to the site, the principal, the read scope, the filters and the snapshot
 * revision, and signed with a key only the site holds. The revision is the site's fingerprint of
 * everything the projection read; if it moved between two pages, the scan is refused (409) rather
 * than stitched from two states of the site. Nothing here keeps state between requests.
 *
 * Plain PHP, no WordPress.
 */

/** Everything the reader needs from a site, already filtered to what the caller may read. */
interface ContentSource
{
    /** @return array{id:string,name:?string,url:string,defaultLocale:?string,locales:string[]} */
    public function site(): array;

    /** @return array{quickstartTag:string,quickstartVersion:string,contractId:?string,contractHash:?string}|null */
    public function provenance(): ?array;

    /** The snapshot revision of the readable scope: changes when anything the projection reads changes. */
    public function revision(): string;

    /** @return array<int,array<string,mixed>> every readable content as a summary, in listing order */
    public function summaries(): array;

    /**
     * One readable content in full, or null when it is not readable. With `$withBody` false the
     * source may leave `bodyHtml` unbuilt (null): a block read does not need the whole page's HTML.
     *
     * @return array<string,mixed>|null
     */
    public function detail(string $id, bool $withBody = true): ?array;

    /** @return array<int,array{code:string,message:string}> what the scope does not cover */
    public function unresolved(): array;

    /** The request is answered: end whatever consistent read the source holds open. */
    public function release(): void;
}

final class ContentReadError extends RuntimeException
{
    /** @var string one of the Content API error codes */
    public $reason;
    /** @var int HTTP status */
    public $status;
    /** @var array<string,mixed> */
    public $extra;

    public function __construct(string $reason, int $status, string $message, array $extra = [])
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->status = $status;
        $this->extra = $extra;
    }

    /** @return array{error:array<string,mixed>} */
    public function body(): array
    {
        return ['error' => ['code' => $this->reason, 'message' => $this->getMessage()] + $this->extra];
    }
}

final class ContentReader
{
    public const SCHEMA_VERSION = 'tracy-content/v1';
    public const MAX_BYTES = 262144;
    /** Headroom kept for the envelope around the payload when a page is cut to size. */
    private const ENVELOPE_MARGIN = 4096;
    public const TTL = 300;
    public const DEFAULT_LIMIT = 30;
    public const MAX_LIMIT = 100;
    public const BLOCK_PAGE = 100;
    public const TYPES = ['page', 'article', 'service', 'project', 'shared', 'generic'];
    /** Query names v1 defines. `itemsCursor` is defined but not served by this adapter. */
    private const KNOWN = ['id', 'type', 'locale', 'limit', 'cursor', 'blocksCursor', 'blockId', 'itemsCursor'];
    private const UNSERVED = ['itemsCursor'];

    /** @var ContentSource */
    private $source;
    /** @var string */
    private $secret;
    /** @var string */
    private $principal;
    /** @var string */
    private $scope;
    /** @var int */
    private $now;
    /** @var int */
    private $maxBytes;

    /**
     * @param string $secret    key the cursor is signed with; changing it (a new token) voids every cursor
     * @param string $principal who is reading (`site-token` for the site's own token)
     * @param string $scope     what that principal may read (`published`, `editorial`)
     */
    public function __construct(ContentSource $source, string $secret, string $principal, string $scope, int $now, int $maxBytes = self::MAX_BYTES)
    {
        if (strlen($secret) < 16) {
            throw new InvalidArgumentException('A cursor secret needs at least 16 bytes');
        }
        $this->source = $source;
        $this->secret = $secret;
        $this->principal = $principal;
        $this->scope = $scope;
        $this->now = $now;
        $this->maxBytes = $maxBytes;
    }

    public static function encode($value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * A raw query string as name → value, refusing what v1 refuses: a name twice, an array name,
     * an empty value. Parsed by hand because `$_GET` folds duplicates and arrays silently.
     *
     * @return array<string,string>
     */
    public static function parseQuery(string $raw): array
    {
        $out = [];
        foreach ($raw === '' ? [] : explode('&', $raw) as $pair) {
            if ($pair === '') {
                throw self::bad();
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode(str_replace('+', ' ', $key));
            $value = rawurldecode(str_replace('+', ' ', $value));
            if (isset($out[$key]) || strpbrk($key, '[]') !== false) {
                throw self::bad();
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public static function bad(): ContentReadError
    {
        return new ContentReadError('CONTENT_BAD_QUERY', 400, 'Invalid content query.');
    }

    /**
     * Answer one request. Throws `ContentReadError` for every refusal, so a caller can never
     * mistake a refusal for an empty listing.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed> a `tracy-content/v1` envelope
     */
    public function read(array $query): array
    {
        try {
            return $this->answer($query);
        } finally {
            $this->source->release();
        }
    }

    private function answer(array $query): array
    {
        foreach ($query as $key => $value) {
            if (!is_string($key) || !in_array($key, self::KNOWN, true)) {
                throw self::bad();
            }
            if (is_int($value)) {
                $value = (string) $value;
                $query[$key] = $value;
            }
            if (!is_string($value) || $value === '') {
                throw self::bad();
            }
        }
        foreach (self::UNSERVED as $key) {
            if (isset($query[$key])) {
                throw new ContentReadError('CONTENT_ADAPTER_UNSUPPORTED', 501, 'This site does not serve ' . $key . ' yet.');
            }
        }
        if (isset($query['id'])) {
            foreach (['cursor', 'type', 'locale', 'limit'] as $key) {
                if (isset($query[$key])) {
                    throw self::bad();
                }
            }
            if (isset($query['blockId'])) {
                return $this->block($query['id'], $query['blockId'], $query['blocksCursor'] ?? null);
            }
            return $this->detail($query['id'], $query['blocksCursor'] ?? null);
        }
        if (isset($query['blocksCursor']) || isset($query['blockId'])) {
            throw self::bad();
        }
        return $this->listing($query);
    }

    // ---- listing ----------------------------------------------------------------------------

    private function listing(array $query): array
    {
        $filters = ['type' => $query['type'] ?? null, 'locale' => $query['locale'] ?? null, 'limit' => $query['limit'] ?? null];
        $offset = 0;
        if (isset($query['cursor'])) {
            $state = $this->decode($query['cursor'], 'list');
            // A cursor carries its filters. A filter repeated beside it must say the same thing;
            // one that differs is a different listing, not a next page.
            foreach ($filters as $key => $value) {
                if ($value !== null && $value !== $state['filters'][$key]) {
                    throw self::bad();
                }
            }
            $filters = $state['filters'];
            $offset = (int) $state['offset'];
        } else {
            $filters['limit'] = $filters['limit'] ?? (string) self::DEFAULT_LIMIT;
            if (!preg_match('/^[1-9][0-9]{0,2}$/D', $filters['limit']) || (int) $filters['limit'] > self::MAX_LIMIT) {
                throw self::bad();
            }
            if ($filters['type'] !== null && !in_array($filters['type'], self::TYPES, true)) {
                throw self::bad();
            }
            if ($filters['locale'] !== null && !in_array($filters['locale'], $this->source->site()['locales'], true)) {
                throw self::bad();
            }
        }
        $limit = (int) $filters['limit'];
        $rows = [];
        foreach ($this->source->summaries() as $summary) {
            if (($filters['type'] === null || $summary['type'] === $filters['type'])
                && ($filters['locale'] === null || $summary['locale'] === $filters['locale'])) {
                $rows[] = $summary;
            }
        }
        $total = count($rows);
        if ($offset > $total) {
            throw self::bad();
        }
        $out = [];
        foreach (array_slice($rows, $offset, $limit) as $summary) {
            $out[] = $summary;
            if (strlen(self::encode($this->envelope($out, $limit, null, $total))) > $this->maxBytes - self::ENVELOPE_MARGIN) {
                array_pop($out);
                break;
            }
        }
        if ($out === [] && $offset < $total) {
            throw $this->tooLarge((string) $rows[$offset]['id'], null, 'summary');
        }
        $next = null;
        if ($offset + count($out) < $total) {
            $next = $this->cursor(['kind' => 'list', 'filters' => $filters, 'offset' => $offset + count($out)]);
        }
        return $this->envelope($out, $limit, $next, $total);
    }

    // ---- detail -----------------------------------------------------------------------------

    private function detail(string $id, ?string $blocksCursor): array
    {
        $offset = 0;
        if ($blocksCursor !== null) {
            $state = $this->decode($blocksCursor, 'blocks');
            if (($state['id'] ?? null) !== $id) {
                throw self::bad();
            }
            $offset = (int) $state['offset'];
        }
        $content = $this->source->detail($id);
        if ($content === null) {
            throw new ContentReadError('CONTENT_NOT_FOUND', 404, 'Content not found.');
        }
        $budget = $this->maxBytes - 2 * self::ENVELOPE_MARGIN;
        foreach (['title', 'summary', 'bodyHtml'] as $key) {
            if (strlen(self::encode($content[$key] ?? null)) > $budget) {
                throw $this->contentTooLarge($content, $key);
            }
        }
        foreach ((array) ($content['fields'] ?? []) as $field) {
            if (strlen(self::encode($field['value'])) > $budget) {
                throw $this->contentTooLarge($content, (string) $field['key']);
            }
        }
        $all = (array) ($content['blocks'] ?? []);
        foreach ($all as $block) {
            foreach ($block['fields'] as $field) {
                if (strlen(self::encode($field['value'])) > $budget) {
                    throw $this->tooLarge($id, (string) $block['id'], (string) $field['key']);
                }
            }
            foreach ($block['items'] as $item) {
                foreach ($item['fields'] as $field) {
                    if (strlen(self::encode($field['value'])) > $budget) {
                        throw new ContentReadError('CONTENT_FIELD_TOO_LARGE', 413, 'A content field exceeds the response budget.',
                            ['field' => ['contentId' => $id, 'blockId' => (string) $block['id'], 'itemId' => (string) $item['id'], 'key' => (string) $field['key']]]);
                    }
                }
            }
        }
        if ($offset > count($all)) {
            throw self::bad();
        }
        $images = (array) ($content['images'] ?? []);
        $take = min(self::BLOCK_PAGE, count($all) - $offset);
        while (true) {
            $slice = array_slice($all, $offset, $take);
            $more = $offset + count($slice) < count($all);
            $page = $content;
            $page['blocks'] = $slice;
            $page['images'] = self::imagesFor($images, $slice, $offset === 0);
            unset($page['links']['next'], $page['blocksPagination']);
            if ($more) {
                $next = $this->cursor(['kind' => 'blocks', 'id' => $id, 'offset' => $offset + count($slice)]);
                $page['detailState'] = 'partial';
                $page['links']['next'] = $page['links']['self'] . '&blocksCursor=' . rawurlencode($next);
                $page['blocksPagination'] = ['limit' => max(1, count($slice)), 'nextCursor' => $next, 'total' => count($all)];
            } else {
                $page['detailState'] = 'complete';
                if ($offset > 0) {
                    $page['blocksPagination'] = ['limit' => max(1, count($slice)), 'nextCursor' => null, 'total' => count($all)];
                }
            }
            $envelope = $this->envelope([$page], 1, null, 1);
            if (strlen(self::encode($envelope)) <= $this->maxBytes) {
                return $envelope;
            }
            if ($take <= 1) {
                // One block alone does not fit beside the content's own fields: name the block.
                throw $this->tooLarge($id, isset($all[$offset]['id']) ? (string) $all[$offset]['id'] : null, 'block');
            }
            $take = intdiv($take, 2);
        }
    }

    /**
     * One block occurrence of a content, with the content's own metadata and the images that
     * block uses — and nothing else: no bodyHtml, fields, tags or relations. It is `partial` by
     * definition. A block scan (from `error.links.firstBlock`, or any block read) is signed: the
     * cursor names the content, the block and its position in ONE snapshot, and each answer's
     * `links.next` carries the cursor of the next block. The last block ends the scan with
     * `blocksPagination.nextCursor: null` and `links.next` back at the content.
     */
    private function block(string $id, string $blockId, ?string $cursor): array
    {
        $at = null;
        if ($cursor !== null) {
            $state = $this->decode($cursor, 'block');
            if (($state['id'] ?? null) !== $id || ($state['blockId'] ?? null) !== $blockId) {
                throw self::bad();
            }
            $at = (int) $state['offset'];
        }
        $content = $this->source->detail($id, false);
        if ($content === null) {
            throw new ContentReadError('CONTENT_NOT_FOUND', 404, 'Content not found.');
        }
        $all = array_values((array) ($content['blocks'] ?? []));
        $index = null;
        foreach ($all as $i => $block) {
            if ($block['id'] === $blockId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new ContentReadError('CONTENT_NOT_FOUND', 404, 'Content not found.');
        }
        if ($at !== null && $at !== $index) {
            throw self::bad(); // the snapshot is the same (checked above), so the cursor lied about the position
        }
        $page = $content;
        foreach (['bodyHtml', 'tags', 'fields', 'relations', 'blocksPagination'] as $key) {
            unset($page[$key]);
        }
        $page['blocks'] = [$all[$index]];
        $page['images'] = self::imagesFor((array) ($content['images'] ?? []), $page['blocks'], false);
        $page['detailState'] = 'partial';
        $next = $index + 1 < count($all) ? $this->blockLink($id, $all, $index + 1) : null;
        $page['blocksPagination'] = ['limit' => 1, 'nextCursor' => $next === null ? null : $next['cursor'], 'total' => count($all)];
        $page['links']['next'] = $next === null ? $page['links']['self'] : $next['path'];
        $page['links']['self'] .= '&blockId=' . rawurlencode($blockId);
        $envelope = $this->envelope([$page], 1, null, 1);
        if (strlen(self::encode($envelope)) > $this->maxBytes) {
            throw $this->tooLarge($id, $blockId, 'block');
        }
        return $envelope;
    }

    /** @return array{cursor:string,path:string} the signed read of block `$index` of one content */
    private function blockLink(string $id, array $blocks, int $index): array
    {
        $blockId = (string) $blocks[$index]['id'];
        $cursor = $this->cursor(['kind' => 'block', 'id' => $id, 'blockId' => $blockId, 'offset' => $index]);
        $path = $this->contentPath() . '?id=' . rawurlencode($id) . '&blockId=' . rawurlencode($blockId) . '&blocksCursor=' . rawurlencode($cursor);
        return ['cursor' => $cursor, 'path' => $path];
    }

    /** The path of `/content.json` on this site, taken from the links the source writes. */
    private function contentPath(): string
    {
        $path = (string) parse_url($this->source->site()['url'], PHP_URL_PATH);
        return rtrim($path, '/') . '/content.json';
    }

    /**
     * A content-level scalar over budget: 413 naming it, with the snapshot of this read and —
     * when the content has blocks — `links.firstBlock`, the signed start of a block scan that
     * never builds the page body.
     */
    private function contentTooLarge(array $content, string $key): ContentReadError
    {
        $error = $this->tooLarge((string) $content['id'], null, $key);
        $error->extra['snapshot'] = ['revision' => $this->source->revision(), 'readAt' => gmdate('Y-m-d\TH:i:s\Z', $this->now)];
        $blocks = array_values((array) ($content['blocks'] ?? []));
        if ($blocks !== []) {
            $url = parse_url($this->source->site()['url']);
            $origin = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
            $error->extra['links'] = ['firstBlock' => $origin . $this->blockLink((string) $content['id'], $blocks, 0)['path']];
        }
        return $error;
    }

    /**
     * The images a page of blocks uses: usages pointing at blocks on this page, plus usages of the
     * content itself (no block) on the first page. An image with no usage left is left out.
     */
    private static function imagesFor(array $images, array $blocks, bool $first): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            $ids[(string) $block['id']] = true;
        }
        $out = [];
        foreach ($images as $image) {
            $usages = [];
            foreach ($image['usages'] as $usage) {
                if ($usage['blockId'] === null ? $first : isset($ids[$usage['blockId']])) {
                    $usages[] = $usage;
                }
            }
            if ($usages !== []) {
                $image['usages'] = $usages;
                $out[] = $image;
            }
        }
        return $out;
    }

    // ---- envelope and cursor ----------------------------------------------------------------

    private function envelope(array $contents, int $limit, ?string $next, ?int $total): array
    {
        $unresolved = $this->source->unresolved();
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'view' => 'authenticated',
            'site' => $this->source->site(),
            'provenance' => $this->source->provenance(),
            'snapshot' => ['revision' => $this->source->revision(), 'readAt' => gmdate('Y-m-d\TH:i:s\Z', $this->now)],
            'pagination' => ['limit' => $limit, 'nextCursor' => $next, 'total' => $total],
            'completeness' => ['scope' => 'supported-content', 'status' => $unresolved === [] ? 'complete' : 'partial', 'unresolved' => $unresolved],
            'contents' => $contents,
        ];
    }

    private function tooLarge(string $id, ?string $block, string $key): ContentReadError
    {
        return new ContentReadError('CONTENT_FIELD_TOO_LARGE', 413, 'A content field exceeds the response budget.',
            ['field' => ['contentId' => $id, 'blockId' => $block, 'itemId' => null, 'key' => $key]]);
    }

    private function cursor(array $state): string
    {
        $state += [
            'v' => 1,
            'site' => $this->source->site()['id'],
            'principal' => $this->principal,
            'scope' => $this->scope,
            'revision' => $this->source->revision(),
            'expires' => $this->now + self::TTL,
        ];
        $data = rtrim(strtr(base64_encode(self::encode($state)), '+/', '-_'), '=');
        return $data . '.' . hash_hmac('sha256', $data, $this->secret);
    }

    /** @return array<string,mixed> the verified state of a cursor of the given kind */
    private function decode(string $cursor, string $kind): array
    {
        if (strlen($cursor) > 4096 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $m)
            || !hash_equals(hash_hmac('sha256', $m[1], $this->secret), $m[2])) {
            throw self::bad();
        }
        $state = json_decode((string) base64_decode(strtr($m[1], '-_', '+/'), true), true);
        if (!is_array($state) || ($state['v'] ?? null) !== 1 || ($state['kind'] ?? null) !== $kind
            || ($state['site'] ?? null) !== $this->source->site()['id']
            || ($state['principal'] ?? null) !== $this->principal || ($state['scope'] ?? null) !== $this->scope
            || !is_int($state['offset'] ?? null) || $state['offset'] < 0) {
            throw self::bad();
        }
        if (!is_int($state['expires'] ?? null) || $state['expires'] <= $this->now
            || !hash_equals((string) ($state['revision'] ?? ''), $this->source->revision())) {
            throw new ContentReadError('CONTENT_SNAPSHOT_EXPIRED', 409, 'Restart the content listing.');
        }
        return $state;
    }
}
