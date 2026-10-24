#!/usr/bin/env bash
# End-to-end: `template.files.*` writes, refuses and takes back a theme's files on a throwaway WordPress.
#
#   wordpress/cowork/tests/e2e/template-files.sh
#   WORDPRESS_IMAGE=wordpress:php8.1-apache ...     # another PHP
#   KEEP=1 ...                                      # leave the stand up
#
# Needs docker and node. Nothing leaves this machine: every container and network is made here and
# removed at the end.
#
# ## Why a real WordPress
#
# tests/run.php proves the door on a folder; this proves what only a site gives it: the roots
# WordPress names (get_stylesheet_directory() and get_template_directory() of a child theme of
# Twenty Twenty-Five), PHP linted by the site's own command line from inside Apache, a functions.php
# that takes effect on the next page (opcache), the files written as the web server's user, the list
# on Settings > Claude Cowork, and the switch there that turns the door off.
#
# ## What it proves
#
# 1. info advertises the door and lints PHP with the command line; the roots are the child and its parent;
# 2. a write updates the child's style.css and functions.php, adds a stylesheet and edits the parent;
#    the next page shows the new functions.php; the write is listed for the administrator;
# 3. wp-config.php, a plugin, uploads, an inactive theme and .htaccess are refused;
# 4. a stale expectation is a conflict; a functions.php that does not parse is refused and the site still answers;
# 5. undo puts back what the write left and keeps the file the owner changed since;
# 6. apply.revert takes a write back; 7. the settings switch takes the door away.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COWORK="$(cd "$HERE/../.." && pwd)"
REPO="$(cd "$COWORK/../.." && pwd)"
IMAGE="${WORDPRESS_IMAGE:-wordpress:apache}"
CLI="${WORDPRESS_CLI_IMAGE:-wordpress:cli-php8.3}"
P="${E2E_PREFIX:-cowork-e2e-wp-tfiles}"
NET="$P-net"
DBPW=e2e-root-pw
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cowork-e2e-wp-tfiles.XXXXXX")"
FAILED=0

