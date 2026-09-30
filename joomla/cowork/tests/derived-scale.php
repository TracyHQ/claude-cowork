<?php
// Loaded by run.php after content-writer-creates.php (its Joomla\CMS\Factory and DatabaseInterface
// stubs) and derived-contract.php. A derived contract at the size of a real import, read by the real
// JoomlaDerivedRows through its own SQL, over SQLite: the rows in batches, the peak memory of a derive,
// and the map kept between requests until the site changes.

namespace Joomla\CMS {
    if (!class_exists(Factory::class)) {
        final class Factory
        {
            public static function getDate(): object
            {
                return new class { public function toSql(): string { return '2026-09-29 10:00:00'; } };
            }
        }
    }
}

namespace Joomla\Database {
    if (!interface_exists(DatabaseInterface::class)) {
        interface DatabaseInterface {}
    }
}

namespace {
    if (!defined('_JEXEC')) define('_JEXEC', 1);
    require_once __DIR__ . '/../lib/DerivedCache.php';
    require_once __DIR__ . '/../com_claudecowork/administrator/src/Service/JoomlaDerivedRows.php';

    use Tracy\Component\ClaudeCowork\Administrator\Service\JoomlaDerivedRows;

    echo "\nDerived contract at scale (Joomla)\n";

    /** The driver calls JoomlaDerivedRows makes, over SQLite with MySQL's CRC32, BIT_XOR, REGEXP and CHAR_LENGTH added. */
    final class SqliteJoomlaDb implements \Joomla\Database\DatabaseInterface
    {
        public PDO $pdo;
        public int $queries = 0;
        /** Rows the driver handed back for #__fields_values. */
        public int $fieldRows = 0;
        private string $sql = '';

        public function __construct()
        {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->sqliteCreateFunction('CRC32', static fn($v) => crc32((string) $v), 1);
            $this->pdo->sqliteCreateFunction('REGEXP', static fn($pattern, $value) => (int) preg_match('~' . $pattern . '~', (string) $value), 2);
            $this->pdo->sqliteCreateFunction('CHAR_LENGTH', static fn($v) => mb_strlen((string) $v), 1);
            $this->pdo->sqliteCreateAggregate('BIT_XOR', static fn($carry, $row, $v) => ((int) $carry) ^ (int) $v, static fn($carry) => (int) $carry, 1);
        }

        public function setQuery(string $sql, int $offset = 0, int $limit = 0): self
        {
            $this->sql = str_replace('#__', 'j_', $sql) . ($limit > 0 ? ' LIMIT ' . $limit . ' OFFSET ' . $offset : '');
            return $this;
        }

        public function loadAssocList(): array
        {
            $this->queries++;
            $rows = $this->pdo->query($this->sql)->fetchAll(PDO::FETCH_ASSOC);
            if (strpos($this->sql, 'FROM j_fields_values') !== false) $this->fieldRows += count($rows);
            return $rows;
        }

        public function loadAssoc(): ?array
        {
            return $this->loadAssocList()[0] ?? null;
        }

        public function loadResult()
        {
            $row = $this->loadAssocList()[0] ?? null;
            return $row === null ? null : reset($row);
        }

        public function loadColumn(): array
        {
            return array_map(static fn(array $r) => reset($r), $this->loadAssocList());
        }

        public function execute(): bool
        {
            $this->pdo->exec($this->sql);
            return true;
        }

        public function quote($value): string
        {
            return $this->pdo->quote((string) $value);
        }

        public function quoteName(string $name): string
        {
            return '`' . $name . '`';
        }
    }

    /** Peak bytes `$run` allocates above what was in use before it. */
    $jsPeak = static function (callable $run, &$result = null): int {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $result = $run();
        return memory_get_peak_usage() - $base;
    };

