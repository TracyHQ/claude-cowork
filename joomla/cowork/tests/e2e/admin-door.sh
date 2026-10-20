#!/usr/bin/env bash
# End-to-end: the administrator door answers a token call the way the front door does, on a throwaway
# Joomla, with the component's site folder on disk and without it.
#
#   joomla/cowork/tests/e2e/admin-door.sh                                       # Joomla 6
#   JOOMLA_IMAGE=joomla:3.10-php7.4-apache joomla/cowork/tests/e2e/admin-door.sh  # Joomla 3
#   (JOOMLA_IMAGE=joomla:4-apache / joomla:5-apache likewise; KEEP: joomla-stand.sh)
#
# Needs docker and node. Nothing leaves this machine.
#
# ## Why
#
# plg_system_claudecoworkapi answers `option=com_claudecowork&task=api.exec` at onAfterInitialise on
# both clients, so `/administrator/index.php?…` is the way in when the front door is gated. Measured
# 19/10/2026 before this script: on Joomla 3.10 the plugin never ran (Joomla 3 loads a system plugin
# from its legacy entry file, which the plugin did not ship) and the administrator answered its own 403
# login page; on Joomla 6.2, with components/com_claudecowork gone, wiring the engine needed the site
# folder's classes and the administrator answered 403 again.
#
# ## What it proves
#
# 1. Everything installed: the administrator door answers info, db.tables, db.dump_many and
#    files.list; a wrong and a missing token are refused there exactly as at the front door; the
#    front door answers the same info with the plugin on and off; the home page and the
#    administrator login page print the same bytes with the plugin on and off.
# 2. components/com_claudecowork removed: the same reads at the administrator door, the same refusals.
# 3. The component removed altogether, the plugin kept: no page and no door answers 500.
set -euo pipefail
E2E_NAME=admindoor
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

FRONT='http://localhost/index.php?option=com_claudecowork&task=api.exec&format=json'
ADMIN='http://localhost/administrator/index.php?option=com_claudecowork&task=api.exec&format=json'

