<?php
require_once __DIR__ . '/MultilingualProfile.php';

/**
 * Adding one language to a bound quickstart, as a sequence of committed steps.
 *
 * 🔒 WHY PHASES AND NOT ONE CALL. A language is roughly 350 writes plus an installer that touches
 * the filesystem, and PHP is killed at `max_execution_time` on the kind of host this receiver
 * exists for. One call would be a change that cannot finish on a large site and cannot say how far
 * it got on any site. Each phase here is one call with one recorded position, so after a crash the
 * answer to "what has been committed" is read, not guessed.
 *
 * ⚠ A PHASE IS BOUNDED, NOT ATOMIC, AND THE DIFFERENCE IS LOAD-BEARING. The phase runs inside a
 * database transaction and most of it does roll back — but Joomla places tree rows and every
 * `#__assets` row through `Table\Nested::store()`, which takes `LOCK TABLES`, and that is an
 * implicit COMMIT in MySQL and MariaDB. So a failed phase can leave real rows behind while its
 * own checkpoint and undo entries vanish. What makes the job safe to re-run is therefore NOT the
 * transaction: it is that every copy is found before it is made (see `existingCopies`), so a
 * repeated phase converges on the same site instead of building a second one.
 *
 * 🔒 THE TARGET LANGUAGE IS NOT PUBLISHED UNTIL THE LAST PHASE. Joomla only routes a content
 * language whose row is published, so a job that dies halfway leaves a site whose visitors see
 * exactly what they saw before — not a half-translated Chinese edition. That is the whole reason
 * the content-language row is created early and published last.
 *
 * 🔒 THE SOURCE MOVES OFF `*` FIRST, AND THAT ORDER IS LOAD-BEARING. `Table\Menu::store()` treats an
 * alias at `language='*'` as taken in every language under the same parent, so copies that keep
 * their source's alias — which is what makes `/en/resources/style-guide` and
 * `/zh/resources/style-guide` the same path — can only be created after the source has a language
 * of its own. Reversed, Joomla refuses each menu item with a sentence about aliases that says
 * nothing about ordering.
 */
final class MultilingualApply
{
    /**
     * In order, and the order is the DEPENDENCY ORDER, not a story about the site.
     *
     * Articles come before menus because a menu item's link names an article by id; menus come
     * before modules because a module's call-to-action names a menu item by `Itemid`. Written the
     * intuitive way round — menus, then articles — every copied link pointed at the English article
     * until a later pass fixed it, and `inspect()` was right to call that drift: between the two
     * passes the site genuinely did not match its own contract. Creating each row with the ids it
     * needs already in hand removes the window instead of tolerating it.
     *
     * A job carries the name of the phase it is ABOUT to run.
     */
    public const PHASES = ['prepare', 'articles', 'menu', 'modules', 'relations', 'finish'];

    /** Rows per call. Small enough to finish under a strict execution limit, large enough to end. */
    private const CHUNK = 25;

    private QuickstartContract $contract;
    private MultilingualProfile $profile;
    private LanguagePackCatalog $catalog;
    private SiteWriter $writer;
    /** @var callable(string,int,array):int one recorded, revertable write */
    private $write;

    public function __construct(QuickstartContract $contract, SiteWriter $writer, callable $write)
    {
        $this->contract = $contract;
        $this->profile = $contract->profile();
        $this->catalog = $contract->catalog();
        $this->writer = $writer;
        $this->write = $write;
    }

    /** A fresh job for one locale, before any of it has run. */
    public static function start(string $locale, string $applyId, string $requestId, string $translationHash, string $contractHash, string $profileHash, string $revision): array
    {
        return [
            'locale' => $locale, 'phase' => 'prepare', 'cursor' => 0, 'ids' => [],
            'applyId' => $applyId, 'requestId' => $requestId, 'translationHash' => $translationHash,
            'contractHash' => $contractHash, 'profileHash' => $profileHash, 'baseRevision' => $revision,
            'retagged' => false, 'contentLanguage' => null, 'switcher' => null,
            'startedAt' => gmdate('c'),
        ];
    }

