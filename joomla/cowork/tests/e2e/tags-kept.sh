#!/usr/bin/env bash
# End-to-end: a content write that does not name an article's tags keeps them, on a throwaway Joomla.
#
#   joomla/cowork/tests/e2e/tags-kept.sh                 # Joomla 6
#   JOOMLA_IMAGE=joomla:5-apache joomla/cowork/tests/e2e/tags-kept.sh
#   KEEP=1 joomla/cowork/tests/e2e/tags-kept.sh          # leave the containers up to look around
#
# Needs docker and node. Nothing leaves this machine: every container and network is made here and
# removed at the end.
#
# ## Why a real Joomla
#
# The bug lives in Joomla, not in lib/: Joomla's Taggable behaviour reads a Table\Content store with
# no `newTags` as "every tag removed" and deletes the article's #__contentitem_tag_map rows. A write
# through the door (content.update, a contract apply, an apply.revert) that names only the words
# took a JA Podcast site's tag map from 190 rows to 0 (measured 08/10/2026, fixed in 0.24.1 by
# JoomlaSiteWriter handing the behaviour the tags the article already has). tests/run.php never
# reaches JoomlaSiteWriter, so this is the test that would have caught it (TCH #1013, D3).
#
# ## What it proves
#
# Tags and a category made through the door, an article in that category with two tags, then:
# 1. a content.update of the article's title alone keeps both tags and its category;
# 2. a content.batch writing the article's words keeps them;
# 3. apply.revert of both (which writes the old title back, naming no tags) keeps them;
# 4. a content.update naming `tags` still replaces them: the fix keeps tags, it does not freeze them;
# 5. a write to the category keeps the article's category and tags.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
COWORK="$(cd "$HERE/../.." && pwd)"
REPO="$(cd "$COWORK/../.." && pwd)"
IMAGE="${JOOMLA_IMAGE:-joomla:6-apache}"
P="${E2E_PREFIX:-cowork-e2e-tags}"
NET="$P-net"
DBPW=e2e-root-pw
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cowork-e2e-tags.XXXXXX")"
FAILED=0

