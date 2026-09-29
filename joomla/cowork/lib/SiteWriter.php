<?php
/**
 * The write side of the site, behind interfaces — so the engine can apply a change and undo it
 * without ever touching Joomla, exactly as RowSource keeps the read side driver-free.
 *
 * Three cooperating contracts:
 *  - SiteWriter  edits the site's structured content (an article, a module, a template style).
 *  - MediaWriter puts a file into the site's media folder.
 *  - ApplyLog    remembers the before-state of every edit, keyed by the Apply it belonged to, so
 *                the whole Apply can be reversed to the byte.
 *
 * The real implementations live in the component and speak Joomla (its Table API handles the
 * assets row, the cache purge and the modified date that a raw UPDATE would miss); the tests hand
 * in memory. That split is why an Apply, and its undo, can be exercised with no web server and no
 * database — the same reason the read side is built this way.
 *
 * The reversibility this makes possible is not a nicety: it is the condition ADR 0048 puts on a
 * change being guaranteed at all. An Apply that cannot be undone cannot be offered for free.
 */

/**
 * The write lock is held by someone else. Its message stays the one the Tracy tools match on
 * ("another writer is changing this site"); what it adds is WHO holds the lock and for how long, so
 * the answer can say when to try again instead of a bare "busy". `holder` is empty when the lock's
 * current owner left no record (an older writer, or a record that belongs to a finished call).
 */
final class WriterBusy extends RuntimeException
{
    /** @var array{action?:string,operation?:?string,applyId?:?string,since?:int} */
    public array $holder;
    public function __construct(string $message, array $holder = [])
    {
        parent::__construct($message);
        $this->holder = $holder;
    }
    /** The facts a busy answer carries: the holder, how long it has held, when to try again. */
    public function facts(int $now): array
    {
        if (!isset($this->holder['since'])) return ['retryAfterMs' => 3000];
        $held = max(0, ($now - (int) $this->holder['since']) * 1000);
        $holder = array_filter([
            'action' => $this->holder['action'] ?? null,
            'operation' => $this->holder['operation'] ?? null,
            'applyId' => $this->holder['applyId'] ?? null,
        ], fn($v) => $v !== null && $v !== '');
        // A contract apply takes 2–4 s; one held longer is nearly done or stuck — ask again soon either way.
        return ['holder' => $holder, 'heldForMs' => $held, 'retryAfterMs' => $held < 3000 ? 3500 - $held : 2000];
    }
}

/**
 * `#__fields_values` has no id column: a value is named by (field_id, item_id). A SiteWriter row is
 * named by one int, so the pair is packed into one — field_id × SPAN + item_id — rather than widen
 * every read, write and undo entry of the engine for the one kind that needs two.
 */
final class FieldValueKey
{
    public const SPAN = 1000000000;

    public static function encode(int $fieldId, int $itemId): int
    {
        if ($fieldId < 1 || $itemId < 1 || $itemId >= self::SPAN) throw new RuntimeException('field value key out of range');
        return $fieldId * self::SPAN + $itemId;
    }

    /**
     * The one row of a pair, or null. The table has no key: a multiple-value field (checkbox, list
     * multiple) keeps several rows for one pair, and none of them is "the" value.
     *
     * @param array<array-key,array> $rows every row of the pair
     */
    public static function only(array $rows): ?array
    {
        return count($rows) === 1 ? reset($rows) : null;
    }

    /** only(), for a write: a pair that is not exactly one row is refused, never guessed at. */
    public static function single(array $rows): array
    {
        if (count($rows) === 0) throw new RuntimeException('target does not exist in this scope');
        if (count($rows) > 1) throw new RuntimeException('this field value is stored in ' . count($rows) . ' rows (a multiple-value field); it cannot be written as one');
        return reset($rows);
    }

    /** @return array{0:int,1:int} [field_id, item_id] */
    public static function decode(int $id): array
    {
        return [intdiv($id, self::SPAN), $id % self::SPAN];
    }
}

