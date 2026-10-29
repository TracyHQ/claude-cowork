<?php
// Joomla 3 runs the Joomla 4+ collaborators (writer, apply log, extension manager) through
// administrator/legacy/LegacyDatabase.php: Joomla 3's mysqli driver given Joomla 4's bind(), the
// three-argument join(). Required from run.php, or alone:
// php tests/joomla3-wiring.php
//
// The adapter extends Joomla 3's driver classes, so the cases run in a subprocess (`--j3w-child`)
// with stand-ins for JDatabaseDriverMysqli and JDatabaseQueryMysqli shaped like Joomla 3.10's: what
// is checked is the SQL the adapter hands the driver, not MySQL.

namespace {
    if (!defined('J3W_CHILD')) define('J3W_CHILD', PHP_SAPI === 'cli' && ($_SERVER['argv'][1] ?? null) === '--j3w-child'
        && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__);

    if (J3W_CHILD) {
        define('_JEXEC', 1);

        /** Joomla 3.10's driver, as far as the adapter touches it. */
        abstract class JDatabaseDriver
        {
            protected $connection;
            protected $tablePrefix;
            protected $utf8mb4 = false;
            public $serverType;
            public $locks = [];
            public function __construct($options) { $this->tablePrefix = $options['prefix'] ?? 'jos_'; }
            public function getPrefix() { return $this->tablePrefix; }
            public function getConnection() { return $this->connection; }
            public function hasUTF8mb4Support() { return $this->utf8mb4; }
            public function quote($text) { return "'" . addslashes((string) $text) . "'"; }
            public function quoteName($name) { return '`' . $name . '`'; }
            public function getQuery($new = false) { return null; }
            public function lockTable($table) { $this->locks[] = "LOCK $table"; return $this; }
            public function unlockTables() { $this->locks[] = 'UNLOCK'; return $this; }
            public function disconnect() { $this->connection = 'CLOSED'; }
            public function __destruct() { $this->disconnect(); }
        }
        class JDatabaseDriverMysqli extends JDatabaseDriver
        {
            public static function joomla($connection)
            {
                $self = new self(['prefix' => 'j3_']);
                $self->connection = $connection;
                $self->utf8mb4 = true;
                $self->serverType = 'mariadb';
                return $self;
            }
        }
        /** Joomla 3.10's query: the clauses it builds, joined in order. */
        class JDatabaseQueryMysqli
        {
            protected $db;
            protected $parts = [];
            public function __construct($db) { $this->db = $db; }
            public function select($c) { $this->parts[] = 'SELECT ' . implode(',', (array) $c); return $this; }
            public function from($t) { $this->parts[] = 'FROM ' . $t; return $this; }
            public function where($c) { $this->parts[] = 'WHERE ' . implode(' AND ', (array) $c); return $this; }
            public function join($type, $conditions) { $this->parts[] = strtoupper($type) . ' JOIN ' . $conditions; return $this; }
            public function clear($clause = null) { $this->parts = []; return $this; }
            public function __toString() { return implode(' ', $this->parts); }
        }

        require __DIR__ . '/../com_claudecowork/administrator/legacy/LegacyDatabase.php';

        $out = [];
        $connection = new stdClass();
        $joomla = JDatabaseDriverMysqli::joomla($connection);
        $db = ClaudeCoworkLegacyDatabase::of($joomla);
        $out['shape'] = [$db instanceof \Joomla\Database\DatabaseInterface, $db instanceof JDatabaseDriverMysqli,
            $db->getConnection() === $connection, $db->getPrefix(), $db->hasUTF8mb4Support(), $db->serverType];
        $out['notMysqli'] = ClaudeCoworkLegacyDatabase::of(new stdClass());
        $out['types'] = [\Joomla\Database\ParameterType::INTEGER, \Joomla\Database\ParameterType::STRING, \Joomla\Database\ParameterType::NULL];

        $id = 7;
        $title = "it's :id";
        $query = $db->getQuery(true)->select('*')->from('#__content')
            ->where(['id = :id', "note = ':id stays'", 'title = :title', 'alias <> `:id`', 'x = :idx'])
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER)
            ->bind(':title', $title);
        $out['queryShape'] = $query instanceof \Joomla\Database\QueryInterface;
        $id = '42abc';  // bound by reference: read when the query becomes SQL, as a prepared statement reads it
        $out['sql'] = (string) $query;

