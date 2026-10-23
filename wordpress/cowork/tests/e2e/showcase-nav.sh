#!/usr/bin/env bash
# showcaseNav.status|apply|revert on a real JA Diner WP 1.0.1 site: the release's own webroot and
# database (sha256 from the ja-diner/wp7/1.0.1 contract manifest this plugin carries), on the official
# wordpress (the PHP the release targets) and mariadb images, with this checkout's plugin mounted and
# activated and the ja-diner/wp7/1.0.1 contract, showcase-nav-map.json included, bound.
#
#   wordpress/cowork/tests/e2e/showcase-nav.sh
#   DINER_RELEASE=<folder> wordpress/cowork/tests/e2e/showcase-nav.sh   # parts already downloaded
#   KEEP=1 ...                                                           # leave the stand up
#
# Without DINER_RELEASE the two parts are downloaded with `gh release download` (the release is
# private: gh must be signed in with read access to it). Either way both sha256 are checked first.
#
# 1. bind, then status: 3 owners to edit (the header part 96, navigations 93 and 278), none edited.
# 2. apply (in batches): 93, 278 and 96 end at the map's finals; the home page's header drawer now
#    renders the mainmenu (About Us, Reservation, Offers, Our Blog, Contact Us) without the Home colour
#    variants, and links no Typography, Error or Offline page; every showcase page still answers 200.
#    Inspect is clean; a second apply changes nothing.
# 3. the customer edits navigation 93; revert restores 278 and 96 byte for byte, names 93 as a
#    conflict and leaves the customer's bytes. The same revert retried gets the committed answer.
# 4. the customer puts 93 back as the apply left it; a new revert request restores it, and every
#    owner holds the release's bytes again. The site inspects clean; status says reverted, nothing remaining.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COWORK="$(cd "$HERE/../.." && pwd)"
REPO="$(cd "$COWORK/../.." && pwd)"
CONTRACT=ja-diner/wp7/1.0.1
MANIFEST="$COWORK/lib/contracts/$CONTRACT/manifest.json"
P="${E2E_PREFIX:-cowork-e2e-wp-snav}"
NET="$P-net"
DBPW=e2e-root-pw
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cowork-e2e-wp-snav.XXXXXX")"
FAILED=0
QS=tr_

