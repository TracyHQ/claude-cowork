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
    'jos_content' => $dpTable('jos_content', 3),
    'jos_users' => $dpTable('jos_users', 1),
    'site2_content' => $dpTable('site2_content', 5),
    'site2_users' => $dpTable('site2_users', 2),
    '_tracy_trash_20260101000000__jos_old' => $dpTable('_tracy_trash_20260101000000__jos_old', 0),
];
$dpSource = new FakeRowSource($dpTables);
$dpNames = $dpSource->tables();
$dpInfo = ['php' => PHP_VERSION, 'joomla' => '5.1.0', 'component' => '0.0.0'];

$dpBefore = (new Engine($DPTOKEN, $dpInfo, new DbDumper($dpSource)))->handle(['token' => $DPTOKEN, 'action' => 'db.tables']);
$dpSite1 = new Engine($DPTOKEN, $dpInfo + ['db_prefix' => 'jos_'], new DbDumper($dpSource));
$dpSite2 = new Engine($DPTOKEN, $dpInfo + ['db_prefix' => 'site2_'], new DbDumper($dpSource));

check('db prefix: info carries the site\'s db_prefix', $dpSite2->handle(['token' => $DPTOKEN, 'action' => 'info'])['info']['db_prefix'], 'site2_');
check('db prefix: each site of one database answers its own db_prefix',
    [$dpSite1->handle(['token' => $DPTOKEN, 'action' => 'info'])['info']['db_prefix'], $dpSite2->handle(['token' => $DPTOKEN, 'action' => 'info'])['info']['db_prefix']],
    ['jos_', 'site2_']);
$dpT1 = $dpSite1->handle(['token' => $DPTOKEN, 'action' => 'db.tables']);
$dpT2 = $dpSite2->handle(['token' => $DPTOKEN, 'action' => 'db.tables']);
check('db prefix: db.tables carries the same prefix', [$dpT1['ok'], $dpT1['prefix'], $dpT2['ok'], $dpT2['prefix']], [true, 'jos_', true, 'site2_']);
check('db prefix: db.tables still lists every table, both sites and the trash', [$dpT1['tables'], $dpT2['tables']], [$dpNames, $dpNames]);
check('db prefix: details unchanged', [$dpT1['details'], $dpT2['details']], [$dpBefore['details'], $dpBefore['details']]);
check('db prefix: with the prefix gone, the answer is exactly the old one', $dpT1, $dpBefore + ['prefix' => 'jos_']);
check('db prefix: a host that names no prefix answers db.tables as before', array_keys($dpBefore), ['ok', 'tables', 'details']);
check('db prefix: and its info has no db_prefix', array_key_exists('db_prefix', (new Engine($DPTOKEN, $dpInfo, new DbDumper($dpSource)))->handle(['token' => $DPTOKEN, 'action' => 'info'])['info']), false);
check('db prefix: an importer filtering on prefix gets only its own site',
    array_values(array_filter($dpT2['tables'], function (string $t) use ($dpT2): bool { return strpos($t, $dpT2['prefix']) === 0; })),
    ['site2_content', 'site2_users']);
