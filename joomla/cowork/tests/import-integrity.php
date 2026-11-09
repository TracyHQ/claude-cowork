<?php
/** Real MariaDB round trip; credentials are read from the environment and never printed. */
require_once __DIR__ . '/../lib/DbDumper.php';
require_once __DIR__ . '/../lib/MysqliRowSource.php';
$db = new mysqli(getenv('TRACY_TEST_DB_HOST'), getenv('TRACY_TEST_DB_USER'), getenv('TRACY_TEST_DB_PASSWORD'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$source = 'joomla_import_integrity_' . bin2hex(random_bytes(4));
$target = $source . '_copy';
function importSql(mysqli $db, string $sql): void {
    $db->multi_query($sql);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
}
try {
    $db->query("CREATE DATABASE `{$source}`");
    $db->query("CREATE DATABASE `{$target}`");
    $db->select_db($source);
    $db->query('CREATE TABLE jos_nokey (id INT, note TEXT)');
    $db->query("INSERT INTO jos_nokey VALUES (1,'one'),(2,'two'),(3,'three'),(4,'four')");
    $keyless = new DbDumper(new MysqliRowSource($db));
    $first = $keyless->dumpChunkFrom('jos_nokey', null, 2);
    $db->query('DELETE FROM jos_nokey WHERE id=1');
    $refused = false;
    try { $keyless->dumpChunkFrom('jos_nokey', $first['next_cursor'], 2); } catch (RuntimeException $error) {
        $refused = strpos($error->getMessage(), 'changed during export') !== false;
    }
    if (!$refused) throw new RuntimeException('keyless deletion silently skipped a row');
    $db->query('DROP TABLE jos_nokey');
    $db->query('CREATE TABLE `jos_tick``name` (id INT PRIMARY KEY, note TEXT)');
    $db->query("INSERT INTO `jos_tick``name` VALUES (1,'literal `{$source}`. stays literal'),(2,'two')");
    $db->query('CREATE VIEW jos_z_view AS SELECT * FROM `jos_tick``name`');
    $db->query('CREATE VIEW jos_a_view AS SELECT * FROM jos_z_view');
    $db->query('CREATE VIEW jos_a_join_view AS SELECT a.id, a.note FROM (jos_z_view a JOIN jos_z_view b ON a.id=b.id)');
    $db->query('CREATE VIEW jos_a_nested_view AS SELECT a.id, a.note FROM jos_z_view a WHERE EXISTS (SELECT 1 FROM jos_z_view b WHERE a.id=b.id)');
    $db->query('CREATE VIEW jos_empty_view AS SELECT * FROM jos_z_view WHERE id < 0');
    $db->query("CREATE VIEW jos_literal_view AS SELECT 'literal `{$source}`. stays literal' AS note");
    $db->query("CREATE TABLE `{$target}`.outside (id INT)");
    $db->query("CREATE VIEW jos_external_view AS SELECT * FROM `{$target}`.outside");
    $refused = false;
    try { (new MysqliRowSource($db))->views(); } catch (RuntimeException $error) {
        $refused = strpos($error->getMessage(), 'another database') !== false;
    }
    if (!$refused) throw new RuntimeException('external database dependency accepted');
    $db->query('DROP VIEW jos_external_view');
    $db->query("CREATE VIEW jos_external_view AS SELECT a.id FROM `jos_tick``name` a, `{$target}`.outside b WHERE a.id=b.id");
    $refused = false;
    try { (new MysqliRowSource($db))->views(); } catch (RuntimeException $error) {
        $refused = strpos($error->getMessage(), 'another database') !== false;
    }
    if (!$refused) throw new RuntimeException('comma join external database dependency accepted');
    $db->query('DROP VIEW jos_external_view');
    // A SELECT-list comma followed by a table alias must not create a self-dependency.
    $db->query('CREATE VIEW jos_alias_view AS SELECT jos_alias_view.id, jos_alias_view.note FROM `jos_tick``name` AS jos_alias_view');
    $db->query('CREATE VIEW `jos_quote\'view` AS SELECT id, note FROM `jos_tick``name`');
    $src = new MysqliRowSource($db);
    // Joomla keeps its own snapshot operation; escaped identifiers must survive that path too.
    $src->copyTable('jos_tick`name', 'jos_copy`name');
    $src->renameTable('jos_copy`name', 'jos_renamed`name');
    if ($db->query('SELECT * FROM `jos_renamed``name` ORDER BY id')->fetch_all() !== $db->query('SELECT * FROM `jos_tick``name` ORDER BY id')->fetch_all()) throw new RuntimeException('snapshot copy changed rows');
    $src->dropTable('jos_renamed`name');
    $dump = new DbDumper($src);
    $views = $dump->views();
    if (array_search('jos_z_view', array_keys($views), true) >= array_search('jos_a_view', array_keys($views), true)) throw new RuntimeException('view order incorrect');
    $sql = '';
    $cursor = null;
    do {
        $part = $dump->dumpChunkFrom('jos_tick`name', $cursor, 1);
        $sql .= $part['sql'];
        $cursor = $part['next_cursor'];
    } while (!$part['done']);
    $expected = $db->query('SELECT * FROM `jos_tick``name` ORDER BY id')->fetch_all();
    foreach ($views as $view) {
        if (strpos($view, 'DEFINER=') !== false) throw new RuntimeException('source definer leaked');
        $sql .= $view . ";\n";
    }
    $db->select_db($target);
    importSql($db, $sql);
    foreach (['jos_z_view', 'jos_a_view', 'jos_alias_view', 'jos_a_join_view', 'jos_a_nested_view', "jos_quote'view"] as $view) {
        if ($db->query("SELECT * FROM `{$view}` ORDER BY id")->fetch_all() !== $expected) throw new RuntimeException('view round trip differs');
    }
    if ($db->query('SELECT COUNT(*) FROM jos_empty_view')->fetch_row()[0] !== '0') throw new RuntimeException('empty view lost');
    if ($db->query('SELECT note FROM jos_literal_view')->fetch_row()[0] !== "literal `{$source}`. stays literal") throw new RuntimeException('view literal rewritten');
    $db->query("DROP DATABASE `{$source}`");
    if ($db->query('SELECT * FROM jos_a_view ORDER BY id')->fetch_all() !== $expected) throw new RuntimeException('copy still depends on source');
    foreach (array_keys($views) as $view) {
        $db->query('SELECT * FROM `' . str_replace('`', '``', $view) . '`')->free();
    }
    echo "ok - escaped identifiers, aliases, joins, nested and empty views round trip independently\n";
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$source}`");
    $db->query("DROP DATABASE IF EXISTS `{$target}`");
}