interface SiteWriter
{
    /**
     * The kinds of content an Apply may edit. A caller naming anything else is refused by the
     * engine. This is the FULL catalog (ADR 0080 §2): five generic actions × these kinds replace
     * a tool-per-entity API — adding an entity later is one catalog row here plus one allowlist
     * line at the relay, not a release train. Which ROLE may touch which kind is the relay's
     * question (content / code / site toolsets); the component only enforces what a kind's write
     * may look like.
     */
    public const KINDS = [
        // content — the site's words and editorial structure
        'article', 'category', 'tag', 'field', 'menuItem', 'menutype', 'redirect',
        // One stored custom field value (`#__fields_values`), named by FieldValueKey: what a derived
        // contract writes when an imported site keeps words in a field. `field` is the DEFINITION.
        'fieldValue',
        'banner', 'bannerClient', 'contact', 'newsfeed',
        // A content language of a multilingual site. It is content, not configuration: it decides
        // which words a visitor is shown, and it is the row without which every article tagged
        // `vi-VN` is filed under a language Joomla does not know and shows to nobody (2026-09-08).
        'language', 'articleAssociation', 'menuAssociation', 'moduleAssignment', 'languageFilter',
        // code — can change markup/behaviour of the rendered site
        'module', 'templateStyle',
        // site — identity and configuration
        'user', 'extensionParams',
    ];

    /**
     * Whether an Apply may create (id 0) this kind. Tree-shaped kinds (menu items, categories,
     * tags) say yes since 0.8.14 — the implementation places the node through Joomla's Table
     * API, never a raw insert, so lft/rgt/path/alias come out as an admin save would make them.
     * Identity kinds (user) and installer-owned rows (extensionParams) say no on principle.
     */
    public function canCreate(string $kind): bool;

    /**
     * The column that soft-deletes this kind (Joomla's trash, value -2), or null when the kind
     * cannot be deleted through Apply at all. `content.delete` is a write to this column — which
     * is what keeps it revertable through the same undo log as any other field change.
     */
    public function trashColumn(string $kind): ?string;

    /**
     * Set one visibility column of an existing row, and nothing else.
     *
     * Not write(): for an article or a module that goes through Joomla's Table, whose store() also
     * writes the row's `#__assets` entry, stamps `modified`, and takes table locks that commit
     * implicitly. A quickstart hiding its own demo must change what Joomla serves, not who may edit
     * it — measured 23/09/2026 on `j-1pd0de`: six demo posts shipped without assets, and a trim
     * through write() minted them at the root and moved their effective ACL.
     *
     * @param string $kind   article (`state`), menuItem (`published`) or module (`published`).
     */
    public function setVisibility(string $kind, int $id, string $column, string $value): void;

    /**
     * Give one existing menu item a new alias, and its branch the paths that follow from it.
     *
     * Only for moving an archive's own row out of the way: Joomla keeps one alias per (client,
     * parent, language), and a quickstart that ships an edition in the language a customer asks
     * for already holds every alias its copy must take (Business: 43 editions; measured 23/09/2026
     * on j-ee6vsk, `Duplicate entry '0-1-home-vi-VN'`). Like setVisibility, not the Table: a menu
     * row has no asset, and the Table's store would also re-decide `home`.
     */
    public function realiasMenuItem(int $id, string $alias): void;

    /**
     * Give the site's source language another tag of the same language — every row that carries the
     * old tag, the content language's own row (its `sef`, and so every URL and every `tb-main-<sef>`
     * menu, unchanged), a template style set per language, and the tag index `#__ucm_content`.
     * Raw updates, like setVisibility: a relabel moves no row and changes no asset.
     *
     * Installing `$to`'s pack makes Joomla add a content language of its own for it (at `/en-us/`,
     * because `en` is taken) — an empty row that would publish a second English. That row is removed,
     * and its label is the one the source row takes, unless `$label` names one (a revert does).
     *
     * @param array{title: string, title_native: string, image: string}|null $label
     * @return array{previous: array{title: string, title_native: string, image: string}, removed: ?array} the source row's old label, and the row removed
     */
    public function relabelLanguage(string $from, string $to, ?array $label = null): array;

    /**
     * The site's default languages, as Joomla keeps them in com_languages' params.
     *
     * @return array{site:string,administrator:string}
     */
    public function readLanguageDefaults(): array;

    /**
     * Set the site's default languages and nothing else in those params. A narrow door on purpose:
     * the generic extensionParams kind is refused on a bound site, and must stay refused.
     */
    public function writeLanguageDefaults(string $site, string $administrator): void;

    /**
     * The current fields of one target, or null when nothing with that id exists.
     *
     * This is the before-state the engine records: precise enough that restoring it returns the
     * target to exactly what it was, and a null answer is itself information — it means the write
     * about to happen is an insert, so its undo is a delete.
     *
     * @param string $kind One of self::KINDS.
     * @return array<string,?scalar>|null
     */
    public function read(string $kind, int $id): ?array;

