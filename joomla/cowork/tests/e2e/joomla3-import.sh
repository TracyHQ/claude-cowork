#!/usr/bin/env bash
# End-to-end: Tracy's import of a Joomla 3 site, on a throwaway Joomla 3.10. Every action Tracy's
# live read (`live-pull.mjs`) makes answers at both doors, and — given the fleet's live-pull.mjs — a
# whole read of the site matches the live database and webroot. The writes ("Sync to live") are
# joomla3-write.sh's.
#
#   joomla/cowork/tests/e2e/joomla3-import.sh                                          # Joomla 3.10, PHP 7.4
#   JOOMLA_IMAGE=joomla:3.10-php8.0-apache joomla/cowork/tests/e2e/joomla3-import.sh   # Joomla 3.10, PHP 8.0
#   LIVE_PULL=/path/to/tracy/scripts/lib/live-pull.mjs joomla/cowork/tests/e2e/joomla3-import.sh
#   (KEEP: joomla-stand.sh)
#
# Needs docker and node. Nothing leaves this machine: with LIVE_PULL, the read runs in a node
# container on the stand's own network.
#
# ## Why
#
# Measured on 0.24.71, Joomla 3.10.11, PHP 7.4: the package installed and the engine answered reads,
# but Tracy's import stopped at its first call, `{"ok":false,"code":"ENGINE_REFUSED","detail":
# "core.manifest: unavailable"}`. live-pull.mjs probes each door with `core.manifest`, and the Joomla 3
# door wired no extension manager at all. Since 0.24.73 the Joomla 3 door runs the same
# EngineFactory::build() as Joomla 4–6, on Joomla 3's own database (legacy/LegacyDatabase.php).
#
# ## What it proves
#
# 1. At the front door and at the administrator door: core.manifest (platform joomla, platformVersion
#    3.10.x, core from `protected`), extension.list, info, db.tables, db.dump (paged by cursor),
#    db.dump_many, db.range_checksums, site.fingerprint, files.list, files.hash, files.pack and
#    file.read answer, and agree with what the database and the disk hold.
# 2. With LIVE_PULL: live-pull.mjs reads the whole site without a refusal; the dump, loaded into an
#    empty database, has the live site's tables and each table's row count; every file of the read
#    has the sha1 of the live file, and every live file outside what Tracy leaves out is in the read.
set -euo pipefail
E2E_NAME=j3import
JOOMLA_IMAGE="${JOOMLA_IMAGE:-joomla:3.10-php7.4-apache}"
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

FRONT='http://localhost/index.php?option=com_claudecowork&task=api.exec&format=json'
ADMIN='http://localhost/administrator/index.php?option=com_claudecowork&task=api.exec&format=json'

