<?php
// Loaded by run.php, last. `content.list` `search`: a caller that knows a page by its title asks for
// it instead of paging to it. What is held here, in the order it is checked:
//
//   1. the needle: how a request's words are cleaned, and the LIKE pattern made from them;
//   2. that pattern through a real SQL engine (SQLite), where `%`, `_`, `!` and a backslash must be
//      literal — the one thing no fake can vouch for;
//   3. the real JoomlaSiteWriter, through a driver that records the SQL it is handed: one bound
//      placeholder per column and variant, none overwritten, no state filter, no words in the text,
//      an alias compared lower-cased on both sides, and the plain list() still the query it was
//      before search existed;
//   3b. that same writer's statements run for real over SQLite in its case-sensitive LIKE mode, which
//      stands in for the binary alias column: a capitalised needle reaches a lower-case alias whose
//      title does not hold it, and an alias stored in upper case is found;
//   3c. a needle no table can hold (a four-byte character on tables still in utf8mb3) answered as no
//      rows, and every other database error left as read_failed;
//   4. the engine's answers through an in-memory writer: the echo that proves the plugin read the
//      request, `matched`, paging, every kind filtered or refused, and the answer without `search`
//      unchanged byte for byte against what the code before this change answered;
//   5. loading: the classes arrive with the files production requires, and a PHP without intl still
//      answers.
//
// Fixtures are neutral. What a LIKE counts as equal (case, accents, NFC against NFD) is the column's
// collation, and only a live site shows a collation. Joomla's own schema gives a title its table's
// utf8mb4_unicode_ci (case and accents ignored) and an alias utf8mb4_bin (case compared); the writer
// makes an alias ignore case by itself, comparing LOWER(alias) with the lower-cased needle (README,
// "Case is ignored in the title and in the alias"). Section 3 holds that statement's text and 3b
// runs it. What SQLite cannot stand in for, a title's collation, accents, and a database's own
// LOWER() on a non-ASCII capital, is left to a live smoke on a real site, and nothing here claims it.

