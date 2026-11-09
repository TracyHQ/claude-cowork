<?php
/** Real MariaDB round trip; credentials are read from the environment and never printed. */
require_once __DIR__ . '/../lib/DbDumper.php';
require_once __DIR__ . '/../lib/MysqliRowSource.php';
$db = new mysqli(getenv('TRACY_TEST_DB_HOST'), getenv('TRACY_TEST_DB_USER'), getenv('TRACY_TEST_DB_PASSWORD'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$source = 'import_integrity_' . bin2hex(random_bytes(4));
$target = $source . '_copy';
function importSql(mysqli $db, string $sql): void {
    $db->multi_query($sql);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
}
try {
    $db->query("CREATE DATABASE `{$source}`");
    $db->query("CREATE DATABASE `{$target}`");
    $db->select_db($source);
    $db->query('CREATE TABLE `wp_tick``name` (id INT PRIMARY KEY, note TEXT)');
    $db->query("INSERT INTO `wp_tick``name` VALUES (1,'literal `{$source}`. stays literal'),(2,'two')");
    $db->query('CREATE VIEW wp_z_view AS SELECT * FROM `wp_tick``name`');
    $db->query('CREATE VIEW wp_a_view AS SELECT * FROM wp_z_view');
    $db->query('CREATE VIEW wp_empty_view AS SELECT * FROM wp_z_view WHERE id < 0');
    $src = new MysqliRowSource($db);
    $dump = new DbDumper($src);
    $views = $dump->views();
    if (array_search('wp_z_view', array_keys($views), true) >= array_search('wp_a_view', array_keys($views), true)) throw new RuntimeException('view order incorrect');
    $sql = '';
    $cursor = null;
    do {
        $part = $dump->dumpChunkFrom('wp_tick`name', $cursor, 1);
        $sql .= $part['sql'];
        $cursor = $part['next_cursor'];
    } while (!$part['done']);
    $expected = $db->query('SELECT * FROM `wp_tick``name` ORDER BY id')->fetch_all();
    foreach ($views as $view) {
        if (strpos($view, 'DEFINER=') !== false) throw new RuntimeException('source definer leaked');
        $sql .= $view . ";\n";
    }
    $db->select_db($target);
    importSql($db, $sql);
    foreach (['wp_z_view', 'wp_a_view'] as $view) {
        if ($db->query("SELECT * FROM `{$view}` ORDER BY id")->fetch_all() !== $expected) throw new RuntimeException('view round trip differs');
    }
    if ($db->query('SELECT COUNT(*) FROM wp_empty_view')->fetch_row()[0] !== '0') throw new RuntimeException('empty view lost');
    $db->query("DROP DATABASE `{$source}`");
    if ($db->query('SELECT * FROM wp_a_view ORDER BY id')->fetch_all() !== $expected) throw new RuntimeException('copy still depends on source');
    echo "ok - backtick identifiers, dependent and empty views round trip independently\n";
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$source}`");
    $db->query("DROP DATABASE IF EXISTS `{$target}`");
}