# knock <url> <action> <params JSON>: the answer's status in $STATUS, its body in $BODY.
STATUS= BODY=
knock() {
  local body out
  body=$(TOKEN="$TOKEN" node -e 'process.stdout.write(JSON.stringify({token: process.env.TOKEN, action: process.argv[1], params: JSON.parse(process.argv[2])}))' "$2" "$3")
  out=$(docker exec "$P-joomla" curl -sS -X POST -H 'Content-Type: application/json' --data-binary "$body" -w '\n%{http_code}' "$1")
  STATUS=${out##*$'\n'}
  BODY=${out%$'\n'*}
}
# A field of $BODY (a JS expression over `a`), or "not JSON: <start of body>".
# The body goes in on stdin: a db.dump page is far over what one argument may hold.
field() { printf %s "$BODY" | node -e 'const t = require("fs").readFileSync(0, "utf8"); let a; try { a = JSON.parse(t) } catch { process.stdout.write("not JSON: " + t.slice(0, 160).replace(/\s+/g, " ")); process.exit(0) }
  try { process.stdout.write(String(eval(process.argv[1]))) } catch (e) { process.stdout.write("unexpected answer: " + t.slice(0, 200)) }' "$1"; }

VERSION=$(docker exec "$P-joomla" php -r 'define("_JEXEC", 1); define("JPATH_PLATFORM", 1); require "libraries/src/Version.php"; echo Joomla\CMS\Version::MAJOR_VERSION . "." . Joomla\CMS\Version::MINOR_VERSION . "." . Joomla\CMS\Version::PATCH_VERSION;')
PHPV=$(docker exec "$P-joomla" php -r 'echo PHP_VERSION;')
PACKAGE=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$COWORK/pkg_claudecowork.xml" | head -1)
say "Joomla $VERSION on PHP $PHPV, package $PACKAGE"
expect "this is Joomla 3.10" "${VERSION%.*}" "3.10"

say "content enough to page: 2500 articles, a binary file and a file named in UTF-8"
sql "INSERT INTO jos_content (title, alias, introtext, \`fulltext\`, state, catid, created, created_by, modified, publish_up, publish_down, checked_out_time, images, urls, attribs, metakey, metadesc, metadata, language, xreference, access)
  SELECT CONCAT('Article ', seq), CONCAT('article-', seq), CONCAT('<p>Intro ', seq, ' — é ü 日本</p>'), '', 1, 2, NOW(), 42, NOW(), NOW(), '0000-00-00 00:00:00', '0000-00-00 00:00:00', '{}', '{}', '{}', '', '', '{}', '*', '', 1
  FROM seq_1_to_2500"
docker exec "$P-joomla" sh -c 'head -c 300000 /dev/urandom > images/blob.bin && printf "héllo\n" > "images/café ü.txt" && chown www-data:www-data images/blob.bin "images/café ü.txt"'
expect "the site holds 2500 articles" "$(sql 'SELECT COUNT(*) FROM jos_content')" "2500"

TABLES=$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'joomla'")
USERS=$(sql 'SELECT COUNT(*) FROM jos_users')
INDEX_SHA1=$(docker exec "$P-joomla" sha1sum index.php | cut -d' ' -f1)
BLOB_SHA1=$(docker exec "$P-joomla" sha1sum images/blob.bin | cut -d' ' -f1)

# The reads an import makes, at one door.
reads() {
  local door=$1 url=$2
  knock "$url" core.manifest '{}'
  expect "$door: core.manifest answers 200" "$STATUS" "200"
  expect "$door: core.manifest names Joomla $VERSION" "$(field 'a.ok + " " + a.platform + " " + a.manifest.platform + " " + a.manifest.platformVersion')" "true joomla joomla $VERSION"
  expect "$door: com_content is core (protected), com_claudecowork is not" \
    "$(field 'const x = (e) => a.manifest.extensions.find((r) => r.element === e && r.type === "component"); x("com_content").core + " " + x("com_claudecowork").core')" "true false"
  expect "$door: core.manifest has a row for each extension with an element" "$(field 'a.manifest.extensions.length')" \
    "$(sql "SELECT COUNT(*) FROM jos_extensions WHERE element <> ''")"
  expect "$door: as many core rows as Joomla 3 protects" "$(field 'a.manifest.extensions.filter((r) => r.core).length')" \
    "$(sql "SELECT COUNT(*) FROM jos_extensions WHERE element <> '' AND protected = 1")"
  knock "$url" extension.list '{}'
  expect "$door: extension.list answers every row, this package in its version" \
    "$(field 'a.ok + " " + a.extensions.length + " " + a.extensions.find((r) => r.element === "pkg_claudecowork").version')" \
    "true $(sql 'SELECT COUNT(*) FROM jos_extensions') $PACKAGE"
  knock "$url" info '{}'
  expect "$door: info names Joomla 3 and offers db.dump_many, site.fingerprint, files.hash" \
    "$(field 'a.ok + " " + a.info.joomla + " " + Boolean(a.info.db_dump_many && a.info.site_fingerprint && a.info.files_hash)')" "true $VERSION true"
  knock "$url" db.tables '{}'
  expect "$door: db.tables lists every table" "$(field 'a.ok + " " + a.tables.length')" "true $TABLES"
  knock "$url" db.dump '{"table":"jos_content","limit":2000,"cursor":""}'
  expect "$door: db.dump pages jos_content by key" "$(field 'a.ok + " " + a.rows + " " + a.total + " " + (a.next_cursor != null)')" "true 2000 2500 true"
  local cursor; cursor=$(field 'JSON.stringify(a.next_cursor)')
  knock "$url" db.dump "{\"table\":\"jos_content\",\"limit\":2000,\"cursor\":$cursor}"
  expect "$door: and its second page holds the rest" "$(field 'a.ok + " " + a.rows + " " + a.done')" "true 500 true"
  knock "$url" db.dump_many '{"tables":["jos_users","jos_extensions"]}'
  expect "$door: db.dump_many dumps the administrator's row" \
    "$(field 'a.ok + " " + a.done + " " + Buffer.from(a.sql_b64, "base64").toString().includes("admin@example.invalid")')" "true true true"
  # Two starts, three ranges: below the first, between, from the last on; each "rows:checksum".
  knock "$url" db.range_checksums '{"table":"jos_content","key":"id","starts":[1001,2001]}'
  expect "$door: db.range_checksums counts three ranges of 1000, 1000 and 500 rows" "$(field 'a.ok + " " + a.ranges.map((r) => r.split(":")[0]).join(",")')" "true 1000,1000,500"
  knock "$url" site.fingerprint '{"files":false,"checksums":true}'
  expect "$door: site.fingerprint checksums every table" "$(field 'a.ok + " " + Object.keys(a.fingerprint.tables).length')" "true $TABLES"
  knock "$url" files.list '{"limit":5}'
  expect "$door: files.list walks the webroot with sha1s" "$(field 'a.ok + " " + a.files.length + " " + /^[0-9a-f]{40}$/.test(a.files[0].sha1)')" "true 5 true"
  knock "$url" files.list '{"limit":5,"hash":false}'
  expect "$door: files.list without hashes" "$(field 'a.ok + " " + a.files.length')" "true 5"
  knock "$url" files.hash '{"paths":["index.php","images/blob.bin"]}'
  expect "$door: files.hash has the disk's sha1s" "$(field 'a.ok + " " + JSON.stringify(a.hashes)')" \
    "true {\"index.php\":\"$INDEX_SHA1\",\"images/blob.bin\":\"$BLOB_SHA1\"}"
  knock "$url" files.pack '{"inline":true,"target_bytes":8388608,"paths":["index.php","images/blob.bin"]}'
  expect "$door: files.pack packs the two files inline" "$(field 'a.ok + " " + a.files + " " + (typeof a.part_b64)')" "true 2 string"
  knock "$url" file.read '{"path":"images/blob.bin"}'
  expect "$door: file.read hands back the file" \
    "$(field 'a.ok + " " + a.sha1 + " " + require("crypto").createHash("sha1").update(Buffer.from(a.content_b64, "base64")).digest("hex")')" \
    "true $BLOB_SHA1 $BLOB_SHA1"
}

say "1. the reads at the front door"
reads front "$FRONT"
say "1. the reads at the administrator door"
reads admin "$ADMIN"

if [ -n "${LIVE_PULL:-}" ]; then
  say "2. Tracy's live read ($LIVE_PULL)"
  IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$P-joomla")
  mkdir -p "$WORK/pull"
  # The way webroot-sync.sh calls it: the endpoint and the token in the environment, a directory as argv.
  # Given the front door; the administrator door is checked alone further down.
  pull() {
    docker run --rm --network "$NET" --user "$(id -u):$(id -g)" \
      -v "$(dirname "$LIVE_PULL"):/pull:ro" -v "$WORK/pull:/out" \
      -e TRACY_SYNC_ENDPOINT="$1" -e TRACY_SYNC_TOKEN="$TOKEN" node:22-alpine node /pull/live-pull.mjs "/out/$2" 2>"$WORK/pull/$2.log" | tail -1
  }
  ANSWER=$(pull "http://$IP/index.php?option=com_claudecowork&task=api.exec&format=json" front)
  echo "  answer: ${ANSWER:0:300}"
  expect "live-pull reads the whole site" "$(node -e 'const a = JSON.parse(process.argv[1]); process.stdout.write(a.ok + " " + (a.code ?? "") + (a.detail ?? ""))' "$ANSWER")" "true "
  LIVE="$WORK/pull/front/live"

  # The copy's database: the dump loaded into an empty schema of the same server, row counts compared table by table.
  sql 'CREATE DATABASE copy'
  gunzip -c "$LIVE/db.sql.gz" | docker exec -i "$P-db" mariadb -uroot -p"$DBPW" copy
  counts() { docker exec "$P-db" mariadb -uroot -p"$DBPW" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$1' ORDER BY 1" |
    while read -r t; do printf '%s %s\n' "$t" "$(docker exec "$P-db" mariadb -uroot -p"$DBPW" -N -e "SELECT COUNT(*) FROM \`$1\`.\`$t\`")"; done; }
  LIVE_COUNTS=$(counts joomla)
  COPY_COUNTS=$(counts copy)
  expect "the copy has the live site's $TABLES tables" "$(printf '%s\n' "$COPY_COUNTS" | wc -l | tr -d ' ')" "$TABLES"
  # Tracy leaves the session table's rows out (TRACY_SYNC_VOLATILE, `session`): it is compared by name only.
  expect "and each table's row count (jos_session aside)" "$(printf '%s\n' "$COPY_COUNTS" | grep -v '^jos_session ')" "$(printf '%s\n' "$LIVE_COUNTS" | grep -v '^jos_session ')"
  expect "jos_content came across whole: 2500 rows" "$(printf '%s\n' "$COPY_COUNTS" | grep '^jos_content ')" "jos_content 2500"
  expect "and checksums the same as the live table" "$(sql 'CHECKSUM TABLE copy.jos_content' | cut -f2)" "$(sql 'CHECKSUM TABLE joomla.jos_content' | cut -f2)"

  # The copy's webroot: every file of the read has the live file's sha1, and so has every entry of the
  # manifest. The live files the read does not hold are named: the engine's walk leaves out the cache,
  # tmp and log folders (lib/FileWalker.php), and Tracy's own files are packed but not listed
  # (live-pull.mjs TRACY_PATHS).
  docker exec "$P-joomla" sh -c 'cd /var/www/html && find . -type f -print0 | xargs -0 sha1sum' > "$WORK/live.sha1"
  ( cd "$LIVE/tree" && find . -type f -print0 | xargs -0 sha1sum ) > "$WORK/read.sha1"
  FILES=$(node -e '
    const fs = require("fs")
    const sums = (f) => new Map(fs.readFileSync(f, "utf8").trim().split("\n").map((l) => [l.slice(42).replace(/^\.\//, ""), l.slice(0, 40)]))
    const live = sums(process.argv[1]), read = sums(process.argv[2])
    const manifest = JSON.parse(fs.readFileSync(process.argv[3], "utf8"))
    const differ = [...read].filter(([p, h]) => live.get(p) !== h).map(([p]) => p)
    const manifestOff = Object.entries(manifest).filter(([p, e]) => e.sha1 !== live.get(p) || e.sha1 !== read.get(p)).map(([p]) => p)
    const missing = [...live.keys()].filter((p) => !read.has(p)).sort()
    const tracy = [...read.keys()].filter((p) => !(p in manifest)).filter((p) => !/^(administrator\/)?components\/com_claudecowork\/|^plugins\/system\/(claudecoworkapi|claudecoworkupdate|tracyaccess)\//.test(p))
    console.log(JSON.stringify({live: live.size, read: read.size, manifest: Object.keys(manifest).length, differ, manifestOff, missing, unlisted: tracy}))
  ' "$WORK/live.sha1" "$WORK/read.sha1" "$LIVE/manifest.json")
  echo "  files: $(node -e 'const f = JSON.parse(process.argv[1]); process.stdout.write(`${f.live} live, ${f.read} read, ${f.manifest} in the manifest`)' "$FILES")"
  BODY=$FILES
  expect "every file read has the live file's sha1" "$(field 'a.differ.length + " " + a.differ.slice(0, 3)')" "0 "
  expect "every manifest entry has the live file's and the read file's sha1" "$(field 'a.manifestOff.length + " " + a.manifestOff.slice(0, 3)')" "0 "
  expect "the live files not read are the cache, tmp and log folders' placeholders" "$(field 'a.missing.join(" ")')" \
    "administrator/cache/index.html administrator/logs/index.html cache/index.html tmp/index.html"
  expect "a file read but not in the manifest is one of Tracy's own" "$(field 'a.unlisted.join(" ")')" ""
  expect "configuration.php and the two test files were read" \
    "$(grep -c -E '  \./(configuration\.php|images/blob\.bin|images/café ü\.txt)$' "$WORK/read.sha1")" "3"

  # The administrator door alone: the endpoint Tracy is given when the front door is gated.
  ANSWER=$(pull "http://$IP/administrator/index.php?option=com_claudecowork&task=api.exec&format=json" admin)
  echo "  answer: ${ANSWER:0:300}"
  expect "live-pull reads the whole site through the administrator door too" "$(node -e 'const a = JSON.parse(process.argv[1]); process.stdout.write(a.ok + " " + (a.code ?? "") + (a.detail ?? ""))' "$ANSWER")" "true "
  expect "the same manifest, file for file" "$(md5sum < "$WORK/pull/admin/live/manifest.json")" "$(md5sum < "$LIVE/manifest.json")"
  expect "the same database, the session table aside" "$(gunzip -c "$WORK/pull/admin/live/db.sql.gz" | grep -v '`jos_session`' | md5sum)" \
    "$(gunzip -c "$LIVE/db.sql.gz" | grep -v '`jos_session`' | md5sum)"
fi

finish
