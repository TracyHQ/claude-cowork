<?php
/**
 * A `$wpdb` for the post search: it answers the two statements `search_posts` sends — the ids of
 * one page, and the count — by READING the SQL it is handed, not by being told what to return.
 *
 * `prepare()` escapes and substitutes the way WordPress does, in one pass over the format, so a
 * value that happens to contain `%s` is never read as a placeholder. `get_col()` and `get_var()`
 * then parse what came out with a small reader for exactly the shapes the writer builds —
 * `SELECT ID | COUNT(*) FROM wp_posts WHERE …` with AND / OR / parentheses over `LIKE`, `IN` and
 * `=`, then `ORDER BY ID ASC` and `LIMIT a, b` — and run it over `WP_Fake::$posts`. Anything it
 * does not understand throws, so a change to the SQL that this reader cannot follow fails a test
 * instead of slipping through one.
 *
 * `LIKE` follows MySQL: a backslash escapes `%`, `_` and itself, `_` is ONE character (not one
 * byte), and the collation is case-insensitive but folds no accents and normalises nothing —
 * stricter than any collation WordPress installs with, so a title stored in one Unicode spelling is
 * found only by a statement that asks for that spelling (a real collation may find more, not less).
 * A column named in `$binaryColumns` compares byte for byte instead, as a `_bin` collation does.
 *
 * It also answers what a real `$wpdb` answers about the columns: `get_col_charset()` names the character
 * set of a column's collation, and a string with a character above U+FFFF (an emoji) compared with a column
 * that keeps three bytes a character (`utf8`) is REFUSED, as it is on a real site, in the two ways it is
 * there: by MySQL ("Illegal mix of collations"), or, for every collation but six, by `wpdb` itself before
 * MySQL sees the statement (see `$collation`). A refusal is a failed statement with the message a site
 * gets, so a statement that should never have been sent shows up as an error, not as a shorter answer.
 *
 * Not a SQL engine: it stands in for one, for one query shape, and a test that needs more belongs
 * on a real install.
 */
declare(strict_types=1);

final class WP_Fake_PostsDb
{
    /** The collations `wpdb::check_safe_collation()` trusts (WordPress 6.1 to 7.1): a table with only these is sent unchecked. */
    private const SAFE_COLLATIONS = ['utf8_bin', 'utf8_general_ci', 'utf8mb3_bin', 'utf8mb3_general_ci', 'utf8mb4_bin', 'utf8mb4_general_ci'];

    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $dbname = 'wp';
    /** What WordPress keeps after a failed statement: an empty answer and this text. */
    public string $last_error = '';
    /** Set to make a statement fail that way; only those holding `$failOnly` when that is set. */
    public string $failWith = '';
    public string $failOnly = '';
    /** Set to have `prepare()` refuse, as WordPress does for a statement it cannot build: an empty string. */
    public bool $prepareRefuses = false;
    /**
     * The collation of the posts table's text columns, as `SHOW FULL COLUMNS` reports it. WordPress installs
     * `utf8mb4_unicode_520_ci` (or a `utf8mb4` one): four bytes a character, so nothing is ever refused. A site that
     * was never moved to utf8mb4 has a `utf8` one (three bytes a character), and a statement holding a character above
     * U+FFFF is then refused by one of two: MySQL, which cannot turn the string into the column's character set
     * ("Illegal mix of collations"), or `wpdb` itself. `wpdb::get_col()` skips its own check only when EVERY text column
     * has one of six collations (see SAFE_COLLATIONS); for any other it removes such a character from the statement,
     * finds it changed and never sends it, answering false with a translated message, and MySQL is never asked.
     */
    public string $collation = 'utf8mb4_unicode_520_ci';
    /**
     * Collations of single columns that differ from `$collation` (a table half converted): column name => collation.
     *
     * @var array<string,string>
     */
    public array $columnCollation = [];
    /** Set for a handle that cannot say what a column keeps: `get_col_charset()` answers false, as it does off MySQL. */
    public bool $charsetUnknown = false;
    /** How many times `get_col_charset()` was asked (a real one reads the table's columns the first time). */
    public int $charsetLookups = 0;
    /** @var string[] statements `wpdb` refused itself: they never reached MySQL, so they are not in `$queries` */
    public array $refused = [];
    /**
     * Columns that compare byte for byte, capitals and all, as a `_bin` collation compares them (a site can
     * set one with DB_COLLATE). Every other column ignores case, as the collations WordPress installs do.
     *
     * @var string[]
     */
    public array $binaryColumns = [];
    /** @var string[] every statement run, as MySQL would have received it */
    public array $queries = [];

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /** What `wpdb::get_col_charset()` answers: the character set in the column's collation name, or false. */
    public function get_col_charset(string $table, string $column)
    {
        $this->charsetLookups++;
        return $this->charsetUnknown ? false : explode('_', $this->collationOf($column))[0];
    }

