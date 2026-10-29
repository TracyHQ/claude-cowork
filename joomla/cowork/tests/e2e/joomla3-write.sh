#!/usr/bin/env bash
# End-to-end: every write Tracy's "Sync to live" sends, and its undo, on a throwaway Joomla 3.10 —
# through both doors, read back from the database and from the Joomla administrator's own lists.
#
#   joomla/cowork/tests/e2e/joomla3-write.sh                                          # Joomla 3.10, PHP 7.4
#   JOOMLA_IMAGE=joomla:3.10-php8.0-apache joomla/cowork/tests/e2e/joomla3-write.sh   # Joomla 3.10, PHP 8.0
#   JOOMLA_IMAGE=joomla:5-apache joomla/cowork/tests/e2e/joomla3-write.sh             # the same writes on Joomla 4–6
#   TRACY_LIB=/path/to/tracy/scripts/lib joomla/cowork/tests/e2e/joomla3-write.sh     # and Tracy's own Sync to live
#   (KEEP: joomla-stand.sh)
#
# Needs docker and node. Nothing leaves this machine: the package extension.install fetches is served
# by an nginx on the stand's own network, over TLS from a CA made for this run.
#
# ## Why
#
# Until 0.24.73 the Joomla 3 door wired no writer, no apply log and no extension manager: every
# write answered 'unavailable', on a decision nobody had made (a code comment of 17/08/2026). Joomla
# 3 is supported as Joomla 4–6 are: the door runs the same EngineFactory::build() and the same
# writer, on Joomla 3's own database (administrator/legacy/LegacyDatabase.php).
#
# ## What it proves, at the front door and at the administrator door
#
# 1. content.update creates, updates and moves an article, a category, a menu item and a module;
#    the rows, their assets and the nested sets (categories, menu) are what Joomla's own forms
#    make, and the administrator's lists show them.
# 2. content.batch compare-and-set: a batch whose `expected` no longer holds is refused whole
#    (CONFLICT, nothing written, the trees untouched); one that holds is written whole.
# 3. content.delete trashes each of the four; media.upload and media.delete land and remove a
#    picture byte for byte; site.identity, template.siteSettings and template.files.write change
#    configuration.php, the template's favicon and a template file (a file not as expected is a
#    conflict, and template.files.undo takes a write back); extension.enable switches a plugin and refuses a core one; extension.install
#    installs a package from a pinned https URL.
# 4. apply.revert takes back every one of them (installs aside, as on Joomla 4+): the tables the
#    writes touched hold again exactly what they held before, file for file.
# 5. With TRACY_LIB (the fleet's scripts/lib): Tracy's own Sync to live — live-pull.mjs, live_sync.py,
#    live_push.py's changeset and live-write.mjs — writes a copy's new category, article, menu item,
#    module and picture, two changed titles and a deleted article to this site, and undoes them.
set -euo pipefail
E2E_NAME=${E2E_NAME:-j3write}
JOOMLA_IMAGE="${JOOMLA_IMAGE:-joomla:3.10-php7.4-apache}"
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

FRONT='http://localhost/index.php?option=com_claudecowork&task=api.exec&format=json'
ADMIN='http://localhost/administrator/index.php?option=com_claudecowork&task=api.exec&format=json'