    // 2,500 articles with the attribs, images and urls Joomla stores (mostly empty switches), four custom
    // text fields each (three hold a number or a code), 100 menu items, 60 modules, 20 categories.
    $jsDb = new SqliteJoomlaDb();
    $jsDb->pdo->exec('CREATE TABLE j_content (id INTEGER PRIMARY KEY, title TEXT, introtext TEXT, `fulltext` TEXT, attribs TEXT, images TEXT, urls TEXT, state INT,
        publish_up TEXT, publish_down TEXT, modified TEXT);
        CREATE TABLE j_menu (id INTEGER PRIMARY KEY, title TEXT, params TEXT, published INT, client_id INT, level INT, menutype TEXT, template_style_id INT);
        CREATE TABLE j_modules (id INTEGER PRIMARY KEY, title TEXT, module TEXT, content TEXT, params TEXT, published INT, client_id INT);
        CREATE TABLE j_categories (id INTEGER PRIMARY KEY, title TEXT, description TEXT, params TEXT, published INT, extension TEXT);
        CREATE TABLE j_fields (id INTEGER PRIMARY KEY, context TEXT, state INT, type TEXT);
        CREATE TABLE j_fields_values (field_id INT, item_id TEXT, value TEXT);
        CREATE TABLE j_template_styles (id INTEGER PRIMARY KEY, client_id INT, home TEXT, params TEXT);
        CREATE TABLE j_claudecowork_content_contract (id INT PRIMARY KEY, binding TEXT NOT NULL);
        CREATE TABLE j_claudecowork_derived_cache (id INT PRIMARY KEY, entry TEXT NOT NULL);');
    $jsAttribs = json_encode(array_fill_keys(['article_layout', 'show_title', 'link_titles', 'show_tags', 'show_intro', 'info_block_position', 'show_category', 'link_category',
        'show_parent_category', 'show_author', 'link_author', 'show_create_date', 'show_modify_date', 'show_publish_date', 'show_item_navigation', 'show_hits',
        'show_noauth', 'urls_position', 'alternative_readmore', 'article_layout_override', 'show_publishing_options', 'show_article_options', 'show_urls_images_backend',
        'show_urls_images_frontend'], ''));
    $jsImages = '{"image_intro":"","image_intro_alt":"","float_intro":"","image_intro_caption":"","image_fulltext":"","image_fulltext_alt":"","float_fulltext":"","image_fulltext_caption":""}';
    $jsUrls = '{"urla":"","urlatext":"","targeta":"","urlb":"","urlbtext":"","targetb":"","urlc":"","urlctext":"","targetc":""}';
    $jsDb->pdo->beginTransaction();
    $jsInsert = $jsDb->pdo->prepare('INSERT INTO j_content VALUES (?, ?, ?, ?, ?, ?, ?, 1, NULL, NULL, ?)');
    $jsValue = $jsDb->pdo->prepare('INSERT INTO j_fields_values VALUES (?, ?, ?)');
    for ($id = 1; $id <= 2500; $id++) {
        $jsInsert->execute([$id, 'Oak chair ' . $id, '<p>Hand finished piece number ' . $id . ', made to order in our workshop.</p>', '', $jsAttribs, $jsImages, $jsUrls, '2026-09-01 10:00:00']);
        foreach ([1 => (string) ($id * 7 % 997), 2 => substr(md5((string) $id), 0, 12), 3 => ($id % 5) . '.5', 4 => 'Solid oak, oiled by hand, piece ' . $id] as $field => $value) {
            $jsValue->execute([$field, (string) $id, $value]);
        }
    }
    foreach ([1, 2, 3, 4] as $field) $jsDb->pdo->exec("INSERT INTO j_fields VALUES ($field, 'com_content.article', 1, 'text')");
    for ($id = 1; $id <= 100; $id++) $jsDb->pdo->exec("INSERT INTO j_menu VALUES ($id, 'Menu $id', '{\"menu-anchor_title\":\"\",\"menu_show\":1}', 1, 0, 1, 'mainmenu', 0)");
    for ($id = 1; $id <= 60; $id++) $jsDb->pdo->exec("INSERT INTO j_modules VALUES ($id, 'Module $id', 'mod_custom', '<p>Call us on day $id</p>', '{\"layout\":\"_:default\",\"moduleclass_sfx\":\"\"}', 1, 0)");
    for ($id = 1; $id <= 20; $id++) $jsDb->pdo->exec("INSERT INTO j_categories VALUES ($id, 'Category $id', '<p>Everything in group $id</p>', '{}', 1, 'com_content')");
    $jsDb->pdo->exec("INSERT INTO j_template_styles VALUES (7, 0, '1', '{\"footerText\":\"Made in Oslo\"}')");
    $jsDb->pdo->commit();

