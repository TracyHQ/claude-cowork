<?php
// Loaded by run.php, last. `content.list` `search`: a caller that knows a page by its title asks for
// it instead of paging to it. What is held here, in the order it is checked:
//
//   1. the needle: how a request's words are cleaned, and the LIKE pattern made from them;
//   2. that pattern through a real SQL engine (SQLite), where `%`, `_`, `!` and a backslash must be
//      literal — the one thing no fake can vouch for;
//   3. the real JoomlaSiteWriter, through a driver that records the SQL it is handed: one bound
//      placeholder per column and variant, none overwritten, no state filter, no words in the text,
//      and the plain list() still the query it was before search existed;
//   4. the engine's answers through an in-memory writer: the echo that proves the plugin read the
//      request, `matched`, paging, every kind filtered or refused, and the answer without `search`
//      unchanged byte for byte against what the code before this change answered;
//   5. loading: the classes arrive with the files production requires, and a PHP without intl still
//      answers.
//
// Fixtures are neutral. Collation behaviour (case, accents, NFC against NFD) is the server's and is
// left to a live smoke on a real site; nothing here claims it.

namespace Joomla\CMS {
    if (!class_exists(Factory::class)) {
        final class Factory {}
    }
}

namespace Joomla\Database {
    if (!interface_exists(DatabaseInterface::class)) {
        interface DatabaseInterface {}
    }
    if (!interface_exists(QueryInterface::class)) {
        interface QueryInterface {}
    }
    if (!class_exists(ParameterType::class)) {
        /** The constants Joomla's class carries, so the writer's `ParameterType::STRING` resolves. */
        final class ParameterType
        {
            public const BOOLEAN = 'boolean';
            public const INTEGER = 'int';
            public const STRING = 'string';
        }
    }
}

namespace {
    if (!defined('_JEXEC')) {
        define('_JEXEC', 1);
    }
    require_once __DIR__ . '/../com_claudecowork/site/src/Controller/JoomlaSiteWriter.php';

    /**
     * Joomla's query builder as far as the writer's list and search use it, keeping what it is told.
     * bind() holds its value BY REFERENCE, exactly as Joomla's does — the driver reads the values when
     * the statement runs, not when they are bound, and that is where a loop variable goes wrong.
     */
    final class CsQuery implements \Joomla\Database\QueryInterface
    {
        public array $select = [];
        public string $from = '';
        public array $join = [];
        public array $where = [];
        public array $order = [];
        /** @var array<string,object{value:mixed,type:string}> */
        public array $bounded = [];

        public function select($columns): self { $this->select = array_merge($this->select, (array) $columns); return $this; }
        public function from($table): self { $this->from = $table; return $this; }
        public function join($type, $table, $condition = null): self { $this->join[] = $type . ' JOIN ' . $table . ' ON ' . $condition; return $this; }
        public function where($conditions): self { $this->where[] = $conditions; return $this; }
        public function order($columns): self { $this->order[] = $columns; return $this; }
        public function bind($key, &$value, $type = 'string'): self
        {
            $bound = new \stdClass();
            $bound->value = &$value;
            $bound->type = $type;
            $this->bounded[$key] = $bound;
            return $this;
        }

        public function sql(): string
        {
            return 'SELECT ' . implode(', ', $this->select) . ' FROM ' . $this->from
                . ($this->join ? ' ' . implode(' ', $this->join) : '')
                . ($this->where ? ' WHERE ' . implode(' AND ', $this->where) : '')
                . ($this->order ? ' ORDER BY ' . implode(', ', $this->order) : '');
        }
    }

    /** A driver that answers what it was told to and records every statement it was asked to run. */
    final class CsDb implements \Joomla\Database\DatabaseInterface
    {
        /** @var array<int,array{sql:string,bound:array<string,mixed>,types:array<string,string>,offset:int,limit:int}> */
        public array $ran = [];
        public array $rows = [];
        public $scalar = 0;
        private $pending = null;

        public function getQuery($new = false): CsQuery { return new CsQuery(); }
        public function quoteName($name, $as = null): string
        {
            $quote = fn (string $n): string => implode('.', array_map(fn (string $part): string => '`' . $part . '`', explode('.', $n)));
            return $quote($name) . ($as !== null ? ' AS `' . $as . '`' : '');
        }
        public function quote($text): string { return "'" . addslashes((string) $text) . "'"; }
        public function setQuery($query, $offset = 0, $limit = 0): self { $this->pending = [$query, $offset, $limit]; return $this; }
        public function loadAssocList(): array { $this->run(); return $this->rows; }
        public function loadResult() { $this->run(); return $this->scalar; }
        public function execute(): bool { $this->run(); return true; }

        private function run(): void
        {
            [$query, $offset, $limit] = $this->pending;
            $bound = [];
            $types = [];
            if ($query instanceof CsQuery) {
                foreach ($query->bounded as $key => $item) {
                    $bound[$key] = $item->value;
                    $types[$key] = $item->type;
                }
            }
            $this->ran[] = ['sql' => $query instanceof CsQuery ? $query->sql() : (string) $query, 'bound' => $bound, 'types' => $types, 'offset' => $offset, 'limit' => $limit];
        }
    }

    use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter;