    /**
     * Run ONE phase of a job and return it advanced. The caller commits; a throw rolls the phase
     * back whole and leaves the job exactly where it was.
     *
     * @param array $job          the job as stored
     * @param array $state        the result of a fresh `inspect()`
     * @param array $translations derived slot key => text, as supplied by the caller
     */
    public function step(array $job, array $state, array $translations): array
    {
        $locale = $job['locale'];
        $method = 'phase' . ucfirst($job['phase']);
        if (!in_array($job['phase'], self::PHASES, true)) throw new RuntimeException('Unknown phase: ' . $job['phase']);
        $done = $this->$method($job, $state, $translations, $locale);
        if ($done) {
            $at = array_search($job['phase'], self::PHASES, true);
            $job['phase'] = self::PHASES[$at + 1] ?? 'completed';
            $job['cursor'] = 0;
        }
        return $job;
    }

    /** Base keys of one kind that get a copy, in the order their rows must be created. */
    private function sources(array $state, string $kind): array
    {
        $keys = [];
        foreach ($state['keys'] as $key => $meta) {
            if (isset($meta['locale']) || !empty($meta['switcher'])) continue;
            if ($meta['kind'] !== $kind || !$this->profile->isTranslated($key)) continue;
            $keys[] = $key;
        }
        if ($kind === 'menuItem')
            // Parents before children, and siblings in the order the source holds them, so the
            // copy's tree has the same shape AND the same order — `lft` is what Joomla sorts a
            // menu by, and it is assigned by the order rows are created.
            usort($keys, function (string $a, string $b) use ($state): int {
                $ra = $state['rows'][$a]; $rb = $state['rows'][$b];
                return [(int)$ra['level'], (int)$ra['lft']] <=> [(int)$rb['level'], (int)$rb['lft']];
            });
        return $keys;
    }

    /** The values a copy's slots take: text translated, links remapped, pictures left alone. */
    private function slotValues(array $state, string $key, string $locale, array $translations, array $idMap): array
    {
        $slots = $this->contract->derivedSlotsFor($locale, $key);
        $values = [];
        foreach ($slots as $slot) {
            $source = $this->contract->slotValue($state['rows'][$key], $slot);
            if ($slot['type'] === 'text') {
                if (!array_key_exists($slot['key'], $translations))
                    throw new RuntimeException('No translation supplied for ' . $slot['key']);
                $values[$slot['key']] = (string) $translations[$slot['key']];
            } elseif ($slot['type'] === 'url') {
                $values[$slot['key']] = $this->profile->remapLink((string) $source, $idMap);
            }
            // An image is the same picture in every language; leaving it out of $values keeps the
            // source's value, which is also what makes the aspect-ratio rule trivially satisfied.
        }
        return $values;
    }

