#!/usr/bin/env bash
# End-to-end: `template.files.*` writes, refuses and takes back a template's files on a throwaway Joomla.
#
#   joomla/cowork/tests/e2e/template-files.sh          # Joomla 6 (JOOMLA_IMAGE, KEEP: joomla-stand.sh)
#   JOOMLA_IMAGE=joomla:5-apache joomla/cowork/tests/e2e/template-files.sh
#
# Needs docker and node. Nothing leaves this machine: every container and network is made here and
# removed at the end.
#
# ## Why a real Joomla
#
# tests/run.php proves the door on a folder; this proves the wiring the door only gets on a site:
# the roots read from #__extensions (Cassiopeia's templates/cassiopeia/ and media/templates/site/
# cassiopeia/), PHP linted by the site's own PHP command line from inside Apache, the files written
# as the web server's user, the page that serves them, the User Actions Log row an administrator
# reads, and the Options switch that turns the door off.
#
# ## What it proves
#
# 1. info advertises the door and lints PHP with the command line;
# 2. status answers the hashes of a file Cassiopeia ships;
# 3. a write updates template.css, adds user.css (which Cassiopeia then links on every page) and a
#    module override, and is logged in the User Actions Log;
# 4. configuration.php, .htaccess, PHP under media/ and a template that is not installed are refused;
# 5. a stale expectation is a conflict and writes nothing; PHP that does not parse is refused;
# 6. undo puts back what the write left and keeps the file the owner changed since;
# 7. apply.revert takes a write back; 8. the Options switch takes the door away (info: enabled false);
# 9. a stale temporary is swept. PHP is refused (php_disabled) until Options › Allow PHP is switched on.
set -euo pipefail
E2E_NAME=tfiles
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

# The self-updater would install the published release over this checkout mid-run (it did, once the
# run outlasted its quarter hour): the door under test would vanish halfway through.
sql "UPDATE jos_extensions SET params=JSON_SET(IF(JSON_VALID(params), params, '{}'), '\$.autoupdate', '0') WHERE type='plugin' AND element='claudecoworkupdate'"
MEDIA=media/templates/site/cassiopeia
in_site() { docker exec "$P-joomla" sh -c "$1"; }
sha1_of() { in_site "sha1sum $1 2>/dev/null | cut -d' ' -f1"; }
field() { node -e 'const v = process.argv[2].split(".").reduce((o, k) => o == null ? o : o[k], JSON.parse(process.argv[1])); process.stdout.write(v === undefined ? "undefined" : typeof v === "string" ? v : JSON.stringify(v))' "$ANSWER" "$1"; }
# A write's params: apply_id, then path|expected|content triples ("-" content: a delete; "null" expected: no file).
write_params() {
  node -e '
    const [apply, ...rest] = process.argv.slice(1); const files = [];
    for (let i = 0; i < rest.length; i += 3) {
      const [path, expected, content] = rest.slice(i, i + 3);
      const e = expected === "null" ? null : expected;
      if (content === "-") files.push({ path, delete: true, expected_sha1: e });
      else files.push({ path, expected_sha1: e, content_b64: Buffer.from(content).toString("base64"), sha256: require("crypto").createHash("sha256").update(content).digest("hex") });
    }
    process.stdout.write(JSON.stringify({ apply_id: apply, files }))' "$@"
}
# A call that must be refused: its error code in $REFUSED.
REFUSED=
refused() {
  local body
  body=$(TOKEN="$TOKEN" node -e 'process.stdout.write(JSON.stringify({token: process.env.TOKEN, action: process.argv[1], params: JSON.parse(process.argv[2])}))' "$1" "$2")
  ANSWER=$(docker exec "$P-joomla" curl -sS -X POST -H 'Content-Type: application/json' --data-binary "$body" \
    'http://localhost/index.php?option=com_claudecowork&task=api.exec&format=json')
  REFUSED=$(node -e 'const a = JSON.parse(process.argv[1]); process.stdout.write(a.ok ? "ok" : a.error)' "$ANSWER")
}

