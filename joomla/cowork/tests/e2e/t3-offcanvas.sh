#!/usr/bin/env bash
# The T3 off-canvas drawer from the keyboard, on a real JA Diner 1.0.4 site: the quickstart's own
# installer package, set up by Joomla's CLI installer, with this checkout's package installed over it.
# A browser (Playwright) opens the home page at 390, 768 and 1440 px wide.
#
#   DINER_QUICKSTART=<folder> PLAYWRIGHT_CORE=<node_modules/playwright-core> joomla/cowork/tests/e2e/t3-offcanvas.sh
#   REPRO=1 …   print what the browser measured, check nothing (the page as T3 alone leaves it)
#
# <folder> is the published release folder of ja-diner/j6 1.0.4: it holds ja-diner-j6-quickstart-1.0.4.zip.
#
# 1. the adapter (plg_system_claudecoworkapi, `t3_offcanvas_a11y`): at each width the toggle shows,
#    Enter on ☰ opens the drawer and puts focus in it; Tab and Shift-Tab stay inside while it is open
#    and reach the ×, which is a button named "Close" and not hidden from screen readers; ESC closes
#    it — right after opening too — and focus is back on ☰, from where Tab goes where it went before
#    the drawer was ever opened. The × and a click on the page behind close it as before, focus back on ☰.
# 2. the switch off: the page carries no adapter, and the drawer does what T3 alone does (ESC does nothing).
# 3. a site on Cassiopeia (not T3): the page is the same bytes with the switch on and off, and has no adapter.
# T3's and the template's files are the bytes the quickstart shipped, before and after.
set -euo pipefail
E2E_NAME=t3oc
: "${DINER_QUICKSTART:?set DINER_QUICKSTART to the ja-diner/j6 1.0.4 release folder}"
: "${PLAYWRIGHT_CORE:?set PLAYWRIGHT_CORE to a playwright-core package folder (its Chromium installed)}"
ZIP="$DINER_QUICKSTART/ja-diner-j6-quickstart-1.0.4.zip"
[ -f "$ZIP" ] || { echo "no ja-diner-j6-quickstart-1.0.4.zip in $DINER_QUICKSTART" >&2; exit 1; }
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/joomla-stand.sh"
# The browser runs on this machine: a proxy on the stand's network gives it a local port.
trap 'docker rm -f -v "$P-proxy" >/dev/null 2>&1 || true; cleanup' EXIT

QS=tr_
qs_sql() { docker exec "$P-db" mariadb -uroot -p"$DBPW" quickstart -N -e "$1"; }

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
FILES="plugins/system/t3 templates/ja_diner"
files_hash() { docker exec "$P-joomla" sh -c "cd /var/www/html && find $FILES -type f | sort | xargs sha256sum | sha256sum | cut -c1-16"; }
SHIPPED=$(files_hash)
if [ "${REPRO:-}" != 1 ]; then
  docker exec -u www-data "$P-joomla" php cli/joomla.php extension:install --path=/tmp/pkg_claudecowork.zip 2>&1 | tail -1
fi
switch() { qs_sql "UPDATE ${QS}extensions SET params=JSON_SET(COALESCE(NULLIF(params,''),'{}'),'\$.t3_offcanvas_a11y','$1') WHERE type='plugin' AND element='claudecoworkapi'"; }

