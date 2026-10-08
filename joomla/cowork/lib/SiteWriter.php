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
     * setVisibility() for many rows of one kind: the same column, the same value, the same rules —
     * in a few statements instead of a read and an UPDATE (or two) per row.
     *
     * 🔒 ALL OR NOTHING. Every id must name a row in this kind's scope (the scope read() applies);
     * one that does not is refused with setVisibility()'s own words, before any row is written. A
     * retire pass hides 6,684 rows on Tracy Business: one row at a time, with its undo row, that
     * was 7 to 8 s of transaction on an idle local stand and several times that under load; in
     * bulk, 0.2 s (measured 05/10/2026).
     *
     * @param string $kind article (`state`), menuItem (`published`) or module (`published`).
     * @param int[]  $ids  rows to write; a repeated id is written once.
     */
    public function setVisibilityMany(string $kind, array $ids, string $column, string $value): void;

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
     * Make the source edition carry `$to`, a tag of ANOTHER language, because its words are now
     * written in it — the same rows, URLs and `sef` as relabelLanguage, so routing, the
     * `tb-main-<sef>` menus and the T4 navigation keep working.
     *
     * A quickstart that ships a (hidden) edition of `$to` holds rows and a content language under
     * that tag already. Folding the source into them would merge two editions, and leaving both
     * under one tag puts two menu items on every alias Joomla keeps unique per language. So the two
     * tags are SWAPPED: every row that carried `$from` carries `$to` and every row that carried
     * `$to` carries `$from`; the two content-language rows trade their tag and label and keep their
     * own id, `sef` and published state. A swap is its own inverse. Without a `$to` row this is
     * relabelLanguage (the `$to` row's label from `$label`, else the installed pack).
     *
     * Refused, before anything is written, while `$to` is routed: its content language published,
     * or any article, front-end menu item or front-end module in `$to` still shown.
     *
     * @param array{title: string, title_native: string, image: string}|null $label
     * @return array{previous: array{title: string, title_native: string, image: string}, swapped: bool}
     */
    public function swapLanguage(string $from, string $to, ?array $label = null): array;

    /**
     * Set the title of front-end modules, raw, by id — the words of a module that SHOWS its title
     * (a footer column's heading), which the base contract carries as a label and so has no slot for.
     *
     * @param array<int,string> $titles module id => title
     */
    public function writeModuleTitles(array $titles): void;

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

/**
 * The words of a `content.list` `search`, cleaned and made ready to match. Plain PHP with no Joomla
 * and no database, so the test runner can put every awkward needle through it.
 *
 * It lives in THIS file, beside the interface that uses it, on purpose: this is the one file every
 * loader of the engine already requires (`EngineFactory::loadEngine`, the Joomla 3 controller,
 * `Engine.php` itself), and `JoomlaSiteWriter` cannot be loaded without it. A helper in a NEW lib
 * file would pass every test, which require files by hand, and fatal in production, which requires
 * an explicit list.
 */
final class SearchNeedle
{
    /** The longest needle, in characters. A title is one line; more than this is a pasted paragraph. */
    public const MAX_LENGTH = 200;

    /**
     * The most bytes a value may take BEFORE it is cleaned: 200 four-byte characters with room to spare
     * for padding. Refused unread above this, so the patterns below only ever see a short string.
     */
    public const MAX_BYTES = 4096;

    /**
     * The character that escapes `%` and `_` in the pattern like() builds — named in the SQL as an
     * explicit ESCAPE clause. Not a backslash: LIKE's default escape character is a backslash except
     * under sql_mode NO_BACKSLASH_ESCAPES, where it has none, so a needle's `%` would be a wildcard
     * on such a site. With the clause spelled out the pattern means the same in every mode.
     */
    public const LIKE_ESCAPE = '!';

    /**
     * Clean one `search` value.
     *
     * Tabs and line breaks (LF, CR, VT, FF and Unicode's NEL, line separator and paragraph separator)
     * are gaps between words; every other control character (NUL, escape, DEL, the rest of the C1
     * range) is dropped; the ends are trimmed, no-break and ideographic spaces included. The
     * result is NFC when this PHP can normalise (`Normalizer`, from intl or a polyfill), and matched
     * as its NFC and NFD forms, because a title may have been stored either way. Without a usable
     * normaliser (none, or one emptied by `disable_classes`) the needle is used as it came: it matches
     * what was typed the same way, and nothing is refused for lack of one. No minimum length: one
     * character is a word in Chinese or Japanese.
     * More than MAX_LENGTH characters once cleaned, or more than MAX_BYTES bytes before, is refused.
     *
     * @param mixed $raw the request's `search`, whatever it was
     * @return array{ok:true,text:string,variants:string[]}|array{ok:false,message:string}
     *         `text` is what the answer echoes; `variants` are the strings to match, none when `text`
     *         is empty (an empty needle filters nothing).
     */
    public static function clean($raw): array
    {
        if (!is_string($raw)) {
            return ['ok' => false, 'message' => 'search must be a string of at most ' . self::MAX_LENGTH . ' characters'];
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'search is limited to ' . self::MAX_LENGTH . ' characters'];
        }
        // Every line break is a gap, not only the ones a keyboard makes: NEL, the line separator and the
        // paragraph separator arrive when text is pasted, and dropping NEL would glue two words together.
        $text = preg_replace(['/[\t\n\r\x0B\x0C\x{85}\x{2028}\x{2029}]+/u', '/\p{Cc}/u'], [' ', ''], $raw);
        // Trimmed by ONE pattern anchored at the start, with a possessive lead, so the text is read once.
        // The obvious `^\s+|\s+$` tries every space of a long run as a start and is quadratic when PCRE's
        // JIT is off, which is how some hosts run PHP: measured with pcre.jit=0, 20,000 spaces inside a
        // needle took 3.2 s and 100,000 took 73 s.
        $kept = $text === null ? false : preg_match('/^[\s\p{Z}]*+(.*[^\s\p{Z}])/su', $text, $found);
        if ($kept === false) {
            return ['ok' => false, 'message' => 'search must be valid UTF-8 text'];
        }
        $text = $kept === 1 ? $found[1] : '';
        $nfc = self::form($text, false);
        if (mb_strlen($nfc, 'UTF-8') > self::MAX_LENGTH) {
            return ['ok' => false, 'message' => 'search is limited to ' . self::MAX_LENGTH . ' characters'];
        }
        if ($nfc === '') {
            return ['ok' => true, 'text' => '', 'variants' => []];
        }
        return ['ok' => true, 'text' => $nfc, 'variants' => array_values(array_unique([$nfc, self::form($nfc, true)]))];
    }

    /**
     * The LIKE pattern for one variant: the word between two `%`, with `%`, `_` and the escape
     * character itself made literal. Pair it with `ESCAPE '<LIKE_ESCAPE>'` in the SQL.
     */
    public static function like(string $variant): string
    {
        $e = self::LIKE_ESCAPE;
        return '%' . strtr($variant, [$e => $e . $e, '%' => $e . '%', '_' => $e . '_']) . '%';
    }

    /**
     * The variants to compare an ALIAS column with: each one lower-cased, the duplicates dropped.
     *
     * An alias has a list of its own because it is the one searched column that does not ignore case by
     * itself. Joomla writes an alias lower case (`OutputFilter::stringURLSafe` and `stringURLUnicodeSlug`
     * both return lower-cased text), but the column is `utf8mb4_bin` in every kind that has one, and a
     * binary collation compares case: `LIKE '%Roof%'` misses the alias `roof-repair`, and a caller
     * types a word the way a person does, capitalised. So the writer lower-cases the column
     * (`LOWER(alias)`) and compares it with THESE, which finds the alias whatever case the needle came
     * in, and the upper-case value that an import once left behind as well. A title or a name needs none
     * of it: it follows its table's collation, which ignores case, and is compared with the variants as
     * they are.
     *
     * The composed and the decomposed form stay two variants, as clean() made them, because an alias may
     * hold either and lower-casing does not join them. Case is the only thing folded here: an alias still
     * tells `e` from `é`, which its binary collation does not fold and this does not try to.
     *
     * @param string[] $variants from clean()
     * @return string[]
     */
    public static function lowerCased(array $variants): array
    {
        return array_values(array_unique(array_map(function (string $variant): string {
            return mb_strtolower($variant, 'UTF-8');
        }, $variants)));
    }

    /**
     * Whether a database's refusal of a search means "no row can hold these words", so that the honest
     * answer is no rows and not an error.
     *
     * A character above U+FFFF (an emoji, say) takes four bytes in UTF-8, and a table still in `utf8`
     * (utf8mb3) cannot hold one, so no row of it can contain the needle. The server does not answer "none"
     * to that comparison, it refuses it: MariaDB says "Illegal mix of collations (utf8mb3_general_ci,IMPLICIT)
     * and (utf8mb4_uca1400_ai_ci,COERCIBLE) for operation 'like'", and a write of such a character says
     * "Incorrect string value". Reported as a failure it sends the caller off to hunt a fault it did not cause.
     *
     * BOTH halves must hold: every variant carries a character above U+FFFF AND the message is one of those
     * two. The same message for a needle without such a character is some other fault (two tables in
     * different collations, say), and any other message is a failure whatever the needle holds: both stay
     * errors. The message is the server's English text; a server set to another `lc_messages` language does
     * not match, and the caller gets the error, which is the safe side.
     *
     * @param string[] $variants the strings the refused statement was bound to
     */
    public static function cannotBeStored(array $variants, string $message): bool
    {
        if ($variants === []) {
            return false;
        }
        $refused = false;
        foreach (['Illegal mix of collations', 'Incorrect string value'] as $phrase) {
            if (stripos($message, $phrase) !== false) {
                $refused = true;
                break;
            }
        }
        if (!$refused) {
            return false;
        }
        foreach ($variants as $variant) {
            if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $variant) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * One Unicode form of a text — composed (NFC) or decomposed (NFD) — or the text itself when it cannot be had.
     *
     * A Normalizer counts only when it can normalise. `disable_classes=Normalizer`, which hardened hosts
     * set, does not remove the class: with intl loaded it stays declared with its methods emptied out, so
     * `class_exists()` alone says yes and the call is then a fatal Error, thrown from outside the
     * engine's try/catch. The method is asked for as well.
     */
    private static function form(string $text, bool $decomposed): string
    {
        if (!class_exists('Normalizer') || !method_exists('Normalizer', 'normalize')) {
            return $text;
        }
        $out = \Normalizer::normalize($text, $decomposed ? \Normalizer::FORM_D : \Normalizer::FORM_C);
        return is_string($out) ? $out : $text;
    }
}

/**
 * Optional: a writer that can list only the rows whose NAME holds some words.
 *
 * Why it exists: a caller that knows a page by its title had to page through list() until it met
 * it. On a site of ~1,900 articles that was two to eight extra model calls, and up to 18 calls and
 * 256 s in one measured run (Tracy bench v7, 30/09/2026).
 *
 * It is its own interface, not a fourth argument of list(), so that a writer without it is REFUSED
 * when a caller sends `search`. A widened list() would let such a writer take the key and ignore it,
 * which is the answer this exists to end: ok, and the whole list, as if it had been filtered.
 */
interface SearchableSiteWriter
{
    /**
     * The kinds this writer searches: the ones whose rows are called by a title or a name. A kind not
     * listed (a user, a redirect, an extension's parameters, a relation between two rows) is not
     * searched, and the engine refuses `search` on it instead of ignoring it.
     *
     * @return string[]
     */
    public function searchableKinds(): array;

    /**
     * list(), narrowed to the rows where ANY searched column of the kind holds ANY of the variants
     * as a substring, ignoring case. The columns do not ignore it the same way. A title or a name follows
     * its table's collation (utf8mb4_unicode_ci on a stock Joomla, which ignores case and accents). An
     * alias column is utf8mb4_bin in Joomla's schema, which compares case, so an alias is compared
     * lower-cased (`LOWER(column)`) with the variants lower-cased (SearchNeedle::lowerCased()): Joomla
     * writes an alias lower case, and a capitalised needle has to reach it all the same. An alias
     * still tells accents apart where a title does not. The same rows, the same order, the same
     * summaries as list() gives; `$offset` and `$limit` apply to the narrowed set. No state filter: a
     * trashed row is listed like any other, because hiding it would make "this is the only match"
     * unsafe to say.
     *
     * A needle that no row can hold answers no rows and does not throw: see SearchNeedle::cannotBeStored().
     *
     * @param string[] $variants strings from SearchNeedle::clean(), at least one
     * @return array<int,array<string,?scalar>>
     */
    public function searchRows(string $kind, array $variants, int $offset, int $limit): array;

    /**
     * How many rows searchRows() would answer over all pages, whatever the offset and limit. Zero for a
     * needle no row can hold, exactly as searchRows() answers no rows for it.
     *
     * @param string[] $variants
     */
    public function countMatches(string $kind, array $variants): int;
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
     * Record many steps at once, exactly as one record() per entry in the order given would: the
     * same entries, in the same order, so entries() and every revert read them back unchanged.
     * A call that throws may have recorded a first part of them, as a loop of record() would have:
     * a caller that must not keep half calls it inside the transaction that holds the change.
     *
     * @param list<array<string,mixed>> $entries
     */
    public function recordMany(string $applyId, array $entries): void;

    /**
     * Every step recorded under an apply_id, oldest first (the caller reverses to undo).
     *
     * @return array<int,array<string,mixed>>
     */
    public function entries(string $applyId): array;

    /** Forget an apply_id — after it has been reverted, or before it is applied again. */
    public function clear(string $applyId): void;
}