say "1. info"
call info '{}'
expect "info advertises template_files version 1" "$(field info.template_files.version)" "1"
expect "PHP is linted by the site's own command line" "$(field info.template_files.php_lint)" "cli"
expect "and PHP is off until the owner switches it on" "$(field info.template_files.php)" "false"
refused template.files.write "$(write_params e2e-tf-x "templates/cassiopeia/html/mod_custom/default.php" null '<?php echo 1;')"
expect "a PHP file is refused php_disabled meanwhile" "$REFUSED $(field refused.0.reason)" "refused php_disabled"
sql "UPDATE jos_extensions SET params=JSON_SET(params, '\$.template_files_php', '1') WHERE element='com_claudecowork'"
call info '{}'
expect "switched on in Options, info says PHP may be written" "$(field info.template_files.php)" "true"

say "2. status"
CSS_SHA=$(sha1_of $MEDIA/css/template.css)
call template.files.status "{\"paths\":[\"$MEDIA/css/template.css\",\"$MEDIA/css/user.css\"]}"
expect "the roots are Cassiopeia's two folders" "$(node -e 'process.stdout.write(JSON.parse(process.argv[1]).roots.filter(r => r.name === "cassiopeia").map(r => r.path + ":" + r.php).join(","))' "$ANSWER")" \
  "templates/cassiopeia:true,$MEDIA:false"
expect "template.css answers its sha1" "$(field files.0.sha1)" "$CSS_SHA"
expect "user.css is not there yet" "$(field files.1.exists)" "false"

say "3. a write"
OVERRIDE='<?php
defined("_JEXEC") or die;
echo "<div class=\"tracy-e2e-override\">" . $module->content . "</div>";
'
call template.files.write "$(write_params e2e-tf-1 \
  "$MEDIA/css/template.css" "$CSS_SHA" "/* Tracy e2e */ body { color: #123456; }" \
  "$MEDIA/css/user.css" null ".tracy-e2e-user { color: red; }" \
  "templates/cassiopeia/html/mod_custom/default.php" null "$OVERRIDE")"
UNDO1=$(field undo_id)
expect "three files changed" "$(field changed)" "3"
expect "the override was linted by the command line" "$(field lint)" "cli"
expect "template.css holds the new bytes" "$(in_site "cat $MEDIA/css/template.css")" "/* Tracy e2e */ body { color: #123456; }"
expect "the override is written as the web server's user" "$(in_site "stat -c %U templates/cassiopeia/html/mod_custom/default.php")" "www-data"
expect "no temporary file is left" "$(in_site "find templates/cassiopeia $MEDIA -name '.tracy-tf-*' | wc -l | tr -d ' '")" "0"
expect "Cassiopeia now links user.css on the home page" "$(in_site "curl -fsS http://localhost/ | grep -c 'css/user.css' || true")" "1"
expect "the write is in the User Actions Log" \
  "$(sql "SELECT CONCAT(message_language_key, ' ', JSON_VALUE(message, '\$.count'), ' ', JSON_VALUE(message, '\$.undo_id')) FROM jos_action_logs WHERE extension='com_claudecowork.template.files' ORDER BY id DESC LIMIT 1")" \
  "COM_CLAUDECOWORK_ACTIONLOG_TEMPLATE_FILES_WRITE 3 $UNDO1"
expect "its message is in the administrator language file" "$(in_site "grep -c '^COM_CLAUDECOWORK_ACTIONLOG_TEMPLATE_FILES_WRITE=' administrator/language/en-GB/com_claudecowork.ini")" "1"