    private function collationOf(string $column): string
    {
        return $this->columnCollation[$column] ?? $this->collation;
    }

    public function prepare(string $query, ...$args): string
    {
        if ($this->prepareRefuses) {
            return '';
        }
        $next = 0;
        $sql = preg_replace_callback('/%(%|s|d)/', static function (array $m) use (&$next, $args): string {
            if ($m[1] === '%') {
                return '%';
            }
            if (!array_key_exists($next, $args)) {
                throw new LogicException('prepare: the statement has more placeholders than values');
            }
            $value = $args[$next++];
            return $m[1] === 'd' ? (string) (int) $value : "'" . SqlValue::escape((string) $value) . "'";
        }, $query);
        if ($next !== count($args)) {
            throw new LogicException('prepare: more values than placeholders');
        }
        return (string) $sql;
    }

    /** @return string[] */
    public function get_col(string $query): array
    {
        return $this->run($query);
    }

    public function get_var(string $query): ?string
    {
        return $this->run($query)[0] ?? null;
    }

    /** @return string[] */
    private function run(string $query): array
    {
        // As wpdb::query() does: an empty statement is never sent, and answers as no rows with no error.
        if ($query === '') {
            return [];
        }
        if ($this->wpdbRefuses($query)) {
            $this->refused[] = $query;
            $this->last_error = 'WordPress database error: Could not perform query because it contains invalid data.';
            return [];
        }
        $this->queries[] = $query;
        $fails = $this->failWith !== '' && ($this->failOnly === '' || strpos($query, $this->failOnly) !== false);
        $this->last_error = $fails ? $this->failWith : '';
        if ($fails) {
            return [];
        }
        try {
            return WP_Fake_PostsSql::run($query, $this->binaryColumns, [$this, 'collationOfColumn']);
        } catch (WP_Fake_MysqlRefusal $e) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }

    /** Public only so the reader can ask; it is what `collationOf()` answers. */
    public function collationOfColumn(string $column): string
    {
        return $this->collationOf($column);
    }

    /**
     * `wpdb::query()` never sends a statement that has a character above U+FFFF when the table keeps three bytes
     * (some column is `utf8`, which is what `get_table_charset()` then answers even beside `utf8mb4` ones) and
     * `check_safe_collation()` is false, i.e. some text column has a collation outside the six it trusts.
     */
    private function wpdbRefuses(string $query): bool
    {
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $query) !== 1) {
            return false;
        }
        $collations = array_merge([$this->collation], array_values($this->columnCollation));
        $threeBytes = array_filter($collations, static fn (string $c): bool => in_array(explode('_', $c)[0], ['utf8', 'utf8mb3'], true));
        return $threeBytes !== [] && array_diff($collations, self::SAFE_COLLATIONS) !== [];
    }
}

/** MySQL's refusal of one statement, as `wpdb` records it in `last_error`. */
final class WP_Fake_MysqlRefusal extends RuntimeException
{
}

/** The reader behind {@see WP_Fake_PostsDb}: tokens, then a recursive descent over one statement shape. */
final class WP_Fake_PostsSql
{
    /** @var array<int,array{0:string,1:mixed}> */
    private array $tokens;
    private int $at = 0;
    /** @var string[] columns compared byte for byte */
    private array $binary;
    /** @var callable|null column name => its collation, so a string the column cannot hold is refused as MySQL refuses it */
    private $collationOf;

    private function __construct(array $tokens, array $binary, ?callable $collationOf)
    {
        $this->tokens = $tokens;
        $this->binary = $binary;
        $this->collationOf = $collationOf;
    }

    /**
     * @param string[] $binary columns that compare byte for byte, as a `_bin` collation does
     * @param callable|null $collationOf asked for the collation of each column a string is compared with
     * @return string[] ids in ascending order (as strings, like a driver answers), or one count
     * @throws WP_Fake_MysqlRefusal for a string with a character above U+FFFF compared with a column that keeps three bytes
     */
    public static function run(string $sql, array $binary = [], ?callable $collationOf = null): array
    {
        return (new self(self::tokenize($sql), $binary, $collationOf))->select();
    }