// The Joomla pieces the search path touches, each declared only if no other test file has declared it
// first. `Joomla\CMS\Factory` is left out ON PURPOSE: the search paths never call it, and
// content-writer-creates.php declares the two calls it does make. An empty one here won whenever this
// file loaded first, and that file then died on `Factory::getDate()`: the order the files load in
// must not decide whether either runs. A file that needs one of the names below guards it the same way.
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

    /**
     * A driver that answers what it was told to and records every statement it was asked to run.
     * Two switches make it a different one: `failWith` refuses every statement with that exception, after
     * recording it, as a database refuses one; `pdo` runs each statement for real on that connection, with
     * the values it was bound to, instead of answering `rows` and `scalar`.
     */
    final class CsDb implements \Joomla\Database\DatabaseInterface
    {
        /** @var array<int,array{sql:string,bound:array<string,mixed>,types:array<string,string>,offset:int,limit:int}> */
        public array $ran = [];
        public array $rows = [];
        public $scalar = 0;
        public ?\Throwable $failWith = null;
        public ?\PDO $pdo = null;
        private array $fetched = [];
        private $pending = null;

        public function getQuery($new = false): CsQuery { return new CsQuery(); }
        public function quoteName($name, $as = null): string
        {
            $quote = fn (string $n): string => implode('.', array_map(fn (string $part): string => '`' . $part . '`', explode('.', $n)));
            return $quote($name) . ($as !== null ? ' AS `' . $as . '`' : '');
        }
        public function quote($text): string { return "'" . addslashes((string) $text) . "'"; }
        public function setQuery($query, $offset = 0, $limit = 0): self { $this->pending = [$query, $offset, $limit]; return $this; }
        public function loadAssocList(): array { $this->run(); return $this->pdo !== null ? $this->fetched : $this->rows; }
        public function loadResult()
        {
            $this->run();
            return $this->pdo !== null ? ($this->fetched === [] ? null : array_values($this->fetched[0])[0]) : $this->scalar;
        }
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
            $sql = $query instanceof CsQuery ? $query->sql() : (string) $query;
            $this->ran[] = ['sql' => $sql, 'bound' => $bound, 'types' => $types, 'offset' => $offset, 'limit' => $limit];
            if ($this->failWith !== null) {
                throw $this->failWith;
            }
            if ($this->pdo !== null) {
                $statement = $this->pdo->prepare($limit > 0 ? $sql . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset : $sql);
                foreach ($bound as $key => $value) {
                    $statement->bindValue($key, $value);
                }
                $statement->execute();
                $this->fetched = $statement->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }

    use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter;

    // A PHP without intl has no `Normalizer` (the official `php` docker images are such a PHP). Stand in
    // for it with the two letters the fixtures use, so the code that calls the real class is what runs;
    // on a PHP that has intl (Homebrew, distro packages, and the PHP CI installs) or Joomla's polyfill
    // this is skipped and the real ICU one does the same work: the suite is green both ways.
    // The absence of a usable Normalizer is checked in processes of their own, below.
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
        'a lone carriage return between words is a space' => ["roof\rrepair", 'roof repair'],
        'a vertical tab between words is a space' => ["roof\x0Brepair", 'roof repair'],
        'a form feed between words is a space' => ["roof\x0Crepair", 'roof repair'],
        'a next-line character (NEL) between words is a space' => ["roof\u{0085}repair", 'roof repair'],
        'a line separator between words is a space' => ["roof\u{2028}repair", 'roof repair'],
        'a paragraph separator between words is a space' => ["roof\u{2029}repair", 'roof repair'],
        'every kind of line break in a row is one space' => ["roof\r\n\u{0085}\u{2028}\u{2029}\x0B\x0Crepair", 'roof repair'],
        'a tab between words is a space' => ["roof\trepair", 'roof repair'],
        'NUL, escape and DEL are dropped' => ["ro\x00o\x1Bf\x7F", 'roof'],
        'C1 controls that are not line breaks are dropped' => ["ro\u{0080}o\u{009F}f", 'roof'],
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
    // The limit is read on the composed (NFC) form, the one the answer echoes: a decomposed letter is
    // three code points and one character, and a needle must not be refused for how it happened to be typed.
    $csDecomposedLetter = "e\u{302}\u{303}";
    check('needle: the limit counts the composed form, so 200 decomposed letters (600 code points) are fine', SearchNeedle::clean(str_repeat($csDecomposedLetter, 200))['ok'], true);
    check('needle: and they are echoed composed', SearchNeedle::clean(str_repeat($csDecomposedLetter, 200))['text'], str_repeat("\u{1EC5}", 200));
    check('needle: 201 decomposed letters are refused, as 201 composed ones are', SearchNeedle::clean(str_repeat($csDecomposedLetter, 201))['ok'], false);

    // Trimming reads the text once, whatever shape the blanks take. (With PCRE's JIT off, a trim written
    // as `^\s+|\s+$` took 3.2 s on 20,000 spaces and 73 s on 100,000: see SearchNeedle::clean.) Refused
    // unread above MAX_BYTES, so what the patterns see stays short — exactly at the limit is still read.
    $csPad = str_repeat(' ', 150);
    foreach ([
        'every kind of blank and nothing else' => ["\u{00A0}\u{3000} \t\r\n\u{2028}", ''],
        'a run of blanks inside is kept as typed' => ['a' . $csPad . 'b', 'a' . $csPad . 'b'],
        'and the ends around it are trimmed' => ['  a' . $csPad . "b \u{00A0}", 'a' . $csPad . 'b'],
        'a long trailing run is trimmed' => ['a' . str_repeat(' ', 4000), 'a'],
        'a long leading run is trimmed' => [str_repeat(' ', 4000) . 'a', 'a'],
        'blanks up to the byte limit are nothing' => [str_repeat(' ', SearchNeedle::MAX_BYTES), ''],
        'one character between blanks' => [' x ', 'x'],
        'one blank character alone' => ["\u{3000}", ''],
        'exactly the byte limit is still read' => [str_repeat(' ', SearchNeedle::MAX_BYTES - 1) . 'a', 'a'],
    ] as $csLabel => [$csRaw, $csText]) {
        $csGot = SearchNeedle::clean($csRaw);
        check("needle: {$csLabel}", [$csGot['ok'], $csGot['text']], [true, $csText]);
    }
    check('needle: one byte over the limit is refused before it is read, whatever it would clean to',
        SearchNeedle::clean(str_repeat(' ', SearchNeedle::MAX_BYTES) . 'a')['ok'], false);
    check('needle: a long blank run inside makes the needle over 200 characters, and it is refused',
        SearchNeedle::clean('a' . str_repeat(' ', 4000) . 'b')['ok'], false);
    check('needle: the refusal for length names the limit', SearchNeedle::clean(str_repeat('a', 201))['message'], 'search is limited to 200 characters');
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

    // lowerCased(): the variants an ALIAS is compared with. Joomla writes an alias lower case, the column
    // is binary, so the needle is lower-cased to meet it (and the column is, in SQL: section 3).
    check('lowerCased: a capitalised word is lower-cased', SearchNeedle::lowerCased(['Roof Repair']), ['roof repair']);
    check('lowerCased: a lower-case stem is left as it is', SearchNeedle::lowerCased(['roof-repair']), ['roof-repair']);
    check('lowerCased: non-ASCII capitals are lower-cased too', SearchNeedle::lowerCased(["\u{0410}\u{0411}", "\u{00C9}cole"]), ["\u{0430}\u{0431}", "\u{00E9}cole"]);
    check('lowerCased: the composed and the decomposed form stay two variants', SearchNeedle::lowerCased([$csNfc, $csNfd]), ["nguy\u{1EC5}n", "nguye\u{302}\u{303}n"]);
    check('lowerCased: variants that agree once lower-cased are one', SearchNeedle::lowerCased(['Roof', 'ROOF', 'roof']), ['roof']);
    check('lowerCased: nothing in, nothing out', SearchNeedle::lowerCased([]), []);
    check('lowerCased: % _ and the escape character have no case, so the pattern is the same one', SearchNeedle::like(SearchNeedle::lowerCased(['50%_OFF!'])[0]), '%50!%!_off!!%');
    check('lowerCased: the cleaned needle is untouched, and it is what the answer echoes', SearchNeedle::clean(' Roof ')['text'], 'Roof');
    check('lowerCased: and the variants clean() hands out are still the words as typed', SearchNeedle::clean('Roof')['variants'], ['Roof']);

    // cannotBeStored(): when a database's refusal means "no row can hold this". Both halves are needed:
    // every variant holds a character above U+FFFF, and the message is the collation mix or the incorrect value.
    $csMix = "Illegal mix of collations (utf8mb3_general_ci,IMPLICIT) and (utf8mb4_uca1400_ai_ci,COERCIBLE) for operation 'like'";
    $csBadValue = "Incorrect string value: '\\xF0\\x9F\\x94\\xA5' for column `db`.`jos_content`.`title` at row 1";
    $csEmoji = "\u{1F525}";
    foreach ([
        'the collation mix, for an emoji' => [[$csEmoji], $csMix, true],
        'an incorrect string value, for an emoji' => [[$csEmoji], $csBadValue, true],
        'the message in another case' => [[$csEmoji], strtoupper($csMix), true],
        'the message inside a longer one, as PDO words it' => [[$csEmoji], 'SQLSTATE[HY000]: General error: 1267 ' . $csMix, true],
        'an emoji among other characters' => [["roof {$csEmoji}"], $csMix, true],
        'every variant holding one' => [[$csEmoji, $csEmoji . $csEmoji], $csMix, true],
        'the first four-byte character' => [["\u{10000}"], $csMix, true],
        'the last three-byte character is stored fine' => [["\u{FFFF}"], $csMix, false],
        'a needle with no four-byte character, the same message' => [['roof'], $csMix, false],
        'CJK is three bytes and is stored fine' => [["\u{5C4B}\u{6839}"], $csMix, false],
        'one variant without one is not proof' => [[$csEmoji, 'roof'], $csMix, false],
        'a variant that is not UTF-8 is not four-byte text' => [["\xF0\x9F\x94"], $csMix, false],
        'another database error, for an emoji' => [[$csEmoji], "Unknown column 'a.titel' in 'where clause'", false],
        'a lost connection, for an emoji' => [[$csEmoji], 'MySQL server has gone away', false],
        'no message, for an emoji' => [[$csEmoji], '', false],
        'no variants at all' => [[], $csMix, false],
    ] as $csLabel => [$csVariants, $csMessage, $csWant]) {
        check("cannotBeStored: {$csLabel}", SearchNeedle::cannotBeStored($csVariants, $csMessage), $csWant);
    }

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

    // The alias is the one column compared lower-cased, `LOWER(a.alias)`, with lower-cased words: the column is
    // binary, Joomla stores an alias lower case, and a capitalised needle has to reach it. The title is not.
    $csTitleAlias = "(a.`title` LIKE :s0 ESCAPE '!' OR a.`title` LIKE :s1 ESCAPE '!' OR LOWER(a.`alias`) LIKE :s2 ESCAPE '!' OR LOWER(a.`alias`) LIKE :s3 ESCAPE '!')";
    $csDb = $csSql(fn ($writer) => $writer->searchRows('category', [$csNfc, $csNfd], 20, 50));
    $csRan = $csDb->ran[0];
    check('sql: a search adds one LIKE per column and variant, each with a placeholder of its own, the alias lower-cased', $csRan['sql'],
        'SELECT a.`id`, a.`title`, a.`alias`, a.`path`, a.`parent_id`, a.`level`, a.`extension`, a.`published`, a.`language` FROM `#__categories` AS `a` WHERE ' . $csTitleAlias . ' ORDER BY a.`id` ASC');
    check('sql: every placeholder holds its own pattern, none overwritten by a later one; the title as typed, the alias lower-cased', $csRan['bound'],
        [':s0' => '%' . $csNfc . '%', ':s1' => '%' . $csNfd . '%', ':s2' => "%nguy\u{1EC5}n%", ':s3' => "%nguye\u{302}\u{303}n%"]);
    check('sql: the patterns are bound as strings', array_values(array_unique($csRan['types'])), ['string']);
    check('sql: offset and limit apply to the narrowed set', [$csRan['offset'], $csRan['limit']], [20, 50]);

    $csDb = $csSql(fn ($writer) => $writer->searchRows('menuItem', ['news'], 0, 100));
    check('sql: a menu item search keeps the site-menu scope beside the words',
        substr($csDb->ran[0]['sql'], strpos($csDb->ran[0]['sql'], ' WHERE ')),
        " WHERE a.`client_id` = 0 AND (a.`title` LIKE :s0 ESCAPE '!' OR LOWER(a.`alias`) LIKE :s1 ESCAPE '!') ORDER BY a.`id` ASC");

    $csDb = $csSql(fn ($writer) => $writer->searchRows('article', ['roof'], 0, 100));
    $csArticleSql = $csDb->ran[0]['sql'];
    checkTrue('sql: an article search keeps the category join', str_contains($csArticleSql, 'LEFT JOIN `#__categories` AS `c` ON c.id = a.catid'));
    checkTrue('sql: and searches the article, not the joined category', str_contains($csArticleSql, " WHERE (a.`title` LIKE :s0 ESCAPE '!' OR LOWER(a.`alias`) LIKE :s1 ESCAPE '!') ORDER BY a.`id` ASC"));

    // A capitalised needle: the title's pattern keeps the case the caller typed (the title's collation
    // ignores it), the alias's is lower-cased (its column is binary and Joomla stores it lower case).
    $csDb = $csSql(fn ($writer) => $writer->searchRows('article', ['Roof Repair'], 0, 100));
    check('sql: a capitalised needle is bound as typed for the title and lower-cased for the alias', $csDb->ran[0]['bound'], [':s0' => '%Roof Repair%', ':s1' => '%roof repair%']);
    $csDb = $csSql(fn ($writer) => $writer->searchRows('article', ['roof'], 0, 100));
    check('sql: a lower-case needle is bound the same for both', $csDb->ran[0]['bound'], [':s0' => '%roof%', ':s1' => '%roof%']);
    $csDb = $csSql(fn ($writer) => $writer->searchRows('field', ['Colour'], 0, 100));
    check('sql: a column that is not an alias is never lower-cased: field is searched by title and name as typed',
        [$csDb->ran[0]['bound'], str_contains($csDb->ran[0]['sql'], 'LOWER(')], [[':s0' => '%Colour%', ':s1' => '%Colour%'], false]);

    $csDb = $csSql(fn ($writer) => $writer->searchRows('bannerClient', ['acme'], 0, 10));
    checkTrue('sql: a banner client is searched by name alone — the table has no alias', str_contains($csDb->ran[0]['sql'], " WHERE (a.`name` LIKE :s0 ESCAPE '!') ORDER BY"));

    foreach ($csConsts['SEARCH_COLUMNS'] as $csKind => $csColumns) {
        $csDb = $csSql(fn ($writer) => $writer->searchRows($csKind, ['x'], 0, 10));
        $csWhere = explode(' WHERE ', $csDb->ran[0]['sql'], 2)[1] ?? '';
        $csWhere = explode(' ORDER BY ', $csWhere, 2)[0];
        checkTrue("sql: {$csKind} has no state or published filter, so a trashed row is listed", !str_contains($csWhere, 'state') && !str_contains($csWhere, 'published'));
        check("columns: every column searched on {$csKind} is one its list returns", array_values(array_diff($csColumns, $csConsts['LIST_COLUMNS'][$csKind] ?? [])), []);
        checkTrue("sql: {$csKind} reads only its searched columns", (bool) preg_match_all('/a\.`(\w+)`\)? LIKE/', $csWhere, $csFound) && array_values(array_unique($csFound[1])) === $csColumns);
        check("sql: {$csKind} lower-cases its alias column, if it has one, and no other",
            preg_match_all('/LOWER\(a\.`(\w+)`\)/', $csWhere, $csLowered) ? array_values(array_unique($csLowered[1])) : [], in_array('alias', $csColumns, true) ? ['alias'] : []);
    }
    check('columns: the searchable kinds are these thirteen', (new JoomlaSiteWriter(new CsDb()))->searchableKinds(),
        ['article', 'category', 'tag', 'menuItem', 'module', 'templateStyle', 'language', 'menutype', 'banner', 'contact', 'newsfeed', 'bannerClient', 'field']);

    // The words are bound, never part of the SQL text. Each needle is paired with a fragment that a
    // string-built statement would carry even after escaping, and that no statement here contains.
    foreach (["O'Brien" => 'Brien', 'say "hi"' => 'say', "x'; DROP TABLE t; --" => 'DROP', '50%_off' => '_off'] as $csNeedle => $csFragment) {
        $csDb = $csSql(fn ($writer) => $writer->searchRows('article', [$csNeedle], 0, 10));
        checkTrue("sql: {$csNeedle} is not in the statement's text", !str_contains($csDb->ran[0]['sql'], $csFragment));
        check("sql: {$csNeedle} is bound as a pattern", $csDb->ran[0]['bound'][':s0'], SearchNeedle::like($csNeedle));
        check("sql: {$csNeedle} is bound for the alias as a lower-cased, still literal, pattern", $csDb->ran[0]['bound'][':s1'], SearchNeedle::like(mb_strtolower($csNeedle, 'UTF-8')));
    }

    $csDb = new CsDb();
    $csDb->scalar = '7';
    $csCounted = (new JoomlaSiteWriter($csDb))->countMatches('article', ['roof']);
    check('count: one COUNT(*) under the same predicate, with no join and no order', $csDb->ran[0]['sql'],
        "SELECT COUNT(*) FROM `#__content` AS `a` WHERE (a.`title` LIKE :s0 ESCAPE '!' OR LOWER(a.`alias`) LIKE :s1 ESCAPE '!')");
    check('count: and answers an integer', $csCounted, 7);
    $csDb = new CsDb();
    (new JoomlaSiteWriter($csDb))->countMatches('article', ['Roof']);
    check('count: the same words are bound as in the rows query, the alias lower-cased', $csDb->ran[0]['bound'], [':s0' => '%Roof%', ':s1' => '%roof%']);
    $csDb = new CsDb();
    (new JoomlaSiteWriter($csDb))->countMatches('menuItem', ['news']);
    check('count: a menu item count keeps the site-menu scope', $csDb->ran[0]['sql'],
        "SELECT COUNT(*) FROM `#__menu` AS `a` WHERE a.`client_id` = 0 AND (a.`title` LIKE :s0 ESCAPE '!' OR LOWER(a.`alias`) LIKE :s1 ESCAPE '!')");

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

    // The engine, asked for a `content.list` over any writer: defined here, ahead of the two sections that put
    // the real writer behind it, and used by every section after.
    $csToken = 'search-token-at-least-16chars';
    $csAsk = function (array $params, SiteWriter $writer) use ($csToken): array {
        return (new Engine($csToken, [], null, null, null, null, $writer, null, null))
            ->handle(['token' => $csToken, 'action' => 'content.list', 'params' => $params]);
    };

    // ======================================= 3b. the alias, through a SQL engine that compares case
    // A binary column (utf8mb4_bin) compares case, and SQLite's LIKE does too once `PRAGMA case_sensitive_like`
    // is on. That is the nearest stand-in a test can have for the alias column, and it shows the one thing the SQL
    // text cannot: that a capitalised needle REACHES a lower-case alias. The writer's own statements run here for
    // real, over a table with the columns its list reads. Only what the stand-in gets right is asserted: a title
    // is asked in the case it was stored, because a real title's collation folds case and this mode does not;
    // and SQLite's LOWER() folds ASCII only, so a non-ASCII capital is lower-cased by the needle alone here, and
    // what a database's own LOWER() does to one is left to a live site.
    checkTrue("sql engine: pdo_sqlite is here to run the writer's statements", extension_loaded('pdo_sqlite'));
    if (extension_loaded('pdo_sqlite')) {
        $csBinary = new PDO('sqlite::memory:');
        $csBinary->exec('PRAGMA case_sensitive_like = ON');
        $csBinary->exec('CREATE TABLE `#__categories` (id INTEGER PRIMARY KEY, title TEXT, alias TEXT, path TEXT, parent_id INTEGER, level INTEGER, extension TEXT, published INTEGER, language TEXT)');
        $csPut = $csBinary->prepare('INSERT INTO `#__categories` (id, title, alias, path, parent_id, level, extension, published, language) VALUES (?, ?, ?, ?, 1, 1, ?, 1, ?)');
        foreach ([
            [1, 'Roofing basics', 'roofing-basics', 'en-GB'],
            // Translated editions of one page: the title does not hold the word, the alias stem does.
            [2, 'Dacharbeiten', 'roofing-basics-de', 'de-DE'],
            [3, 'Toiture', 'roofing-basics-fr', 'fr-FR'],
            [4, 'Gutters', 'gutters', 'en-GB'],
            // An alias stored in upper case, as an import or a hand edit can leave one.
            [5, 'Legacy import', 'ROOFING-LEGACY', '*'],
            [6, 'Primary school', "\u{00E9}cole-primaire", 'fr-FR'],
            [7, 'Uebersicht', "\u{0448}\u{043A}\u{043E}\u{043B}\u{0430}-1", 'de-DE'],
        ] as [$csId, $csTitle, $csAlias, $csLanguage]) {
            $csPut->execute([$csId, $csTitle, $csAlias, 'p' . $csId, 'com_content', $csLanguage]);
        }
        $csOver = function (array $params) use ($csAsk, $csBinary): array {
            $db = new CsDb();
            $db->pdo = $csBinary;
            return [$csAsk(['kind' => 'category'] + $params, new JoomlaSiteWriter($db)), $db];
        };
        $csFound = fn (array $answer): array => array_map('intval', array_column($answer['items'] ?? [], 'id'));

        // The control, on the same connection and rows: the comparison this replaces. It misses every edition,
        // so the checks below could fail.
        $csPlain = $csBinary->prepare("SELECT id FROM `#__categories` WHERE title LIKE ? ESCAPE '!' OR alias LIKE ? ESCAPE '!' ORDER BY id");
        $csPlain->execute(['%Roofing%', '%Roofing%']);
        check('sql engine: control - a plain alias comparison misses every edition and the upper-case alias', array_map('intval', $csPlain->fetchAll(PDO::FETCH_COLUMN)), [1]);

        foreach ([
            'a capitalised needle finds the editions whose alias holds it and whose title does not, and an upper-case alias' => ['Roofing', [1, 2, 3, 5]],
            'an all-capitals needle' => ['ROOFING', [1, 2, 3, 5]],
            'a lower-case needle' => ['roofing', [1, 2, 3, 5]],
            'a capitalised stem, hyphen and all' => ['Roofing-Basics', [1, 2, 3]],
            'a stem in capitals' => ['ROOFING-BASICS', [1, 2, 3]],
            'a stem as it is stored' => ['roofing-basics', [1, 2, 3]],
            'an alias stored in upper case, by a lower-case needle' => ['legacy', [5]],
            'and by a needle in capitals' => ['LEGACY', [5]],
            'and by a capitalised one, which its title holds as well' => ['Legacy', [5]],
            'a word only a title holds, as typed' => ['Dacharbeiten', [2]],
            'a non-ASCII capital finds a lower-case alias' => ["\u{00C9}cole", [6]],
            'a Cyrillic capital finds a lower-case alias' => ["\u{0428}\u{043A}\u{043E}\u{043B}\u{0430}", [7]],
            'a word in no title and no alias' => ['Nothing', []],
        ] as $csLabel => [$csNeedle, $csWant]) {
            [$csAnswer] = $csOver(['search' => $csNeedle]);
            check("sql engine: {$csLabel} ({$csNeedle})", [$csFound($csAnswer), $csAnswer['search'] ?? null, $csAnswer['matched'] ?? null], [$csWant, $csNeedle, count($csWant)]);
        }

        // Paged, so that a page which fills is counted by COUNT(*), which runs the same predicate.
        [$csAnswer, $csDb] = $csOver(['search' => 'Roofing', 'limit' => 2]);
        check('sql engine: a page that fills is counted by its own COUNT(*)',
            [$csFound($csAnswer), $csAnswer['matched'], count($csDb->ran), str_starts_with($csDb->ran[1]['sql'] ?? '', 'SELECT COUNT(*)')], [[1, 2], 4, 2, true]);
        [$csAnswer] = $csOver(['search' => 'Roofing', 'limit' => 2, 'offset' => 2]);
        check('sql engine: the next page', [$csFound($csAnswer), $csAnswer['matched']], [[3, 5], 4]);
        [$csAnswer] = $csOver(['search' => 'Roofing', 'limit' => 2, 'offset' => 4]);
        check('sql engine: a page past the end is empty and still counted', [$csFound($csAnswer), $csAnswer['matched']], [[], 4]);
    }

    // ================================ 3c. a needle no row can hold: four bytes, on tables still in utf8mb3
    // A table in `utf8` (utf8mb3) cannot hold a character above U+FFFF, and the server refuses to compare one of
    // its columns with one: MariaDB says "Illegal mix of collations ...". Nothing there can hold the needle, so
    // the honest answer is no rows, with the echo and a zero count, and not a read failure. The driver below
    // refuses every statement with the server's own text, after recording it.
    $csRefuse = function (string $message, string $needle, array $params = [], string $kind = 'category') use ($csAsk): array {
        $db = new CsDb();
        $db->failWith = new \RuntimeException($message);
        return [$csAsk(['kind' => $kind, 'search' => $needle] + $params, new JoomlaSiteWriter($db)), $db];
    };
    $csEmpty = $csAsk(['kind' => 'category', 'search' => $csEmoji], new JoomlaSiteWriter(new CsDb()));
    check('unstorable: what a search that finds nothing answers, for reference', $csEmpty, ['ok' => true, 'kind' => 'category', 'offset' => 0, 'search' => $csEmoji, 'matched' => 0, 'items' => []]);
    foreach (['category', 'article', 'menuItem', 'field'] as $csKind) {
        [$csAnswer, $csDb] = $csRefuse($csMix, $csEmoji, [], $csKind);
        check("unstorable: {$csKind} - the collation mix, for an emoji, is no rows, answered as a search that found nothing",
            $csAnswer, ['ok' => true, 'kind' => $csKind, 'offset' => 0, 'search' => $csEmoji, 'matched' => 0, 'items' => []]);
        check("unstorable: {$csKind} - through one statement, the search itself, and no count for a first page that did not fill",
            [count($csDb->ran), str_contains($csDb->ran[0]['sql'] ?? '', ' LIKE :s0 ')], [1, true]);
    }
    [$csAnswer] = $csRefuse($csBadValue, $csEmoji);
    check('unstorable: an incorrect string value is the same', $csAnswer, ['ok' => true, 'kind' => 'category', 'offset' => 0, 'search' => $csEmoji, 'matched' => 0, 'items' => []]);
    [$csAnswer] = $csRefuse($csMix, "  roof {$csEmoji} \n");
    check('unstorable: the echo is the needle cleaned, as for any search', [$csAnswer['ok'], $csAnswer['search'], $csAnswer['matched'], $csAnswer['items']], [true, "roof {$csEmoji}", 0, []]);
    [$csAnswer, $csDb] = $csRefuse($csMix, $csEmoji, ['offset' => 20]);
    check('unstorable: a later page is zero too, the count refused the same way', [$csAnswer['ok'], $csAnswer['matched'], $csAnswer['items'], count($csDb->ran)], [true, 0, [], 2]);

    // Any other refusal, or the same one for a needle with no such character, is a failure like any other.
    foreach ([
        'an unknown column' => [$csEmoji, "Unknown column 'a.titel' in 'where clause'"],
        'a lost connection' => [$csEmoji, 'MySQL server has gone away'],
        'the collation mix, for a needle of ASCII' => ['roof', $csMix],
        'the collation mix, for a composed letter' => [$csNfc, $csMix],
        'the collation mix, for CJK, which takes three bytes' => ["\u{5C4B}\u{6839}", $csMix],
        'an incorrect string value, for ASCII' => ['roof', $csBadValue],
    ] as $csLabel => [$csNeedle, $csMessage]) {
        [$csAnswer] = $csRefuse($csMessage, $csNeedle);
        check("unstorable: {$csLabel} stays read_failed, with the database's own words", [$csAnswer['ok'], $csAnswer['error'] ?? null, $csAnswer['message'] ?? null], [false, 'read_failed', $csMessage]);
    }
    // Only a search with words is read that way: a plain list, and a blank needle (which is a plain list), fail as they always did.
    foreach (['no search at all' => [], 'a blank needle' => ['search' => '   ']] as $csLabel => $csParams) {
        $csDb = new CsDb();
        $csDb->failWith = new \RuntimeException($csMix);
        $csAnswer = $csAsk(['kind' => 'category'] + $csParams, new JoomlaSiteWriter($csDb));
        check("unstorable: {$csLabel} answers read_failed for the same message", [$csAnswer['ok'], $csAnswer['error'] ?? null, $csAnswer['message'] ?? null], [false, 'read_failed', $csMix]);
    }
    // The same rule at the writer's own doors: searchRows and countMatches answer none for a needle no table
    // can hold, and throw the refusal on for any other, as list() does.
    $csDb = new CsDb();
    $csDb->failWith = new \RuntimeException($csMix);
    $csWriter = new JoomlaSiteWriter($csDb);
    check('unstorable: searchRows answers no rows for it', $csWriter->searchRows('category', [$csEmoji], 0, 10), []);
    check('unstorable: countMatches answers zero for it', $csWriter->countMatches('category', [$csEmoji]), 0);
    foreach (['searchRows' => fn () => $csWriter->searchRows('category', ['roof'], 0, 10), 'countMatches' => fn () => $csWriter->countMatches('category', ['roof']), 'list' => fn () => $csWriter->list('category', 0, 10)] as $csDoor => $csCall) {
        $csThrown = null;
        try {
            $csCall();
        } catch (\RuntimeException $csThrownBy) {
            $csThrown = $csThrownBy->getMessage();
        }
        check("unstorable: {$csDoor} throws the refusal on, for a needle a table can hold", $csThrown, $csMix);
    }

    // =============================================== 4. the engine's answers, through a writer in memory
    /**
     * The real writer's searched columns, in memory. A check below holds the two maps equal.
     * It compares the way the real writer's SQL does, minus the collation: a title or a name ignores case (a
     * real collation ignores accents as well, which this does not), and an alias is lower-cased on both sides.
     * What is held here is what the engine does with a writer's answer, not how a database compares: sections
     * 3 and 3b hold that.
     */
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
        /** Every read() the engine made: what `include_body` asks a writer for, one full row per row found. */
        public array $readIds = [];

        public function read(string $kind, int $id): ?array { $this->readIds[] = [$kind, $id]; return parent::read($kind, $id); }
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
            $lowered = SearchNeedle::lowerCased($variants);
            $out = [];
            foreach ($rows as $id => $fields) {
                foreach (self::COLUMNS[$kind] as $column) {
                    $isAlias = $column === 'alias';
                    $held = (string) ($fields[$column] ?? '');
                    foreach ($isAlias ? $lowered : $variants as $variant) {
                        if ($isAlias ? mb_strpos(mb_strtolower($held, 'UTF-8'), $variant) !== false : mb_stripos($held, $variant) !== false) {
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
    // The alias ignores case as the title does. Joomla writes an alias lower case, in a binary column, and the
    // writer makes a capitalised needle reach it (3b runs that SQL); this is what the engine answers with it.
    check('answer: a capitalised alias stem finds the editions too', $csIds($csAsk(['kind' => 'article', 'search' => 'Roof-Repair'], $csShop())), [1, 2]);
    check('answer: and one in capitals', $csIds($csAsk(['kind' => 'article', 'search' => 'ROOF-REPAIR'], $csShop())), [1, 2]);
    $csEditions = new CsSearchWriter();
    foreach ([1 => ['Roofing basics', 'roofing-basics'], 2 => ['Dacharbeiten', 'roofing-basics-de'], 3 => ['Toiture', 'roofing-basics-fr'], 4 => ['Gutters', 'gutters'], 5 => ['Legacy import', 'ROOFING-LEGACY']] as $csId => [$csTitle, $csAlias]) {
        $csEditions->store['article'][$csId] = ['title' => $csTitle, 'alias' => $csAlias];
    }
    foreach ([
        'a capitalised needle finds the rows whose alias holds it and whose title does not' => ['Roofing', [1, 2, 3, 5]],
        'an alias stored in upper case is still found, by a lower-case needle' => ['legacy', [5]],
        'and by a capitalised one' => ['Legacy', [5]],
        'a title is matched as before: a word only a title holds' => ['toiture', [3]],
        'and in another case' => ['DACHARBEITEN', [2]],
        'a word neither holds' => ['Nothing', []],
    ] as $csLabel => [$csNeedle, $csWant]) {
        $csAnswer = $csAsk(['kind' => 'article', 'search' => $csNeedle], $csEditions);
        check("answer: {$csLabel} ({$csNeedle})", [$csIds($csAnswer), $csAnswer['search'], $csAnswer['matched']], [$csWant, $csNeedle, count($csWant)]);
    }
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
    $csWriter = $csShop();
    $csAnswer = $csAsk(['kind' => 'article', 'search' => 'Roof repair', 'include_body' => true], $csWriter);
    check('include_body: each matching row carries its body', array_column($csAnswer['items'], 'introtext'), ['<p>one</p>', '<p>three</p>']);
    // The in-memory list already holds a body, so the check above cannot tell a body that was read from one
    // that was there; what it CAN see is whether the engine asked the writer for the full row.
    check('include_body: the body is read for the rows found, one read each and no others', $csWriter->readIds, [['article', 1], ['article', 3]]);
    check('include_body: and the echo and count are still there', [$csAnswer['search'], $csAnswer['matched']], ['Roof repair', 2]);
    $csWriter = $csShop();
    $csAsk(['kind' => 'article', 'search' => 'Roof repair'], $csWriter);
    check('include_body: without it no full row is read', $csWriter->readIds, []);
    // The page a search asks the writer for. The ceiling is what keeps one request from taking a shared
    // host's whole content table, so it is read where it is applied: the limit handed to searchRows().
    foreach ([
        'by default' => [[], 100],
        'by default, with bodies' => [['include_body' => true], 25],
        'over the ceiling' => [['limit' => 500], 200],
        'over the ceiling, with bodies' => [['include_body' => true, 'limit' => 500], 25],
    ] as $csLabel => [$csParams, $csWantLimit]) {
        $csWriter = $csShop();
        $csAsk(['kind' => 'article', 'search' => 'a'] + $csParams, $csWriter);
        check("include_body: the page a search asks for {$csLabel} is {$csWantLimit} rows", $csWriter->calls[0][4] ?? null, $csWantLimit);
    }

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

    // The refusal says what search does cover and claims nothing about the refused kind: a user and an
    // extension's parameters both HAVE a name, and text saying "no title or name to match" was false for them.
    foreach (['user', 'extensionParams', 'redirect', 'fieldValue'] as $csKind) {
        check("refusal: {$csKind} is named, with what search does cover and the road to take instead",
            $csAsk(['kind' => $csKind, 'search' => 'x'], $csShop())['message'] ?? null,
            'search does not cover kind "' . $csKind . '". It covers: ' . implode(', ', array_keys(CsSearchWriter::COLUMNS)) . '. Leave search out and page the list with offset and limit');
    }

    // --- the production writer, joined to the engine
    // Every engine check above runs over an in-memory writer that declares SearchableSiteWriter itself,
    // and every check of the real writer above calls its methods directly, so none of them crosses the
    // gate the engine holds (`$this->writer instanceof SearchableSiteWriter`). The interface list of
    // JoomlaSiteWriter is the one line that switches search on in production: without it the engine
    // refuses every search on every site, and the rest of this file stays green. So the real writer is
    // asked here, and the engine is run over it, through the same recording driver as section 3.
    checkTrue('real writer: JoomlaSiteWriter declares SearchableSiteWriter', (new JoomlaSiteWriter(new CsDb())) instanceof SearchableSiteWriter);
    $csRealDb = new CsDb();
    $csRealDb->rows = [['id' => 5, 'title' => 'News', 'alias' => 'news']];
    check('real writer: the engine answers a search with the echo, the count and the rows',
        $csAsk(['kind' => 'category', 'search' => 'news'], new JoomlaSiteWriter($csRealDb)),
        ['ok' => true, 'kind' => 'category', 'offset' => 0, 'search' => 'news', 'matched' => 1, 'items' => [['id' => 5, 'title' => 'News', 'alias' => 'news']]]);
    check('real writer: through one narrowed query, and no count since the first page did not fill',
        array_map(fn (array $ran): bool => str_contains($ran['sql'], " WHERE (a.`title` LIKE :s0 ESCAPE '!'"), $csRealDb->ran), [true]);
    $csRealDb = new CsDb();
    $csRealDb->rows = [['id' => 5, 'title' => 'News', 'alias' => 'news']];
    $csRealDb->scalar = '7';
    $csAnswer = $csAsk(['kind' => 'category', 'search' => 'news', 'limit' => 1], new JoomlaSiteWriter($csRealDb));
    check('real writer: a page that fills is counted by its own COUNT, and the count is an integer',
        [$csAnswer['matched'] ?? null, array_map(fn (array $ran): bool => str_starts_with($ran['sql'], 'SELECT COUNT(*)'), $csRealDb->ran)], [7, [false, true]]);
    foreach (SiteWriter::KINDS as $csKind) {
        $csRealDb = new CsDb();
        $csAnswer = $csAsk(['kind' => $csKind, 'search' => 'x'], new JoomlaSiteWriter($csRealDb));
        $csEchoed = ($csAnswer['ok'] ?? false) === true && array_key_exists('search', $csAnswer);
        $csRefused = ($csAnswer['ok'] ?? null) === false && ($csAnswer['error'] ?? null) === 'bad_params';
        checkTrue("real writer, kind {$csKind}: search is answered with its echo or refused, never answered without it", $csEchoed || $csRefused);
        check("real writer, kind {$csKind}: it is filtered exactly where the table above says", $csEchoed, in_array($csKind, $csFiltered, true));
        checkTrue("real writer, kind {$csKind}: a refused search runs no statement", $csEchoed || $csRealDb->ran === []);
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
    // lists from the source and runs a fresh PHP that requires exactly them — also where there is no
    // usable Normalizer, which must still answer, unnormalised.
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

    // $normalizer says what the fresh PHP is made to hold:
    //   'as-is'    — nothing changed: the PHP's own Normalizer, or none where there is no intl;
    //   'disabled' — `disable_classes=Normalizer`, the setting a hardened host uses. It does NOT remove
    //                the class: with intl the class stays declared and only its methods are emptied out;
    //                with no intl there is nothing to disable and the class is simply absent;
    //   'emptied'  — as 'disabled', and where the class is absent the script declares an empty one, so
    //                "a Normalizer class without normalize()" is what runs on every PHP, intl or not.
    // The script reports whether a Normalizer is USABLE (class and method), the one thing all three
    // agree on being able to say about a PHP whatever its build.
    $csProbe = function (array $names, bool $writer, string $normalizer) {
        $code = <<<'PHP'
<?php
if (__EMPTIED__ && !class_exists('Normalizer', false)) { final class Normalizer {} }
foreach (__NAMES__ as $c) { require_once __LIB__ . '/' . $c . '.php'; }
$w = __WRITER__ ? new class implements SiteWriter, SearchableSiteWriter {
    public function canCreate(string $kind): bool { return false; }
    public function trashColumn(string $kind): ?string { return null; }
    public function setVisibility(string $kind, int $id, string $column, string $value): void {}
    public function setVisibilityMany(string $kind, array $ids, string $column, string $value): void {}
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
echo json_encode(['usable' => class_exists('Normalizer') && method_exists('Normalizer', 'normalize'), 'answer' => $a]);
PHP;
        $code = str_replace(['__EMPTIED__', '__NAMES__', '__LIB__', '__WRITER__'], [$normalizer === 'emptied' ? 'true' : 'false', var_export($names, true), var_export(realpath(__DIR__ . '/../lib'), true), $writer ? 'true' : 'false'], $code);
        $file = tempnam(sys_get_temp_dir(), 'cs-probe');
        file_put_contents($file, $code);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1' . ($normalizer !== 'as-is' ? ' -d disable_classes=Normalizer' : '') . ' ' . escapeshellarg($file) . ' 2>&1', $out, $status);
        unlink($file);
        $text = implode("\n", $out);
        $start = strpos($text, '{"usable"');
        $decoded = $start === false ? null : json_decode(substr($text, $start), true);
        return $decoded === null ? ['failed' => $status, 'output' => $text] : $decoded;
    };
    $csWantEcho = ['ok' => true, 'kind' => 'article', 'offset' => 0, 'search' => $csNfc, 'matched' => 1];
    $csLoaded = $csProbe($csFactoryList, true, 'as-is');
    check('loading: the factory list is enough to search', array_diff_key($csLoaded['answer'] ?? ['probe' => $csLoaded], ['items' => 1]), $csWantEcho);
    foreach (['disabled' => 'with disable_classes=Normalizer', 'emptied' => 'with a Normalizer class that has no normalize()'] as $csMode => $csWhat) {
        $csBare = $csProbe($csFactoryList, true, $csMode);
        check("loading: {$csWhat} there is no usable Normalizer", $csBare['usable'] ?? $csBare, false);
        check("loading: {$csWhat} search still answers, the needle as it came and matched as it came", $csBare['answer'] ?? ['probe' => $csBare], $csWantEcho + ['items' => [['id' => 1, 'title' => $csNfc]]]);
    }
    $csOld = $csProbe($csLegacyList, false, 'as-is');
    check('loading: the Joomla 3 door loads the same files and refuses a search with no writer', [$csOld['answer']['ok'] ?? $csOld, $csOld['answer']['error'] ?? null], [false, 'unavailable']);
}