    /**
     * The writes that hide every language the site should not route, and every row a quickstart
     * shipped in a language other than its source that the contract does not govern.
     *
     * 🔒 UNPUBLISH, NEVER DELETE, AND NEVER A GOVERNED ROW. The Business archive carries 43
     * content languages its contract does not govern; left published, the switcher and hreflang
     * offered all of them (the vendor's demo company, translated), and the archive's own zh-CN
     * edition sat beside the copy derived from the customer's words — measured 23/09/2026 on
     * `j-h0n2f4`. A governed row is compared field by field by `inspect`, so it is left exactly as
     * it is; a retired language is hidden through its `#__languages` row instead, which `inspect`
     * does not read. Every row is recorded as a `visibility` undo, and `multilingual.restore` brings a
     * pass back (a sealed site's `apply.revert` takes contract receipts only).
     *
     * @param array<string,array<int,array<string,mixed>>> $rows `language`, `article`, `menuItem`, `module` summaries
     * @param array<string,int[]> $governed per kind, the ids the contract governs — derived copies
     *        and the switcher included. Per kind because ids are per table.
     * @param string[] $routed the content languages that stay published; the source always does
     * @return array<int,array{0:string,1:int,2:array<string,int>}>
     */
    public static function retireWrites(array $rows, array $governed, string $source, array $routed): array
    {
        $routed = array_merge([$source], $routed);
        $out = [];
        foreach ($rows['language'] ?? [] as $row)
            if ((int) $row['published'] === 1 && !in_array((string) $row['lang_code'], $routed, true))
                $out[] = ['language', (int) $row['lang_id'], ['published' => 0]];
        $columns = ['article' => 'state', 'menuItem' => 'published', 'module' => 'published'];
        foreach ($columns as $kind => $column) {
            $keep = array_flip(array_map('intval', $governed[$kind] ?? []));
            foreach ($rows[$kind] ?? [] as $row) {
                $language = (string) ($row['language'] ?? '*');
                if ($language === '*' || $language === $source || (int) ($row[$column] ?? 0) !== 1) continue;
                if ($kind === 'menuItem' && (int) ($row['client_id'] ?? 0) !== 0) continue;
                if (isset($keep[(int) $row['id']])) continue;
                $out[] = [$kind, (int) $row['id'], [$column => 0]];
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------- phases */

    /**
     * Move every translated source row off `*`, and make sure the target language has a row —
     * unpublished, so nothing is routed to it yet.
     */
    private function phasePrepare(array &$job, array $state, array $translations, string $locale): bool
    {
        $keys = [];
        foreach ($state['keys'] as $key => $meta) {
            if (isset($meta['locale']) || !empty($meta['switcher'])) continue;
            if ($this->profile->isTranslated($key) && (string) $state['rows'][$key]['language'] === '*') $keys[] = $key;
        }
        $slice = array_slice($keys, 0, self::CHUNK);
        foreach ($slice as $key)
            ($this->write)($state['keys'][$key]['kind'], $state['ids'][$key], ['language' => $this->profile->sourceLanguage()]);
        // The cursor counts rows already moved. It is what a caller watches to tell a phase that is
        // working from one that is repeating itself — `$keys` shrinks as rows move, so without this
        // every call of a long retag looks identical from outside.
        $job['cursor'] += count($slice);
        if (count($keys) > count($slice)) return false;
        if ($job['contentLanguage'] === null) {
            $row = $this->contentLanguageRow($locale);
            $name = $this->catalog->name($locale);
            $fields = $this->profile->contentLanguageFields($locale, $name);
            // Joomla's own language installer already created this row, unpublished. Creating a
            // second one would give the site two rows for one tag and route neither.
            $fields['published'] = 0;
            $job['contentLanguage'] = ($this->write)('language', $row ? (int) $row['lang_id'] : 0, $fields);
        }
        $job['retagged'] = true;
        return true;
    }

    private function contentLanguageRow(string $locale): ?array
    {
        for ($offset = 0; $offset < 500; $offset += 100) {
            $page = $this->writer->list('language', $offset, 100);
            foreach ($page as $row) if ((string) $row['lang_code'] === $locale) return $row;
            if (count($page) < 100) return null;
        }
        return null;
    }

    private function phaseMenu(array &$job, array $state, array $translations, string $locale): bool
    {
        return $this->createChunk($job, $state, $translations, $locale, 'menuItem', function (array $row, array $fields, array $idMap) use ($locale): array {
            $parent = (int) $row['parent_id'];
            // The LIVE map, handed in per row. Capturing the job here instead read the ids as they
            // were when the phase began, so every child in the first chunk hung off its SOURCE
            // parent and the whole Chinese menu was a second copy of the English tree.
            $mapped = $idMap['menuItem'][$parent] ?? null;
            return [
                'title' => $fields['title'],
                'menutype' => $row['menutype'],
                // Remapped HERE, at create time, because by this phase every article already has a
                // copy to point at. This is the whole reason articles run first.
                'link' => $this->profile->remapLink((string) $row['link'], $idMap),
                'type' => $row['type'],
                'published' => $row['published'],
                'parent_id' => $mapped ?? $parent,
                'browserNav' => $row['browserNav'],
                'access' => $row['access'],
                'language' => $locale,
                'note' => $this->profile->marker($this->currentKey, $locale),
                'params' => $row['params'],
                'home' => $row['home'],
                'template_style_id' => $row['template_style_id'],
                'alias' => $this->profile->derivedAlias('menuItem', (string) $row['alias'], $locale),
            ];
        });
    }

    private function phaseArticles(array &$job, array $state, array $translations, string $locale): bool
    {
        return $this->createChunk($job, $state, $translations, $locale, 'article', function (array $row, array $fields, array $idMap) use ($locale): array {
            return [
                'title' => $fields['title'],
                'alias' => $this->profile->derivedAlias('article', (string) $row['alias'], $locale),
                'introtext' => $fields['introtext'], 'fulltext' => $fields['fulltext'],
                'state' => $row['state'], 'catid' => $row['catid'], 'images' => $row['images'],
                'urls' => $row['urls'], 'attribs' => $row['attribs'], 'metadata' => $row['metadata'],
                'metakey' => $row['metakey'], 'metadesc' => $row['metadesc'],
                'language' => $locale, 'featured' => $row['featured'], 'ordering' => $row['ordering'],
                'access' => $row['access'], 'publish_up' => $row['publish_up'], 'publish_down' => $row['publish_down'],
                // A copy is its source minus the translated fields, and inspect holds it to exactly
                // that — the note included. Business marks every article (`tb:pilot`…); leaving it
                // behind failed every copy (23/09/2026, j-ee6vsk).
                'note' => (string) ($row['note'] ?? ''),
            ];
        });
    }

    /**
     * The copies of the modules, each with its page assignment written in the same breath.
     *
     * 🔒 A MODULE IS NEVER LEFT WITHOUT ITS ASSIGNMENT. Assigning them in a later pass left every
     * copy showing on no page at all between the two, and `inspect()` was right to call that drift:
     * a module with an empty `#__modules_menu` is a module the site does not display. Menu copies
     * already exist by this phase, so there is nothing to wait for.
     */
    private function phaseModules(array &$job, array $state, array $translations, string $locale): bool
    {
        $assign = function (string $key, int $id, array $idMap) use ($state): void {
            $menus = [];
            foreach ($state['assignments'][$key] as $menu) {
                $n = abs($menu);
                $menus[] = ($menu < 0 ? -1 : 1) * ($n === 0 ? 0 : ($idMap['menuItem'][$n] ?? $n));
            }
            sort($menus);
            ($this->write)('moduleAssignment', $id, ['menuids' => json_encode(array_values($menus))]);
        };
        $done = $this->createChunk($job, $state, $translations, $locale, 'module', function (array $row, array $fields, array $idMap) use ($locale): array {
            $lock = $this->contract->lockFields($this->currentKey);
            return [
                'title' => $this->profile->showsTitle($this->currentKey, $lock)
                    ? $fields['title']
                    : $lock['title'] . ' [' . $locale . ']',
                'note' => $this->profile->marker($this->currentKey, $locale),
                'content' => $fields['content'], 'position' => $row['position'],
                'module' => $row['module'], 'access' => $row['access'], 'showtitle' => $row['showtitle'],
                'params' => $fields['params'], 'published' => $row['published'],
                'language' => $locale, 'ordering' => $row['ordering'],
            ];
        }, $assign);
        return $done;
    }

    /** The association groups that let the switcher land on the same page in the other language. */
    private function phaseRelations(array &$job, array $state, array $translations, string $locale): bool
    {
        $work = [];
        foreach (['menuItem' => 'menuAssociation', 'article' => 'articleAssociation'] as $kind => $relation)
            foreach ($this->sources($state, $kind) as $key) $work[] = [$relation, $key];
        $slice = array_slice($work, $job['cursor'], self::CHUNK);
        foreach ($slice as [$relation, $key]) {
            // 🔒 THE WHOLE GROUP, NOT A PAIR. An association in Joomla is one set per entity across
            // every language, and `JoomlaRelations` refuses — rightly — to merge a pair into a
            // group that already exists. Sending [source, new] worked while there was one
            // translation and failed on the THIRD language with "association already belongs to
            // another group", measured adding French to a site that already had Chinese.
            $ids = [(int) $state['ids'][$key]];
            foreach ($state['keys'] as $other => $meta)
                if (($meta['base'] ?? null) === $key && isset($state['ids'][$other])) $ids[] = (int) $state['ids'][$other];
            $ids[] = (int) $job['ids'][$key];
            $ids = array_values(array_unique($ids));
            sort($ids);
            // 🔒 WRITE THE GROUP THROUGH THE SOURCE, NOT THROUGH THE NEW COPY. The undo log stores
            // what `read()` answered for the id being written, and a copy created moments ago
            // belongs to no group at all — so a write addressed to it recorded an EMPTY before, and
            // undoing it deleted the whole group instead of restoring it. Measured 12/09 on a site
            // with English, Chinese and French: reverting French left `#__associations` empty, and
            // the switcher on a Chinese inner page fell back to the Chinese home page. Addressed to
            // the source, the before is the group as it stood — [en, zh] — and the undo restores it.
            ($this->write)($relation, (int) $state['ids'][$key], ['ids' => json_encode($ids)]);
        }
        $job['cursor'] += count($slice);
        return $job['cursor'] >= count($work);
    }

    /**
     * The switcher, the routing plugin, and only then the language itself.
     *
     * Publishing the content language is the last write of the last phase on purpose: it is the
     * single switch that makes the new edition reachable, so everything before it is invisible to
     * a visitor and everything after it is nothing.
     */
    private function phaseFinish(array &$job, array $state, array $translations, string $locale): bool
    {
        if ($job['switcher'] === null) {
            // Same reason as every other copy: a `finish` that died after this write leaves the
            // module behind, and making a second one would put two switchers in the header.
            $id = $this->existingSwitcher() ?: ($this->write)('module', 0, $this->switcherFields());
            // A switcher the archive ships is a governed module with its own locked assignment (Business:
            // module-425); only a switcher this job created is placed on every page.
            if (!in_array($id, array_map('intval', $state['ids']), true))
                ($this->write)('moduleAssignment', $id, ['menuids' => '[0]']);
            $job['switcher'] = $id;
        }
        $filters = $this->writer->list('languageFilter', 0, 10);
        // The writer aliases this row's primary key to `id` in a listing; reading `extension_id`
        // here handed the write an id of 0, which the catalog correctly refused as a create.
        $filterId = (int) ($filters[0]['id'] ?? 0);
        if (!$filterId) throw new RuntimeException('Joomla Language Filter is not installed');
        ($this->write)('languageFilter', $filterId, [
            'enabled' => 1,
            'params' => json_encode($this->profile->languageFilterParams()),
        ]);
        ($this->write)('language', (int) $job['contentLanguage'], ['published' => 1]);
        return true;
    }

    /** Module fields for the switcher, all of them fixed by the profile. */
    private function switcherFields(): array
    {
        $s = $this->profile->switcherPresentation();
        $s['params'] = json_encode($s['params']);
        unset($s['publish_up'], $s['publish_down']);
        return $s;
    }

    /* ---------------------------------------------------------------- helpers */

    /** The base key currently being copied, so a field builder can ask the profile about it. */
    private string $currentKey = '';

    /**
     * Create up to CHUNK copies of one kind, recording each id as it lands.
     *
     * 🔒 A ROW THAT IS ALREADY THERE IS ADOPTED, NOT MADE AGAIN — and this is not belt and braces,
     * it is the only thing standing between a killed request and a site with two Chinese menus.
     * The phase runs inside a database transaction, but **that transaction does not hold**: Joomla
     * places a menu item, a category and every `#__assets` row through `Table\Nested::store()`,
     * which takes `LOCK TABLES`, and `LOCK TABLES` is an implicit COMMIT in MySQL and MariaDB.
     * Measured 2026-09-12: a `finish` phase that threw AFTER creating the switcher module rolled
     * back its apply-log rows and its job checkpoint — both ordinary InnoDB writes — while the
     * module itself survived. So a resumed job cannot assume its last phase left nothing behind.
     * Each copy is found by an identity it alone can have (the provenance note; for an article,
     * whose row has no writable note, the derived alias inside its category), so re-running a
     * phase converges instead of duplicating.
     */
    private function createChunk(array &$job, array $state, array $translations, string $locale, string $kind, callable $build, ?callable $after = null): bool
    {
        $idMap = $this->idMap($state, $job);
        $adopted = $this->existingCopies($state, $kind, $locale);
        foreach ($this->sources($state, $kind) as $key)
            if (!isset($job['ids'][$key]) && isset($adopted[$key])) $job['ids'][$key] = $adopted[$key];
        $idMap = $this->idMap($state, $job);
        $keys = array_values(array_filter($this->sources($state, $kind), fn($k) => !isset($job['ids'][$k])));
        $slice = array_slice($keys, 0, self::CHUNK);
        foreach ($slice as $key) {
            $this->currentKey = $key;
            $values = $this->slotValues($state, $key, $locale, $translations, $idMap);
            $row = $this->applySlots($state, $key, $locale, $values);
            $job['ids'][$key] = ($this->write)($kind, 0, $build($state['rows'][$key], $row, $idMap));
            $job['cursor'] += 1;
            // A menu parent created in this very chunk must be visible to the next child.
            $idMap = $this->idMap($state, $job);
            if ($after) $after($key, (int) $job['ids'][$key], $idMap);
        }
        return count($keys) <= count($slice);
    }


    /**
     * Copies of this kind that are already on the site, by the base key they came from.
     *
     * One listing per phase rather than a lookup per row: the alternative is a table scan for every
     * one of 173 copies. A module and a menu item are found by the provenance note they carry; an
     * article by the alias this profile derives for it, because `#__content` has no note column an
     * Apply may write and the alias is unique within a category by Joomla's own rule.
     *
     * @return array<string,int>
     */
    private function existingCopies(array $state, string $kind, string $locale): array
    {
        $rows = [];
        for ($offset = 0; $offset < 20000; $offset += 100) {
            $page = $this->writer->list($kind, $offset, 100);
            foreach ($page as $row) $rows[(int) $row['id']] = $row;
            if (count($page) < 100) break;
        }
        $out = [];
        foreach ($this->sources($state, $kind) as $key) {
            $marker = $this->profile->marker($key, $locale);
            $alias = $this->profile->derivedAlias($kind, (string) ($state['rows'][$key]['alias'] ?? ''), $locale);
            foreach ($rows as $id => $row) {
                if (isset($state['ids'][$key]) && (int) $state['ids'][$key] === $id) continue;
                $found = $kind === 'article'
                    ? ((string) ($row['alias'] ?? '') === $alias && (string) ($row['language'] ?? '') === $locale
                        && (int) ($row['catid'] ?? 0) === (int) $state['rows'][$key]['catid'])
                    : (string) ($row['note'] ?? '') === $marker;
                if ($found) { $out[$key] = $id; break; }
            }
        }
        return $out;
    }

    /** The switcher module already on the site, or 0 — found by the note only it carries. */
    private function existingSwitcher(): int
    {
        $note = $this->profile->switcherPresentation()['note'];
        for ($offset = 0; $offset < 20000; $offset += 100) {
            $page = $this->writer->list('module', $offset, 100);
            foreach ($page as $row) if ((string) ($row['note'] ?? '') === $note) return (int) $row['id'];
            if (count($page) < 100) break;
        }
        return 0;
    }

    /** The source row with its derived slots filled in — structure untouched, words replaced. */
    private function applySlots(array $state, string $key, string $locale, array $values): array
    {
        return $this->contract->patch($state['rows'][$key], $this->contract->derivedSlotsFor($locale, $key), $values);
    }

    /** Installed source id => id of its copy, for every copy that exists so far. */
    private function idMap(array $state, array $job): array
    {
        $map = [];
        foreach ($job['ids'] as $key => $id) $map[$state['keys'][$key]['kind']][(int) $state['ids'][$key]] = (int) $id;
        return $map;
    }
}
