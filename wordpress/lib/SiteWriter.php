<?php
/**
 * The write side of the site, behind interfaces — so the engine can apply a change and undo it
 * without ever touching WordPress, exactly as RowSource keeps the read side driver-free.
 *
 * Three cooperating contracts:
 *  - SiteWriter  edits the site's structured content (a post, a post's meta, an option).
 *  - MediaWriter puts a file into the site's uploads folder and into its Media Library.
 *  - ApplyLog    remembers the before-state of every edit, keyed by the Apply it belonged to, so
 *                the whole Apply can be reversed to the byte.
 *
 * The real implementations live in the plugin and speak WordPress (its own functions run the
 * hooks, the revisions and the cache invalidation a raw UPDATE would miss); the tests hand in
 * memory. That split is why an Apply, and its undo, can be exercised with no web server and no
 * database — the same reason the read side is built this way.
 *
 * The reversibility this makes possible is not a nicety: it is the condition ADR 0048 puts on a
 * change being guaranteed at all. An Apply that cannot be undone cannot be offered for free.
 *
 * ## Two places this deliberately differs from the Joomla original
 *
 * **A string key alongside the id.** Joomla's three kinds are all rows with an integer id.
 * WordPress's are not: a post has an id, a meta is a *named* value hanging off a post, and an
 * option is a name with no id at all. One extra parameter carries that name, rather than three
 * kinds pretending to be rows they are not.
 *
 * **A media write produces an id.** Putting a file in `uploads/` is only half of adding media to
 * WordPress; the other half is the attachment row that makes it appear in the Media Library. That
 * row is state an undo has to clean up, so `write()` reports the attachment it created.
 */

interface SiteWriter
{
    /** The kinds of content an Apply may edit. A caller naming anything else is refused by the engine. */
    public const KINDS = ['post', 'postmeta', 'option'];

    /**
     * The current state of one target, or null when nothing is there.
     *
     * This is the before-state the engine records: precise enough that restoring it returns the
     * target to exactly what it was, and a null answer is itself information — it means the write
     * about to happen creates something, so its undo is a delete.
     *
     * @param string $kind One of self::KINDS.
     * @param int    $id   The post id for `post` and `postmeta`; ignored for `option`.
     * @param string $key  The meta key for `postmeta`, the option name for `option`; unused for `post`.
     * @return array<string,mixed>|null
     */
    public function read(string $kind, int $id, string $key = ''): ?array;

    /**
     * Write one target and return the post id it belongs to. An id of 0 on a `post` inserts and
     * mints a new id; any other id updates that post. The implementation whitelists which fields a
     * kind may carry — the engine passes fields through untrusted, and it is the writer that
     * refuses what is not the caller's to set.
     *
     * A refusal is a thrown exception, not a silent skip: a caller whose field never landed is
     * entitled to be told, rather than to read the page afterwards and wonder.
     *
     * @param array<string,mixed> $fields
     */
    public function write(string $kind, int $id, array $fields, string $key = ''): int;

    /** Remove one target. Used only to reverse a create this run made — never a user-facing delete. */
    public function delete(string $kind, int $id, string $key = ''): void;

    /**
     * Drop whatever cache would otherwise keep serving the version just replaced. Best-effort by
     * contract: a cache that could not be cleared must not turn a completed write into a failure,
     * so the implementation swallows its own errors and this returns nothing to report.
     */
    public function purgeCache(): void;
}

interface MediaWriter
{
    /** The bytes currently at an upload path, or null when nothing is there (so the undo is a delete). */
    public function read(string $path): ?string;

    /**
     * Write bytes to an upload path, creating parent folders as needed, and register the file with
     * the Media Library when it is new. Path is already validated by the engine.
     *
     * @return int The attachment created, or 0 when the bytes replaced a file that already had one
     *             (or had none, and this is not the moment to invent one).
     */
    public function write(string $path, string $bytes): int;

    /** Remove an upload file. Used only to reverse an upload this run made. */
    public function delete(string $path): void;

    /**
     * Remove an attachment and everything WordPress generated from it — the thumbnails most of
     * all, which a plain file delete leaves behind as orphans nobody will ever find.
     */
    public function deleteAttachment(int $attachmentId): void;
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
