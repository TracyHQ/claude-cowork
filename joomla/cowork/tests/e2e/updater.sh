#!/usr/bin/env bash
# End-to-end: the self-updater, the update receipt and the release manifest, on a throwaway Joomla.
#
#   joomla/cowork/tests/e2e/updater.sh                 # Joomla 6
#   JOOMLA_IMAGE=joomla:5-apache joomla/cowork/tests/e2e/updater.sh
#   KEEP=1 joomla/cowork/tests/e2e/updater.sh          # leave the containers up to look around
#
# Needs docker, node, curl and network access to github.com once (the old release, step 1).
#
# ## The seam: GitHub is faked by the network, not by the code
#
# The updater reads only raw.githubusercontent.com/TracyHQ/claude-cowork/main/… and installs only
# assets under github.com/TracyHQ/claude-cowork/releases/download/. Those rules are not loosened for
# a test, not even behind JDEBUG: a switch that widens where a site downloads code from is a switch
# someone eventually leaves on. Instead the test network answers for those two names:
#
# - an nginx container carries the network aliases `raw.githubusercontent.com` and `github.com`, so
#   the Joomla container resolves both to it through Docker's own DNS;
# - it serves TLS with a certificate for those names, signed by a CA made for this run and added to
#   the Joomla container's system trust store (Joomla's HTTP transports use the system bundle);
# - it serves an update.xml announcing a build of this checkout, at the release-asset URL shape.
#
# The plugin under test runs byte for byte as shipped. Nothing here can reach a real site: every
# container, network and certificate is made by this script and removed at the end.
#
# ## What it proves
#
# 1. The released joomla-v<OLD> (whose updater predates the install context) auto-updates to build A
#    → receipt `auto-updater`, found from the call stack.
# 2. Build B installed over A from the CLI → receipt `unknown`; and whether files A shipped and B
#    does not are still on disk (the deletion question).
# 3. B's own updater takes build C → receipt `auto-updater`, from the context it sets.
# 4. After each step, the manifest on disk matches the disk, entry for entry.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
COWORK="$(cd "$HERE/../.." && pwd)"
REPO="$(cd "$COWORK/../.." && pwd)"
IMAGE="${JOOMLA_IMAGE:-joomla:6-apache}"
OLD="${OLD_RELEASE:-0.20.3}"
P="${E2E_PREFIX:-cowork-e2e}"
NET="$P-net"
DBPW=e2e-root-pw
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cowork-e2e.XXXXXX")"
A=0.90.0
B=0.90.1
C=0.90.2