cleanup() {
  if [ "${KEEP:-}" = 1 ]; then
    echo "KEEP=1: containers $P-* and network $NET left up; work dir $WORK"
    return
  fi
  # -v: the wordpress image declares /var/www/html a volume; it goes with its container.
  docker rm -fv "$P-wp" "$P-db" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

say() { printf '\n== %s\n' "$*"; }
expect() {
  if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1"; echo "       got : $2"; echo "       want: $3"; FAILED=$((FAILED + 1)); fi
}
part() { node -e 'const q = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")).quickstart; process.stdout.write(String(process.argv[2].split(".").reduce((o, k) => o[k], q)))' "$MANIFEST" "$1"; }

say "the release parts"
PHP_VERSION=$(part phpVersion)
IMAGE="${WORDPRESS_IMAGE:-wordpress:php${PHP_VERSION%.*}-apache}"
WEBROOT_FILE=$(part parts.webroot.file)
DATABASE_FILE=$(part parts.database.file)
if [ -n "${DINER_RELEASE:-}" ]; then
  PARTS="$DINER_RELEASE"
else
  PARTS="$WORK/download"
  mkdir -p "$PARTS"
  # https://github.com/<owner>/<repo>/releases/download/<tag>/<file>
  read -r GH_REPO GH_TAG < <(node -e 'const p = new URL(process.argv[1]).pathname.split("/"); process.stdout.write(`${p[1]}/${p[2]} ${decodeURIComponent(p[5])}\n`)' "$(part parts.webroot.url)")
  gh release download "$GH_TAG" --repo "$GH_REPO" -p "$WEBROOT_FILE" -p "$DATABASE_FILE" --dir "$PARTS" --clobber
fi
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
expect "the webroot is the one the manifest names" "$(sha "$PARTS/$WEBROOT_FILE")" "$(part parts.webroot.sha256)"
expect "the database is the one the manifest names" "$(sha "$PARTS/$DATABASE_FILE")" "$(part parts.database.sha256)"
[ "$FAILED" = 0 ] || exit 1

say "building this checkout"
mkdir -p "$WORK/src/wordpress" "$WORK/src/scripts"
cp "$REPO/scripts/release-manifest.mjs" "$WORK/src/scripts/"
rsync -a --exclude build --exclude dist --exclude claude-cowork/lib --exclude tests "$COWORK/" "$WORK/src/wordpress/cowork/"
( cd "$WORK/src/wordpress/cowork" && ./build.sh >/dev/null )
PLUGIN="$WORK/src/wordpress/cowork/build/claude-cowork"

say "network, database, WordPress ($IMAGE)"
docker network create "$NET" >/dev/null
docker run -d --name "$P-db" --network "$NET" -e MARIADB_ROOT_PASSWORD="$DBPW" -e MARIADB_DATABASE=quickstart mariadb:11 >/dev/null
for _ in $(seq 1 60); do docker exec "$P-db" mariadb -uroot -p"$DBPW" -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
sql() { docker exec "$P-db" mariadb -uroot -p"$DBPW" quickstart -N -e "$1"; }
gunzip -c "$PARTS/$DATABASE_FILE" | docker exec -i "$P-db" mariadb -uroot -p"$DBPW" quickstart
docker create --name "$P-wp" --network "$NET" -v "$PLUGIN:/var/www/html/wp-content/plugins/claude-cowork" "$IMAGE" >/dev/null
# The webroot holds index.php and wp-includes, so the image's entrypoint copies no WordPress of its own.
gunzip -c "$PARTS/$WEBROOT_FILE" | docker cp - "$P-wp:/var/www/html"
docker start "$P-wp" >/dev/null
docker exec "$P-wp" sed -i -e "s/{{DB_NAME}}/quickstart/" -e "s/{{DB_USER}}/root/" -e "s/{{DB_PASSWORD}}/$DBPW/" \
  -e "s/{{DB_HOST}}/$P-db/" /var/www/html/wp-config.php
docker exec "$P-wp" sh -c 'chown -R www-data:www-data /var/www/html/wp-content/uploads /var/www/html/wp-config.php 2>/dev/null || true'
sql "UPDATE ${QS}options SET option_value='http://localhost' WHERE option_name IN ('siteurl','home')"
for _ in $(seq 1 60); do docker exec "$P-wp" curl -fsS -o /dev/null http://localhost/ >/dev/null 2>&1 && break; sleep 1; done
expect "the restored site answers its home page" "$(docker exec "$P-wp" curl -sS -o /dev/null -w '%{http_code}' http://localhost/)" 200
expect "it runs the WordPress the release was made on" "$(docker exec "$P-wp" php -r 'include "/var/www/html/wp-includes/version.php"; echo $wp_version;')" "$(part platformVersion)"

say "activating the plugin"
docker exec -u www-data -w /var/www/html "$P-wp" php -r '
  $_SERVER["HTTP_HOST"] = "localhost";
  require "wp-load.php";
  require_once ABSPATH . "wp-admin/includes/plugin.php";
  $r = activate_plugin("claude-cowork/claude-cowork.php");
  if (is_wp_error($r)) { fwrite(STDERR, $r->get_error_message() . "\n"); exit(1); }'
TOKEN=$(sql "SELECT option_value FROM ${QS}options WHERE option_name='claude_cowork_token'")
[ -n "$TOKEN" ] || { echo "the activation wrote no token" >&2; exit 1; }

# One door call: action and params (a JSON object) in, the answer's JSON in $ANSWER. Fails the run
# when the answer is not ok, printing it. Not called inside $(…): an exit there ends only the subshell.
ANSWER=
call() {
  local body
  body=$(TOKEN="$TOKEN" node -e 'process.stdout.write(JSON.stringify({token: process.env.TOKEN, action: process.argv[1], params: JSON.parse(process.argv[2])}))' "$1" "$2")
  ANSWER=$(docker exec "$P-wp" curl -sS -X POST -H 'Content-Type: application/json' --data-binary "$body" \
    'http://localhost/wp-admin/admin-ajax.php?action=claude_cowork')
  node -e 'const a = JSON.parse(process.argv[1]); if (!a.ok) { console.error(JSON.stringify(a)); process.exit(1) }' "$ANSWER" \
    || { echo "$1 was refused" >&2; exit 1; }
}
contract() { call content.contract "$1"; }
field() { node -e 'const v = process.argv[2].split(".").reduce((o, k) => o?.[k], JSON.parse(process.argv[1])); process.stdout.write(typeof v === "object" ? JSON.stringify(v) : String(v))' "$ANSWER" "$1"; }
keys() { node -e 'process.stdout.write(Object.keys(JSON.parse(process.argv[1])[process.argv[2]] || {}).join(","))' "$ANSWER" "$1"; }
# A batched job: the same request until it is done. Moved summed over the calls into $MOVED.
MOVED=0
run_job() {
  MOVED=0
  for _ in 1 2 3 4 5; do
    contract "$1"
    MOVED=$((MOVED + $(field moved)))
    [ "$(field status)" = running ] || return 0
  done
  echo "$1 still running after five calls" >&2; exit 1
}

OWNERS="93,96,278"
MAP="$COWORK/lib/contracts/$CONTRACT/showcase-nav-map.json"
final() { node -e 'const m = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")); process.stdout.write(["navigation-93", "templatePart-96", "navigation-278"].map((k) => m.finals.find((f) => f.key === k)[process.argv[2]]).join(","))' "$MAP" "$1"; }
shas() { sql "SELECT GROUP_CONCAT(SHA2(post_content,256) ORDER BY ID) FROM ${QS}posts WHERE ID IN ($OWNERS)"; }
changed() { sql "SELECT COALESCE(GROUP_CONCAT(p.ID ORDER BY p.ID),'none') FROM ${QS}posts p JOIN snav_original o ON o.ID=p.ID WHERE BINARY p.post_content<>BINARY o.post_content"; }
sql "CREATE TABLE snav_original AS SELECT ID, post_content FROM ${QS}posts WHERE ID IN ($OWNERS)"
expect "the release ships 93, 96 and 278 as the map starts from" "$(shas)" "$(final before)"

UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'
# The navigation labels the home page renders at desktop, in order: `drawer` for the header's
# off-canvas navigation (the one whose ref the map moves), `header` for every navigation above the footer.
labels() {
  docker exec "$P-wp" curl -fsS -A "$UA" http://localhost/ | node -e '
    const html = require("fs").readFileSync(0, "utf8");
    const region = process.argv[1] === "drawer"
      ? (html.match(/<nav [^>]*class="[^"]*\bjm-off-canvas-nav\b[\s\S]*?<\/nav>/) || [""])[0]
      : html.slice(0, html.indexOf("<footer"));
    const labels = [...region.matchAll(/class="wp-block-navigation-item__label"[^>]*>([\s\S]*?)<\/span>/g)]
      .map((m) => m[1].replace(/<[^>]+>/g, "").replace(/&#8211;|&ndash;/g, "-").replace(/&amp;/g, "&").trim());
    process.stdout.write(labels.join("|"));' "$1"
}
SHOWCASE="85 86 87 88 82 83 275"
SHOWCASE_LABELS="Home - Red|Home - Pink|Home - Green|Home - Blue|Error Page|Offline Page|Typography"
# Each showcase page's own URL, and the HTTP status it answers there.
pages() {
  local id url out=
  for id in $SHOWCASE; do
    url=$(docker exec "$P-wp" curl -sS -o /dev/null -L -w '%{url_effective}' "http://localhost/?page_id=$id")
    out="$out $id:$(docker exec "$P-wp" curl -sS -o /dev/null -A "$UA" -w '%{http_code}' "$url")"
  done
  printf %s "${out# }"
}
# Which of the showcase labels, and of the mainmenu's own, a region renders.
showcase_in() {
  node -e 'const shown = process.argv[1].split("|"); process.stdout.write(process.argv[2].split("|").filter((l) => shown.includes(l)).join("|") || "none")' "$(labels "$1")" "$SHOWCASE_LABELS"
}
mainmenu_in() {
  node -e 'const shown = process.argv[1].split("|"); process.stdout.write(["About Us", "Reservation", "Offers", "Our Blog", "Contact Us"].filter((l) => shown.includes(l)).join("|") || "none")' "$(labels "$1")"
}

say "1. bind and status"
contract "{\"operation\":\"bind\",\"contract\":\"$CONTRACT\"}"
expect "bound" "$(field bound) $(field contract)" "true $CONTRACT"
contract '{"operation":"showcaseNav.status"}'
expect "status: three owners to edit, none edited" "$(field status) $(field remaining) $(field hides)" \
  'none 3 {"navigation.removeLink":7,"templatePart.setNavigationRef":1}'
expect "before an apply the header drawer is the site-pages navigation" "$(showcase_in drawer) / $(mainmenu_in drawer)" "Error Page|Offline Page|Typography / none"
expect "and the header links every showcase page" "$(showcase_in header)" "$SHOWCASE_LABELS"

say "2. apply"
run_job '{"operation":"showcaseNav.apply","apply_id":"snav-e2e","request_id":"e2e-1"}'
expect "apply completes, in batches" "$(field status) $MOVED $(field remaining) $(field skipped)" "completed 3 0 []"
expect "93, 96 and 278 hold the map's finals" "$(shas)" "$(final after)"
expect "the header part's drawer navigation now points at the mainmenu" \
  "$(sql "SELECT LOCATE('\"ref\":93', post_content) > 0, LOCATE('\"ref\":278', post_content) FROM ${QS}posts WHERE ID=96" | tr '\t' ' ')" "1 0"
expect "the header drawer renders the mainmenu's own links" "$(mainmenu_in drawer)" "About Us|Reservation|Offers|Our Blog|Contact Us"
expect "and the header links none of the showcase" "$(showcase_in drawer) / $(showcase_in header)" "none / none"
expect "every showcase page still answers" "$(pages)" "85:200 86:200 87:200 88:200 82:200 83:200 275:200"
contract '{"operation":"inspect"}'
expect "the applied site inspects clean" "$(field warnings)" undefined
sql "CREATE TABLE snav_applied AS SELECT ID, post_content FROM ${QS}posts WHERE ID IN ($OWNERS)"
contract '{"operation":"showcaseNav.apply","apply_id":"snav-e2e2","request_id":"e2e-2"}'
expect "a second apply is a no-op" "$(field status) $(field alreadyApplied) $(field moved) $(shas)" "completed true 0 $(final after)"

say "3. the customer edits navigation 93; revert"
sql "UPDATE ${QS}posts SET post_content=CONCAT(post_content, '\n<!-- wp:navigation-link {\"label\":\"Customer\",\"url\":\"/customer/\",\"kind\":\"custom\"} /-->') WHERE ID=93"
CUSTOMER=$(sql "SELECT SHA2(post_content,256) FROM ${QS}posts WHERE ID=93")
run_job '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo","request_id":"e2e-3"}'
expect "revert brings the others back" "$(field status) $MOVED $(field remaining)" "reverted 2 0"
expect "and names navigation 93 as a conflict" "$(keys conflicts)" navigation-93
expect "96 and 278 have exactly the release's bytes back" "$(changed)" 93
expect "93 stays as the customer left it" "$(sql "SELECT SHA2(post_content,256) FROM ${QS}posts WHERE ID=93")" "$CUSTOMER"
contract '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo","request_id":"e2e-3"}'
expect "the same revert retried gets the committed answer" "$(field status) $(field alreadyReverted) $(keys conflicts)" "reverted true navigation-93"
contract '{"operation":"showcaseNav.status"}'
expect "status lists the conflict" "$(keys conflicts)" navigation-93

say "4. the customer puts 93 back; a later revert recovers it"
sql "UPDATE ${QS}posts p JOIN snav_applied a ON a.ID=p.ID SET p.post_content=a.post_content WHERE p.ID=93"
run_job '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo2","request_id":"e2e-4"}'
expect "a new revert request restores it" "$(field status) $MOVED $(field conflicts)" "reverted 1 []"
expect "every owner holds the release's bytes" "$(changed) $(shas)" "none $(final before)"
contract '{"operation":"inspect"}'
expect "the reverted site inspects clean" "$(field warnings)" undefined
contract '{"operation":"showcaseNav.status"}'
expect "and status says the job is reverted, nothing remaining" "$(field status) $(field remaining) $(field conflicts) $(field owners)" "reverted 0 [] 3"
expect "the header drawer links the showcase again" "$(showcase_in drawer) / $(showcase_in header)" "Error Page|Offline Page|Typography / $SHOWCASE_LABELS"
contract '{"operation":"showcaseNav.revert","apply_id":"snav-e2e-undo","request_id":"e2e-3"}'
expect "the first revert still answers its retry" "$(field alreadyReverted) $(keys conflicts)" "true navigation-93"
sql "DROP TABLE snav_original, snav_applied"

echo
if [ "$FAILED" -gt 0 ]; then echo "$FAILED check(s) failed"; exit 1; fi
echo "all checks passed"