    $jsReadPeak = $jsPeak(static function () use ($jsDb): array {
        $kinds = [];
        $batches = JoomlaDerivedRows::batches($jsDb);
        foreach ($batches as $batch) foreach ($batch as $row) $kinds[$row['kind']] = ($kinds[$row['kind']] ?? 0) + 1;
        return [$kinds, $batches->getReturn()];
    }, $jsRead);
    [$jsKinds, $jsUnresolved] = $jsRead;
    echo '       rows: ' . array_sum($jsKinds) . ' rows in batches, peak ' . round($jsReadPeak / 1048576, 1) . ' MB; ' . $jsDb->fieldRows . " field values left the database\n";
    check('scale: every published row is read, and only the field values that can hold words', [$jsKinds, $jsUnresolved],
        [['article' => 2500, 'menuItem' => 100, 'module' => 60, 'category' => 20, 'fieldValue' => 2500, 'templateStyle' => 1], []]);
    checkTrue('scale: reading the rows in batches stays under 8 MB (' . round($jsReadPeak / 1048576, 1) . ' MB)', $jsReadPeak < 8 * 1048576);
    check('scale: rows() is the batches gathered, in order', array_column(JoomlaDerivedRows::rows($jsDb), 'id'),
        array_merge(...array_map(static fn(array $b) => array_column($b, 'id'), iterator_to_array(JoomlaDerivedRows::batches($jsDb), false))));

    [$jsStore, $jsLog, $jsWriter] = $dcFresh();
    $jsEngine = (new Engine($WTOKEN, [], null, null, null, null, $jsWriter, null, $jsLog))
        ->derivedSource($jsStore, $dcRoot, static fn(): array => ['rows' => JoomlaDerivedRows::batches($jsDb), 'pages' => ['<p>Oak chair 1</p><p>Solid oak, oiled by hand, piece 1</p>'], 'unresolved' => []]);
    $jsStarted = microtime(true);
    $jsDerivePeak = $jsPeak(static fn(): array => $jsEngine->handle($dcDerive('derive-scale', 'catalog-import')), $jsAnswer);
    echo '       derive: ' . ($jsAnswer['entities'] ?? '?') . ' entities, ' . ($jsAnswer['slots'] ?? '?') . ' slots, peak ' . round($jsDerivePeak / 1048576, 1) . ' MB, '
        . round(microtime(true) - $jsStarted, 1) . " s\n";
    check('scale: derive succeeds, calibrated', [($jsAnswer['ok'] ?? false) ? true : $jsAnswer, $jsAnswer['calibrated'] ?? null], [true, true]);
    checkTrue('scale: derive stays under 32 MB (' . round($jsDerivePeak / 1048576, 1) . ' MB)', $jsDerivePeak < 32 * 1048576);
    check('scale: batches build the map one build over every row would', DerivedMap::buildBatches(JoomlaDerivedRows::batches($jsDb), null, 'catalog-import', 1, $jsStore->binding['keep']),
        DerivedMap::build(JoomlaDerivedRows::rows($jsDb), null, 'catalog-import', 1, $jsStore->binding['keep']));

    // The map a read builds, as EngineFactory wires it: kept in the contract table between requests.
    $jsBuilds = 0;
    $jsBuilt = static function () use ($jsDb, $jsStore, &$jsBuilds, $jsPeak, &$jsLastPeak): array {
        $cache = JoomlaDerivedRows::cache($jsDb, 'test');
        $jsLastPeak = $jsPeak(static fn(): array => JoomlaDerivedRows::built($jsDb, $jsStore->binding, $cache), $built);
        $jsBuilds += $cache->builds;
        return $built;
    };
    $jsFirst = $jsBuilt();
    echo '       first read: peak ' . round($jsLastPeak / 1048576, 1) . ' MB, ' . count($jsFirst['map']['slots']) . " slots\n";
    $jsSecond = $jsBuilt();
    echo '       second read: peak ' . round($jsLastPeak / 1048576, 1) . ' MB; kept: '
        . round(strlen((string) $jsDb->setQuery('SELECT entry FROM #__claudecowork_derived_cache WHERE id = 1')->loadResult()) / 1024) . " KB in one row\n";
    check('scale: a second read with the tables unchanged does not build the map again, and reads the same', [$jsBuilds, $jsSecond === $jsFirst], [1, true]);
    checkTrue('scale: a read from the kept map stays under 16 MB (' . round($jsLastPeak / 1048576, 1) . ' MB)', $jsLastPeak < 16 * 1048576);
    $jsDb->pdo->exec("UPDATE j_content SET title = 'Walnut chair 2000' WHERE id = 2000");
    $jsChanged = $jsBuilt();
    check('scale: one article changed builds it again, with the new words', [$jsBuilds, in_array('Walnut chair 2000', array_column($jsChanged['map']['slots'], 'sample'), true)], [2, true]);
    $jsDb->pdo->exec("UPDATE j_fields_values SET value = 'Solid walnut' WHERE field_id = 4 AND item_id = '2001'");
    $jsBuilt();
    $jsDb->pdo->exec("UPDATE j_modules SET params = '{\"layout\":\"_:card\"}' WHERE id = 3");
    $jsBuilt();
    $jsDb->pdo->exec("UPDATE j_content SET state = 0 WHERE id = 2002");
    $jsBuilt();
    check('scale: so does a field value, a module, an article unpublished', $jsBuilds, 5);
    JoomlaDerivedRows::forget($jsDb);
    $jsBuilt();
    check('scale: forget() after a derived apply builds it again', $jsBuilds, 6);
    check('scale: the kept map is never a row of the contract table the reader snapshots whole',
        (int) $jsDb->setQuery('SELECT COUNT(*) FROM #__claudecowork_content_contract')->loadResult(), 0);
    // A read's snapshot: what is built inside it is stored after it ends, never inside.
    $jsDb->pdo->exec("UPDATE j_content SET title = 'Ash chair 2003' WHERE id = 2003");
    $jsSite = JoomlaDerivedRows::siteCache($jsDb, 'test');
    check('scale: one kept map per request and connection', $jsSite === JoomlaDerivedRows::siteCache($jsDb, 'test'), true);
    $jsBefore = $jsDb->setQuery('SELECT entry FROM #__claudecowork_derived_cache WHERE id = 1')->loadResult();
    JoomlaDerivedRows::holdSiteCaches();
    JoomlaDerivedRows::built($jsDb, $jsStore->binding, $jsSite);
    $jsInside = $jsDb->setQuery('SELECT entry FROM #__claudecowork_derived_cache WHERE id = 1')->loadResult();
    JoomlaDerivedRows::flushSiteCaches();
    $jsAfter = $jsDb->setQuery('SELECT entry FROM #__claudecowork_derived_cache WHERE id = 1')->loadResult();
    check('scale: built inside a read, stored only after it', [$jsInside === $jsBefore, is_string($jsAfter) && $jsAfter !== $jsBefore], [true, true]);