    /**
     * Upsert one target and return the id written. An id of 0 inserts and mints a new id; any
     * other id updates that row. The implementation whitelists which columns a kind may carry —
     * the engine passes fields through untrusted, and it is the writer that refuses a column that
     * is not the caller's to set.
     *
     * @param array<string,?scalar> $fields
     */
    public function write(string $kind, int $id, array $fields): int;

    /** Remove one target. Used only to reverse an insert this run made — never a user-facing delete. */
    public function delete(string $kind, int $id): void;

    /**
     * Where a tree node currently sits, precisely enough to put it back: its parent and the
     * sibling standing immediately before it (`after` 0 when it is the first child). Null when
     * the kind is not tree-shaped or the id does not exist — which is also how the engine learns
     * a kind cannot be moved at all.
     *
     * @return array{parent_id:int,after:int}|null
     */
    public function positionOf(string $kind, int $id): ?array;

    /**
     * Re-hang one tree node: after the sibling `$after` when it is positive, first child of
     * `$parentId` when `$after` is 0, last child of `$parentId` otherwise. The implementation
     * owns everything a move drags along — lft/rgt renumbering, the path chain of the node and
     * every descendant. Throws on refusal (moving under one's own descendant, unknown target).
     */
    public function move(string $kind, int $id, int $parentId, int $after): void;

    /**
     * One bounded page of a kind's rows, as summaries — the read half of the content mirror
     * (ADR 0071): a caller pages through these, then fetches each full row with read(). Summary
     * means identity and bookkeeping columns only, never body text, so a page stays small enough
     * for a shared host's execution limit no matter how big the articles are. The implementation
     * decides the exact column set per kind; for articles it includes enough of the category
     * (title and path) to place the row in a folder tree without a second query.
     *
     * Rows come back in a stable order (by id) so offset paging never skips or repeats a row
     * that existed when paging began.
     *
     * @return array<int,array<string,?scalar>>
     */
    public function list(string $kind, int $offset, int $limit): array;

    /**
     * Drop whatever cache would otherwise keep serving the version just replaced. Best-effort by
     * contract: a cache that could not be cleared must not turn a completed write into a failure,
     * so the implementation swallows its own errors and this returns nothing to report.
     */
    public function purgeCache(): void;
}

/**
 * Optional: a writer that reads many rows in one round trip.
 *
 * Why it exists: a contract inspect on Tracy Business reads ~7,800 governed rows. Through
 * `list()` + `read()` that was one query per row plus five per article (list enriches articles
 * with a routed URL, menu lookups, author, tags and access name the contract never looks at):
 * 21,931 queries per inspect, 65 s on a VPS where each round trip costs ~3 ms (measured
 * 25/09/2026 on dev `fj1823`). Both methods return EXACTLY what `read()` returns for the same
 * rows, so a caller may use them in place of a loop of reads without changing any answer.
 * Nothing here caches: every call goes to the database.
 */
interface BulkSiteReader
{
    /**
     * Every row of a kind inside the scope `read()` applies, as `read()` returns it, keyed by
     * primary key in ascending order. At most $limit rows; a caller that needs to know whether
     * there were more asks for one more than it accepts.
     *
     * @return array<int,array<string,?scalar>>
     */
    public function readAll(string $kind, int $limit): array;

    /**
     * `read()` for many ids at once, keyed by id. An id `read()` would answer null for is left
     * out; a relation kind whose `read()` throws for a missing target throws the same here.
     *
     * @param int[] $ids
     * @return array<int,array<string,?scalar>>
     */
    public function readMany(string $kind, array $ids): array;
}

interface MediaWriter
{
    /** The bytes currently at a media path, or null when nothing is there (so the undo is a delete). */
    public function read(string $path): ?string;

    /** Write bytes to a media path, creating parent folders as needed. Path is already validated by the engine. */
    public function write(string $path, string $bytes): void;

    /** Remove a media file. Used only to reverse an upload this run made. */
    public function delete(string $path): void;
}

interface ApplyLog
{
    /**
     * Record one reversible step. Entries accumulate under an apply_id in the order they happen;
     * reverting replays them newest-first.
     *
     * @param array<string,mixed> $entry {op, ...target..., before}
     */
    public function record(string $applyId, array $entry): void;

    /**
     * Every step recorded under an apply_id, oldest first (the caller reverses to undo).
     *
     * @return array<int,array<string,mixed>>
     */
    public function entries(string $applyId): array;

    /** Forget an apply_id — after it has been reverted, or before it is applied again. */
    public function clear(string $applyId): void;
}
