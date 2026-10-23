#!/usr/bin/env bash
# showcaseNav.status|apply|revert on a real JA Diner 1.0.4 site: the quickstart's own installer
# package, set up by Joomla's CLI installer (which loads its demo content), with this checkout's
# package installed over it and the ja-diner/j6/1.0.4 contract, showcase-nav-map.json included, on disk.
#
#   DINER_QUICKSTART=<folder> joomla/cowork/tests/e2e/showcase-nav.sh
#
# <folder> is the published release folder of ja-diner/j6 1.0.4: it holds ja-diner-j6-quickstart-1.0.4.zip.
#
# 1. bind, then status: 15 entries to hide, none hidden.
# 2. apply: the J! Pages drawer and the Home colour variants carry menu_show 0 in #__menu, still
#    published; Home, Our Blog, Contact Us, Offers, About Us and Reservation keep their params byte
#    for byte, and the home page no longer links the hidden entries. Inspect is clean; a
#    second apply is a no-op.
# 3. the customer shows Home - Pink again; revert restores every other params text byte for byte and
#    names Home - Pink as a conflict, leaving it as the customer set it. The same revert retried
#    gets the committed answer.
# 4. the customer's change is not adopted: put back by hand, Home - Pink is still reported, until a
#    new revert request finds it as the apply found it, restores it with nothing written, and the site
#    inspects clean with no showcase navigation applied. Both reverts still answer their retries.
# 5. the writer's compare-and-sets on this MariaDB: params that differ from what was read only in
#    letter case (equal under the site's collation) are not overwritten, nor is a module whose
#    published is not the one expected.
set -euo pipefail
E2E_NAME=snav
: "${DINER_QUICKSTART:?set DINER_QUICKSTART to the ja-diner/j6 1.0.4 release folder}"
ZIP="$DINER_QUICKSTART/ja-diner-j6-quickstart-1.0.4.zip"
[ -f "$ZIP" ] || { echo "no ja-diner-j6-quickstart-1.0.4.zip in $DINER_QUICKSTART" >&2; exit 1; }
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/joomla-stand.sh"

QS=tr_
qs_sql() { docker exec "$P-db" mariadb -uroot -p"$DBPW" quickstart -N -e "$1"; }
field() { node -e 'const v = process.argv[2].split(".").reduce((o, k) => o?.[k], JSON.parse(process.argv[1])); process.stdout.write(typeof v === "object" ? JSON.stringify(v) : String(v))' "$ANSWER" "$1"; }
contract() { call content.contract "$1"; }
HIDDEN="118,119,120,121,122,123,125,128,130,131,132,145,146,147,148"
KEPT="101,108,117,141,142,144"

say "installing the JA Diner 1.0.4 quickstart over the stand"
docker cp "$ZIP" "$P-joomla:/tmp/quickstart.zip"
docker exec "$P-joomla" sh -c 'find /var/www/html -mindepth 1 -delete'
docker exec "$P-joomla" php -r '$z = new ZipArchive(); exit($z->open("/tmp/quickstart.zip") === true && $z->extractTo("/var/www/html") ? 0 : 1);'
docker exec "$P-joomla" sh -c 'rm -f /tmp/quickstart.zip && chown -R www-data:www-data /var/www/html'
docker exec "$P-db" mariadb -uroot -p"$DBPW" -e 'DROP DATABASE IF EXISTS quickstart; CREATE DATABASE quickstart'
docker exec -u www-data -w /var/www/html "$P-joomla" php installation/joomla.php install --no-interaction \
  --site-name "JA Diner" --admin-user Admin --admin-username admin --admin-password e2e-admin-password-1 \
  --admin-email admin@example.invalid --db-type mysqli --db-host "$P-db" --db-user root --db-pass "$DBPW" \
  --db-name quickstart --db-prefix "$QS" >/dev/null