cleanup() {
  if [ "${KEEP:-}" = 1 ]; then
    echo "KEEP=1: containers $P-* and network $NET left up; work dir $WORK"
    return
  fi
  docker rm -fv "$P-wp" "$P-db" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

say() { printf '\n== %s\n' "$*"; }
expect() {
  if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1"; echo "       got : $2"; echo "       want: $3"; FAILED=$((FAILED + 1)); fi
}

say "building this checkout"
mkdir -p "$WORK/src/wordpress" "$WORK/src/scripts"
cp "$REPO/scripts/release-manifest.mjs" "$WORK/src/scripts/"
rsync -a --exclude build --exclude dist --exclude claude-cowork/lib --exclude tests "$COWORK/" "$WORK/src/wordpress/cowork/"
( cd "$WORK/src/wordpress/cowork" && ./build.sh >/dev/null )
PLUGIN="$WORK/src/wordpress/cowork/build/claude-cowork"

say "network, database, WordPress ($IMAGE)"
docker network create "$NET" >/dev/null
docker run -d --name "$P-db" --network "$NET" -e MARIADB_ROOT_PASSWORD="$DBPW" -e MARIADB_DATABASE=wordpress mariadb:11 >/dev/null
for _ in $(seq 1 60); do docker exec "$P-db" mariadb -uroot -p"$DBPW" -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
docker run -d --name "$P-wp" --network "$NET" -e WORDPRESS_DB_HOST="$P-db" -e WORDPRESS_DB_USER=root \
  -e WORDPRESS_DB_PASSWORD="$DBPW" -e WORDPRESS_DB_NAME=wordpress "$IMAGE" >/dev/null
for _ in $(seq 1 90); do docker exec "$P-wp" test -f /var/www/html/wp-config.php >/dev/null 2>&1 && break; sleep 1; done
docker cp "$PLUGIN" "$P-wp:/var/www/html/wp-content/plugins/claude-cowork"
in_site() { docker exec -w /var/www/html "$P-wp" sh -c "$1"; }
wp() { docker run --rm --network "$NET" --volumes-from "$P-wp" -e WORDPRESS_DB_HOST="$P-db" -e WORDPRESS_DB_USER=root \
  -e WORDPRESS_DB_PASSWORD="$DBPW" -e WORDPRESS_DB_NAME=wordpress --user 33:33 "$CLI" wp "$@"; }
# A child theme of Twenty Twenty-Five: the door's two roots.
in_site 'mkdir -p wp-content/themes/e2e-child && printf "/*\nTheme Name: E2E Child\nTemplate: twentytwentyfive\n*/\n" > wp-content/themes/e2e-child/style.css \
  && printf "<?php\n// E2E child theme.\n" > wp-content/themes/e2e-child/functions.php && chown -R www-data:www-data wp-content'
wp core install --url=http://localhost --title=cowork-e2e --admin_user=admin --admin_password=e2e-admin-password-1 \
  --admin_email=admin@example.invalid --skip-email >/dev/null
wp theme activate e2e-child >/dev/null
wp plugin activate claude-cowork >/dev/null
TOKEN=$(wp option get claude_cowork_token)
[ -n "$TOKEN" ] || { echo "the plugin made no token" >&2; exit 1; }

ANSWER=
post() {
  local body
  body=$(TOKEN="$TOKEN" node -e 'process.stdout.write(JSON.stringify({token: process.env.TOKEN, action: process.argv[1], params: JSON.parse(process.argv[2])}))' "$1" "$2")
  ANSWER=$(docker exec "$P-wp" curl -sS -X POST -H 'Content-Type: application/json' --data-binary "$body" 'http://localhost/wp-admin/admin-ajax.php?action=claude_cowork')
}
call() {
  post "$1" "$2"
  node -e 'const a = JSON.parse(process.argv[1]); if (!a.ok) { console.error(JSON.stringify(a)); process.exit(1) }' "$ANSWER" || { echo "$1 was refused" >&2; exit 1; }
}
REFUSED=
refused() { post "$1" "$2"; REFUSED=$(node -e 'const a = JSON.parse(process.argv[1]); process.stdout.write(a.ok ? "ok" : a.error)' "$ANSWER"); }
field() { node -e 'const v = process.argv[2].split(".").reduce((o, k) => o == null ? o : o[k], JSON.parse(process.argv[1])); process.stdout.write(v === undefined ? "undefined" : typeof v === "string" ? v : JSON.stringify(v))' "$ANSWER" "$1"; }
sha1_of() { in_site "sha1sum $1 2>/dev/null | cut -d' ' -f1"; }
sha1_text() { node -e 'process.stdout.write(require("crypto").createHash("sha1").update(process.argv[1]).digest("hex"))' "$1"; }
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
CHILD=wp-content/themes/e2e-child
PARENT=wp-content/themes/twentytwentyfive

say "1. info and status"
call info '{}'
expect "info advertises template_files version 1" "$(field info.template_files.version)" "1"
expect "PHP is linted by the site's own command line" "$(field info.template_files.php_lint)" "cli"
call template.files.status "{\"paths\":[\"$CHILD/style.css\"]}"
expect "the roots are the child theme and its parent" "$(node -e 'process.stdout.write(JSON.parse(process.argv[1]).roots.map(r => r.path + ":" + r.kind).join(","))' "$ANSWER")" \
  "$CHILD:theme,$PARENT:parent-theme"
expect "style.css answers its sha1" "$(field files.0.sha1)" "$(sha1_of $CHILD/style.css)"

say "2. a write"
STYLE_SHA=$(sha1_of $CHILD/style.css)
FUNCTIONS_SHA=$(sha1_of $CHILD/functions.php)
PARENT_SHA=$(sha1_of $PARENT/style.css)
PARENT_BYTES=$(in_site "cat $PARENT/style.css")
NEW_STYLE=$(printf '/*\nTheme Name: E2E Child\nTemplate: twentytwentyfive\n*/\nbody { color: #123456; }\n')
NEW_FUNCTIONS='<?php
add_action("wp_head", static function () { echo "<meta name=\"tracy-e2e\" content=\"functions-took\">\n"; });
'
call template.files.write "$(write_params e2e-tf-1 \
  "$CHILD/style.css" "$STYLE_SHA" "$NEW_STYLE" \
  "$CHILD/functions.php" "$FUNCTIONS_SHA" "$NEW_FUNCTIONS" \
  "$CHILD/assets/e2e.css" null ".e2e { margin: 0; }" \
  "$PARENT/style.css" "$PARENT_SHA" "$PARENT_BYTES
/* Tracy e2e */")"
UNDO1=$(field undo_id)
expect "four files changed" "$(field changed)" "4"
expect "functions.php was linted by the command line" "$(field lint)" "cli"
expect "the new folder and file are the web server's" "$(in_site "stat -c %U $CHILD/assets $CHILD/assets/e2e.css | sort -u")" "www-data"
expect "no temporary file is left" "$(in_site "find wp-content/themes -name '.tracy-tf-*' | wc -l | tr -d ' '")" "0"
expect "the next page runs the new functions.php" "$(in_site "curl -fsS http://localhost/ | grep -c 'tracy-e2e' || true")" "1"
expect "the write is listed for the administrator" "$(wp option get claude_cowork_template_files_log --format=json | node -e 'const l = JSON.parse(require("fs").readFileSync(0, "utf8")); process.stdout.write(l[0].action + " " + l[0].undo_id + " " + l[0].files.length)')" \
  "template.files.write $UNDO1 4"
expect "Settings > Claude Cowork shows the switch on and the write with its undo id" \
  "$(wp eval 'wp_set_current_user(1); claude_cowork_render_settings_page();' | node -e 'const h = require("fs").readFileSync(0, "utf8"); process.stdout.write([/name="claude_cowork_template_files"\s+value="1"\s+checked/.test(h), h.includes("Theme file changes"), h.includes(process.argv[1]), h.includes("e2e-child/functions.php (updated)")].join(" "))' "$UNDO1")" \
  "true true true true"

say "3. refusals"
for case in "wp-config.php:denied" "wp-content/plugins/akismet/akismet.php:not_in_scope" "wp-content/plugins/hello.php:not_in_scope" \
            "wp-content/uploads/x.css:not_in_scope" "wp-content/themes/twentytwentyfour/style.css:not_in_scope" "$CHILD/.htaccess:dotfile" \
            "$CHILD/../../../wp-config.php:traversal" "$CHILD/x.phtml:extension"; do
  path=${case%:*}; reason=${case##*:}
  refused template.files.write "$(write_params e2e-tf-x "$path" null "x")"
  expect "refused $path" "$REFUSED $(field refused.0.reason)" "refused $reason"
done

say "4. conflict and lint"
in_site "printf '/* owner */' >> $CHILD/assets/e2e.css"
refused template.files.write "$(write_params e2e-tf-x "$CHILD/assets/e2e.css" "$(sha1_text ".e2e { margin: 0; }")" "changed")"
expect "a stale expectation is a conflict" "$REFUSED $(field conflicts.0.path)" "conflict $CHILD/assets/e2e.css"
refused template.files.write "$(write_params e2e-tf-x "$CHILD/functions.php" "$(sha1_text "$NEW_FUNCTIONS")" '<?php echo "no semicolon" $x;')"
expect "a functions.php that does not parse is refused" "$REFUSED $(field lint_mode)" "php_lint_failed cli"
expect "and the site still answers" "$(in_site "curl -sS -o /dev/null -w '%{http_code}' http://localhost/")" "200"

say "5. undo"
call template.files.undo "{\"apply_id\":\"e2e-tf-1\",\"undo_id\":\"$UNDO1\"}"
expect "the owner's stylesheet is kept" "$(field kept.0.path) $(field kept.0.reason)" "$CHILD/assets/e2e.css changed-on-live"
expect "three files went back" "$(field restored.length)" "3"
expect "style.css, functions.php and the parent's style.css are back" \
  "$(sha1_of $CHILD/style.css) $(sha1_of $CHILD/functions.php) $(sha1_of $PARENT/style.css)" "$STYLE_SHA $FUNCTIONS_SHA $PARENT_SHA"
expect "the next page runs the old functions.php" "$(in_site "curl -fsS http://localhost/ | grep -c 'tracy-e2e' || true")" "0"

say "6. apply.revert"
call template.files.write "$(write_params e2e-tf-2 "$CHILD/style.css" "$STYLE_SHA" "body{}")"
call apply.revert '{"apply_id":"e2e-tf-2"}'
expect "apply.revert takes the write back" "$(sha1_of $CHILD/style.css)" "$STYLE_SHA"

say "7. the settings switch"
wp option update claude_cowork_template_files 0 >/dev/null
call info '{}'
expect "info no longer advertises the door" "$(field info.template_files)" "undefined"
refused template.files.write "$(write_params e2e-tf-3 "$CHILD/style.css" "$STYLE_SHA" "body{}")"
expect "a write answers unavailable" "$REFUSED" "unavailable"

say "result"
if [ "$FAILED" -gt 0 ]; then echo "$FAILED check(s) failed"; exit 1; fi
echo "every check passed"