    /**
     * MySQL cannot turn a string with a character above U+FFFF into the character set of a `utf8` column, so it does
     * not answer "no rows" to the comparison: it refuses the whole statement, before it reads a row, whichever
     * branch of an OR held the string. A `utf8mb4` column takes it. Only the columns of one comparison are asked, so
     * a table half converted refuses exactly the comparisons that name its three-byte column.
     */
    private function refuseWhatTheColumnCannotHold(string $column, string $literal, string $operation): void
    {
        if ($this->collationOf === null || preg_match('/[\x{10000}-\x{10FFFF}]/u', $literal) !== 1) {
            return;
        }
        $collation = ($this->collationOf)($column);
        if (in_array(explode('_', $collation)[0], ['utf8', 'utf8mb3'], true)) {
            throw new WP_Fake_MysqlRefusal("Illegal mix of collations ({$collation},IMPLICIT) and (utf8mb4_unicode_520_ci,COERCIBLE) for operation '{$operation}'");
        }
    }

    /** What the statement's string literal holds once MySQL has read it (`\%` and `\_` stay, for LIKE). */
    private static function tokenize(string $sql): array
    {
        $plain = ['0' => "\0", "'" => "'", '"' => '"', 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", '\\' => '\\'];
        $tokens = [];
        $n = strlen($sql);
        for ($i = 0; $i < $n;) {
            $c = $sql[$i];
            if (ctype_space($c)) {
                $i++;
            } elseif ($c === "'") {
                $value = '';
                for ($i++; ; ) {
                    if ($i >= $n) {
                        throw new LogicException('SQL reader: a string literal never ends');
                    }
                    $c = $sql[$i];
                    if ($c === "'" && ($sql[$i + 1] ?? '') === "'") {
                        $value .= "'";
                        $i += 2;
                    } elseif ($c === "'") {
                        $i++;
                        break;
                    } elseif ($c === '\\') {
                        $next = $sql[$i + 1] ?? '';
                        if ($next === '') {
                            throw new LogicException('SQL reader: a string literal ends in a backslash');
                        }
                        $value .= ($next === '%' || $next === '_') ? '\\' . $next : ($plain[$next] ?? $next);
                        $i += 2;
                    } else {
                        $value .= $c;
                        $i++;
                    }
                }
                $tokens[] = ['str', $value];
            } elseif (ctype_alpha($c) || $c === '_') {
                $start = $i;
                while ($i < $n && (ctype_alnum($sql[$i]) || $sql[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['word', substr($sql, $start, $i - $start)];
            } elseif (ctype_digit($c)) {
                $start = $i;
                while ($i < $n && ctype_digit($sql[$i])) {
                    $i++;
                }
                $tokens[] = ['num', (int) substr($sql, $start, $i - $start)];
            } elseif (strpos('(),=*', $c) !== false) {
                $tokens[] = ['sym', $c];
                $i++;
            } else {
                throw new LogicException("SQL reader: cannot read `{$c}` near: " . substr($sql, max(0, $i - 20), 40));
            }
        }
        return $tokens;
    }

    private function select(): array
    {
        $this->word('SELECT');
        $count = $this->isWord('COUNT');
        if ($count) {
            $this->word('COUNT');
            $this->sym('(');
            $this->sym('*');
            $this->sym(')');
        } else {
            $this->word('ID');
        }
        $this->word('FROM');
        $table = $this->name();
        if ($table !== 'wp_posts') {
            throw new LogicException("SQL reader: only wp_posts is known, not {$table}");
        }
        $this->word('WHERE');
        $keep = $this->either();
        $ordered = false;
        $offset = 0;
        $limit = null;
        if ($this->isWord('ORDER')) {
            $this->word('ORDER');
            $this->word('BY');
            $this->word('ID');
            $this->word('ASC');
            $ordered = true;
        }
        if ($this->isWord('LIMIT')) {
            $this->word('LIMIT');
            $limit = $this->number();
            if ($this->isSym(',')) {
                $this->sym(',');
                $offset = $limit;
                $limit = $this->number();
            }
        }
        if ($this->at !== count($this->tokens)) {
            throw new LogicException('SQL reader: unexpected ' . json_encode($this->tokens[$this->at]));
        }
        if ($count && ($ordered || $limit !== null)) {
            throw new LogicException('SQL reader: a count is neither ordered nor limited here');
        }

        // Rows come back in the order the table holds them unless the statement asks for one, so a
        // statement that forgets ORDER BY shows up as ids out of order.
        $rows = WP_Fake::$posts;
        if ($ordered) {
            ksort($rows);
        }
        $ids = [];
        foreach ($rows as $id => $row) {
            if ($keep(['ID' => (string) $id] + array_map('strval', array_filter($row, 'is_scalar')))) {
                $ids[] = (string) $id;
            }
        }
        return $count ? [(string) count($ids)] : array_slice($ids, $offset, $limit);
    }

    /** a OR b … — the loosest binding, as in SQL */
    private function either(): callable
    {
        $parts = [$this->both()];
        while ($this->isWord('OR')) {
            $this->word('OR');
            $parts[] = $this->both();
        }
        return static function (array $row) use ($parts): bool {
            foreach ($parts as $part) {
                if ($part($row)) {
                    return true;
                }
            }
            return false;
        };
    }

    /** a AND b … */
    private function both(): callable
    {
        $parts = [$this->one()];
        while ($this->isWord('AND')) {
            $this->word('AND');
            $parts[] = $this->one();
        }
        return static function (array $row) use ($parts): bool {
            foreach ($parts as $part) {
                if (!$part($row)) {
                    return false;
                }
            }
            return true;
        };
    }

    /** ( … ) | column LIKE 'p' | column IN ('a', …) | column = 'a' */
    private function one(): callable
    {
        if ($this->isSym('(')) {
            $this->sym('(');
            $inner = $this->either();
            $this->sym(')');
            return $inner;
        }
        $column = $this->name();
        if (!in_array($column, ['ID', 'post_type', 'post_status', 'post_name', 'post_title'], true)) {
            throw new LogicException("SQL reader: unknown column {$column}");
        }
        $value = static fn (array $row): string => (string) ($row[$column] ?? '');
        $exact = in_array($column, $this->binary, true);
        // How this column compares text: exactly under a binary collation, ignoring case under the rest.
        $fold = static fn (string $text): string => $exact ? $text : mb_strtolower($text);
        if ($this->isWord('LIKE')) {
            $this->word('LIKE');
            $pattern = $this->string();
            $this->refuseWhatTheColumnCannotHold($column, $pattern, 'like');
            $regex = self::likeRegex($pattern, $exact);
            return static fn (array $row): bool => preg_match($regex, $value($row)) === 1;
        }
        if ($this->isWord('IN')) {
            $this->word('IN');
            $this->sym('(');
            $literal = $this->string();
            $this->refuseWhatTheColumnCannotHold($column, $literal, 'in');
            $set = [$fold($literal)];
            while ($this->isSym(',')) {
                $this->sym(',');
                $literal = $this->string();
                $this->refuseWhatTheColumnCannotHold($column, $literal, 'in');
                $set[] = $fold($literal);
            }
            $this->sym(')');
            return static fn (array $row): bool => in_array($fold($value($row)), $set, true);
        }
        $this->sym('=');
        $literal = $this->string();
        $this->refuseWhatTheColumnCannotHold($column, $literal, '=');
        $want = $fold($literal);
        return static fn (array $row): bool => $fold($value($row)) === $want;
    }

    /**
     * A LIKE pattern as a regex: `\x` is x, `%` any run, `_` one character, the rest itself. Case is
     * ignored unless `$exact` (a binary collation).
     */
    private static function likeRegex(string $pattern, bool $exact = false): string
    {
        $regex = '';
        $n = strlen($pattern);
        for ($i = 0; $i < $n; $i++) {
            $c = $pattern[$i];
            if ($c === '\\') {
                if ($i + 1 >= $n) {
                    throw new LogicException('SQL reader: a LIKE pattern ends in its escape character');
                }
                $regex .= preg_quote($pattern[++$i], '~');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '~');
            }
        }
        return '~^' . $regex . '\z~' . ($exact ? '' : 'i') . 'su';
    }

    private function next(): array
    {
        if ($this->at >= count($this->tokens)) {
            throw new LogicException('SQL reader: the statement ends too soon');
        }
        return $this->tokens[$this->at++];
    }

    private function isWord(string $word): bool
    {
        $t = $this->tokens[$this->at] ?? null;
        return $t !== null && $t[0] === 'word' && strcasecmp($t[1], $word) === 0;
    }

    private function isSym(string $sym): bool
    {
        $t = $this->tokens[$this->at] ?? null;
        return $t !== null && $t[0] === 'sym' && $t[1] === $sym;
    }

    private function word(string $word): void
    {
        if (!$this->isWord($word)) {
            throw new LogicException("SQL reader: expected {$word} at token {$this->at}");
        }
        $this->at++;
    }

    private function sym(string $sym): void
    {
        if (!$this->isSym($sym)) {
            throw new LogicException("SQL reader: expected {$sym} at token {$this->at}");
        }
        $this->at++;
    }

    private function name(): string
    {
        $t = $this->next();
        if ($t[0] !== 'word') {
            throw new LogicException('SQL reader: expected a name, got ' . json_encode($t));
        }
        return $t[1];
    }

    private function string(): string
    {
        $t = $this->next();
        if ($t[0] !== 'str') {
            throw new LogicException('SQL reader: expected a quoted value, got ' . json_encode($t));
        }
        return $t[1];
    }

    private function number(): int
    {
        $t = $this->next();
        if ($t[0] !== 'num') {
            throw new LogicException('SQL reader: expected a number, got ' . json_encode($t));
        }
        return $t[1];
    }
}