docker exec "$P-joomla" rm -rf /var/www/html/installation
docker exec -u www-data "$P-joomla" php cli/joomla.php extension:install --path=/tmp/pkg_claudecowork.zip 2>&1 | tail -1
TOKEN=$(qs_sql "SELECT JSON_VALUE(params,'\$.token') FROM ${QS}extensions WHERE element='com_claudecowork'")
[ -n "$TOKEN" ] && [ "$TOKEN" != NULL ] || { echo "the install on the quickstart wrote no token" >&2; exit 1; }
# The package ships no profile (it is fetched from the release when needed): this checkout's goes on disk.
CON=/var/www/html/administrator/components/com_claudecowork/lib/contracts/ja-diner/j6
docker exec "$P-joomla" mkdir -p "$CON"
docker cp "$COWORK/lib/contracts/ja-diner/j6/1.0.4" "$P-joomla:$CON/1.0.4"
docker exec "$P-joomla" chown -R www-data:www-data "$CON"
qs_sql "UPDATE ${QS}extensions SET params=JSON_SET(params,'\$.contract','ja-diner/j6/1.0.4') WHERE element='com_claudecowork' AND type='component'"
qs_sql "CREATE TABLE snav_original AS SELECT id, params FROM ${QS}menu WHERE id IN ($HIDDEN,$KEPT)"
changed() { qs_sql "SELECT COALESCE(GROUP_CONCAT(m.id ORDER BY m.id),'none') FROM ${QS}menu m JOIN snav_original o ON o.id=m.id WHERE BINARY m.params<>BINARY o.params AND m.id IN ($1)"; }
shows() { qs_sql "SELECT GROUP_CONCAT(CONCAT(id,':',COALESCE(JSON_VALUE(params,'\$.menu_show'),'-'),':',published) ORDER BY id) FROM ${QS}menu WHERE id IN ($1)"; }

say "1. bind and status"
contract '{"operation":"bind"}'
contract '{"operation":"showcaseNav.status"}'
expect "status: 15 entries to hide, none hidden" "$(field status) $(field hides.menuItem) $(field remaining)" "none 15 15"

# How many links the home page has to each of four entries: two the map hides, two it keeps.
links() {
  docker exec "$P-joomla" curl -fsS http://localhost/ | node -e '
    const html = require("fs").readFileSync(0, "utf8");
    const count = (path) => html.split(`href="/index.php/${path}"`).length - 1;
    process.stdout.write(["joomla-content/typography", "j-pages/login", "offers", "our-blog"].map((p) => `${p.split("/").pop()}:${count(p)}`).join(" "));'
}
expect "before an apply the home page links the showcase and the customer's pages" "$(links)" "typography:1 login:1 offers:1 our-blog:1"

say "2. apply"
contract '{"operation":"showcaseNav.apply","apply_id":"snav-e2e","request_id":"e2e-1"}'
expect "apply completes in one call" "$(field status) $(field moved) $(field remaining)" "completed 15 0"
expect "every listed entry is out of the menu, still published" "$(shows "$HIDDEN")" \
  "118:0:1,119:0:1,120:0:1,121:0:1,122:0:1,123:0:1,125:0:1,128:0:1,130:0:1,131:0:1,132:0:1,145:0:1,146:0:1,147:0:1,148:0:1"
expect "the customer's own pages keep their params byte for byte" "$(changed "$KEPT")" none
expect "the home page's menu drops the showcase links, and only them" "$(links)" "typography:0 login:0 offers:1 our-blog:1"
contract '{"operation":"inspect"}'
expect "the applied site inspects clean" "$(field warnings)" undefined
contract '{"operation":"showcaseNav.apply","apply_id":"snav-e2e2","request_id":"e2e-2"}'
expect "a second apply is a no-op" "$(field status) $(field alreadyApplied)" "completed true"

say "3. the customer shows Home - Pink again; revert"
qs_sql "UPDATE ${QS}menu SET params=JSON_SET(params,'\$.menu_show','1') WHERE id=146"
contract '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo","request_id":"e2e-3"}'
expect "revert brings the others back" "$(field status) $(field moved) $(field remaining)" "reverted 14 0"
expect "and names Home - Pink as a conflict" "$(node -e 'process.stdout.write(Object.keys(JSON.parse(process.argv[1]).conflicts).join(","))' "$ANSWER")" menuItem-146
expect "every other entry has exactly its params back" "$(changed "$HIDDEN")" 146
expect "Home - Pink stays as the customer set it" "$(shows 146)" "146:1:1"
expect "the home page links the showcase again" "$(links)" "typography:1 login:1 offers:1 our-blog:1"
contract '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo","request_id":"e2e-3"}'
expect "the same revert retried gets the committed answer" "$(field status) $(field alreadyReverted) $(field remaining)" "reverted true 0"

