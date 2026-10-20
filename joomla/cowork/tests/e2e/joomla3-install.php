<?php
// Installs a bare Joomla 3 inside the stand's Joomla container, the way the web installer would:
// the core schema under the stand's prefix, configuration.php from Joomla's own template, one Super
// User, and no installation/ folder. Joomla 4+ images install themselves from the JOOMLA_* variables;
// Joomla 3 images only copy the files, and Joomla 3 has no CLI installer. Run from the webroot:
//
//   php joomla3-install.php <db host> <db user> <db password> <db name> <prefix> <admin password>

[, $host, $user, $password, $name, $prefix, $adminPassword] = $argv;

$db = new mysqli($host, $user, $password, $name);
$db->set_charset('utf8mb4');
$schema = str_replace('#__', $prefix, file_get_contents('installation/sql/mysql/joomla.sql'));
if (!$db->multi_query($schema)) {
    fwrite(STDERR, "schema: {$db->error}\n");
    exit(1);
}
do {
    if ($result = $db->store_result()) $result->free();
} while ($db->more_results() && $db->next_result());
if ($db->errno) {
    fwrite(STDERR, "schema: {$db->error}\n");
    exit(1);
}

$hash = password_hash($adminPassword, PASSWORD_BCRYPT);
$now = gmdate('Y-m-d H:i:s');
$stmt = $db->prepare("INSERT INTO {$prefix}users (id, name, username, email, password, block, sendEmail, registerDate, lastvisitDate, activation, params, lastResetTime, resetCount, otpKey, otep, requireReset)"
    . " VALUES (42, 'Admin', 'admin', 'admin@example.invalid', ?, 0, 1, ?, '0000-00-00 00:00:00', '', '', '0000-00-00 00:00:00', 0, '', '', 0)");
$stmt->bind_param('ss', $hash, $now);
$stmt->execute() or exit("admin: {$db->error}\n");
$db->query("INSERT INTO {$prefix}user_usergroup_map (user_id, group_id) VALUES (42, 8)") or exit("admin group: {$db->error}\n");

$root = getcwd();
$values = [
    'sitename' => 'cowork-e2e', 'host' => $host, 'user' => $user, 'password' => $password, 'db' => $name,
    'dbprefix' => $prefix, 'secret' => bin2hex(random_bytes(8)), 'tmp_path' => "$root/tmp",
    'log_path' => "$root/administrator/logs", 'mailfrom' => 'admin@example.invalid', 'fromname' => 'cowork-e2e',
];
$config = file_get_contents('installation/configuration.php-dist');
foreach ($values as $key => $value) {
    $config = preg_replace('/public \$' . $key . '\s*=\s*[^;]*;/', 'public $' . $key . ' = ' . var_export($value, true) . ';', $config, 1);
}
file_put_contents('configuration.php', $config) or exit("configuration.php was not written\n");

exec('rm -rf ' . escapeshellarg("$root/installation"));
echo "Joomla 3 installed\n";
