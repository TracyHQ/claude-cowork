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

interface SiteWriter
{
    /** The kinds of content an Apply may edit. A caller naming anything else is refused by the engine. */
    public const KINDS = ['article', 'module', 'templateStyle'];

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