cat > "$WORK/proxy.conf" <<CONF
server { listen 80; location / { proxy_pass http://$P-joomla; proxy_set_header Host \$http_host; } }
CONF
docker run -d --name "$P-proxy" --network "$NET" -p 127.0.0.1::80 -v "$WORK/proxy.conf:/etc/nginx/conf.d/default.conf:ro" nginx:alpine >/dev/null
URL="http://$(docker port "$P-proxy" 80/tcp | head -1)/"
for _ in $(seq 1 30); do curl -fsS -o /dev/null "$URL" 2>/dev/null && break; sleep 1; done

browse() { node "$HERE/t3-offcanvas.mjs" "$URL" "$PLAYWRIGHT_CORE"; }
# One field of the line for one width.
at() { node -e 'const [w, k, text] = process.argv.slice(1); const line = text.split("\n").map((l) => JSON.parse(l)).find((l) => l.width === +w);
  const v = line?.[k]; process.stdout.write(typeof v === "object" ? JSON.stringify(v) : String(v))' "$1" "$2" "$3"; }

if [ "${REPRO:-}" = 1 ]; then
  say "T3 alone"
  browse
  exit 0
fi

say "1. the adapter on"
ON=$(browse)
echo "$ON"
for w in 390 768 1440; do
  expect "$w: ☰ shows, named" "$(at $w toggleVisible "$ON") $(at $w toggleName "$ON")" "true Toggle Navigation"
  expect "$w: the adapter is on the page" "$(at $w adapter "$ON")" true
  expect "$w: Enter on ☰ opens the drawer, focus in it" "$(at $w opened "$ON") $(at $w focusOnOpen "$ON" | cut -d: -f1)" "true drawer"
  expect "$w: the × is a button named Close, not hidden from screen readers, and on top" \
    "$(at $w closeAnnounced "$ON") $(node -e 'const c = JSON.parse(process.argv[1]); process.stdout.write([String(c.ariaHidden), c.label, c.hit].join(" "))' "$(at $w close "$ON")")" "1 null Close true"
  expect "$w: Tab reaches the ×; Tab and Shift-Tab never leave the drawer" "$(at $w tabReachesClose "$ON") $(at $w tabLeaves "$ON") $(at $w shiftTabLeaves "$ON")" "true 0 0"
  expect "$w: ESC closes, focus back on ☰" "$(at $w escCloses "$ON") $(at $w focusAfterEsc "$ON")" "true page:button.btn.btn-primary.off-canvas-toggle"
  expect "$w: Tab after the close goes where it went from ☰ before" "$(at $w tabContinues "$ON")" true
  expect "$w: ESC right after opening closes too" "$(at $w escDuringOpenCloses "$ON") $(at $w focusAfterEarlyEsc "$ON")" "true page:button.btn.btn-primary.off-canvas-toggle"
  expect "$w: the × and the page behind close it, focus back on ☰" \
    "$(at $w closeClickCloses "$ON") $(at $w focusAfterCloseClick "$ON") $(at $w pageClickCloses "$ON") $(at $w focusAfterPageClick "$ON")" \
    "true page:button.btn.btn-primary.off-canvas-toggle true page:button.btn.btn-primary.off-canvas-toggle"
  expect "$w: the page scrolls again, and no script error" "$(at $w scrollLocked "$ON") $(at $w errors "$ON")" "false []"
done
PAGE_ON=$(curl -fsS "$URL")

say "2. the switch off"
switch 0
OFF=$(browse)
echo "$OFF"
for w in 390 768 1440; do
  expect "$w: no adapter, ESC does what T3 alone does (nothing)" "$(at $w adapter "$OFF") $(at $w escCloses "$OFF") $(node -e 'process.stdout.write(String(JSON.parse(process.argv[1]).ariaHidden))' "$(at $w close "$OFF")")" "false false true"
done
PAGE_OFF=$(curl -fsS "$URL")
# Only the adapter's script and its options differ between the two (and the ids AcyMailing draws at random).
strip() { grep -v 't3-offcanvas-a11y' | sed -E -e 's/,?"plg_system_claudecoworkapi\.t3OffCanvas":\{[^}]*\}//' -e 's/[0-9a-f]{32}/TOKEN/g' -e 's/(formAcym|email_field_)[0-9]+/\1/g'; }
expect "the page with the switch off is the page with it on, less the adapter" "$(printf %s "$PAGE_OFF" | strip | shasum | cut -c1-16)" "$(printf %s "$PAGE_ON" | strip | shasum | cut -c1-16)"
[ "$(printf %s "$PAGE_OFF" | strip | shasum)" = "$(printf %s "$PAGE_ON" | strip | shasum)" ] || diff <(printf %s "$PAGE_OFF" | strip) <(printf %s "$PAGE_ON" | strip) | cut -c1-300 | head -20 || true

say "3. a site on Cassiopeia"
qs_sql "UPDATE ${QS}template_styles SET home=IF(template='cassiopeia',1,0) WHERE client_id=0; UPDATE ${QS}menu SET template_style_id=0 WHERE client_id=0"
CASS_OFF=$(curl -fsS "$URL" | sed -E 's/[0-9a-f]{32}/TOKEN/g')
switch 1
CASS_ON=$(curl -fsS "$URL" | sed -E 's/[0-9a-f]{32}/TOKEN/g')
expect "Cassiopeia: the page is the same bytes with the switch on and off, no adapter" \
  "$(printf %s "$CASS_ON" | shasum | cut -c1-16) $(printf %s "$CASS_ON" | grep -c 't3-offcanvas-a11y' || true)" "$(printf %s "$CASS_OFF" | shasum | cut -c1-16) 0"
expect "Cassiopeia is what the page is drawn with, not JA Diner" "$(printf %s "$CASS_ON" | grep -c 'templates/site/cassiopeia' | sed 's/^[1-9][0-9]*$/yes/') $(printf %s "$CASS_ON" | grep -c 'ja_diner' || true)" "yes 0"
expect "T3's and the template's files are the bytes the quickstart shipped" "$(files_hash)" "$SHIPPED"

finish