cleanup() {
  if [ "${KEEP:-}" = 1 ]; then
    echo "KEEP=1: containers $P-* and network $NET left up; work dir $WORK"
    return
  fi
  docker rm -f "$P-joomla" "$P-db" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

say() { printf '\n== %s\n' "$*"; }

say "building this checkout"
mkdir -p "$WORK/src/joomla" "$WORK/src/scripts"
cp "$REPO/scripts/release-manifest.mjs" "$WORK/src/scripts/"
rsync -a --exclude build --exclude dist --exclude 'com_claudecowork/administrator/lib' \
  --exclude 'com_claudecowork/administrator/tracy-release.json' --exclude tests "$COWORK/" "$WORK/src/joomla/cowork/"
( cd "$WORK/src/joomla/cowork" && ./build.sh >/dev/null )

say "network, database, Joomla ($IMAGE)"
docker network create --subnet "${E2E_SUBNET:-10.251.78.0/24}" "$NET" >/dev/null
docker run -d --name "$P-db" --network "$NET" -e MARIADB_ROOT_PASSWORD="$DBPW" -e MARIADB_DATABASE=joomla mariadb:11 >/dev/null
for _ in $(seq 1 60); do docker exec "$P-db" mariadb -uroot -p"$DBPW" -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
docker run -d --name "$P-joomla" --network "$NET" \
  -e JOOMLA_DB_HOST="$P-db" -e JOOMLA_DB_USER=root -e JOOMLA_DB_PASSWORD="$DBPW" -e JOOMLA_DB_NAME=joomla \
  -e JOOMLA_DB_PREFIX=jos_ -e JOOMLA_SITE_NAME=cowork-e2e -e JOOMLA_ADMIN_USER=Admin -e JOOMLA_ADMIN_USERNAME=admin \
  -e JOOMLA_ADMIN_PASSWORD=e2e-admin-password-1 -e JOOMLA_ADMIN_EMAIL=admin@example.invalid "$IMAGE" >/dev/null
for _ in $(seq 1 180); do
  docker exec "$P-joomla" sh -c 'test -f configuration.php && curl -fsS -o /dev/null http://localhost/' >/dev/null 2>&1 && break
  sleep 2
done
docker exec "$P-joomla" test -f configuration.php || { echo "Joomla did not install itself" >&2; docker logs --tail 30 "$P-joomla" >&2; exit 1; }

sql() { docker exec "$P-db" mariadb -uroot -p"$DBPW" joomla -N -e "$1"; }

say "installing the package"
docker cp "$WORK/src/joomla/cowork/dist/pkg_claudecowork.zip" "$P-joomla:/tmp/pkg_claudecowork.zip"
docker exec -u www-data "$P-joomla" php cli/joomla.php extension:install --path=/tmp/pkg_claudecowork.zip 2>&1 | tail -1
TOKEN=$(sql "SELECT JSON_VALUE(params,'\$.token') FROM jos_extensions WHERE element='com_claudecowork'")
[ -n "$TOKEN" ] && [ "$TOKEN" != NULL ] || { echo "the install wrote no token" >&2; exit 1; }

# One door call: action and params (a JSON object) in, the answer's JSON in $ANSWER. Fails the run
# when the answer is not ok, printing it. Not called inside $(…): an exit there would end only the
# subshell, and bash 3.2 brace-expands a double-quoted JSON argument nested in $(…) inside quotes.
ANSWER=
call() {
  local body
  body=$(TOKEN="$TOKEN" node -e 'process.stdout.write(JSON.stringify({token: process.env.TOKEN, action: process.argv[1], params: JSON.parse(process.argv[2])}))' "$1" "$2")
  ANSWER=$(docker exec "$P-joomla" curl -sS -X POST -H 'Content-Type: application/json' --data-binary "$body" \
    'http://localhost/index.php?option=com_claudecowork&task=api.exec&format=json')
  node -e 'const a = JSON.parse(process.argv[1]); if (!a.ok) { console.error(JSON.stringify(a)); process.exit(1) }' "$ANSWER" \
    || { echo "$1 was refused" >&2; exit 1; }
}
# The id the last call answered.
answered_id() { node -e 'process.stdout.write(String(JSON.parse(process.argv[1]).id))' "$ANSWER"; }

# The article's tag ids, sorted, comma-separated; and its category.
tags_of() { sql "SELECT IFNULL(GROUP_CONCAT(tag_id ORDER BY tag_id),'') FROM jos_contentitem_tag_map WHERE type_alias='com_content.article' AND content_item_id=$1"; }
category_of() { sql "SELECT catid FROM jos_content WHERE id=$1"; }

expect() {
  if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1"; echo "       got : $2"; echo "       want: $3"; FAILED=$((FAILED + 1)); fi
}

say "tags, a category and a tagged article, made through the door"
call content.update '{"apply_id":"e2e-setup","kind":"tag","fields":{"title":"Alpha"}}'; ALPHA=$(answered_id)
call content.update '{"apply_id":"e2e-setup","kind":"tag","fields":{"title":"Beta"}}'; BETA=$(answered_id)
call content.update '{"apply_id":"e2e-setup","kind":"tag","fields":{"title":"Gamma"}}'; GAMMA=$(answered_id)
call content.update '{"apply_id":"e2e-setup","kind":"category","fields":{"title":"Kept news"}}'; CAT=$(answered_id)
call content.update "$(printf '{"apply_id":"e2e-setup","kind":"article","fields":{"title":"Demo episode","catid":%s,"state":1,"introtext":"<p>Demo words.</p>","tags":["Alpha","Beta"]}}' "$CAT")"
ART=$(answered_id)
TAGGED=$(printf '%s\n' "$ALPHA" "$BETA" | sort -n | paste -sd, -)
expect "the article starts with two tags" "$(tags_of "$ART")" "$TAGGED"
expect "and in its category" "$(category_of "$ART")" "$CAT"

say "1. content.update of the title alone"
call content.update "$(printf '{"apply_id":"e2e-words","kind":"article","id":%s,"fields":{"title":"Our first episode"}}' "$ART")"
expect "the title changed" "$(sql "SELECT title FROM jos_content WHERE id=$ART")" "Our first episode"
expect "the article keeps both tags" "$(tags_of "$ART")" "$TAGGED"
expect "and its category" "$(category_of "$ART")" "$CAT"

say "2. content.batch writing the article's words"
call content.batch "$(printf '{"apply_id":"e2e-words","request_id":"e2e-batch-1","operations":[{"kind":"article","id":%s,"fields":{"introtext":"<p>Our own words.</p>"}}]}' "$ART")"
expect "the article keeps both tags after a batch" "$(tags_of "$ART")" "$TAGGED"

say "3. apply.revert of both writes"
call apply.revert '{"apply_id":"e2e-words"}'
expect "the title is back" "$(sql "SELECT title FROM jos_content WHERE id=$ART")" "Demo episode"
expect "the article keeps both tags after the revert" "$(tags_of "$ART")" "$TAGGED"
expect "and its category" "$(category_of "$ART")" "$CAT"

say "4. a write naming tags still replaces them"
call content.update "$(printf '{"apply_id":"e2e-tags","kind":"article","id":%s,"fields":{"title":"Retagged episode","tags":["Gamma"]}}' "$ART")"
expect "the article's tags are the ones named" "$(tags_of "$ART")" "$GAMMA"

say "5. a write to the category"
BEFORE_CATEGORY_WRITE=$(tags_of "$ART")
call content.update "$(printf '{"apply_id":"e2e-category","kind":"category","id":%s,"fields":{"description":"<p>Our news.</p>"}}' "$CAT")"
expect "the article keeps its category" "$(category_of "$ART")" "$CAT"
expect "and its tags" "$(tags_of "$ART")" "$BEFORE_CATEGORY_WRITE"
expect "the category is still published" "$(sql "SELECT published FROM jos_categories WHERE id=$CAT")" "1"

say "result"
if [ "$FAILED" -gt 0 ]; then echo "$FAILED check(s) failed"; exit 1; fi
echo "every check passed"