    // A PHP without intl has no `Normalizer`, and the image these tests run in is one. Stand in for it
    // with the two letters the fixtures use, so the code that calls the real class is what runs; on a
    // PHP that has intl (or Joomla's polyfill) this is skipped and the real one does the same work.
    // The absence itself is checked in a process of its own, below.
    if (!class_exists('Normalizer')) {
        final class Normalizer
        {
            public const FORM_D = 2;
            public const FORM_C = 4;
            private const PAIRS = ["\u{1EC5}" => "e\u{302}\u{303}", "\u{1EC7}" => "e\u{323}\u{302}"];

            public static function normalize(string $input, int $form = self::FORM_C)
            {
                return $form === self::FORM_D ? strtr($input, self::PAIRS) : strtr($input, array_flip(self::PAIRS));
            }
        }
    }

    $csNfc = "Nguy\u{1EC5}n";
    $csNfd = "Nguye\u{302}\u{303}n";

    // ============================================================ 1. the needle
    foreach ([
        'a padded needle is trimmed' => [' Roof repair ', 'Roof repair'],
        'nothing is nothing' => ['', ''],
        'blanks are nothing' => ['   ', ''],
        'line breaks and tabs alone are nothing' => ["\t\r\n ", ''],
        'a line break between words is a space' => ["roof\nrepair", 'roof repair'],
        'a tab between words is a space' => ["roof\trepair", 'roof repair'],
        'NUL, escape and DEL are dropped' => ["ro\x00o\x1Bf\x7F", 'roof'],
        'a C1 control is dropped' => ["ro\u{0085}of", 'roof'],
        'no-break and ideographic spaces are trimmed' => ["\u{00A0}roof\u{3000}", 'roof'],
        'spaces inside are kept as typed' => ['roof  repair', 'roof  repair'],
        'one CJK character is a needle' => ["\u{5C4B}", "\u{5C4B}"],
        'CJK words are kept' => ["\u{5C4B}\u{6839}\u{4FEE}\u{7406}", "\u{5C4B}\u{6839}\u{4FEE}\u{7406}"],
        'a four-byte emoji is kept' => ["\u{1F525}", "\u{1F525}"],
        'quotes and a backslash are words like any other' => ["O'Brien \"x\" C:\\path", "O'Brien \"x\" C:\\path"],
        'wildcards and the escape character are words too' => ['50%_off!', '50%_off!'],
    ] as $csLabel => [$csRaw, $csText]) {
        $csGot = SearchNeedle::clean($csRaw);
        check("needle: {$csLabel}", [$csGot['ok'], $csGot['text']], [true, $csText]);
    }
    check('needle: an empty needle has nothing to match', SearchNeedle::clean('   ')['variants'], []);
    check('needle: plain text is matched as itself only', SearchNeedle::clean(' Roof repair '), ['ok' => true, 'text' => 'Roof repair', 'variants' => ['Roof repair']]);
    check('needle: a composed needle is matched composed and decomposed', SearchNeedle::clean($csNfc), ['ok' => true, 'text' => $csNfc, 'variants' => [$csNfc, $csNfd]]);
    check('needle: a decomposed needle is echoed composed and matched both ways', SearchNeedle::clean($csNfd), ['ok' => true, 'text' => $csNfc, 'variants' => [$csNfc, $csNfd]]);

    check('needle: 200 characters are fine, counted as characters and not bytes', SearchNeedle::clean(str_repeat("\u{00E9}", 200))['ok'], true);
    check('needle: 201 characters are refused', SearchNeedle::clean(str_repeat("\u{00E9}", 201))['ok'], false);
    check('needle: 200 four-byte characters are fine', SearchNeedle::clean(str_repeat("\u{1F525}", 200))['ok'], true);
    check('needle: 201 four-byte characters are refused', SearchNeedle::clean(str_repeat("\u{1F525}", 201))['ok'], false);
    check('needle: the limit is on the cleaned needle, so padding does not count', SearchNeedle::clean('  ' . str_repeat('a', 200) . "  \n")['ok'], true);
    foreach (['an array' => ['roof'], 'a number' => 123, 'a float' => 1.5, 'true' => true, 'null' => null, 'an object' => new stdClass()] as $csLabel => $csRaw) {
        check("needle: {$csLabel} is refused", SearchNeedle::clean($csRaw)['ok'], false);
    }
    $csBad = SearchNeedle::clean("\xC3\x28");
    check('needle: text that is not UTF-8 is refused, and says so', [$csBad['ok'], str_contains($csBad['message'], 'UTF-8')], [false, true]);

    check('like: a word sits between two wildcards', SearchNeedle::like('roof'), '%roof%');
    check('like: % and _ are made literal', SearchNeedle::like('50%_off'), '%50!%!_off%');
    check('like: the escape character escapes itself', SearchNeedle::like('wow!'), '%wow!!%');
    check('like: quotes and a backslash are left alone', SearchNeedle::like("C:\\path O'Brien \"x\""), "%C:\\path O'Brien \"x\"%");
    check('like: an escape is never escaped twice', SearchNeedle::like('!%'), '%!!!%%');

