<?php
/**
 * One database, two sites: `info.db_prefix` and `db.tables.prefix` name this site's prefix, so an
 * importer can pick its tables, while `tables` itself stays the whole list db.cleanup / db.restore /
 * db.purge work on. Required from run.php, whose check() and checkTrue() it uses.
 */

$DPTOKEN = bin2hex(random_bytes(16));

$dpTable = function (string $name, int $rows): array {
    return ['create' => "CREATE TABLE `{$name}` (`id` int)", 'rows' => array_fill(0, $rows, ['1']), 'columns' => ['id'], 'key' => ['id']];
};
$dpTables = [
    'wp_content' => $dpTable('wp_content', 3),
    'wp_users' => $dpTable('wp_users', 1),
    'shop_content' => $dpTable('shop_content', 5),
    'shop_users' => $dpTable('shop_users', 2),
    '_tracy_trash_20260101000000__wp_old' => $dpTable('_tracy_trash_20260101000000__wp_old', 0),
];
$dpSource = new FakeRowSource($dpTables);
$dpNames = $dpSource->tables();
$dpInfo = ['php' => PHP_VERSION, 'wordpress' => '6.6', 'component' => '0.0.0'];

$dpBefore = (new Engine($DPTOKEN, $dpInfo, new DbDumper($dpSource)))->handle(['token' => $DPTOKEN, 'action' => 'db.tables']);
$dpSite1 = new Engine($DPTOKEN, $dpInfo + ['db_prefix' => 'wp_'], new DbDumper($dpSource));
$dpSite2 = new Engine($DPTOKEN, $dpInfo + ['db_prefix' => 'shop_'], new DbDumper($dpSource));

check('db prefix: info carries the site\'s db_prefix', $dpSite2->handle(['token' => $DPTOKEN, 'action' => 'info'])['info']['db_prefix'], 'shop_');
check('db prefix: each site of one database answers its own db_prefix',
    [$dpSite1->handle(['token' => $DPTOKEN, 'action' => 'info'])['info']['db_prefix'], $dpSite2->handle(['token' => $DPTOKEN, 'action' => 'info'])['info']['db_prefix']],
    ['wp_', 'shop_']);
$dpT1 = $dpSite1->handle(['token' => $DPTOKEN, 'action' => 'db.tables']);
$dpT2 = $dpSite2->handle(['token' => $DPTOKEN, 'action' => 'db.tables']);
check('db prefix: db.tables carries the same prefix', [$dpT1['ok'], $dpT1['prefix'], $dpT2['ok'], $dpT2['prefix']], [true, 'wp_', true, 'shop_']);
check('db prefix: db.tables still lists every table, both sites and the trash', [$dpT1['tables'], $dpT2['tables']], [$dpNames, $dpNames]);
check('db prefix: with the prefix gone, the answer is exactly the old one', $dpT1, $dpBefore + ['prefix' => 'wp_']);
check('db prefix: a host that names no prefix answers db.tables as before', array_keys($dpBefore), ['ok', 'tables']);
check('db prefix: and its info has no db_prefix', array_key_exists('db_prefix', (new Engine($DPTOKEN, $dpInfo, new DbDumper($dpSource)))->handle(['token' => $DPTOKEN, 'action' => 'info'])['info']), false);
check('db prefix: an importer filtering on prefix gets only its own site',
    array_values(array_filter($dpT2['tables'], function (string $t) use ($dpT2): bool { return strpos($t, $dpT2['prefix']) === 0; })),
    ['shop_content', 'shop_users']);

// A multisite network: site 2's own tables carry `wp_2_`, the network's shared ones `wp_`. The importer
// filters by `base_prefix` so the network keeps its users and blogs.
$dpNetwork = new FakeRowSource([
    'wp_blogs' => $dpTable('wp_blogs', 2),
    'wp_options' => $dpTable('wp_options', 4),
    'wp_users' => $dpTable('wp_users', 3),
    'wp_2_options' => $dpTable('wp_2_options', 4),
    'wp_2_posts' => $dpTable('wp_2_posts', 6),
    'shop_posts' => $dpTable('shop_posts', 1),
]);
$dpNetworkNames = $dpNetwork->tables();
$dpMulti = new Engine($DPTOKEN, $dpInfo + ['db_prefix' => 'wp_2_', 'db_base_prefix' => 'wp_'], new DbDumper($dpNetwork));
$dpMultiInfo = $dpMulti->handle(['token' => $DPTOKEN, 'action' => 'info'])['info'];
check('db prefix (multisite): info carries db_prefix and db_base_prefix', [$dpMultiInfo['db_prefix'], $dpMultiInfo['db_base_prefix']], ['wp_2_', 'wp_']);
$dpMultiTables = $dpMulti->handle(['token' => $DPTOKEN, 'action' => 'db.tables']);
check('db prefix (multisite): db.tables carries prefix and base_prefix', [$dpMultiTables['ok'], $dpMultiTables['prefix'], $dpMultiTables['base_prefix']], [true, 'wp_2_', 'wp_']);
check('db prefix (multisite): db.tables still lists every table', $dpMultiTables['tables'], $dpNetworkNames);
check('db prefix (multisite): the answer is the old one plus the two prefixes',
    $dpMultiTables, (new Engine($DPTOKEN, $dpInfo, new DbDumper($dpNetwork)))->handle(['token' => $DPTOKEN, 'action' => 'db.tables']) + ['prefix' => 'wp_2_', 'base_prefix' => 'wp_']);
check('db prefix (multisite): filtering by base_prefix keeps the shared tables and the site\'s own',
    array_values(array_filter($dpMultiTables['tables'], function (string $t) use ($dpMultiTables): bool { return strpos($t, $dpMultiTables['base_prefix']) === 0; })),
    ['wp_blogs', 'wp_options', 'wp_users', 'wp_2_options', 'wp_2_posts']);
check('db prefix: a host that names only db_prefix answers no base_prefix', array_key_exists('base_prefix', $dpT1), false);

// The plugin hands the engine $wpdb->prefix and $wpdb->base_prefix: read from its source, as other wiring checks here do,
// since the plugin file needs a whole WordPress to load.
$dpPlugin = (string) file_get_contents(__DIR__ . '/../claude-cowork/claude-cowork.php');
checkTrue('db prefix: the plugin wires $wpdb->prefix into info', str_contains($dpPlugin, "'db_prefix' => (string) \$GLOBALS['wpdb']->prefix,"));
checkTrue('db prefix: the plugin wires $wpdb->base_prefix into info', str_contains($dpPlugin, "'db_base_prefix' => (string) \$GLOBALS['wpdb']->base_prefix,"));