        $none = null;
        $flag = true;
        $out['nullBool'] = (string) $db->getQuery(true)->select('*')->from('t')->where(['a = :a', 'b = :b'])
            ->bind(':a', $none, \Joomla\Database\ParameterType::STRING)->bind(':b', $flag, \Joomla\Database\ParameterType::BOOLEAN);
        $escaped = "a\\'b";
        $out['escapes'] = (string) $db->getQuery(true)->select('*')->from('t')->where(["c = 'x\\' :e'", 'd = :e'])->bind(':e', $escaped);
        $out['join'] = (string) $db->getQuery(true)->select('*')->from('a')->join('LEFT', 'b', 'b.id = a.b')->join('INNER', 'c ON c.id = a.c');
        $cleared = $db->getQuery(true)->select('*')->from('t')->where('a = :a')->bind(':a', $id);
        $cleared->clear()->select('1');
        $out['cleared'] = (string) $cleared;

        $db->lockTable('#__menu');
        $db->unlockTables();
        $out['locks'] = $db->locks;
        unset($db);
        $out['connectionKept'] = $joomla->getConnection() === $connection;

        echo json_encode($out);
        exit(0);
    }

    if (!function_exists('check')) {
        $j3wFailed = 0;
        function check(string $what, $got, $want): void { global $j3wFailed; if ($got === $want) { echo "  ok   $what\n"; return; } $j3wFailed++; echo "  FAIL $what\n       got : " . json_encode($got) . "\n       want: " . json_encode($want) . "\n"; }
        function checkTrue(string $what, bool $got): void { check($what, $got, true); }
    }

    $j3wOut = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --j3w-child 2>&1');
    $j3w = json_decode((string) $j3wOut, true);
    checkTrue('joomla 3 db: the child answered JSON (' . substr((string) $j3wOut, 0, 200) . ')', is_array($j3w));
    $j3w = is_array($j3w) ? $j3w : [];

    check('joomla 3 db: Joomla 3\'s own mysqli driver in the Joomla 4 shape, on the same connection, prefix and charset',
        $j3w['shape'] ?? null, [true, true, true, 'j3_', true, 'mariadb']);
    check('joomla 3 db: anything but mysqli is not wired', array_key_exists('notMysqli', $j3w) ? $j3w['notMysqli'] : 'missing', null);
    check('joomla 3 db: ParameterType is Joomla 4\'s', $j3w['types'] ?? null, ['int', 'string', 'null']);
    checkTrue('joomla 3 db: the query is a Joomla 4 QueryInterface', ($j3w['queryShape'] ?? false) === true);
    check('joomla 3 db: bound values become literals, read when the query becomes SQL; quoted text and identifiers are left alone',
        $j3w['sql'] ?? null, "SELECT * FROM #__content WHERE id = 42 AND note = ':id stays' AND title = 'it\\'s :id' AND alias <> `:id` AND x = :idx");
    check('joomla 3 db: NULL and booleans', $j3w['nullBool'] ?? null, 'SELECT * FROM t WHERE a = NULL AND b = 1');
    check('joomla 3 db: an escaped quote does not end a quoted string', $j3w['escapes'] ?? null, "SELECT * FROM t WHERE c = 'x\\' :e' AND d = 'a\\\\\\'b'");
    check('joomla 3 db: Joomla 4\'s three-argument join, and Joomla 3\'s two', $j3w['join'] ?? null, 'SELECT * FROM a LEFT JOIN b ON b.id = a.b INNER JOIN c ON c.id = a.c');
    check('joomla 3 db: clear() forgets the bindings', $j3w['cleared'] ?? null, 'SELECT 1');
    check('joomla 3 db: a Table locks its table as on Joomla 4 (Joomla 3\'s own driver does it)', $j3w['locks'] ?? null, ['LOCK #__menu', 'UNLOCK']);
    checkTrue('joomla 3 db: the adapter going away leaves Joomla\'s connection open', ($j3w['connectionKept'] ?? false) === true);

    // core.upgrade with no upgrader: Joomla 4+ as before, Joomla 3 saying why (Tracy upgrades its own copy).
    if (class_exists('Engine')) {
        $j3wToken = str_repeat('u', 32);
        $j3wAsk = static function (Engine $engine) use ($j3wToken) { return $engine->handle(['token' => $j3wToken, 'action' => 'core.upgrade', 'params' => ['to' => '4.4', 'step' => 'prepare']]); };
        check('core.upgrade unwired answers as it always has', $j3wAsk(new Engine($j3wToken)), ['ok' => false, 'error' => 'unavailable', 'message' => 'core upgrader not wired']);
        check('core.upgrade on Joomla 3 is unavailable and says why',
            $j3wAsk((new Engine($j3wToken))->coreUpgradeUnavailable('core upgrade is not available on Joomla 3: Tracy upgrades its own copy of the site, not the live site')),
            ['ok' => false, 'error' => 'unavailable', 'message' => 'core upgrade is not available on Joomla 3: Tracy upgrades its own copy of the site, not the live site']);
    }

    if (isset($j3wFailed) && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        echo $j3wFailed ? "$j3wFailed check(s) failed\n" : "every check passed\n";
        exit($j3wFailed ? 1 : 0);
    }
}