cleanup() {
  if [ "${KEEP:-}" = 1 ]; then
    echo "KEEP=1: containers $P-* and network $NET left up; work dir $WORK"
    return
  fi
  docker rm -f "$P-joomla" "$P-db" "$P-github" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

say() { printf '\n== %s\n' "$*"; }

# ── builds of this checkout under other version numbers ─────────────────────────────────────────
# `probe` adds what a later release no longer ships: a file inside a folder the manifest lists
# (lib/), a nested one (lib/contracts/), one in a plugin's src/, and a file the component manifest
# names on its own line — the two shapes Joomla's installer could treat differently.
variant() {
  local version=$1 probe=$2 dir="$WORK/src-$1"
  mkdir -p "$dir/joomla" "$dir/scripts"
  cp "$REPO/scripts/release-manifest.mjs" "$dir/scripts/"
  rsync -a --exclude build --exclude dist --exclude 'com_claudecowork/administrator/lib' \
    --exclude 'com_claudecowork/administrator/tracy-release.json' --exclude tests "$COWORK/" "$dir/joomla/cowork/"
  local c="$dir/joomla/cowork"
  sed -i.bak "s|<version>[^<]*</version>|<version>$version</version>|" "$c/pkg_claudecowork.xml" "$c/com_claudecowork/claudecowork.xml"
  rm -f "$c"/*.bak "$c"/com_claudecowork/*.bak
  if [ "$probe" = probe ]; then
    echo '<?php // shipped by A only' > "$c/lib/ObsoleteProbe.php"
    mkdir -p "$c/lib/contracts/obsolete-probe"
    echo '{"shippedBy":"A"}' > "$c/lib/contracts/obsolete-probe/manifest.json"
    echo '<?php // shipped by A only' > "$c/plg_system_claudecoworkapi/src/Extension/ObsoleteProbe.php"
    echo '<?php // shipped by A only' > "$c/com_claudecowork/administrator/obsolete-probe.php"
    sed -i.bak 's|<filename>config.xml</filename>|<filename>config.xml</filename><filename>obsolete-probe.php</filename>|' "$c/com_claudecowork/claudecowork.xml"
    rm -f "$c"/com_claudecowork/*.bak
  fi
  ( cd "$c" && ./build.sh >/dev/null )
  cp "$c/dist/pkg_claudecowork.zip" "$WORK/pkg_claudecowork-$version.zip"
}

say "building A=$A (with probe files), B=$B, C=$C from this checkout"
variant "$A" probe
variant "$B" plain
variant "$C" plain

say "building tpl_tracy from this checkout"
mkdir -p "$WORK/tpl/joomla" "$WORK/tpl/scripts"
cp "$REPO/scripts/release-manifest.mjs" "$WORK/tpl/scripts/"
rsync -a --exclude build --exclude dist "$REPO/joomla/template/" "$WORK/tpl/joomla/template/"
( cd "$WORK/tpl/joomla/template" && ./build.sh >/dev/null )
cp "$WORK/tpl/joomla/template/dist/tpl_tracy.zip" "$WORK/tpl_tracy.zip"

say "downloading the released joomla-v$OLD"
curl -fsSL -o "$WORK/pkg_claudecowork-$OLD.zip" \
  "https://github.com/TracyHQ/claude-cowork/releases/download/joomla-v$OLD/pkg_claudecowork-$OLD.zip"

# ── the fake GitHub ─────────────────────────────────────────────────────────────────────────────
say "certificates for raw.githubusercontent.com and github.com, from a CA made for this run"
mkdir -p "$WORK/tls" "$WORK/www"
docker run --rm -v "$WORK/tls:/t" -w /t --entrypoint sh "$IMAGE" -c '
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=cowork-e2e test CA" -keyout ca.key -out ca.crt 2>/dev/null &&
  openssl req -newkey rsa:2048 -nodes -subj "/CN=github.com" -keyout leaf.key -out leaf.csr 2>/dev/null &&
  printf "subjectAltName=DNS:github.com,DNS:raw.githubusercontent.com\n" > san.ext &&
  openssl x509 -req -in leaf.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 2 -extfile san.ext -out leaf.crt 2>/dev/null &&
  chmod 644 *'
cat > "$WORK/nginx.conf" <<'NGINX'
server {
  listen 443 ssl;
  server_name github.com raw.githubusercontent.com;
  ssl_certificate /tls/leaf.crt;
  ssl_certificate_key /tls/leaf.key;
  root /www;
}
NGINX

announce() {
  local version=$1
  local asset="TracyHQ/claude-cowork/releases/download/joomla-v$version/pkg_claudecowork-$version.zip"
  mkdir -p "$WORK/www/TracyHQ/claude-cowork/main/joomla" "$WORK/www/$(dirname "$asset")"
  cp "$WORK/pkg_claudecowork-$version.zip" "$WORK/www/$asset"
  cat > "$WORK/www/TracyHQ/claude-cowork/main/joomla/update.xml" <<XML
<?xml version="1.0" encoding="utf-8"?>
<updates>
  <update>
    <name>Joomla Claude Cowork</name>
    <element>pkg_claudecowork</element>
    <type>package</type>
    <version>$version</version>
    <downloads><downloadurl type="full" format="zip">https://github.com/$asset</downloadurl></downloads>
    <targetplatform name="joomla" version="[456].*"/>
  </update>
</updates>
XML
}

say "network, database, fake GitHub, Joomla ($IMAGE)"
# An explicit subnet: a Docker host running many stands can have every default pool taken.
docker network create --subnet "${E2E_SUBNET:-10.251.77.0/24}" "$NET" >/dev/null
docker run -d --name "$P-db" --network "$NET" -e MARIADB_ROOT_PASSWORD="$DBPW" -e MARIADB_DATABASE=joomla mariadb:11 >/dev/null
docker run -d --name "$P-github" --network "$NET" --network-alias github.com --network-alias raw.githubusercontent.com \
  -v "$WORK/nginx.conf:/etc/nginx/conf.d/default.conf:ro" -v "$WORK/tls:/tls:ro" -v "$WORK/www:/www:ro" nginx:alpine >/dev/null
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
docker cp "$WORK/tls/ca.crt" "$P-joomla:/usr/local/share/ca-certificates/cowork-e2e.crt"
docker exec "$P-joomla" update-ca-certificates >/dev/null 2>&1
docker cp "$HERE/verify-install.php" "$P-joomla:/tmp/verify-install.php"
for f in "pkg_claudecowork-$OLD.zip" "pkg_claudecowork-$B.zip" tpl_tracy.zip; do docker cp "$WORK/$f" "$P-joomla:/tmp/"; done
# Any HTTP status means the TLS handshake with the fake GitHub succeeded (nginx answers / with 403).
code=$(docker exec "$P-joomla" curl -sS -o /dev/null -w "%{http_code}" https://raw.githubusercontent.com/ || true)
[ "$code" != 000 ] && [ -n "$code" ] || { echo "the Joomla container cannot reach the fake GitHub over TLS" >&2; exit 1; }

sql() { docker exec "$P-db" mariadb -uroot -p"$DBPW" joomla -e "$1"; }
cli_install() { docker exec -u www-data "$P-joomla" php cli/joomla.php extension:install --path="/tmp/$1" 2>&1 | tail -2; }
version_now() { sql "SELECT JSON_VALUE(manifest_cache,'\$.version') v FROM jos_extensions WHERE element='pkg_claudecowork'" | tail -1; }
visit() {
  # The updater runs on the NEXT request after its interval: clear the stamp, then ask for a page.
  sql "UPDATE jos_extensions SET params='{}' WHERE type='plugin' AND element='claudecoworkupdate'"
  docker exec "$P-joomla" curl -fsS -o /dev/null http://localhost/
  for _ in $(seq 1 30); do [ "$(version_now)" = "$1" ] && return 0; sleep 1; done
  echo "the updater did not install $1 (still $(version_now))" >&2
  docker exec "$P-joomla" sh -c 'cat administrator/logs/plg_system_claudecoworkupdate.php 2>/dev/null | tail -5' >&2
  return 1
}
# Prints the comparison and fails the run when any manifest entry is missing or differs on disk.
check_manifest() {
  local out
  out=$(docker exec -w /var/www/html "$P-joomla" php /tmp/verify-install.php "$@")
  echo "$out"
  case "$out" in *"\"mismatched\": []"*) ;; *) echo "the manifest does not match the disk" >&2; exit 1 ;; esac
}
verify() { check_manifest administrator/components/com_claudecowork/tracy-release.json "$@"; }
PROBES=(administrator/components/com_claudecowork/lib/ObsoleteProbe.php
  administrator/components/com_claudecowork/lib/contracts/obsolete-probe/manifest.json
  plugins/system/claudecoworkapi/src/Extension/ObsoleteProbe.php
  administrator/components/com_claudecowork/obsolete-probe.php)

say "0. install the released $OLD from the CLI"
cli_install "pkg_claudecowork-$OLD.zip"
echo "installed: $(version_now)"

say "1. the $OLD updater (no install context) takes A=$A"
announce "$A"
visit "$A"
verify "${PROBES[@]}"

say "2. B=$B over A from the CLI — are A's files B does not ship still there?"
cli_install "pkg_claudecowork-$B.zip"
echo "installed: $(version_now)"
verify "${PROBES[@]}"

say "3. B's updater (sets the install context) takes C=$C"
announce "$C"
visit "$C"
verify

say "4. tpl_tracy from the CLI: its manifest against the disk"
cli_install tpl_tracy.zip
check_manifest templates/tpl_tracy/tracy-release.json

say "receipts"
sql "SELECT id, at, element, from_version, to_version, tag, LEFT(manifest_sha256,12) sha, \`trigger\`, user_id, apply_id FROM jos_claudecowork_update_log ORDER BY id"
say "manifest hash on disk now"
docker exec -w /var/www/html "$P-joomla" sha256sum administrator/components/com_claudecowork/tracy-release.json
say "updater log"
docker exec "$P-joomla" sh -c 'tail -5 administrator/logs/plg_system_claudecoworkupdate.php 2>/dev/null || echo "(no log file)"'

triggers=$(sql "SELECT GROUP_CONCAT(\`trigger\` ORDER BY id) FROM jos_claudecowork_update_log" | tail -1)
[ "$triggers" = "auto-updater,unknown,auto-updater" ] || { echo "receipts say $triggers, expected auto-updater,unknown,auto-updater" >&2; exit 1; }
echo
echo "✓ manifests match the disk; receipts: $triggers"
