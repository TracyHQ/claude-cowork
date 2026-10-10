<?php
// Loaded by run.php after content-writer-creates.php (its Joomla stand-ins). `userProfile`: an author's profile fields a
// visitor reads (UserProfileKey::FIELDS), listed and written by the real JoomlaSiteWriter over SQLite, never created or
// deleted, and taken back by apply.revert (TracyHQ/tch#1013). Measured 10/10/2026 on dev g60-ess-j-full (JA Essence j6, vi):
// "Collaborator & Editor" (`profile.user_jobtitle`) and an English bio (`profile.aboutme`) on the author listing and every
// author block, 17 pages; T4 reads both from `#__user_profiles`, JSON-encoded, and no door reached them.

namespace {
    use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter;

    check('a profile field\'s key packs the author and the field; another field of the account has none', [
        UserProfileKey::encode(42, 'profile.aboutme'), UserProfileKey::encode(42, 'profile.user_jobtitle'), UserProfileKey::decode(4201),
    ], [4200, 4201, [42, 'profile.user_jobtitle']]);
    $apThrows = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException $e) { return true; } };
    checkTrue('a key outside the visitor fields is refused both ways', $apThrows(fn () => UserProfileKey::encode(42, 'profile.phone')) && $apThrows(fn () => UserProfileKey::decode(4299)));
    check('the words are kept as the profile plugin stores them, JSON-encoded', [UserProfileKey::stored('Cộng tác viên & "Biên tập"'), UserProfileKey::words('"Collaborator & Editor"'), UserProfileKey::words('plain')],
        ['"Cộng tác viên & \\"Biên tập\\""', 'Collaborator & Editor', 'plain']);

    /** Joomla's query builder as far as the userProfile kind uses it. */
    final class ApQuery implements \Joomla\Database\QueryInterface
    {
        private string $verb = 'SELECT';
        private string $table = '';
        private array $select = [];
        private array $join = [];
        private array $where = [];
        private array $set = [];
        private array $order = [];
        public array $bounded = [];
        public function select($columns): self { $this->select = array_merge($this->select, (array) $columns); return $this; }
        public function from($table): self { $this->table = $table; return $this; }
        public function join($type, $condition): self { $this->join[] = " {$type} JOIN {$condition}"; return $this; }
        public function update($table): self { $this->verb = 'UPDATE'; $this->table = $table; return $this; }
        public function set($condition): self { $this->set[] = $condition; return $this; }
        public function where($conditions): self { $this->where[] = $conditions; return $this; }
        public function order($columns): self { $this->order = array_merge($this->order, (array) $columns); return $this; }
        public function bind($key, &$value, $type = 'string'): self { $this->bounded[$key] = $value; return $this; }
        public function __toString(): string
        {
            $where = $this->where ? ' WHERE ' . implode(' AND ', $this->where) : '';
            if ($this->verb === 'UPDATE') return 'UPDATE ' . $this->table . ' SET ' . implode(', ', $this->set) . $where;
            return 'SELECT ' . implode(',', $this->select) . ' FROM ' . $this->table . implode('', $this->join) . $where . ($this->order ? ' ORDER BY ' . implode(', ', $this->order) : '');
        }
    }

    /** A driver over SQLite: `#__` read as `j_`, a query's limit and offset kept. */
    final class ApDb implements \Joomla\Database\DatabaseInterface
    {
        public PDO $pdo;
        private $pending = '';
        private int $offset = 0;
        private int $limit = 0;
        public function __construct() { $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
        public function getQuery($new = false): ApQuery { return new ApQuery(); }
        public function quoteName($name, $as = null)
        {
            if (is_array($name)) return array_map(fn ($n) => $this->quoteName($n), $name);
            return implode('.', array_map(fn (string $part): string => '`' . $part . '`', explode('.', $name))) . ($as ? ' AS `' . $as . '`' : '');
        }
        public function quote($text): string { return $this->pdo->quote((string) $text); }
        public function setQuery($query, $offset = 0, $limit = 0): self { $this->pending = $query; $this->offset = (int) $offset; $this->limit = (int) $limit; return $this; }
        public function loadResult() { $row = $this->run()->fetch(PDO::FETCH_NUM); return $row === false ? null : $row[0]; }
        public function loadAssocList(): array { return $this->run()->fetchAll(PDO::FETCH_ASSOC); }
        public function execute(): bool { $this->run(); return true; }
        private function run(): PDOStatement
        {
            $sql = str_replace('#__', 'j_', (string) $this->pending) . ($this->limit > 0 ? ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset : '');
            $statement = $this->pdo->prepare($sql);
            if ($this->pending instanceof ApQuery) foreach ($this->pending->bounded as $key => $value) $statement->bindValue($key, $value);
            $statement->execute();
            return $statement;
        }
    }

    if (extension_loaded('pdo_sqlite')) {
        $apDb = new ApDb();
        $apDb->pdo->exec('CREATE TABLE j_users (id INTEGER PRIMARY KEY, name TEXT)');
        $apDb->pdo->exec('CREATE TABLE j_user_profiles (user_id INTEGER, profile_key TEXT, profile_value TEXT, ordering INTEGER)');
        $apDb->pdo->exec("INSERT INTO j_users VALUES (42, 'Alfonso Cortez'), (43, 'Trevor Patrick')");
        $apBio = 'It’s remarkable to watch a five-year-old draw.';
        $apIns = $apDb->pdo->prepare('INSERT INTO j_user_profiles VALUES (?, ?, ?, 0)');
        foreach ([[42, 'profile.aboutme', json_encode($apBio, JSON_UNESCAPED_UNICODE)], [42, 'profile.user_jobtitle', '"Collaborator & Editor"'],
                  [42, 'profile.user_social', '[{"social_name":"facebook","social_link":"#"}]'], [42, 'profile.phone', '"0123"'],
                  [43, 'profile.user_jobtitle', '"Senior Writer"'], [43, 'profile.aboutme', '""']] as $row) $apIns->execute($row);
        $apWriter = new JoomlaSiteWriter($apDb);

        check('content.list kind userProfile: the visitor fields that hold words, never a social link, a phone or an empty one', $apWriter->list('userProfile', 0, 200), [
            ['id' => 4200, 'user_id' => 42, 'name' => 'Alfonso Cortez', 'field' => 'aboutme', 'value' => $apBio],
            ['id' => 4201, 'user_id' => 42, 'name' => 'Alfonso Cortez', 'field' => 'user_jobtitle', 'value' => 'Collaborator & Editor'],
            ['id' => 4301, 'user_id' => 43, 'name' => 'Trevor Patrick', 'field' => 'user_jobtitle', 'value' => 'Senior Writer'],
        ]);
        check('read: one field\'s words; a field the author does not hold reads as nothing', [$apWriter->read('userProfile', 4201), $apWriter->read('userProfile', 4302)],
            [['value' => 'Collaborator & Editor'], null]);
        $apWriter->write('userProfile', 4201, ['value' => 'Cộng tác viên & Biên tập']);
        check('write: the words land JSON-encoded, as the profile plugin and T4 read them',
            $apDb->pdo->query("SELECT profile_value FROM j_user_profiles WHERE user_id = 42 AND profile_key = 'profile.user_jobtitle'")->fetchColumn(),
            '"Cộng tác viên & Biên tập"');
        check('and read back as words', $apWriter->read('userProfile', 4201), ['value' => 'Cộng tác viên & Biên tập']);
        checkTrue('no new field, no other field of the account, no delete', $apThrows(fn () => $apWriter->write('userProfile', 4302, ['value' => 'x']))
            && $apThrows(fn () => $apWriter->write('userProfile', 0, ['value' => 'x'])) && $apThrows(fn () => $apWriter->delete('userProfile', 4201))
            && $apWriter->canCreate('userProfile') === false && $apWriter->trashColumn('userProfile') === null);
    }

    // The engine: kind userProfile through content.update, recorded and taken back (the fake writer keeps rows by id).
    $apFake = new FakeSiteWriter();
    $apFake->store['userProfile'][4201] = ['value' => 'Collaborator & Editor'];
    $apLog = new FakeApplyLog();
    $apEngine = new Engine($TOKEN, [], null, null, null, null, $apFake, new FakeMediaWriter(), $apLog);
    $apSet = $apEngine->handle(['token' => $TOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'authors-1', 'kind' => 'userProfile', 'id' => 4201, 'fields' => ['value' => 'Cộng tác viên']]]);
    check('content.update takes kind userProfile', [$apSet['ok'] ?? null, $apFake->store['userProfile'][4201]['value'] ?? null], [true, 'Cộng tác viên']);
    $apEngine->handle(['token' => $TOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'authors-1']]);
    check('apply.revert puts the words back', $apFake->store['userProfile'][4201]['value'] ?? null, 'Collaborator & Editor');
}