say "4. refusals"
for case in "configuration.php:denied" "templates/cassiopeia/.htaccess:dotfile" "$MEDIA/js/x.php:php_not_allowed" \
            "templates/atum/css/a.css:not_in_scope" "templates/cassiopeia/../../configuration.php:traversal" "images/a.css:not_in_scope"; do
  path=${case%:*}; reason=${case##*:}
  refused template.files.write "$(write_params e2e-tf-x "$path" null "x")"
  expect "refused $path" "$REFUSED $(field refused.0.reason)" "refused $reason"
done
expect "configuration.php is untouched" "$(in_site "grep -c 'class JConfig' configuration.php")" "1"

say "5. conflict and lint"
in_site "printf '/* owner */' >> $MEDIA/css/user.css"
refused template.files.write "$(write_params e2e-tf-x "$MEDIA/css/user.css" "$(node -e 'process.stdout.write(require("crypto").createHash("sha1").update(".tracy-e2e-user { color: red; }").digest("hex"))')" "changed")"
expect "a stale expectation is a conflict" "$REFUSED $(field conflicts.0.path)" "conflict $MEDIA/css/user.css"
refused template.files.write "$(write_params e2e-tf-x "templates/cassiopeia/html/mod_custom/broken.php" null '<?php echo "no semicolon" $x;')"
expect "PHP that does not parse is refused" "$REFUSED $(field lint_mode)" "php_lint_failed cli"
expect "and is not on the site" "$(in_site 'test -e templates/cassiopeia/html/mod_custom/broken.php && echo yes || echo no')" "no"

say "6. undo"
call template.files.undo "{\"apply_id\":\"e2e-tf-1\",\"undo_id\":\"$UNDO1\"}"
expect "the owner's user.css is kept" "$(field kept.0.path) $(field kept.0.reason)" "$MEDIA/css/user.css changed-on-live"
expect "template.css is back" "$(sha1_of $MEDIA/css/template.css)" "$CSS_SHA"
expect "the override it added is gone, Cassiopeia's own banner override beside it kept" \
  "$(in_site 'ls templates/cassiopeia/html/mod_custom | tr "\n" " "')" "banner.php "
expect "the undo is in the User Actions Log too" "$(sql "SELECT message_language_key FROM jos_action_logs WHERE extension='com_claudecowork.template.files' ORDER BY id DESC LIMIT 1")" \
  "COM_CLAUDECOWORK_ACTIONLOG_TEMPLATE_FILES_UNDO"

say "7. apply.revert"
CSS_SHA=$(sha1_of $MEDIA/css/template.css)
call template.files.write "$(write_params e2e-tf-2 "$MEDIA/css/template.css" "$CSS_SHA" "body{}")"
call apply.revert '{"apply_id":"e2e-tf-2"}'
expect "apply.revert takes the write back" "$(sha1_of $MEDIA/css/template.css)" "$CSS_SHA"

say "8. the Options switch"
sql "UPDATE jos_extensions SET params=JSON_SET(params, '\$.template_files', '0') WHERE element='com_claudecowork'"
call info '{}'
expect "info names the door as switched off" "$(field info.template_files)" '{"version":1,"enabled":false}'
refused template.files.write "$(write_params e2e-tf-3 "$MEDIA/css/template.css" "$CSS_SHA" "body{}")"
expect "a write answers disabled" "$REFUSED" "disabled"

say "9. leftovers of a request that died"
sql "UPDATE jos_extensions SET params=JSON_SET(params, '\$.template_files', '1') WHERE element='com_claudecowork'"
in_site "printf x > $MEDIA/css/.tracy-tf-0123456789abcdef.tmp && touch -d '2 hours ago' $MEDIA/css/.tracy-tf-0123456789abcdef.tmp && printf x > $MEDIA/css/.tracy-tf-1111111111111111.tmp"
call template.files.status '{}'
expect "status sweeps the stale temporary and keeps the fresh one" "$(field swept) $(in_site "ls -A $MEDIA/css | grep -c '^.tracy-tf-'")" "1 1"
in_site "rm -f $MEDIA/css/.tracy-tf-1111111111111111.tmp"

finish