# knock <url> <action> <params JSON>: the answer's status in $STATUS, its body in $BODY.
STATUS= BODY=
knock() {
  local out
  out=$(TOKEN="$TOKEN" node -e 'process.stdout.write(JSON.stringify({token: process.env.TOKEN, action: process.argv[1], params: JSON.parse(process.argv[2])}))' "$2" "$3" |
    docker exec -i "$P-joomla" curl -sS -X POST -H 'Content-Type: application/json' --data-binary @- -w '\n%{http_code}' "$1")
  STATUS=${out##*$'\n'}
  BODY=${out%$'\n'*}
}
# A field of $BODY (a JS expression over `a`), or "not JSON: <start of body>".
field() { printf %s "$BODY" | node -e 'const t = require("fs").readFileSync(0, "utf8"); let a; try { a = JSON.parse(t) } catch { process.stdout.write("not JSON: " + t.slice(0, 200).replace(/\s+/g, " ")); process.exit(0) }
  try { process.stdout.write(String(eval(process.argv[1]))) } catch (e) { process.stdout.write("unexpected answer: " + t.slice(0, 300)) }' "$1"; }
# The id the last answer created, or the answer itself when it did not.
created() { field 'a.ok && a.id ? a.id : JSON.stringify(a)'; }

MAJOR=$(docker exec "$P-joomla" sed -n 's/.*const MAJOR_VERSION = \([0-9]*\);.*/\1/p' libraries/src/Version.php)
TEMPLATE=$(sql "SELECT template FROM jos_template_styles WHERE client_id = 0 AND home = '1' LIMIT 1")
say "Joomla $MAJOR ($(docker exec "$P-joomla" php -r 'echo PHP_VERSION;')), site template $TEMPLATE"

# Every nested-set row inside its parent, and the root spanning the whole table: what Joomla's own
# rebuild would leave. Prints the rows that break it, nothing when the tree is whole.
tree_breaks() {
  sql "SELECT CONCAT('$1:', c.id) FROM $1 c LEFT JOIN $1 p ON p.id = c.parent_id
       WHERE c.id <> 1 AND (p.id IS NULL OR c.lft <= p.lft OR c.rgt >= p.rgt OR c.lft >= c.rgt OR c.level <> p.level + 1)
       UNION ALL SELECT CONCAT('$1:root') FROM $1 r WHERE r.id = 1 AND r.rgt <> (SELECT COUNT(*) * 2 - 1 FROM $1)"
}
# What the writes may touch, as one hash per table (the session and the apply log aside).
state() { sql "CHECKSUM TABLE jos_content, jos_categories, jos_menu, jos_modules, jos_modules_menu, jos_assets, jos_ucm_content" | md5sum | cut -c1-12; }

# ── the Joomla administrator, logged in once, for its lists ──────────────────────────────────────
docker exec "$P-joomla" sh -c '
  jar=/tmp/e2e-lists.cookies; admin=http://localhost/administrator/index.php; rm -f $jar
  token() { grep -o "name=\"[0-9a-f]\{32\}\" value=\"1\"" | head -1 | cut -d\" -f2; }
  form=$(curl -sS -c $jar -b $jar "$admin")
  curl -sS -o /dev/null -c $jar -b $jar "$admin" --data-urlencode username=admin --data-urlencode passwd=e2e-admin-password-1 \
    -d option=com_login -d task=login -d return="$(printf %s "$form" | grep -o "name=\"return\" value=\"[^\"]*\"" | cut -d\" -f4)" -d "$(printf %s "$form" | token)=1"'
# shown <list query> <title> <edit task> <id>: "shown" when the administrator's list, searched for the
# title, links that row's edit form (the search box echoes the title whether the row is there or not).
# Looked at up to three times, a second apart: once each on Joomla 3.10 / PHP 8.0 and on Joomla 5 a
# list missed a row its next load showed. A row still missing leaves the page in the work dir.
shown() {
  local try page
  for try in 1 2 3; do
    page=$(docker exec "$P-joomla" curl -sS -b /tmp/e2e-lists.cookies -c /tmp/e2e-lists.cookies \
      "http://localhost/administrator/index.php?$1&filter%5Bsearch%5D=$(node -e 'process.stdout.write(encodeURIComponent(process.argv[1]))' "$2")&filter%5Bpublished%5D=*&list%5Blimit%5D=100")
    if printf %s "$page" | grep -qE "task=$3\.edit&(amp;)?id=$4[^0-9]"; then echo shown; return; fi
    sleep 1
  done
  printf %s "$page" > "$WORK/not-shown-$3-$4.html"
  echo "not shown"
}

# ── a package to install, served over https from this stand's network ──────────────────────────
say "a module package, and an https host that serves it"
mkdir -p "$WORK/ext/mod_tracye2e" "$WORK/www" "$WORK/tls"
cat > "$WORK/ext/mod_tracye2e/mod_tracye2e.xml" <<'XML'
<?xml version="1.0" encoding="utf-8"?>
<extension type="module" version="3.1" client="site" method="upgrade">
    <name>mod_tracye2e</name>
    <version>1.0.0</version>
    <description>Installed by joomla3-write.sh</description>
    <files><filename module="mod_tracye2e">mod_tracye2e.php</filename></files>
</extension>
XML
printf '<?php\n\\defined("_JEXEC") or die;\necho "tracy e2e";\n' > "$WORK/ext/mod_tracye2e/mod_tracye2e.php"
docker run --rm -v "$WORK:/w" --entrypoint php "$JOOMLA_IMAGE" -r '$z = new ZipArchive(); $z->open("/w/www/mod_tracye2e.zip", ZipArchive::CREATE);
  foreach (["mod_tracye2e.xml", "mod_tracye2e.php"] as $f) $z->addFile("/w/ext/mod_tracye2e/$f", $f); $z->close();'
docker run --rm -v "$WORK/tls:/t" -w /t --entrypoint sh "$JOOMLA_IMAGE" -c '
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=cowork-e2e test CA" -keyout ca.key -out ca.crt 2>/dev/null &&
  openssl req -newkey rsa:2048 -nodes -subj "/CN=packages.example.org" -keyout leaf.key -out leaf.csr 2>/dev/null &&
  printf "subjectAltName=DNS:packages.example.org\n" > san.ext &&
  openssl x509 -req -in leaf.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 2 -extfile san.ext -out leaf.crt 2>/dev/null && chmod 644 *'
printf 'server { listen 443 ssl; server_name packages.example.org; ssl_certificate /tls/leaf.crt; ssl_certificate_key /tls/leaf.key; root /www; }\n' > "$WORK/nginx.conf"
docker run -d --name "$P-packages" --network "$NET" --network-alias packages.example.org \
  -v "$WORK/nginx.conf:/etc/nginx/conf.d/default.conf:ro" -v "$WORK/tls:/tls:ro" -v "$WORK/www:/www:ro" nginx:alpine >/dev/null
trap 'docker rm -f -v "$P-packages" >/dev/null 2>&1 || true; cleanup' EXIT
docker cp "$WORK/tls/ca.crt" "$P-joomla:/usr/local/share/ca-certificates/cowork-e2e.crt"
docker exec "$P-joomla" update-ca-certificates >/dev/null 2>&1
PKG_SHA=$(sha256sum "$WORK/www/mod_tracye2e.zip" | cut -d' ' -f1)
PKG_BYTES=$(wc -c < "$WORK/www/mod_tracye2e.zip" | tr -d ' ')
for _ in $(seq 1 20); do [ "$(docker exec "$P-joomla" curl -s -o /dev/null -w '%{http_code}' https://packages.example.org/mod_tracye2e.zip)" = 200 ] && break; sleep 1; done

# A picture to upload: Joomla's own logo, bytes known.
PICTURE=$(docker exec "$P-joomla" sh -c 'ls images/joomla_black.png images/joomla_black.gif images/powered_by.png 2>/dev/null | head -1')
PICTURE_B64=$(docker exec "$P-joomla" base64 -w0 "$PICTURE")
PICTURE_SHA=$(docker exec "$P-joomla" sha1sum "$PICTURE" | cut -d' ' -f1)
REDIRECT_BEFORE=$(sql "SELECT enabled FROM jos_extensions WHERE type = 'plugin' AND folder = 'system' AND element = 'redirect'")

# writes <door> <url>: every write at one door, then its undo.
writes() {
  local door=$1 url=$2 tag article category menu module before_all
  tag="E2E $door"
  before_all=$(state)

  say "$door: creates"
  knock "$url" content.update "{\"apply_id\":\"$door-create\",\"kind\":\"article\",\"id\":0,\"fields\":{\"title\":\"$tag article\",\"alias\":\"e2e-$door-article\",\"introtext\":\"<p>Written through the $door door</p>\",\"catid\":2,\"state\":1}}"
  article=$(created)
  knock "$url" content.update "{\"apply_id\":\"$door-create\",\"kind\":\"category\",\"id\":0,\"fields\":{\"title\":\"$tag category\",\"parent_id\":1}}"
  category=$(created)
  knock "$url" content.update "{\"apply_id\":\"$door-create\",\"kind\":\"menuItem\",\"id\":0,\"fields\":{\"title\":\"$tag menu item\",\"menutype\":\"mainmenu\",\"type\":\"component\",\"link\":\"index.php?option=com_content&view=article&id=$article\",\"parent_id\":1}}"
  menu=$(created)
  knock "$url" content.update "{\"apply_id\":\"$door-create\",\"kind\":\"module\",\"id\":0,\"fields\":{\"title\":\"$tag module\",\"module\":\"mod_custom\",\"position\":\"position-7\",\"content\":\"<p>m</p>\",\"published\":1,\"params\":\"{}\"}}"
  module=$(created)
  expect "$door: the four creates answer ids" "$([[ "$article$category$menu$module" =~ ^[0-9]+$ ]] && echo ids || echo "$article / $category / $menu / $module")" "ids"
  expect "$door: the article is published in its category, with an asset and an author" \
    "$(sql "SELECT CONCAT(state, ' ', catid, ' ', asset_id > 0, ' ', created_by > 0, ' ', title) FROM jos_content WHERE id = $article")" "1 2 1 1 $tag article"
  expect "$door: the category hangs under the root, with an asset and a path" \
    "$(sql "SELECT CONCAT(parent_id, ' ', level, ' ', asset_id > 0, ' ', path, ' ', extension) FROM jos_categories WHERE id = $category")" "1 1 1 e2e-$door-category com_content"
  expect "$door: the menu item links the article through com_content" \
    "$(sql "SELECT CONCAT(menutype, ' ', level, ' ', component_id = (SELECT extension_id FROM jos_extensions WHERE element = 'com_content' AND type = 'component'), ' ', published) FROM jos_menu WHERE id = $menu")" "mainmenu 1 1 1"
  expect "$door: the module is in its position" "$(sql "SELECT CONCAT(position, ' ', published, ' ', client_id) FROM jos_modules WHERE id = $module")" "position-7 1 0"
  expect "$door: both trees are whole" "$(tree_breaks jos_categories; tree_breaks jos_menu)" ""
  expect "$door: the administrator lists the article, the category, the menu item and the module" \
    "$(shown 'option=com_content&view=articles' "$tag article" article "$article") $(shown 'option=com_categories&view=categories&extension=com_content' "$tag category" category "$category") $(shown 'option=com_menus&view=items&menutype=mainmenu' "$tag menu item" item "$menu") $(shown 'option=com_modules&view=modules&client_id=0' "$tag module" module "$module")" \
    "shown shown shown shown"

  say "$door: updates and a move"
  knock "$url" content.update "{\"apply_id\":\"$door-update\",\"kind\":\"article\",\"id\":$article,\"fields\":{\"title\":\"$tag article v2\"}}"
  knock "$url" content.update "{\"apply_id\":\"$door-update\",\"kind\":\"category\",\"id\":$category,\"fields\":{\"title\":\"$tag category v2\"}}"
  knock "$url" content.update "{\"apply_id\":\"$door-update\",\"kind\":\"menuItem\",\"id\":$menu,\"fields\":{\"title\":\"$tag menu item v2\"}}"
  knock "$url" content.update "{\"apply_id\":\"$door-update\",\"kind\":\"module\",\"id\":$module,\"fields\":{\"title\":\"$tag module v2\"}}"
  knock "$url" content.update "{\"apply_id\":\"$door-update\",\"kind\":\"category\",\"id\":$category,\"fields\":{\"parent_id\":2}}"
  expect "$door: the four titles changed" \
    "$(sql "SELECT CONCAT_WS('|', (SELECT title FROM jos_content WHERE id = $article), (SELECT title FROM jos_categories WHERE id = $category), (SELECT title FROM jos_menu WHERE id = $menu), (SELECT title FROM jos_modules WHERE id = $module))")" \
    "$tag article v2|$tag category v2|$tag menu item v2|$tag module v2"
  expect "$door: the category moved under Uncategorised, its tree whole" "$(sql "SELECT CONCAT(parent_id, ' ', level) FROM jos_categories WHERE id = $category") $(tree_breaks jos_categories)" "2 2 "
  knock "$url" content.get "{\"kind\":\"article\",\"id\":$article}"
  expect "$door: content.get reads the article as written" "$(field 'a.ok + " " + a.item.title')" "true $tag article v2"

  say "$door: content.batch compare-and-set"
  local before trees
  before=$(state)
  knock "$url" content.batch "{\"apply_id\":\"$door-batch\",\"request_id\":\"$door-conflict\",\"operations\":[{\"key\":\"c\",\"kind\":\"category\",\"id\":0,\"fields\":{\"title\":\"$tag batch category\",\"parent_id\":1}},{\"kind\":\"article\",\"id\":0,\"fields\":{\"title\":\"$tag batch article\",\"alias\":\"e2e-$door-batch\",\"introtext\":\"x\",\"catid\":\"\$c\",\"state\":1}},{\"kind\":\"article\",\"id\":$article,\"fields\":{\"title\":\"lost\"},\"expected\":{\"title\":\"$tag article\"}}]}"
  # The words Tracy's live-write.mjs reads to know nothing of the batch stayed (`every write … taken back`).
  expect "$door: a batch whose expected value moved is refused as a conflict, every write taken back" "$STATUS $BODY" "200 {\"ok\":false,\"error\":\"batch_failed\",\"message\":\"conflict at operation 2: title changed; every write of this batch was taken back\"}"
  expect "$door: and nothing of it was written, the new category and article included" "$(state) $(tree_breaks jos_categories)" "$before "
  knock "$url" content.batch "{\"apply_id\":\"$door-batch\",\"request_id\":\"$door-holds\",\"operations\":[{\"kind\":\"article\",\"id\":$article,\"fields\":{\"title\":\"$tag article v3\"},\"expected\":{\"title\":\"$tag article v2\"}},{\"kind\":\"module\",\"id\":$module,\"fields\":{\"title\":\"$tag module v3\"},\"expected\":{\"title\":\"$tag module v2\"}}]}"
  expect "$door: a batch whose expected values hold is written whole" "$(field 'a.ok') $(sql "SELECT CONCAT((SELECT title FROM jos_content WHERE id = $article), '|', (SELECT title FROM jos_modules WHERE id = $module))")" \
    "true $tag article v3|$tag module v3"
  knock "$url" content.batch "{\"apply_id\":\"$door-batch\",\"request_id\":\"$door-holds\",\"operations\":[{\"kind\":\"article\",\"id\":$article,\"fields\":{\"title\":\"$tag article v3\"},\"expected\":{\"title\":\"$tag article v2\"}},{\"kind\":\"module\",\"id\":$module,\"fields\":{\"title\":\"$tag module v3\"},\"expected\":{\"title\":\"$tag module v2\"}}]}"
  expect "$door: the same request_id again answers what it answered, writing nothing twice" "$(field 'a.ok')" "true"

  say "$door: deletes"
  knock "$url" content.delete "{\"apply_id\":\"$door-delete\",\"kind\":\"article\",\"id\":$article}"
  knock "$url" content.delete "{\"apply_id\":\"$door-delete\",\"kind\":\"menuItem\",\"id\":$menu}"
  knock "$url" content.delete "{\"apply_id\":\"$door-delete\",\"kind\":\"module\",\"id\":$module}"
  knock "$url" content.delete "{\"apply_id\":\"$door-delete\",\"kind\":\"category\",\"id\":$category}"
  expect "$door: the four are in the trash" \
    "$(sql "SELECT CONCAT_WS(' ', (SELECT state FROM jos_content WHERE id = $article), (SELECT published FROM jos_categories WHERE id = $category), (SELECT published FROM jos_menu WHERE id = $menu), (SELECT published FROM jos_modules WHERE id = $module))")" "-2 -2 -2 -2"

  say "$door: a picture, the site name, a template file"
  knock "$url" media.upload "{\"apply_id\":\"$door-media\",\"path\":\"images/tracy-e2e/$door.png\",\"content_b64\":\"$PICTURE_B64\"}"
  expect "$door: media.upload lands the picture byte for byte" "$(field 'a.ok') $(docker exec "$P-joomla" sha1sum "images/tracy-e2e/$door.png" | cut -d' ' -f1)" "true $PICTURE_SHA"
  knock "$url" media.delete "{\"apply_id\":\"$door-media\",\"path\":\"images/tracy-e2e/$door.png\"}"
  expect "$door: media.delete removes it" "$(field 'a.ok') $(docker exec "$P-joomla" sh -c "test -e images/tracy-e2e/$door.png && echo there || echo gone")" "true gone"
  knock "$url" site.identity "{\"operation\":\"set\",\"apply_id\":\"$door-settings\",\"fields\":{\"sitename\":\"$tag site\"}}"
  expect "$door: site.identity renames the site in configuration.php" "$(field 'a.ok') $(docker exec "$P-joomla" php -r 'require "configuration.php"; echo (new JConfig())->sitename;')" "true $tag site"
  local css b64 sha
  css="/* $tag */"
  b64=$(printf %s "$css" | base64 -w0); sha=$(printf %s "$css" | sha256sum | cut -d' ' -f1)
  knock "$url" template.files.write "{\"apply_id\":\"$door-settings\",\"files\":[{\"path\":\"templates/$TEMPLATE/css/tracy-e2e.css\",\"expected_sha1\":null,\"content_b64\":\"$b64\",\"sha256\":\"$sha\"}]}"
  expect "$door: template.files.write writes a file of the site template" "$(field 'a.ok') $(docker exec "$P-joomla" cat "templates/$TEMPLATE/css/tracy-e2e.css")" "true $css"
  # Other bytes than the file holds: a write of the bytes already there is `unchanged`, whatever was expected.
  knock "$url" template.files.write "{\"apply_id\":\"$door-tfiles\",\"files\":[{\"path\":\"templates/$TEMPLATE/css/tracy-e2e.css\",\"expected_sha1\":\"$(printf '0%.0s' $(seq 40))\",\"content_b64\":\"$(printf 'other' | base64 -w0)\",\"sha256\":\"$(printf 'other' | sha256sum | cut -d' ' -f1)\"}]}"
  expect "$door: template.files.write refuses a file that is not what the caller expected" "$(field 'a.ok + " " + a.error + " " + a.code')" "false conflict CONFLICT"
  knock "$url" template.files.write "{\"apply_id\":\"$door-tfiles\",\"files\":[{\"path\":\"templates/$TEMPLATE/css/tracy-e2e-undo.css\",\"expected_sha1\":null,\"content_b64\":\"$b64\",\"sha256\":\"$sha\"}]}"
  knock "$url" template.files.undo "{\"apply_id\":\"$door-tfiles\"}"
  expect "$door: template.files.undo takes its write back" "$(field 'a.ok + " " + a.complete + " " + a.restored.map((r) => r.op).join(",")') $(docker exec "$P-joomla" sh -c "test -e templates/$TEMPLATE/css/tracy-e2e-undo.css && echo there || echo gone")" "true true removed gone"
  knock "$url" media.upload "{\"apply_id\":\"$door-settings\",\"path\":\"images/tracy-e2e/favicon-$door.png\",\"content_b64\":\"$PICTURE_B64\"}"
  knock "$url" template.siteSettings "{\"operation\":\"set\",\"apply_id\":\"$door-settings\",\"template\":\"$TEMPLATE\",\"fields\":{\"other_faviconFile\":\"images/tracy-e2e/favicon-$door.png\"}}"
  expect "$door: template.siteSettings sets the template's favicon" "$(field 'a.ok + " " + a.settings.other_faviconFile')" "true images/tracy-e2e/favicon-$door.png"
  knock "$url" template.siteSettings "{\"template\":\"$TEMPLATE\"}"
  expect "$door: and reads it back" "$(field 'a.ok + " " + a.settings.other_faviconFile')" "true images/tracy-e2e/favicon-$door.png"

  say "$door: extensions"
  knock "$url" extension.enable "{\"apply_id\":\"$door-ext\",\"type\":\"plugin\",\"element\":\"redirect\",\"folder\":\"system\",\"enabled\":true}"
  expect "$door: extension.enable switches System - Redirect on" "$(field 'a.ok') $(sql "SELECT enabled FROM jos_extensions WHERE type = 'plugin' AND folder = 'system' AND element = 'redirect'")" "true 1"
  knock "$url" extension.enable "{\"apply_id\":\"$door-ext\",\"type\":\"plugin\",\"element\":\"joomla\",\"folder\":\"authentication\",\"enabled\":false}"
  expect "$door: and refuses to switch a core plugin off (Authentication - Joomla)" "$(field 'a.ok + " " + a.error') $(sql "SELECT enabled FROM jos_extensions WHERE type = 'plugin' AND folder = 'authentication' AND element = 'joomla'")" "false refused 1"
  say "$door: undo, newest first"
  for apply in ext settings media delete batch update create; do
    knock "$url" apply.revert "{\"apply_id\":\"$door-$apply\"}"
    expect "$door: apply.revert $door-$apply" "$(field 'a.ok')" "true"
  done
  expect "$door: the four rows are gone again, the trees whole" \
    "$(sql "SELECT (SELECT COUNT(*) FROM jos_content WHERE id = $article) + (SELECT COUNT(*) FROM jos_categories WHERE id = $category) + (SELECT COUNT(*) FROM jos_menu WHERE id = $menu) + (SELECT COUNT(*) FROM jos_modules WHERE id = $module)") $(tree_breaks jos_categories; tree_breaks jos_menu)" "0 "
  expect "$door: the administrator lists none of them" \
    "$(shown 'option=com_content&view=articles' "$tag article" article "$article") $(shown 'option=com_categories&view=categories&extension=com_content' "$tag category" category "$category") $(shown 'option=com_menus&view=items&menutype=mainmenu' "$tag menu item" item "$menu") $(shown 'option=com_modules&view=modules&client_id=0' "$tag module" module "$module")" \
    "not shown not shown not shown not shown"
  knock "$url" template.siteSettings "{\"template\":\"$TEMPLATE\"}"
  expect "$door: the favicon setting and its picture are gone again" "$(field 'a.ok + " [" + a.settings.other_faviconFile + "]"') $(docker exec "$P-joomla" sh -c "test -e images/tracy-e2e/favicon-$door.png && echo there || echo gone")" "true [] gone"
  expect "$door: the site name, the template file and the redirect plugin are back" \
    "$(docker exec "$P-joomla" php -r 'require "configuration.php"; echo (new JConfig())->sitename;') $(docker exec "$P-joomla" sh -c "test -e templates/$TEMPLATE/css/tracy-e2e.css && echo there || echo gone") $(sql "SELECT enabled FROM jos_extensions WHERE type = 'plugin' AND folder = 'system' AND element = 'redirect'")" \
    "cowork-e2e gone $REDIRECT_BEFORE"
  expect "$door: every table the writes touched holds what it held before" "$(state)" "$before_all"

  # Last, after the undo: an install is not in the undo log, on Joomla 4+ as here.
  say "$door: extension.install"
  knock "$url" extension.install "{\"apply_id\":\"$door-ext\",\"url\":\"https://packages.example.org/mod_tracye2e.zip\",\"sha256\":\"$PKG_SHA\",\"bytes\":$PKG_BYTES}"
  expect "$door: extension.install installs the pinned package" "$(field 'a.ok + " " + (a.installed && a.installed.version)') $(sql "SELECT COUNT(*) FROM jos_extensions WHERE element = 'mod_tracye2e'") $(docker exec "$P-joomla" test -f modules/mod_tracye2e/mod_tracye2e.php && echo file)" \
    "true 1.0.0 1 file"
  knock "$url" extension.install "{\"apply_id\":\"$door-ext\",\"url\":\"https://packages.example.org/mod_tracye2e.zip\",\"sha256\":\"$(printf '0%.0s' $(seq 64))\"}"
  expect "$door: and refuses one whose bytes are not the pinned ones" "$(field 'a.ok + " " + a.error')" "false install_failed"

  # Taken away again by hand for the next door: the extension and the module Joomla made for it.
  sql "DELETE a FROM jos_assets a JOIN jos_modules m ON a.name = CONCAT('com_modules.module.', m.id) WHERE m.module = 'mod_tracye2e'"
  sql "DELETE FROM jos_modules WHERE module = 'mod_tracye2e'; DELETE FROM jos_extensions WHERE element = 'mod_tracye2e'"
  docker exec "$P-joomla" rm -rf modules/mod_tracye2e
}

# core.upgrade: Tracy upgrades its own copy, never the live site; on Joomla 3 the door says so.
if [ "$MAJOR" = 3 ]; then
  for door in "$FRONT" "$ADMIN"; do
    knock "$door" core.upgrade '{"to":"4.4","step":"prepare"}'
    expect "core.upgrade on Joomla 3 is unavailable, saying why" "$STATUS $BODY" \
      '200 {"ok":false,"error":"unavailable","message":"core upgrade is not available on Joomla 3: Tracy upgrades its own copy of the site, not the live site"}'
  done
fi

writes front "$FRONT"
writes admin "$ADMIN"

# ── Tracy's own Sync to live, end to end (TRACY_LIB: the fleet's scripts/lib) ──────────────────
# As webroot-live-push.sh runs it: live-pull.mjs reads the site (the base); the read is loaded twice,
# as the base and as the copy, each normalised by live_sync.py; the copy and a git repository of the
# webroot change as a Tracy copy would; live_push.py builds the changeset; live-write.mjs applies it
# and undoes it. On this machine: node, python3 and git, and the stand's address reachable from here.
if [ -n "${TRACY_LIB:-}" ]; then
  say "Tracy's Sync to live ($TRACY_LIB)"
  SYNC="$WORK/sync"; mkdir -p "$SYNC/preview" "$SYNC/push/0f8e2c1a-1111-4222-8333-000000000001"
  IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$P-joomla")
  export TRACY_SYNC_ENDPOINT="http://$IP/index.php?option=com_claudecowork&task=api.exec&format=json" TRACY_SYNC_TOKEN="$TOKEN"
  export TRACY_SYNC_SQL="[\"docker\",\"exec\",\"-i\",\"$P-db\",\"mariadb\",\"-uroot\",\"-p$DBPW\"]"
  # What the live site holds before the read: rows to update, to delete, and to clone as the copy's new ones.
  seed() { knock "$FRONT" content.update "{\"apply_id\":\"sync-seed\",\"kind\":\"$1\",\"id\":0,\"fields\":$2}"; created; }
  scat=$(seed category '{"title":"Sync seed category","parent_id":1}')
  sart=$(seed article "{\"title\":\"Sync seed article\",\"alias\":\"sync-seed-article\",\"introtext\":\"<p>seed</p>\",\"catid\":$scat,\"state\":1}")
  sdoomed=$(seed article '{"title":"Sync doomed article","alias":"sync-doomed-article","introtext":"<p>doomed</p>","catid":2,"state":1}')
  smenu=$(seed menuItem "{\"title\":\"Sync seed item\",\"menutype\":\"mainmenu\",\"type\":\"component\",\"link\":\"index.php?option=com_content&view=article&id=$sart\",\"parent_id\":1}")
  smod=$(seed module '{"title":"Sync seed module","module":"mod_custom","position":"position-7","content":"<p>seed</p>","published":1,"params":"{}"}')
  expect "sync: the live site's seed rows" "$([[ "$scat$sart$sdoomed$smenu$smod" =~ ^[0-9]+$ ]] && echo ids || echo "$scat $sart $sdoomed $smenu $smod")" "ids"

  ANSWER=$(node "$TRACY_LIB/live-pull.mjs" "$SYNC/read" 2>"$SYNC/pull.log" | tail -1)
  expect "sync: live-pull.mjs reads the site" "$(node -e 'const a = JSON.parse(process.argv[1]); process.stdout.write(a.ok + " " + (a.code ?? ""))' "$ANSWER")" "true "
  sql "CREATE DATABASE tbase; CREATE DATABASE tcopy"
  for schema in tbase tcopy; do
    gunzip -c "$SYNC/read/live/db.sql.gz" | docker exec -i "$P-db" mariadb -uroot -p"$DBPW" "$schema"
    python3 "$TRACY_LIB/live_sync.py" normalise --schema "$schema" --platform joomla --prefix jos_ > "$SYNC/normalise-$schema.json"
  done
  # The copy: a category, an article in it, a menu item to it and a module, each cloned from a seed row
  # with a new number; two titles changed; one article gone.
  clone() { # table id new-id "SET …"
    sql "USE tcopy; CREATE TEMPORARY TABLE c AS SELECT * FROM $1 WHERE id = $2; UPDATE c SET id = $3, $4; INSERT INTO $1 SELECT * FROM c"
  }
  clone jos_categories "$scat" 900 "title = 'Pushed category', alias = 'pushed-category', path = 'pushed-category', asset_id = 0, lft = 9000, rgt = 9001"
  clone jos_content "$sart" 900 "title = 'Pushed article', alias = 'pushed-article', catid = 900, asset_id = 0"
  clone jos_menu "$smenu" 900 "title = 'Pushed menu item', alias = 'pushed-menu-item', path = 'pushed-menu-item', link = 'index.php?option=com_content&view=article&id=900', lft = 9000, rgt = 9001"
  clone jos_modules "$smod" 900 "title = 'Pushed module', asset_id = 0"
  sql "USE tcopy; UPDATE jos_content SET title = 'Sync seed article, pushed' WHERE id = $sart; UPDATE jos_modules SET title = 'Sync seed module, pushed' WHERE id = $smod; DELETE FROM jos_content WHERE id = $sdoomed"
  mkdir -p "$SYNC/repo"; cp -a "$SYNC/read/live/tree/." "$SYNC/repo/"
  git -C "$SYNC/repo" init -q && git -C "$SYNC/repo" add -A && git -C "$SYNC/repo" -c user.email=e2e@example.invalid -c user.name=e2e commit -qm base
  BASE_REV=$(git -C "$SYNC/repo" rev-parse HEAD)
  mkdir -p "$SYNC/repo/images/pushed"; docker exec "$P-joomla" cat "$PICTURE" > "$SYNC/repo/images/pushed/picture.png"
  git -C "$SYNC/repo" add -A && git -C "$SYNC/repo" -c user.email=e2e@example.invalid -c user.name=e2e commit -qm copy

  node "$TRACY_LIB/live-write.mjs" info 2>/dev/null | tail -1 > "$SYNC/preview/engine.json"
  python3 "$TRACY_LIB/live_push.py" changeset --base-schema tbase --copy-schema tcopy --platform joomla --prefix jos_ \
    --id-map "$SYNC/id-map.json" --repo "$SYNC/repo/.git" --base-rev "$BASE_REV" --copy-rev "$(git -C "$SYNC/repo" rev-parse HEAD)" \
    --engine "$SYNC/preview/engine.json" > "$SYNC/preview/changeset.json" 2>"$SYNC/changeset.log"
  expect "sync: live_push.py's changeset names the eight changes" \
    "$(node -e 'const c = JSON.parse(require("fs").readFileSync(process.argv[1])); process.stdout.write(c.ok + " " + (c.items || []).map((i) => i.kind + ":" + i.op).sort().join(","))' "$SYNC/preview/changeset.json")" \
    "true article:create,article:delete,article:update,category:create,file:upload,menuItem:create,module:create,module:update"
  PUSH="$SYNC/push/0f8e2c1a-1111-4222-8333-000000000001"
  cp "$SYNC/preview/changeset.json" "$PUSH/changeset.json"
  printf '{"pushId":"0f8e2c1a-1111-4222-8333-000000000001","platform":"joomla"}' > "$PUSH/push.json"

  BODY=$(node "$TRACY_LIB/live-write.mjs" apply "$PUSH" "$PUSH" --limit 200 --webroot "$SYNC/repo" --confirm-deletes 2>"$SYNC/apply.log" | tail -1)
  expect "sync: live-write.mjs applies all eight" "$(field 'a.ok + " " + a.state + " " + (a.written || []).length')" "true applied 8"
  ncat=$(sql "SELECT id FROM jos_categories WHERE title = 'Pushed category'")
  nart=$(sql "SELECT id FROM jos_content WHERE title = 'Pushed article'")
  expect "sync: the live site holds the new category, its article, the menu item to it and the module, in its own numbers" \
    "$(sql "SELECT CONCAT_WS(' | ', (SELECT CONCAT(parent_id, ' ', published) FROM jos_categories WHERE id = $ncat), (SELECT CONCAT(catid = $ncat, ' ', state) FROM jos_content WHERE id = $nart), (SELECT link FROM jos_menu WHERE title = 'Pushed menu item'), (SELECT CONCAT(position, ' ', published) FROM jos_modules WHERE title = 'Pushed module'))")" \
    "1 1 | 1 1 | index.php?option=com_content&view=article&id=$nart | position-7 1"
  expect "sync: the two titles changed, the doomed article is in the trash, the picture landed byte for byte" \
    "$(sql "SELECT CONCAT_WS(' | ', (SELECT title FROM jos_content WHERE id = $sart), (SELECT title FROM jos_modules WHERE id = $smod), (SELECT state FROM jos_content WHERE id = $sdoomed))") $(docker exec "$P-joomla" sha1sum images/pushed/picture.png | cut -d' ' -f1)" \
    "Sync seed article, pushed | Sync seed module, pushed | -2 $PICTURE_SHA"
  expect "sync: both trees are whole" "$(tree_breaks jos_categories; tree_breaks jos_menu)" ""

  BODY=$(node "$TRACY_LIB/live-write.mjs" undo "$PUSH" 2>"$SYNC/undo.log" | tail -1)
  expect "sync: live-write.mjs undoes all eight" "$(field 'a.ok + " " + a.state + " " + (a.restored || []).length + " " + (a.kept || []).length')" "true undone 8 0"
  expect "sync: the titles and the doomed article are back, the four new rows in the trash, the picture gone" \
    "$(sql "SELECT CONCAT_WS(' | ', (SELECT title FROM jos_content WHERE id = $sart), (SELECT title FROM jos_modules WHERE id = $smod), (SELECT state FROM jos_content WHERE id = $sdoomed), (SELECT published FROM jos_categories WHERE id = $ncat), (SELECT state FROM jos_content WHERE id = $nart), (SELECT published FROM jos_menu WHERE title = 'Pushed menu item'), (SELECT published FROM jos_modules WHERE title = 'Pushed module'))") $(docker exec "$P-joomla" sh -c 'test -e images/pushed/picture.png && echo there || echo gone')" \
    "Sync seed article | Sync seed module | 1 | -2 | -2 | -2 | -2 gone"
  expect "sync: both trees are whole" "$(tree_breaks jos_categories; tree_breaks jos_menu)" ""
fi

finish
