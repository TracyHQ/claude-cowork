# Sourced after joomla-stand.sh: restores a published Joomla quickstart over the stand's Joomla, so
# an e2e script can prove a door on a real template site (T4 has no bare-Joomla stand-in). The
# folder holds the release's manifest.json and its webroot and database files, as published.
#
# Gives: restore_quickstart <folder> (sets QS_TEMPLATE, QS_PREFIX and a new TOKEN for `call`) and
# qs_sql "<query>" on the restored database. The package is installed again on the restored site.

qs_sql() { docker exec "$P-db" mariadb -uroot -p"$DBPW" quickstart -N -e "$1"; }

restore_quickstart() {
  local folder="$1" field
  field() { node -e 'const m = require(process.argv[1]); const v = process.argv[2].split(".").reduce((o, k) => o?.[k], m); if (v == null) process.exit(1); process.stdout.write(String(v))' "$folder/manifest.json" "$1"; }
  [ -f "$folder/manifest.json" ] || { echo "no manifest.json in $folder" >&2; exit 1; }
  [ "$(field platform)" = joomla ] || { echo "$folder is not a Joomla quickstart" >&2; exit 1; }
  QS_TEMPLATE=$(field template)
  QS_PREFIX=$(field prefix)
  local webroot database
  webroot="$folder/$(field parts.webroot.file)"
  database="$folder/$(field parts.database.file)"

  say "restoring quickstart $(field id) $(field version) ($(field framework)) over the stand"
  docker exec "$P-db" mariadb -uroot -p"$DBPW" -e 'DROP DATABASE IF EXISTS quickstart; CREATE DATABASE quickstart'
  gzip -dc "$database" | docker exec -i "$P-db" mariadb -uroot -p"$DBPW" quickstart
  docker cp "$webroot" "$P-joomla:/tmp/quickstart-webroot.tar.gz"
  docker exec "$P-joomla" sh -c 'find /var/www/html -mindepth 1 -delete \
    && tar xzf /tmp/quickstart-webroot.tar.gz -C /var/www/html && chown -R www-data:www-data /var/www/html'
  # The release blanks its database settings and paths: point them at the stand.
  docker exec -u www-data "$P-joomla" php -r '
    $f = "configuration.php"; $c = file_get_contents($f);
    $set = ["host" => $argv[1], "user" => "root", "password" => $argv[2], "db" => "quickstart",
      "log_path" => "/var/www/html/administrator/logs", "tmp_path" => "/var/www/html/tmp", "live_site" => ""];
    foreach ($set as $k => $v) $c = preg_replace_callback("~public [\$]$k = .*;~", fn() => "public \$" . $k . " = " . var_export($v, true) . ";", $c, 1);
    file_put_contents($f, $c);' "$P-db" "$DBPW"
  docker exec -u www-data "$P-joomla" php cli/joomla.php extension:install --path=/tmp/pkg_claudecowork.zip 2>&1 | tail -1
  TOKEN=$(qs_sql "SELECT JSON_VALUE(params,'\$.token') FROM ${QS_PREFIX}extensions WHERE element='com_claudecowork'")
  [ -n "$TOKEN" ] && [ "$TOKEN" != NULL ] || { echo "the install on the quickstart wrote no token" >&2; exit 1; }
  docker exec "$P-joomla" curl -fsS -o /dev/null http://localhost/ || { echo "the restored site does not answer" >&2; exit 1; }
}
