#!/usr/bin/env bash
# End-to-end: an article created through the door is one a T4 site can print, on a throwaway Joomla
# restored from a published JA quickstart.
#
#   T4_INSTALLER=<quickstart zip> joomla/cowork/tests/e2e/article-create.sh   # e.g. ja-impact j6 1.0.1
#
# Needs docker and node. Nothing leaves this machine.
#
# ## Why a real Joomla and a real T4 quickstart
#
# The door runs as a guest, and Table\Content::store() takes the signed-in user as a new article's
# author, so an article created through content.update id 0 was stored with created_by = 0. JA
# Impact's home page lists articles through mod_articles_category layouts that call T4's
# Author::authorInfo($item) unguarded; T4's AuthorModel::getAuthor(0) then falls through to
# implode(',', null) and the whole page answers 500 — measured 04/10/2026 on dev e2ef-jo, until the
# site was deleted (TracyHQ/tch#1182, J5.8). tests/run.php never reaches Table\Content or T4.
#
# T4_INSTALLER names the release zip of a classic JA quickstart (webroot + installation/, the shape
# 79 JA j6 quickstarts ship): the site is built by Joomla's own CLI installer, as the fleet does.
#
# ## What it proves
#
# 1. The quickstart's home page answers 200.
# 2. content.update kind article id 0, published, with an image, in each category a home-page module
#    lists with its author: the answer says `created: true`; the row has a real author (a Super
#    User), modified_by, publish_up, and JSON in attribs/metadata/urls/images; its page answers 200.
# 3. The home page still answers 200.
set -euo pipefail
E2E_NAME=article
[ -n "${T4_INSTALLER:-}" ] && [ -f "$T4_INSTALLER" ] || { echo "set T4_INSTALLER to a JA j6 quickstart zip (installer shape)" >&2; exit 2; }
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

say "building $(basename "$T4_INSTALLER") with Joomla's CLI installer over the stand"
docker exec "$P-db" mariadb -uroot -p"$DBPW" -e 'DROP DATABASE IF EXISTS quickstart; CREATE DATABASE quickstart'
docker cp "$T4_INSTALLER" "$P-joomla:/tmp/quickstart.zip"
docker exec "$P-joomla" sh -c 'find /var/www/html -mindepth 1 -delete'
docker exec "$P-joomla" php -r '$z = new ZipArchive(); if ($z->open("/tmp/quickstart.zip") !== true || !$z->extractTo("/var/www/html")) exit(1);'
docker exec "$P-joomla" chown -R www-data:www-data /var/www/html
docker exec -u www-data -w /var/www/html "$P-joomla" php installation/joomla.php install --no-interaction \
  --site-name cowork-e2e --admin-user 'Tracy Agent' --admin-username tracyagent \
  --admin-password e2e-admin-password-1 --admin-email agent@example.invalid \
  --db-type mysqli --db-host "$P-db" --db-user root --db-pass "$DBPW" --db-name quickstart --db-prefix qs_ | tail -2
docker exec "$P-joomla" rm -rf /var/www/html/installation
docker exec -u www-data "$P-joomla" php cli/joomla.php extension:install --path=/tmp/pkg_claudecowork.zip 2>&1 | tail -1
qs_sql() { docker exec "$P-db" mariadb -uroot -p"$DBPW" quickstart -N -e "$1"; }
qs_raw() { docker exec "$P-db" mariadb -uroot -p"$DBPW" quickstart -N --raw -e "$1"; }
TOKEN=$(qs_sql "SELECT JSON_VALUE(params,'\$.token') FROM qs_extensions WHERE element='com_claudecowork'")
[ -n "$TOKEN" ] && [ "$TOKEN" != NULL ] || { echo "the install on the quickstart wrote no token" >&2; exit 1; }
status() { docker exec "$P-joomla" curl -s -o /dev/null -w '%{http_code}' "http://localhost/$1"; }

say "1. the quickstart as published"
expect "the home page answers 200" "$(status '')" "200"

say "2. one article created through the door in each category the home page lists with its author"
# The categories of the published article-category modules whose layout prints the author (JA
# Impact: masonry ordered by publish_up — the one that answered 500 —, events, donation); else the
# newest published article's. A new article must reach at least one of those lists.
CATS=$(qs_raw "SELECT params FROM qs_modules WHERE module = 'mod_articles_category' AND published = 1 AND client_id = 0 ORDER BY id" \
  | node -e 'const rows = require("fs").readFileSync(0, "utf8").split("\n").filter(Boolean).map((l) => JSON.parse(l));
    const cats = rows.filter((p) => /masonry|events|donation/.test(p.layout ?? "")).flatMap((p) => [].concat(p.catid ?? [])).filter(Boolean);
    process.stdout.write([...new Set(cats)].join(" "))')
[ -n "$CATS" ] || CATS=$(qs_sql "SELECT catid FROM qs_content WHERE state = 1 ORDER BY created DESC, id DESC LIMIT 1")
IMAGES=$(node -e 'process.stdout.write(JSON.stringify(JSON.stringify({image_intro: "images/joomla_black.png", image_fulltext: "images/joomla_black.png"})))')
SUPER=$(qs_sql "SELECT u.id FROM qs_users u JOIN qs_user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0 ORDER BY u.id LIMIT 1")
for CAT in $CATS; do
  say "category $CAT"
  call content.update "$(printf '{"apply_id":"e2e-article","kind":"article","fields":{"title":"A new article %s","catid":%s,"state":1,"introtext":"<p>One paragraph.</p>","images":%s}}' "$CAT" "$CAT" "$IMAGES")"
  ART=$(answered_id)
  expect "the answer says it created a row" "$(node -e 'process.stdout.write(String(JSON.parse(process.argv[1]).created))' "$ANSWER")" "true"
  expect "it is by a Super User, never user 0" "$(qs_sql "SELECT created_by FROM qs_content WHERE id = $ART")" "$SUPER"
  expect "and last changed by them" "$(qs_sql "SELECT modified_by FROM qs_content WHERE id = $ART")" "$SUPER"
  expect "it is published from a date" "$(qs_sql "SELECT publish_up IS NOT NULL FROM qs_content WHERE id = $ART")" "1"
  expect "attribs, metadata, urls and images are JSON" "$(qs_sql "SELECT CONCAT(JSON_VALID(attribs), JSON_VALID(metadata), JSON_VALID(urls), JSON_VALID(images)) FROM qs_content WHERE id = $ART")" "1111"
  expect "the new article's page answers 200" "$(status "index.php?option=com_content&view=article&id=$ART&catid=$CAT")" "200"
done

say "3. the site"
expect "the home page still answers 200" "$(status '')" "200"

finish