say "4. the customer's change is not adopted"
drifted() { node -e 'process.stdout.write((JSON.parse(process.argv[1]).warnings || []).map((w) => (w.message.match(/drift: (\S+) /) || [])[1]).join(","))' "$ANSWER"; }
qs_sql "UPDATE ${QS}menu m JOIN snav_original o ON o.id=m.id SET m.params=o.params WHERE m.id=146"
contract '{"operation":"inspect"}'
expect "Home - Pink put back by hand is still reported" "$(drifted)" menuItem-146
contract '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo2","request_id":"e2e-4"}'
expect "a new revert request restores it with nothing written" "$(field status) $(field moved) $(field conflicts) $(changed "$HIDDEN")" "reverted 1 [] none"
contract '{"operation":"inspect"}'
expect "the reverted site inspects clean" "$(field warnings)" undefined
contract '{"operation":"showcaseNav.status"}'
expect "and has no showcase navigation applied" "$(field status) $(field conflicts)" "none []"
contract '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo","request_id":"e2e-3"}'
expect "the first revert still answers its retry" "$(field alreadyReverted) $(node -e 'process.stdout.write(Object.keys(JSON.parse(process.argv[1]).conflicts).join(","))' "$ANSWER")" "true menuItem-146"
contract '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo2","request_id":"e2e-4"}'
expect "and so does the second" "$(field alreadyReverted) $(field conflicts)" "true []"
qs_sql "DROP TABLE snav_original"

say "5. compare-and-set by bytes"
docker exec -i -u www-data "$P-joomla" php <<'PHP' > /dev/null
<?php
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
$db = Joomla\CMS\Factory::getContainer()->get(Joomla\Database\DatabaseInterface::class);
foreach (['SqlValue', 'RowSource', 'DbDumper', 'FileWalker', 'TarStream', 'Uploader', 'Extensions', 'SiteWriter', 'ChangeStamp', 'CoreUpgrader', 'FilesRestorer', 'Token', 'Engine', 'MysqliRowSource', 'Door'] as $f)
    require_once JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib/' . $f . '.php';
require_once JPATH_SITE . '/components/com_claudecowork/src/Controller/JoomlaSiteWriter.php';
$w = new Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter($db);
$read = fn () => (string) $db->setQuery('SELECT params FROM #__menu WHERE id = 141')->loadResult();
$params = $read();
$folded = preg_replace_callback('/[a-z]/', fn ($m) => strtoupper($m[0]), $params, 1);
$out = [
    'collationFolds' => (int) $db->setQuery('SELECT COUNT(*) FROM #__menu WHERE id = 141 AND params = ' . $db->quote($folded))->loadResult(),
    'caseOnly' => $w->replaceMenuItemParams(141, $folded, '{"overwritten":1}'),
];
$out['kept'] = $read() === $params;
$module = (int) $db->setQuery('SELECT id FROM #__modules WHERE published = 1 AND client_id = 0 ORDER BY id LIMIT 1')->loadResult();
$out['moduleWrongExpected'] = $w->setVisibilityIf('module', $module, 'published', '0', '1');
$out['moduleKept'] = (int) $db->setQuery('SELECT published FROM #__modules WHERE id = ' . $module)->loadResult();
$out['exact'] = $w->replaceMenuItemParams(141, $params, $params . ' ');
$out['exactBack'] = $w->replaceMenuItemParams(141, $params . ' ', $params) && $read() === $params;
file_put_contents('/tmp/snav-cas.json', json_encode($out));
PHP
ANSWER=$(docker exec "$P-joomla" sh -c 'cat /tmp/snav-cas.json && rm -f /tmp/snav-cas.json')
expect "the site's collation folds case, so a text compare would match" "$(field collationFolds)" 1
expect "params that differ only in case are not overwritten" "$(field caseOnly) $(field kept)" "false true"
expect "a module whose published is not the one expected is not written" "$(field moduleWrongExpected) $(field moduleKept)" "false 1"
expect "the exact bytes are written, and back" "$(field exact) $(field exactBack)" "true true"

finish