    // The reader's media hashes: a picture whose signature is the one its hash was kept under is not read again.
    require_once __DIR__ . '/../com_claudecowork/administrator/src/Service/JoomlaContentReader.php';
    $jsMedia = sys_get_temp_dir() . '/cowork-media-' . bin2hex(random_bytes(4));
    mkdir($jsMedia . '/images', 0777, true);
    file_put_contents($jsMedia . '/images/a.jpg', 'picture a');
    file_put_contents($jsMedia . '/images/b.jpg', 'picture b');
    [$jsHashed, $jsKept] = \Tracy\Component\ClaudeCowork\Administrator\Service\JoomlaContentReader::hashesWith($jsMedia,
        ['images/a.jpg' => 'sig-a', 'images/b.jpg' => 'sig-b', 'images/gone.jpg' => null], []);
    check('media: the files are hashed, a missing one stays null', $jsHashed, ['images/a.jpg' => hash('sha256', 'picture a'), 'images/b.jpg' => hash('sha256', 'picture b'), 'images/gone.jpg' => null]);
    JoomlaDerivedRows::keepHashes($jsDb, $jsKept);
    unlink($jsMedia . '/images/a.jpg'); // were it read again, the read would fail
    file_put_contents($jsMedia . '/images/b.jpg', 'picture b, edited');
    [$jsAgain] = \Tracy\Component\ClaudeCowork\Administrator\Service\JoomlaContentReader::hashesWith($jsMedia,
        ['images/a.jpg' => 'sig-a', 'images/b.jpg' => 'sig-b2'], JoomlaDerivedRows::keptHashes($jsDb));
    check('media: a kept hash under the same signature is not read again; a new signature is', $jsAgain,
        ['images/a.jpg' => hash('sha256', 'picture a'), 'images/b.jpg' => hash('sha256', 'picture b, edited')]);
    // Racy hashes: stat() times are whole seconds, so a file written again in the second it was hashed can
    // keep its whole signature. Its hash is used, never kept, until its times are 2 s older than the hash.
    $jsReader = \Tracy\Component\ClaudeCowork\Administrator\Service\JoomlaContentReader::class;
    file_put_contents($jsMedia . '/images/c.jpg', 'picture c');
    [$jsFresh, $jsFreshKept] = $jsReader::hashesWith($jsMedia, ['images/c.jpg' => '9:1000:1001:77'], [], 1002);
    [, $jsOldKept] = $jsReader::hashesWith($jsMedia, ['images/c.jpg' => '9:1000:1001:77'], [], 1004);
    check('media: a file changed within 2 s of its hashing is hashed, not kept; an older one is kept',
        [$jsFresh['images/c.jpg'], $jsFreshKept, $jsOldKept], [hash('sha256', 'picture c'), [], ['images/c.jpg' => ['9:1000:1001:77', hash('sha256', 'picture c')]]]);
}
