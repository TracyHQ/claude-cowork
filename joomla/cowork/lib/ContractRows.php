<?php
require_once __DIR__ . '/SiteWriter.php';

/**
 * The site's rows as ONE contract call sees them: fetched in bulk, answered from memory, gone
 * when the call returns.
 *
 * 🔒 SCOPE IS ONE CALL, NOT ONE REQUEST. An apply inspects, writes, then inspects again inside the
 * same request; rows remembered across that write would let the second inspect bless the site as
 * it was before the change. So every `inspect()` / `readMapping()` builds a fresh instance and
 * drops it on return — nothing here is static and nothing outlives the method that made it.
 *
 * Answers are the writer's own: a row served from here is the row `read()` returns for that id.
 * A writer without {@see BulkSiteReader} is read exactly as before — one `list()` walk plus one
 * `read()` per row — so doubles and older writers keep their behaviour.
 */
final class ContractRows
{
    /** The inventory walk's ceiling, unchanged from the paged loop it replaces. */
    public const LIMIT = 20000;

    private SiteWriter $writer;
    private bool $bulk;
    /** @var array<string,array<int,array>> kind => id => full row, for kinds read whole */
    private array $all = [];
    /** @var array<string,array<int,?array>> kind => id => row or null, for ids fetched by readMany */
    private array $known = [];

    /** @var (callable(): array<int,array>)|null the `languageString` rows a contract names (LanguageStrings::rows) */
    private $languageStrings;

    /** @var (callable(): array<int,array>)|null the `siteSetting` rows a contract names (SiteSettingRows::rows) */
    private $siteSettings;

    /**
     * @param (callable(): array<int,array>)|null $languageStrings the rows of kind `languageString`, by id: they are
     * strings of language files, not rows of the writer's tables (lib/LanguageStrings.php). Without it the kind has none.
     * @param (callable(): array<int,array>)|null $siteSettings the rows of kind `siteSetting`, by id: logos of a T4 profile
     * file (lib/SiteSettingRows.php), likewise not rows of the writer's tables.
     */
    public function __construct(SiteWriter $writer, ?callable $languageStrings = null, ?callable $siteSettings = null)
    {
        $this->writer = $writer;
        $this->bulk = $writer instanceof BulkSiteReader;
        $this->languageStrings = $languageStrings;
        $this->siteSettings = $siteSettings;
    }

    /** A kind that is lines of files, not rows of the writer's tables: language strings and T4 site settings. */
    private static function fromFiles(string $kind): bool
    {
        return $kind === 'languageString' || $kind === 'siteSetting';
    }

    /**
     * Every in-scope row of a kind, full, in id order.
     *
     * @return array<int,array> a list, in the order the paged walk produced
     */
    public function all(string $kind): array
    {
        if (!isset($this->all[$kind])) {
            $rows = [];
            if ($kind === 'languageString') {
                // Read whole from the files at once (a few dozen strings); never from the writer.
                foreach ($this->languageStrings ? ($this->languageStrings)() : [] as $id => $row) $rows[(int) $id] = $row;
            } elseif ($kind === 'siteSetting') {
                foreach ($this->siteSettings ? ($this->siteSettings)() : [] as $id => $row) $rows[(int) $id] = $row;
            } elseif ($kind === 'userProfile') {
                // Never read whole (it is one value per author and key): its list rows name the author
                // (`user_id`) and the field, which is what a contract entity is matched by.
                for ($offset = 0; $offset < self::LIMIT; $offset += 100) {
                    $page = $this->writer->list($kind, $offset, 100);
                    foreach ($page as $item) $rows[(int) $item['id']] = $item;
                    if (count($page) < 100) break;
                }
            } elseif ($this->bulk) {
                // The same refusal as the paged walk, which threw on a full page at offset 19,900 —
                // that is, at 20,000 rows or more, so a result filling the limit is refused too.
                foreach ($this->writer->readAll($kind, self::LIMIT) as $id => $row) $rows[(int) $id] = $row;
                if (count($rows) >= self::LIMIT) throw new RuntimeException('Inventory limit exceeded');
            } else {
                for ($offset = 0; $offset < self::LIMIT; $offset += 100) {
                    $page = $this->writer->list($kind, $offset, 100);
                    foreach ($page as $item) $rows[(int) $item['id']] = $this->writer->read($kind, (int) $item['id']);
                    if (count($page) < 100) break;
                    if ($offset === self::LIMIT - 100) throw new RuntimeException('Inventory limit exceeded');
                }
            }
            $this->all[$kind] = $rows;
        }
        return array_values($this->all[$kind]);
    }

    /**
     * The rows a scan over a kind needs (id, note, alias, language, catid). With a bulk reader the
     * full rows already carry them; without one this is the writer's own list walk, as before. Both
     * refuse a kind of LIMIT rows or more, as all() does.
     *
     * @return array<int,array>
     */
    public function summaries(string $kind): array
    {
        if ($this->bulk || self::fromFiles($kind)) {
            $this->all($kind);
            $rows = [];
            // A list row always names its id; a full row does too on Joomla, and keeps its own.
            foreach ($this->all[$kind] as $id => $row) $rows[] = $row + ['id' => $id];
            return $rows;
        }
        $rows = [];
        for ($offset = 0; $offset < self::LIMIT; $offset += 100) {
            $page = $this->writer->list($kind, $offset, 100);
            foreach ($page as $row) $rows[] = $row;
            if (count($page) < 100) break;
            // all()'s refusal, so whether a kind this large is scanned does not depend on the reader.
            if ($offset === self::LIMIT - 100) throw new RuntimeException('Inventory limit exceeded');
        }
        return $rows;
    }

    /** Fetch many ids of one kind in one round trip, so the `row()` calls that follow cost nothing. */
    public function prefetch(string $kind, array $ids): void
    {
        if (self::fromFiles($kind)) { $this->all($kind); return; }
        if (!$this->bulk) return;
        $missing = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($this->all[$kind]) || array_key_exists($id, $this->known[$kind] ?? [])) continue;
            $missing[$id] = true;
        }
        if (!$missing) return;
        $found = $this->writer->readMany($kind, array_keys($missing));
        foreach (array_keys($missing) as $id) $this->known[$kind][$id] = $found[$id] ?? null;
    }

    /** What `read($kind, $id)` answers, from memory when this call already holds it. */
    public function row(string $kind, int $id): ?array
    {
        if (self::fromFiles($kind)) { $this->all($kind); return $this->all[$kind][$id] ?? null; }
        if ($this->bulk) {
            // A kind read whole is the complete in-scope set: an id outside it is one `read()`
            // would also answer null for, because both apply the same scope.
            if (isset($this->all[$kind])) return $id > 0 ? ($this->all[$kind][$id] ?? null) : null;
            if (array_key_exists($id, $this->known[$kind] ?? [])) return $this->known[$kind][$id];
        }
        return $this->writer->read($kind, $id);
    }
}