# knock <url> <token> <action> <params JSON>: the answer's status in $STATUS, its body in $BODY.
# A token of "-" sends none at all.
STATUS= BODY=
knock() {
  local body out
  body=$(TOKEN="$2" node -e 'const b = {action: process.argv[1], params: JSON.parse(process.argv[2])};
    if (process.env.TOKEN !== "-") b.token = process.env.TOKEN; process.stdout.write(JSON.stringify(b))' "$3" "$4")
  out=$(docker exec "$P-joomla" curl -sS -X POST -H 'Content-Type: application/json' --data-binary "$body" -w '\n%{http_code}' "$1")
  STATUS=${out##*$'\n'}
  BODY=${out%$'\n'*}
}
# A field of $BODY (a JS expression over `a`), or "not JSON: <start of body>".
field() { node -e 'let a; try { a = JSON.parse(process.argv[1]) } catch { process.stdout.write("not JSON: " + process.argv[1].slice(0, 120).replace(/\s+/g, " ")); process.exit(0) }
  try { process.stdout.write(String(eval(process.argv[2]))) } catch (e) { process.stdout.write("unexpected answer: " + process.argv[1].slice(0, 160)) }' "$BODY" "$1"; }
# A page's bytes with the per-session form tokens taken out.
page() { docker exec "$P-joomla" curl -sS "http://localhost/$1" | sed -E 's/[0-9a-f]{32}/TOKEN/g'; }
plugin() { sql "UPDATE jos_extensions SET enabled = $1 WHERE type = 'plugin' AND folder = 'system' AND element = 'claudecoworkapi'"; }

MAJOR=$(docker exec "$P-joomla" sed -n 's/.*const MAJOR_VERSION = \([0-9]*\);.*/\1/p' libraries/src/Version.php)
say "Joomla $MAJOR: the plugin row is enabled"
expect "plg_system_claudecoworkapi is enabled" "$(sql "SELECT enabled FROM jos_extensions WHERE type = 'plugin' AND element = 'claudecoworkapi'")" "1"

# The reads an import makes, at the administrator door.
reads() {
  knock "$ADMIN" "$TOKEN" info '{}'
  expect "$1: info answers 200" "$STATUS" "200"
  expect "$1: info is ok and names this Joomla" "$(field 'a.ok + " " + String(a.info.joomla).split(".")[0]')" "true $MAJOR"
  knock "$ADMIN" "$TOKEN" db.tables '{}'
  expect "$1: db.tables lists jos_users" "$(field 'a.ok + " " + a.tables.some((t) => (t.name ?? t) === "jos_users")')" "true true"
  knock "$ADMIN" "$TOKEN" db.dump_many '{"tables":["jos_users"]}'
  expect "$1: db.dump_many dumps the administrator's row" "$(field 'a.ok + " " + a.done + " " + Buffer.from(a.sql_b64, "base64").toString().includes("admin@example.invalid")')" "true true true"
  knock "$ADMIN" "$TOKEN" files.list '{"limit":5}'
  expect "$1: files.list walks the webroot" "$(field 'a.ok')" "true"
}

# A wrong and a missing token are refused at the administrator door exactly as $WRONG/$MISSING say.
refusals() {
  knock "$ADMIN" wrong-token info '{}'
  expect "$1: a wrong token is refused as at the front door" "$STATUS $BODY" "$WRONG"
  knock "$ADMIN" - info '{}'
  expect "$1: a missing token is refused as at the front door" "$STATUS $BODY" "$MISSING"
}

say "1. everything installed"
knock "$FRONT" wrong-token info '{}'; WRONG="$STATUS $BODY"
knock "$FRONT" - info '{}'; MISSING="$STATUS $BODY"
expect "the front door refuses a wrong token" "$(field 'a.error')" "unauthorized"
reads "installed"
refusals "installed"
knock "$FRONT" "$TOKEN" info '{}'; FRONT_ON="$STATUS $BODY"
HOME_ON=$(page '')
LOGIN_ON=$(page 'administrator/index.php')
plugin 0
knock "$FRONT" "$TOKEN" info '{}'; FRONT_OFF="$STATUS $BODY"
HOME_OFF=$(page '')
LOGIN_OFF=$(page 'administrator/index.php')
plugin 1
expect "the front door answers info with the plugin on as with it off" "$FRONT_ON" "$FRONT_OFF"
expect "the front door's info is ok" "$(BODY=${FRONT_ON#* }; field 'a.ok')" "true"
expect "the home page prints the same bytes with the plugin on and off" "$(printf %s "$HOME_ON" | shasum)" "$(printf %s "$HOME_OFF" | shasum)"
expect "the administrator login page prints the same bytes with the plugin on and off" "$(printf %s "$LOGIN_ON" | shasum)" "$(printf %s "$LOGIN_OFF" | shasum)"

say "2. components/com_claudecowork removed"
docker exec "$P-joomla" rm -rf components/com_claudecowork
reads "no site folder"
refusals "no site folder"
expect "the home page still answers 200" "$(docker exec "$P-joomla" curl -s -o /dev/null -w '%{http_code}' http://localhost/)" "200"

say "3. the component removed altogether, the plugin kept"
docker exec "$P-joomla" rm -rf administrator/components/com_claudecowork
expect "the home page answers 200" "$(docker exec "$P-joomla" curl -s -o /dev/null -w '%{http_code}' http://localhost/)" "200"
knock "$ADMIN" "$TOKEN" info '{}'
expect "the administrator door is not a 500" "$([ "$STATUS" != 500 ] && echo yes || echo "no: $BODY")" "yes"
knock "$FRONT" "$TOKEN" info '{}'
expect "the front door is not a 500" "$([ "$STATUS" != 500 ] && echo yes || echo "no: $BODY")" "yes"

finish
