<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

/**
 * Joomla 3's database, in the shape the Joomla 4+ collaborators (`site/src/Controller`,
 * `administrator/src/Service`) are written against — so Joomla 3 runs the SAME writer, apply log,
 * extension manager and readers as Joomla 4–6, wired by the same `EngineFactory::build()`, and not
 * a second copy of them kept in step by hand.
 *
 * Joomla 3.10's mysqli driver lacks three things those classes use, and this file supplies exactly
 * those, on Joomla 3 only (`EngineFactory::database()` loads it when Joomla has no DI container):
 *
 * - `Joomla\Database\DatabaseInterface`, `QueryInterface` and `ParameterType`, which the classes
 *   name in type hints and `bind()` calls. Declared only when absent, as empty markers.
 * - `$query->bind(':name', $value, $type)`: Joomla 3's mysqli query has no prepared statements, so
 *   a bound value is written into the SQL as a literal (quoted by the driver, an integer cast) when
 *   the query is turned into SQL — outside quoted strings and identifiers only.
 * - `$query->join($type, $table, $condition)`, Joomla 4's three-argument form.
 *
 * Everything else is Joomla 3's own driver, table locks included: a nested Table (`Table\Menu`,
 * `Table\Category`) takes `LOCK TABLES` around a store on Joomla 3 exactly as on Joomla 4, so a
 * batch that fails is taken back the same way on both (the transaction, then the undo log) and
 * answers the same words.
 *
 * The adapter shares the connection Joomla opened — same session, same transaction, no second login
 * — and never closes it.
 */

namespace Joomla\Database {
    \defined('_JEXEC') or die;

    if (!\interface_exists(__NAMESPACE__ . '\\DatabaseInterface', false)) {
        interface DatabaseInterface
        {
        }
    }
    if (!\interface_exists(__NAMESPACE__ . '\\QueryInterface', false)) {
        interface QueryInterface
        {
        }
    }
    if (!\class_exists(__NAMESPACE__ . '\\ParameterType', false)) {
        final class ParameterType
        {
            public const BOOLEAN = 'boolean';
            public const INTEGER = 'int';
            public const LARGE_OBJECT = 'lob';
            public const NULL = 'null';
            public const STRING = 'string';
        }
    }
}

namespace {
    final class ClaudeCoworkLegacyDatabase extends \JDatabaseDriverMysqli implements \Joomla\Database\DatabaseInterface
    {
        /** Joomla's own driver, `Factory::getDbo()`, in the Joomla 4 shape; null when it is not mysqli. */
        public static function of($db)
        {
            if (!$db instanceof \JDatabaseDriverMysqli) {
                return null;
            }
            $self = new self(['driver' => 'mysqli', 'prefix' => $db->getPrefix(), 'select' => false]);
            $self->connection = $db->getConnection();
            $self->utf8mb4 = $db->hasUTF8mb4Support();
            $self->serverType = $db->serverType;
            return $self;
        }

        public function getQuery($new = false)
        {
            return $new ? new ClaudeCoworkLegacyQuery($this) : parent::getQuery(false);
        }

        /** The connection is Joomla's: it stays open when this adapter goes. */
        public function disconnect()
        {
            $this->connection = null;
        }

        public function __destruct()
        {
            $this->connection = null;
        }
    }

    final class ClaudeCoworkLegacyQuery extends \JDatabaseQueryMysqli implements \Joomla\Database\QueryInterface
    {
        /** @var array<string,array{value:mixed,type:string}> */
        private $bounded = [];

        /** Joomla 4's bind(): the value is read when the query becomes SQL, as a prepared statement reads it. */
        public function bind($key, &$value, $dataType = 'string', $length = 0, $driverOptions = [])
        {
            foreach ((array) $key as $name) {
                $name = ':' . ltrim((string) $name, ':');
                $this->bounded[$name] = ['value' => &$value, 'type' => (string) $dataType];
            }
            return $this;
        }

        public function unbind($key)
        {
            foreach ((array) $key as $name) {
                unset($this->bounded[':' . ltrim((string) $name, ':')]);
            }
            return $this;
        }

        /** Joomla 4's join($type, $table, $condition); Joomla 3's two-argument form still works. */
        public function join($type, $table, $condition = null)
        {
            return parent::join($type, $condition === null ? $table : $table . ' ON ' . $condition);
        }

        public function clear($clause = null)
        {
            if ($clause === null) {
                $this->bounded = [];
            }
            return parent::clear($clause);
        }

        public function __toString()
        {
            $sql = parent::__toString();
            return $this->bounded === [] ? $sql : self::substitute($sql, $this->bounded, $this->db);
        }

        /**
         * `$sql` with every bound `:name` outside quotes replaced by its literal. MySQL's quoting: '…'
         * and "…" with backslash escapes and doubled quotes, `…` for identifiers.
         */
        public static function substitute($sql, array $bounded, $db)
        {
            $out = '';
            $length = strlen($sql);
            for ($i = 0; $i < $length; $i++) {
                $char = $sql[$i];
                if ($char === "'" || $char === '"' || $char === '`') {
                    $end = $i + 1;
                    while ($end < $length) {
                        if ($sql[$end] === '\\' && $char !== '`') {
                            $end += 2;
                            continue;
                        }
                        if ($sql[$end] === $char) {
                            if ($end + 1 < $length && $sql[$end + 1] === $char) {
                                $end += 2;
                                continue;
                            }
                            break;
                        }
                        $end++;
                    }
                    $out .= substr($sql, $i, $end - $i + 1);
                    $i = $end;
                    continue;
                }
                if ($char === ':' && preg_match('/\G:[A-Za-z_][A-Za-z0-9_]*/', $sql, $match, 0, $i) && isset($bounded[$match[0]])) {
                    $out .= self::literal($bounded[$match[0]]['value'], $bounded[$match[0]]['type'], $db);
                    $i += strlen($match[0]) - 1;
                    continue;
                }
                $out .= $char;
            }
            return $out;
        }

        private static function literal($value, $type, $db)
        {
            if ($value === null || $type === 'null') {
                return 'NULL';
            }
            if ($type === 'int') {
                return (string) (int) $value;
            }
            if ($type === 'boolean') {
                return $value ? '1' : '0';
            }
            return $db->quote((string) $value);
        }
    }
}