    // ============================================ 2. the pattern through a real SQL engine
    // SQLite's LIKE folds case for ASCII only, where MySQL's follows the collation, so this holds the
    // ESCAPE behaviour and nothing else: that the pattern means what it says.
    $csTitles = [
        1 => 'Roof repair', 2 => 'Roof Repair Basics', 3 => '50%_off sale', 4 => '500 off sale', 5 => 'Wow! Deals',
        6 => 'C:\\path\\to', 7 => "O'Brien & Co", 8 => 'Say "hello"', 9 => 'a_b', 10 => 'axb', 11 => '100!', 12 => 'Tile',
    ];
    checkTrue('sql engine: pdo_sqlite is here to run the patterns', extension_loaded('pdo_sqlite'));
    if (extension_loaded('pdo_sqlite')) {
        $csPdo = new PDO('sqlite::memory:');
        $csPdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, title TEXT)');
        $csInsert = $csPdo->prepare('INSERT INTO t (id, title) VALUES (?, ?)');
        foreach ($csTitles as $csId => $csTitle) {
            $csInsert->execute([$csId, $csTitle]);
        }
        $csFind = function (string $pattern) use ($csPdo): array {
            $statement = $csPdo->prepare("SELECT id FROM t WHERE title LIKE ? ESCAPE '" . SearchNeedle::LIKE_ESCAPE . "' ORDER BY id");
            $statement->execute([$pattern]);
            return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        };
        foreach ([
            'roof repair' => [1, 2],
            '50%_off' => [3],
            '_' => [3, 9],
            '%' => [3],
            '!' => [5, 11],
            '100!' => [11],
            '\\' => [6],
            "o'brien" => [7],
            '"hello"' => [8],
            'a_b' => [9],
        ] as $csNeedle => $csWant) {
            check('sql engine: ' . $csNeedle . ' matches only what holds it', $csFind(SearchNeedle::like($csNeedle)), $csWant);
        }
        // The control: without the escaping the same needles are wildcards, so the checks above could fail.
        check('sql engine: unescaped, a_b is a wildcard and finds two', $csFind('%a_b%'), [9, 10]);
        check('sql engine: unescaped, 50%_off is a wildcard and finds two', $csFind('%50%_off%'), [3, 4]);
    }

    // ===================================== 3. the real writer, through a driver that records SQL
    $csClass = new ReflectionClass(JoomlaSiteWriter::class);
    $csConsts = $csClass->getConstants();
    $csSql = function (callable $act) {
        $db = new CsDb();
        $act(new JoomlaSiteWriter($db), $db);
        return $db;
    };

    // list() with no words is the query it was before this change, statement for statement (captured
    // from the code at c9e0820 through this same driver).
    foreach ([
        'article' => 'SELECT a.`id`, a.`title`, a.`alias`, a.`catid`, a.`state`, a.`language`, a.`created`, a.`modified`, a.`created_by`, a.`created_by_alias`, a.`featured`, a.`images`, a.`metadesc`, a.`metakey`, a.`access`, a.`publish_up`, a.`publish_down`, `c`.`title` AS `category_title`, `c`.`path` AS `category_path`, SHA2(CONCAT(COALESCE(a.introtext, \'\'), CHAR(0), COALESCE(a.`fulltext`, \'\')), 256) AS `checksum` FROM `#__content` AS `a` LEFT JOIN `#__categories` AS `c` ON c.id = a.catid ORDER BY a.`id` ASC',
        'category' => 'SELECT a.`id`, a.`title`, a.`alias`, a.`path`, a.`parent_id`, a.`level`, a.`extension`, a.`published`, a.`language` FROM `#__categories` AS `a` ORDER BY a.`id` ASC',
        'menuItem' => 'SELECT a.`id`, a.`menutype`, a.`title`, a.`note`, a.`alias`, a.`path`, a.`link`, a.`type`, a.`published`, a.`parent_id`, a.`level`, a.`language`, a.`client_id` FROM `#__menu` AS `a` WHERE a.`client_id` = 0 ORDER BY a.`id` ASC',
        'bannerClient' => 'SELECT a.`id`, a.`name`, a.`contact`, a.`email`, a.`state` FROM `#__banner_clients` AS `a` ORDER BY a.`id` ASC',
    ] as $csKind => $csWantSql) {
        $csDb = $csSql(fn ($writer) => $writer->list($csKind, 20, 50));
        check("sql: list({$csKind}) is the query it was before search existed", [$csDb->ran[0]['sql'], $csDb->ran[0]['bound'], $csDb->ran[0]['offset'], $csDb->ran[0]['limit']], [$csWantSql, [], 20, 50]);
    }

    $csTitleAlias = "(a.`title` LIKE :s0 ESCAPE '!' OR a.`title` LIKE :s1 ESCAPE '!' OR a.`alias` LIKE :s2 ESCAPE '!' OR a.`alias` LIKE :s3 ESCAPE '!')";
    $csDb = $csSql(fn ($writer) => $writer->searchRows('category', [$csNfc, $csNfd], 20, 50));
    $csRan = $csDb->ran[0];
    check('sql: a search adds one LIKE per column and variant, each with a placeholder of its own', $csRan['sql'],
        'SELECT a.`id`, a.`title`, a.`alias`, a.`path`, a.`parent_id`, a.`level`, a.`extension`, a.`published`, a.`language` FROM `#__categories` AS `a` WHERE ' . $csTitleAlias . ' ORDER BY a.`id` ASC');
    check('sql: every placeholder holds its own pattern, none overwritten by a later one', $csRan['bound'],
        [':s0' => '%' . $csNfc . '%', ':s1' => '%' . $csNfd . '%', ':s2' => '%' . $csNfc . '%', ':s3' => '%' . $csNfd . '%']);
    check('sql: the patterns are bound as strings', array_values(array_unique($csRan['types'])), ['string']);
    check('sql: offset and limit apply to the narrowed set', [$csRan['offset'], $csRan['limit']], [20, 50]);

    $csDb = $csSql(fn ($writer) => $writer->searchRows('menuItem', ['news'], 0, 100));
    check('sql: a menu item search keeps the site-menu scope beside the words',
        substr($csDb->ran[0]['sql'], strpos($csDb->ran[0]['sql'], ' WHERE ')),
        " WHERE a.`client_id` = 0 AND (a.`title` LIKE :s0 ESCAPE '!' OR a.`alias` LIKE :s1 ESCAPE '!') ORDER BY a.`id` ASC");

    $csDb = $csSql(fn ($writer) => $writer->searchRows('article', ['roof'], 0, 100));
    $csArticleSql = $csDb->ran[0]['sql'];
    checkTrue('sql: an article search keeps the category join', str_contains($csArticleSql, 'LEFT JOIN `#__categories` AS `c` ON c.id = a.catid'));
    checkTrue('sql: and searches the article, not the joined category', str_contains($csArticleSql, " WHERE (a.`title` LIKE :s0 ESCAPE '!' OR a.`alias` LIKE :s1 ESCAPE '!') ORDER BY a.`id` ASC"));

    $csDb = $csSql(fn ($writer) => $writer->searchRows('bannerClient', ['acme'], 0, 10));
    checkTrue('sql: a banner client is searched by name alone — the table has no alias', str_contains($csDb->ran[0]['sql'], " WHERE (a.`name` LIKE :s0 ESCAPE '!') ORDER BY"));

    foreach ($csConsts['SEARCH_COLUMNS'] as $csKind => $csColumns) {
        $csDb = $csSql(fn ($writer) => $writer->searchRows($csKind, ['x'], 0, 10));
        $csWhere = explode(' WHERE ', $csDb->ran[0]['sql'], 2)[1] ?? '';
        $csWhere = explode(' ORDER BY ', $csWhere, 2)[0];
        checkTrue("sql: {$csKind} has no state or published filter, so a trashed row is listed", !str_contains($csWhere, 'state') && !str_contains($csWhere, 'published'));
        check("columns: every column searched on {$csKind} is one its list returns", array_values(array_diff($csColumns, $csConsts['LIST_COLUMNS'][$csKind] ?? [])), []);
        checkTrue("sql: {$csKind} reads only its searched columns", (bool) preg_match_all('/a\.`(\w+)` LIKE/', $csWhere, $csFound) && array_values(array_unique($csFound[1])) === $csColumns);
    }
    check('columns: the searchable kinds are these thirteen', (new JoomlaSiteWriter(new CsDb()))->searchableKinds(),
        ['article', 'category', 'tag', 'menuItem', 'module', 'templateStyle', 'language', 'menutype', 'banner', 'contact', 'newsfeed', 'bannerClient', 'field']);

    // The words are bound, never part of the SQL text. Each needle is paired with a fragment that a
    // string-built statement would carry even after escaping, and that no statement here contains.
    foreach (["O'Brien" => 'Brien', 'say "hi"' => 'say', "x'; DROP TABLE t; --" => 'DROP', '50%_off' => '_off'] as $csNeedle => $csFragment) {
        $csDb = $csSql(fn ($writer) => $writer->searchRows('article', [$csNeedle], 0, 10));
        checkTrue("sql: {$csNeedle} is not in the statement's text", !str_contains($csDb->ran[0]['sql'], $csFragment));
        check("sql: {$csNeedle} is bound as a pattern", $csDb->ran[0]['bound'][':s0'], SearchNeedle::like($csNeedle));
    }

    $csDb = new CsDb();
    $csDb->scalar = '7';
    $csCounted = (new JoomlaSiteWriter($csDb))->countMatches('article', ['roof']);
    check('count: one COUNT(*) under the same predicate, with no join and no order', $csDb->ran[0]['sql'],
        "SELECT COUNT(*) FROM `#__content` AS `a` WHERE (a.`title` LIKE :s0 ESCAPE '!' OR a.`alias` LIKE :s1 ESCAPE '!')");
    check('count: and answers an integer', $csCounted, 7);
    $csDb = new CsDb();
    (new JoomlaSiteWriter($csDb))->countMatches('menuItem', ['news']);
    check('count: a menu item count keeps the site-menu scope', $csDb->ran[0]['sql'],
        "SELECT COUNT(*) FROM `#__menu` AS `a` WHERE a.`client_id` = 0 AND (a.`title` LIKE :s0 ESCAPE '!' OR a.`alias` LIKE :s1 ESCAPE '!')");

    $csDb = new CsDb();
    $csDb->rows = [['id' => 5, 'title' => 'News', 'alias' => 'news']];
    check('search: the rows come back as list() hands them', (new JoomlaSiteWriter($csDb))->searchRows('category', ['news'], 0, 10), [['id' => 5, 'title' => 'News', 'alias' => 'news']]);
    foreach ([['user', ['x']], ['redirect', ['x']], ['article', []]] as [$csKind, $csVariants]) {
        $csDb = new CsDb();
        $csThrown = '';
        try {
            (new JoomlaSiteWriter($csDb))->searchRows($csKind, $csVariants, 0, 10);
        } catch (\RuntimeException $csThrownBy) {
            $csThrown = $csThrownBy->getMessage();
        }
        checkTrue("search: the writer refuses {$csKind} with " . count($csVariants) . ' words, and runs nothing', $csThrown !== '' && $csDb->ran === []);
    }

    // =============================================== 4. the engine's answers, through a writer in memory
    /** The real writer's searched columns, in memory. A check below holds the two maps equal. */
    final class CsSearchWriter extends FakeSiteWriter implements SearchableSiteWriter
    {
        public const COLUMNS = [
            'article' => ['title', 'alias'], 'category' => ['title', 'alias'], 'tag' => ['title', 'alias'], 'menuItem' => ['title', 'alias'],
            'module' => ['title'], 'templateStyle' => ['title'], 'language' => ['title'], 'menutype' => ['title'],
            'banner' => ['name', 'alias'], 'contact' => ['name', 'alias'], 'newsfeed' => ['name', 'alias'], 'bannerClient' => ['name'],
            'field' => ['title', 'name'],
        ];
        /** What the engine asked of it, in order. */
        public array $calls = [];

        public function searchableKinds(): array { return array_keys(self::COLUMNS); }
        public function searchRows(string $kind, array $variants, int $offset, int $limit): array
        {
            $this->calls[] = ['searchRows', $kind, $variants, $offset, $limit];
            return array_slice($this->matching($kind, $variants), $offset, $limit);
        }
        public function countMatches(string $kind, array $variants): int
        {
            $this->calls[] = ['countMatches', $kind, $variants];
            return count($this->matching($kind, $variants));
        }
        private function matching(string $kind, array $variants): array
        {
            $rows = $this->store[$kind] ?? [];
            ksort($rows);
            $out = [];
            foreach ($rows as $id => $fields) {
                foreach (self::COLUMNS[$kind] as $column) {
                    foreach ($variants as $variant) {
                        if (mb_stripos((string) ($fields[$column] ?? ''), $variant) !== false) {
                            $out[] = ['id' => $id] + $fields;
                            continue 3;
                        }
                    }
                }
            }
            return $out;
        }
    }

    /** A writer that predates search, whatever mode the suite runs in: the plain base, never the bulk one. */
    final class FakeSearchlessSite extends FakeSiteWriterBase {}

    $csToken = 'search-token-at-least-16chars';
    $csAsk = function (array $params, SiteWriter $writer) use ($csToken): array {
        return (new Engine($csToken, [], null, null, null, null, $writer, null, null))
            ->handle(['token' => $csToken, 'action' => 'content.list', 'params' => $params]);
    };
    $csShop = function (): CsSearchWriter {
        $w = new CsSearchWriter();
        $w->store['article'] = [
            1 => ['title' => 'Roof repair', 'alias' => 'roof-repair', 'introtext' => '<p>one</p>'],
            2 => ['title' => 'Dachreparatur', 'alias' => 'roof-repair-de'],
            3 => ['title' => 'ROOF REPAIR guide', 'alias' => 'guide', 'state' => -2, 'introtext' => '<p>three</p>'],
            4 => ['title' => 'Gutter cleaning', 'alias' => 'gutters', 'note' => 'roof repair', 'introtext' => '<p>roof repair</p>'],
            5 => ['title' => '50%_off sale', 'alias' => 'sale-a'],
            6 => ['title' => '500 off sale', 'alias' => 'sale-b'],
            7 => ['title' => "O'Brien", 'alias' => 'obrien'],
            8 => ['title' => 'Say "hi"', 'alias' => 'say-hi'],
            9 => ['title' => "Nguy\u{1EC5}n", 'alias' => 'person-a'],
            10 => ['title' => "Nguye\u{302}\u{303}n", 'alias' => 'person-b'],
            11 => ['title' => "\u{5C4B}\u{6839}\u{4FEE}\u{7406}", 'alias' => 'cjk'],
            12 => ['title' => "\u{1F525} sale", 'alias' => 'fire'],
            13 => ['title' => 'Wow! Deals', 'alias' => 'deals'],
            14 => ['title' => 'C:\\path', 'alias' => 'path'],
        ];
        $w->store['module'] = [
            1 => ['title' => 'Roof banner', 'note' => ''],
            2 => ['title' => 'Menu', 'note' => 'roof'],
        ];
        return $w;
    };
    $csIds = fn (array $answer): array => array_column($answer['items'] ?? [], 'id');

    check('fake: the in-memory writer searches the columns the real one does', CsSearchWriter::COLUMNS, $csConsts['SEARCH_COLUMNS']);

    // --- what a search answers
    $csWriter = $csShop();
    $csAnswer = $csAsk(['kind' => 'article', 'search' => ' Roof repair '], $csWriter);
    check('answer: a padded needle is echoed trimmed, with the count', [$csAnswer['ok'], $csAnswer['search'], $csAnswer['matched']], [true, 'Roof repair', 2]);
    check('answer: its keys, in order', array_keys($csAnswer), ['ok', 'kind', 'offset', 'search', 'matched', 'items']);
    check('answer: the title hits in id order, a trashed one included, a note not matched', $csIds($csAnswer), [1, 3]);
    check('answer: a row is the summary list() gives', $csAnswer['items'][0], ['id' => 1, 'title' => 'Roof repair', 'alias' => 'roof-repair', 'introtext' => '<p>one</p>']);
    check('answer: an alias-only hit is found — language editions share an alias stem', $csIds($csAsk(['kind' => 'article', 'search' => 'roof-repair'], $csShop())), [1, 2]);
    check('answer: a match is not case-sensitive', $csIds($csAsk(['kind' => 'article', 'search' => 'GUTTER'], $csShop())), [4]);
    check('answer: no match is an empty page that says so', (function () use ($csAsk, $csShop) {
        $a = $csAsk(['kind' => 'article', 'search' => 'zzz'], $csShop());
        return [$a['ok'], $a['search'], $a['matched'], $a['items']];
    })(), [true, 'zzz', 0, []]);

    foreach (['', '   ', "\n\t"] as $csBlank) {
        $csWriter = $csShop();
        $csAnswer = $csAsk(['kind' => 'article', 'search' => $csBlank], $csWriter);
        check('answer: a blank needle filters nothing, is echoed empty and has no count', [$csAnswer['ok'], $csAnswer['search'], array_key_exists('matched', $csAnswer), count($csAnswer['items'])], [true, '', false, 14]);
        check("answer: a blank needle's keys are the list's plus the echo", array_keys($csAnswer), ['ok', 'kind', 'offset', 'search', 'items']);
        check('answer: a blank needle never reaches the search', $csWriter->calls, []);
    }

    foreach (['50%_off' => [5], 'wow!' => [13], 'C:\\path' => [14], "O'Brien" => [7], 'say "hi"' => [8]] as $csNeedle => $csWant) {
        $csWriter = $csShop();
        $csAnswer = $csAsk(['kind' => 'article', 'search' => $csNeedle], $csWriter);
        check("answer: {$csNeedle} is echoed as sent", $csAnswer['search'], $csNeedle);
        check("answer: {$csNeedle} finds its title", $csIds($csAnswer), $csWant);
        check("answer: {$csNeedle} reaches the writer unescaped — the pattern is the SQL side's job", $csWriter->calls[0][2], [$csNeedle]);
    }

    foreach (['composed' => $csNfc, 'decomposed' => $csNfd] as $csHow => $csNeedle) {
        $csAnswer = $csAsk(['kind' => 'article', 'search' => $csNeedle], $csShop());
        check("answer: a {$csHow} needle finds titles stored composed and decomposed", $csIds($csAnswer), [9, 10]);
        check("answer: a {$csHow} needle is echoed composed", $csAnswer['search'], $csNfc);
    }
    check('answer: one CJK character finds its title', $csIds($csAsk(['kind' => 'article', 'search' => "\u{5C4B}"], $csShop())), [11]);
    check('answer: CJK words find their title', $csIds($csAsk(['kind' => 'article', 'search' => "\u{6839}\u{4FEE}"], $csShop())), [11]);
    check('answer: a four-byte emoji finds its title', $csIds($csAsk(['kind' => 'article', 'search' => "\u{1F525}"], $csShop())), [12]);

    check('answer: 200 characters are answered', $csAsk(['kind' => 'article', 'search' => str_repeat('a', 200)], $csShop())['ok'], true);
    foreach (['201 characters' => str_repeat('a', 201), 'an array' => ['roof'], 'a number' => 5, 'true' => true, 'null' => null, 'an object' => ['a' => 1]] as $csLabel => $csBadSearch) {
        $csWriter = $csShop();
        $csAnswer = $csAsk(['kind' => 'article', 'search' => $csBadSearch], $csWriter);
        check("answer: {$csLabel} is refused as bad_params", [$csAnswer['ok'], $csAnswer['error'] ?? null], [false, 'bad_params']);
        check("answer: {$csLabel} searches nothing", $csWriter->calls, []);
    }

    // --- paging over three matches, and when the count is asked
    $csPaged = new CsSearchWriter();
    foreach ([1 => 'Tile A', 2 => 'Other', 3 => 'Tile B', 4 => 'Plain', 5 => 'Tile C'] as $csId => $csTitle) {
        $csPaged->store['article'][$csId] = ['title' => $csTitle, 'alias' => 'x' . $csId];
    }
    foreach ([
        'the first full page' => [['limit' => 2], [1, 3], 0, 3, ['searchRows', 'countMatches']],
        'the next page, one row short' => [['limit' => 2, 'offset' => 2], [5], 2, 3, ['searchRows', 'countMatches']],
        'a page past the end' => [['limit' => 2, 'offset' => 3], [], 3, 3, ['searchRows', 'countMatches']],
        'a first page that does not fill is the whole set, counted without a query' => [['limit' => 10], [1, 3, 5], 0, 3, ['searchRows']],
        'a first page that exactly fills is counted' => [['limit' => 3], [1, 3, 5], 0, 3, ['searchRows', 'countMatches']],
    ] as $csLabel => [$csParams, $csWantIds, $csWantOffset, $csWantMatched, $csWantCalls]) {
        $csPaged->calls = [];
        $csAnswer = $csAsk(['kind' => 'article', 'search' => 'tile'] + $csParams, $csPaged);
        check("paging: {$csLabel}", [$csIds($csAnswer), $csAnswer['offset'], $csAnswer['matched'], array_column($csPaged->calls, 0)], [$csWantIds, $csWantOffset, $csWantMatched, $csWantCalls]);
    }
    $csPaged->calls = [];
    $csAnswer = $csAsk(['kind' => 'article', 'search' => 'zzz'], $csPaged);
    check('paging: no match on the first page is counted without a query', [$csAnswer['matched'], array_column($csPaged->calls, 0)], [0, ['searchRows']]);

    // --- search with kind and include_body
    check('kind: a module is searched by title, not by its note', $csIds($csAsk(['kind' => 'module', 'search' => 'roof'], $csShop())), [1]);
    $csAnswer = $csAsk(['kind' => 'article', 'search' => 'Roof repair', 'include_body' => true], $csShop());
    check('include_body: each matching row carries its body', array_column($csAnswer['items'], 'introtext'), ['<p>one</p>', '<p>three</p>']);
    check('include_body: and the echo and count are still there', [$csAnswer['search'], $csAnswer['matched']], ['Roof repair', 2]);
    check('include_body: the page ceiling of 25 still holds', $csAsk(['kind' => 'article', 'search' => 'a', 'include_body' => true, 'limit' => 500], $csShop())['ok'], true);

    // --- every kind is filtered or refused, never ignored
    $csFiltered = ['article', 'category', 'tag', 'field', 'menuItem', 'menutype', 'banner', 'bannerClient', 'contact', 'newsfeed', 'language', 'module', 'templateStyle'];
    foreach (SiteWriter::KINDS as $csKind) {
        $csAnswer = $csAsk(['kind' => $csKind, 'search' => 'x'], $csShop());
        $csEchoed = ($csAnswer['ok'] ?? false) === true && array_key_exists('search', $csAnswer);
        $csRefused = ($csAnswer['ok'] ?? null) === false && ($csAnswer['error'] ?? null) === 'bad_params';
        checkTrue("kind {$csKind}: search is answered with its echo or refused, never answered without it", $csEchoed || $csRefused);
        check("kind {$csKind}: it is filtered exactly where the writer can", $csEchoed, in_array($csKind, $csFiltered, true));
        if ($csRefused) {
            checkTrue("kind {$csKind}: the refusal names the kind", str_contains($csAnswer['message'], '"' . $csKind . '"'));
        }
        $csPlain = $csAsk(['kind' => $csKind, 'search' => 'x'], new FakeSiteWriter());
        check("kind {$csKind}: a writer that cannot search refuses it", [$csPlain['ok'], $csPlain['error'] ?? null], [false, 'bad_params']);
        check("kind {$csKind}: without search the answer has the keys it always had", array_keys($csAsk(['kind' => $csKind], $csShop())), ['ok', 'kind', 'offset', 'items']);
        check("kind {$csKind}: and so does a writer that cannot search", array_keys($csAsk(['kind' => $csKind], new FakeSearchlessSite())), ['ok', 'kind', 'offset', 'items']);
    }

    // --- the answer without `search`, byte for byte against the code before this change
    $csPin = function (SiteWriter $w): SiteWriter {
        $w->store['article'][3] = ['title' => 'First', 'alias' => 'first', 'introtext' => '<p>long body</p>'];
        $w->store['article'][9] = ['title' => 'Second', 'alias' => 'second', 'introtext' => '<p>x</p>'];
        $w->store['article'][12] = ['title' => 'Third', 'alias' => 'third', 'introtext' => '<p>y</p>', 'state' => -2];
        $w->store['category'][5] = ['title' => 'News', 'alias' => 'news'];
        return $w;
    };
    $csBefore = [
        'the default kind' => [[], '{"ok":true,"kind":"article","offset":0,"items":[{"id":3,"title":"First","alias":"first","introtext":"<p>long body<\/p>"},{"id":9,"title":"Second","alias":"second","introtext":"<p>x<\/p>"},{"id":12,"title":"Third","alias":"third","introtext":"<p>y<\/p>","state":-2}]}'],
        'a page' => [['kind' => 'article', 'offset' => 1, 'limit' => 1], '{"ok":true,"kind":"article","offset":1,"items":[{"id":9,"title":"Second","alias":"second","introtext":"<p>x<\/p>"}]}'],
        'with bodies' => [['kind' => 'article', 'include_body' => true], '{"ok":true,"kind":"article","offset":0,"items":[{"title":"First","alias":"first","introtext":"<p>long body<\/p>","id":3},{"title":"Second","alias":"second","introtext":"<p>x<\/p>","id":9},{"title":"Third","alias":"third","introtext":"<p>y<\/p>","state":-2,"id":12}]}'],
        'a category' => [['kind' => 'category'], '{"ok":true,"kind":"category","offset":0,"items":[{"id":5,"title":"News","alias":"news"}]}'],
        'past the end' => [['kind' => 'article', 'offset' => 99], '{"ok":true,"kind":"article","offset":99,"items":[]}'],
        'an empty kind' => [['kind' => 'user'], '{"ok":true,"kind":"user","offset":0,"items":[]}'],
        'a limit over the ceiling' => [['kind' => 'article', 'limit' => 500], '{"ok":true,"kind":"article","offset":0,"items":[{"id":3,"title":"First","alias":"first","introtext":"<p>long body<\/p>"},{"id":9,"title":"Second","alias":"second","introtext":"<p>x<\/p>"},{"id":12,"title":"Third","alias":"third","introtext":"<p>y<\/p>","state":-2}]}'],
    ];
    foreach (['a writer that cannot search' => new FakeSiteWriter(), 'a writer that can, asked nothing' => new CsSearchWriter()] as $csWho => $csPinWriter) {
        $csPin($csPinWriter);
        foreach ($csBefore as $csLabel => [$csParams, $csWantJson]) {
            check("compat: {$csLabel} on {$csWho} answers exactly what it did before search", json_encode($csAsk($csParams, $csPinWriter)), $csWantJson);
        }
    }
    check('compat: an unknown kind is still refused by the kind check', $csAsk(['kind' => 'wombat'], new FakeSiteWriter())['error'], 'bad_params');

    // ============================================================== 5. loading, as production does it
    // The tests above require the files by hand, so they would pass with the new classes in a file
    // production never loads. Production requires the list named in EngineFactory::loadEngine (and the
    // Joomla 3 door its own list), and Engine.php requires what it needs itself. This reads those
    // lists from the source and runs a fresh PHP that requires exactly them — once on a PHP with no
    // Normalizer, which must still answer, unnormalised.
    $csListOf = function (string $file, string $after) {
        $source = file_get_contents($file);
        $from = strpos($source, $after);
        if ($from === false || !preg_match('/foreach \(\[(.*?)\] as \$class\)/s', $source, $found, 0, $from)) {
            return [];
        }
        preg_match_all("/'([A-Za-z]+)'/", $found[1], $names);
        return $names[1];
    };
    $csFactoryList = $csListOf(__DIR__ . '/../com_claudecowork/administrator/src/Service/EngineFactory.php', 'function loadEngine');
    $csLegacyList = $csListOf(__DIR__ . '/../com_claudecowork/site/controllers/api.php', 'function exec');
    checkTrue('loading: the lists were read from the source', in_array('SiteWriter', $csFactoryList, true) && in_array('Engine', $csFactoryList, true)
        && in_array('SiteWriter', $csLegacyList, true) && in_array('Engine', $csLegacyList, true));

    $csProbe = function (array $names, bool $writer, bool $withoutIntl) {
        $code = <<<'PHP'
<?php
foreach (__NAMES__ as $c) { require_once __LIB__ . '/' . $c . '.php'; }
$w = __WRITER__ ? new class implements SiteWriter, SearchableSiteWriter {
    public function canCreate(string $kind): bool { return false; }
    public function trashColumn(string $kind): ?string { return null; }
    public function setVisibility(string $kind, int $id, string $column, string $value): void {}
    public function realiasMenuItem(int $id, string $alias): void {}
    public function relabelLanguage(string $from, string $to, ?array $label = null): array { return []; }
    public function readLanguageDefaults(): array { return []; }
    public function writeLanguageDefaults(string $site, string $administrator): void {}
    public function read(string $kind, int $id): ?array { return null; }
    public function write(string $kind, int $id, array $fields): int { return 0; }
    public function delete(string $kind, int $id): void {}
    public function positionOf(string $kind, int $id): ?array { return null; }
    public function move(string $kind, int $id, int $parentId, int $after): void {}
    public function list(string $kind, int $offset, int $limit): array { return []; }
    public function purgeCache(): void {}
    public function searchableKinds(): array { return ['article']; }
    public function searchRows(string $kind, array $variants, int $offset, int $limit): array { return [['id' => 1, 'title' => implode('|', $variants)]]; }
    public function countMatches(string $kind, array $variants): int { return 1; }
} : null;
$e = new Engine('probe-token-at-least-16', [], null, null, null, null, $w, null, null);
$a = $e->handle(['token' => 'probe-token-at-least-16', 'action' => 'content.list', 'params' => ['kind' => 'article', 'search' => "Nguy\u{1EC5}n"]]);
echo json_encode(['normalizer' => class_exists('Normalizer'), 'answer' => $a]);
PHP;
        $code = str_replace(['__NAMES__', '__LIB__', '__WRITER__'], [var_export($names, true), var_export(realpath(__DIR__ . '/../lib'), true), $writer ? 'true' : 'false'], $code);
        $file = tempnam(sys_get_temp_dir(), 'cs-probe');
        file_put_contents($file, $code);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1' . ($withoutIntl ? ' -d disable_classes=Normalizer' : '') . ' ' . escapeshellarg($file) . ' 2>&1', $out, $status);
        unlink($file);
        $text = implode("\n", $out);
        $start = strpos($text, '{"normalizer"');
        $decoded = $start === false ? null : json_decode(substr($text, $start), true);
        return $decoded === null ? ['failed' => $status, 'output' => $text] : $decoded;
    };
    $csWantEcho = ['ok' => true, 'kind' => 'article', 'offset' => 0, 'search' => $csNfc, 'matched' => 1];
    $csLoaded = $csProbe($csFactoryList, true, false);
    check('loading: the factory list is enough to search', array_diff_key($csLoaded['answer'] ?? ['probe' => $csLoaded], ['items' => 1]), $csWantEcho);
    $csBare = $csProbe($csFactoryList, true, true);
    check('loading: on a PHP with no Normalizer the class is gone', $csBare['normalizer'] ?? $csBare, false);
    check('loading: and search still answers, the needle as it came and matched as it came', $csBare['answer'] ?? ['probe' => $csBare], $csWantEcho + ['items' => [['id' => 1, 'title' => $csNfc]]]);
    $csOld = $csProbe($csLegacyList, false, false);
    check('loading: the Joomla 3 door loads the same files and refuses a search with no writer', [$csOld['answer']['ok'] ?? $csOld, $csOld['answer']['error'] ?? null], [false, 'unavailable']);
}
