# Sourced by the e2e scripts that need this checkout running on a throwaway Joomla (docker): builds
# the package, starts MariaDB and Joomla on their own network, installs the package from the CLI and
# gives the caller what it needs to talk to the door. The caller sets `set -euo pipefail` and
# E2E_NAME (a short word that names its containers) before sourcing this file.
#
# Gives: $P (container prefix), sql "<query>", call <action> '<params JSON>' (answer in $ANSWER),
# answered_id, expect "<what>" "<got>" "<want>" (counts failures in $FAILED), say, and finish.
#
#   JOOMLA_IMAGE=joomla:5-apache <script>     # another Joomla
#   KEEP=1 <script>                           # leave the containers up to look around

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COWORK="$(cd "$HERE/../.." && pwd)"
REPO="$(cd "$COWORK/../.." && pwd)"
IMAGE="${JOOMLA_IMAGE:-joomla:6-apache}"
P="${E2E_PREFIX:-cowork-e2e-$E2E_NAME}"
NET="$P-net"
DBPW=e2e-root-pw
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cowork-e2e-$E2E_NAME.XXXXXX")"
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

expect() {
  if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1"; echo "       got : $2"; echo "       want: $3"; FAILED=$((FAILED + 1)); fi
}

# The last line of a script: its result, and its exit status.
finish() {
  say "result"
  if [ "$FAILED" -gt 0 ]; then echo "$FAILED check(s) failed"; exit 1; fi
  echo "every check passed"
}
