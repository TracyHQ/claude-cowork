#!/usr/bin/env bash
# The contract profile fetch (lib/ContractFetch.php) on a real Joomla, every road a site takes to it:
# installed as root the way Tracy provisions a site (the component then belongs to root while PHP
# runs as www-data), installed as www-data, a profile placed before the site runs, updated from a
# release that fetched into lib/contracts and from one that carried every profile, a reinstall,
# Joomla's cache purge, an emptied tmp, a custom tmp_path, nowhere to write, an uninstall, no network.
#
# It installs the RELEASED packages 0.24.54 (every profile inside), 0.24.55 (the fetch that refused
# a root-owned component) and 0.24.56 (the spare folder under tmp_path), downloaded with `gh`, and
# talks to the door as Tracy does: `content.contract` `bind`. A bare Joomla holds none of a
# quickstart's rows, so a bind that has read its profile answers `contract_failed: Missing or
# ambiguous entity …`; one that has not answers `contract_unavailable … does not carry …`.
#
#   tests/e2e/contract-fetch.sh                              # every case, Joomla 6
#   CASES="2 4 6" JOOMLA_IMAGE=joomla:5-php8.3-apache tests/e2e/contract-fetch.sh
#   RELEASES_DIR=<folder with pkg_claudecowork-0.24.5{4,5,6}.zip> …   # no download
set -euo pipefail
E2E_NAME=contract-fetch
STAND_NO_PACKAGE=1
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/joomla-stand.sh"

CASES=" ${CASES:-1 2 3 4 5 6 7 8 9 10 11 12 13} "
J="$P-joomla"
WEB=/var/www/html
COMP=$WEB/administrator/components/com_claudecowork
CON=$COMP/lib/contracts
SPARE=$WEB/tmp/com_claudecowork/contracts
ALTTMP=/var/e2e-tmp
ID=ja-fresco/j6/1.0.4
OTHER=ja-fresco/j6/1.0.3
RELEASE=https://github.com/TracyHQ/claude-cowork/releases/download

say "the released packages"
REL="${RELEASES_DIR:-$WORK/releases}"
for v in 0.24.54 0.24.55 0.24.56; do
  [ -f "$REL/pkg_claudecowork-$v.zip" ] || gh release download -R TracyHQ/claude-cowork "joomla-v$v" -p "pkg_claudecowork-$v.zip" -D "$REL"
  docker cp "$REL/pkg_claudecowork-$v.zip" "$J:/tmp/pkg-$v.zip"
done

# Scripts change under Apache between cases: opcache looks at every file again on each request.
docker exec "$J" sh -c 'echo opcache.revalidate_freq=0 > /usr/local/etc/php/conf.d/zz-e2e.ini && apache2ctl -k graceful' 2>/dev/null
docker exec "$J" tar -C /var/www -cf /e2e-snap.tar html
docker exec "$P-db" sh -c "mariadb-dump -uroot -p$DBPW joomla > /e2e-snap.sql"
JVERSION=$(sql "SELECT JSON_VALUE(manifest_cache,'\$.version') FROM jos_extensions WHERE element='joomla' AND type='file'")

in_j() { docker exec "$J" sh -c "$1"; }
as_www() { docker exec -u www-data "$J" sh -c "$1"; }

# Back to the Joomla this run started, without the package; the network open.
restore() {
  unblock
  in_j "find $WEB -mindepth 1 -delete && tar -C /var/www -xf /e2e-snap.tar && rm -rf $ALTTMP"
  docker exec "$P-db" sh -c "mariadb -uroot -p$DBPW joomla < /e2e-snap.sql"
}

# Tracy's provisioning: the webroot handed to www-data, then the package installed by root.
provision_as_root() { in_j "chown -R www-data:www-data $WEB"; install "$1" root; }

install() { # <version> <user>: a fresh install, or an update when a package is already there
  local out
  out=$(docker exec -u "$2" -w $WEB "$J" php cli/joomla.php extension:install --path="/tmp/pkg-$1.zip" 2>&1 | tr -s ' \n' ' ') || true
  case "$out" in *"installed successfully"*) ;; *) echo "  install of $1 as $2 failed: ${out:0:300}"; FAILED=$((FAILED + 1)) ;; esac
  TOKEN=$(sql "SELECT JSON_VALUE(params,'\$.token') FROM jos_extensions WHERE element='com_claudecowork' AND type='component'")
}

installed() { sql "SELECT JSON_VALUE(manifest_cache,'\$.version') FROM jos_extensions WHERE element='pkg_claudecowork'"; }

bind_param() { sql "UPDATE jos_extensions SET params=JSON_SET(params,'\$.contract','$1') WHERE element='com_claudecowork' AND type='component'"; }

# One content.contract bind. $ANSWER the raw answer, $RESULT "ok" or "<error>: <message>", $READ
# "read" when the bind got past the profile (ok, or the site's rows do not match it), else $RESULT.
door() {
  local body
  body=$(TOKEN="$TOKEN" node -e 'process.stdout.write(JSON.stringify({token: process.env.TOKEN, action: "content.contract", params: {operation: "bind"}}))')
  ANSWER=$(docker exec "$J" curl -sS -X POST -H 'Content-Type: application/json' --data-binary "$body" \
    'http://localhost/index.php?option=com_claudecowork&task=api.exec&format=json')
  RESULT=$(node -e 'const a = JSON.parse(process.argv[1]); process.stdout.write(a.ok ? "ok" : a.error + ": " + a.message)' "$ANSWER" 2>/dev/null) \
    || RESULT="not JSON: ${ANSWER:0:300}"
  case "$RESULT" in ok|"contract_failed: Missing or ambiguous entity"*) READ=read ;; *) READ="$RESULT" ;; esac
  echo "  door: ${RESULT:0:240}"
}

owner() { in_j "stat -c %U '$1' 2>/dev/null || echo absent"; }
stamp() { in_j "stat -c '%i %Y' '$1' 2>/dev/null || echo absent"; }
profiles() { in_j "cd '$1' 2>/dev/null && find . -name manifest.json | sed 's|^\./||; s|/manifest.json\$||' | sort | tr '\n' ' '" || true; }

# No egress to GitHub: its names resolve to this container, where nothing answers on 443.
block() { in_j "[ -f /e2e-hosts ] || { cp /etc/hosts /e2e-hosts && echo '127.0.0.1 github.com objects.githubusercontent.com release-assets.githubusercontent.com' >> /etc/hosts; }"; }
unblock() { in_j "[ ! -f /e2e-hosts ] || { cat /e2e-hosts > /etc/hosts && rm /e2e-hosts; }"; }

ROWS=()
CASE_FAILED=0
CASE_NOTE=
begin() { CASE=$1; CASE_FAILED=$FAILED; CASE_NOTE=; say "case $1 (Joomla $JVERSION): $2"; restore; }
note() { CASE_NOTE="${CASE_NOTE:+$CASE_NOTE; }$*"; }
end() {
  local verdict=PASS
  [ "$FAILED" -gt "$CASE_FAILED" ] && verdict=FAIL
  ROWS+=("$(printf '%-3s %-7s %-4s %s' "$CASE" "$JVERSION" "$verdict" "$CASE_NOTE")")
}
want() { case " $CASES " in *" $1 "*) return 0 ;; esac; return 1; }

if want 1; then
  begin 1 "control: 0.24.55 installed as root refuses the bind"
  provision_as_root 0.24.55
  expect "the component belongs to root" "$(owner $COMP/lib)" root
  bind_param $ID
  door
  case "$RESULT" in "contract_unavailable: "*"cannot create administrator/components/com_claudecowork/lib/contracts/ja-fresco/j6"*) got=refused ;; *) got="$RESULT" ;; esac
  expect "refused with cannot create …lib/contracts/ja-fresco/j6" "$got" refused
  note "$RESULT"
  end
fi

if want 2; then
  begin 2 "0.24.56 installed as root: the profile lands in the spare folder"
  provision_as_root 0.24.56
  expect "the component belongs to root" "$(owner $COMP/lib)" root
  bind_param $ID
  door
  expect "the bind reads the profile" "$READ" read
  expect "the profile is in tmp_path/com_claudecowork/contracts, owned by www-data" "$(owner $SPARE/$ID/manifest.json)" www-data
  expect "nothing in lib/contracts" "$(owner $CON)" absent
  before=$(stamp $SPARE/$ID/manifest.json)
  block
  door
  unblock
  expect "a second bind with GitHub unreachable still reads it" "$READ" read
  expect "and did not fetch it again (same inode and mtime)" "$(stamp $SPARE/$ID/manifest.json)" "$before"
  note "$RESULT; spare manifest owner $(owner $SPARE/$ID/manifest.json); lib/contracts $(owner $CON); 2nd bind offline, stamp kept"
  end
fi

if want 3; then
  begin 3 "0.24.56 installed as www-data: the profile lands in lib/contracts"
  in_j "chown -R www-data:www-data $WEB"
  install 0.24.56 www-data
  expect "the component belongs to www-data" "$(owner $COMP/lib)" www-data
  bind_param $ID
  door
  expect "the bind reads the profile" "$READ" read
  expect "the profile is in lib/contracts, owned by www-data" "$(owner $CON/$ID/manifest.json)" www-data
  expect "nothing in the spare folder" "$(owner $SPARE)" absent
  note "$RESULT; lib/contracts manifest owner $(owner $CON/$ID/manifest.json); spare $(owner $SPARE)"
  end
fi

if want 4; then
  begin 4 "a profile placed in lib/contracts by root before the site runs"
  provision_as_root 0.24.56
  in_j "curl -fsSL -o /tmp/profile.zip $RELEASE/joomla-v0.24.56/$(echo $ID | sed 's|/|--|g').zip && mkdir -p $CON/$ID && php -r '\$z = new ZipArchive(); exit(\$z->open(\"/tmp/profile.zip\") === true && \$z->extractTo(\"$CON/$ID\") ? 0 : 1);' && rm /tmp/profile.zip"
  expect "lib/contracts and the profile belong to root" "$(owner $CON) $(owner $CON/$ID/manifest.json)" "root root"
  bind_param $ID
  in_j "touch /e2e-mark"
  sleep 1
  block
  door
  unblock
  expect "the bind reads it with GitHub unreachable" "$READ" read
  expect "nothing written in lib/contracts or tmp" "$(in_j "find $CON $WEB/tmp -newer /e2e-mark | head -3")" ""
  expect "no spare folder" "$(owner $SPARE)" absent
  note "$RESULT; offline; lib/contracts root-owned, nothing newer in lib/contracts or tmp; spare $(owner $SPARE)"
  end
fi

if want 5; then
  begin 5 "a 0.24.55 site that works (chowned by hand) updated to 0.24.56"
  provision_as_root 0.24.55
  bind_param $ID
  door
  expect "0.24.55 as installed refuses" "${RESULT%%:*}" contract_unavailable
  # The hand fix hands the webroot back to www-data: the package's language files and manifest
  # are root's too, and an update as www-data could not replace them.
  in_j "chown -R www-data:www-data $WEB"
  door
  expect "after the manual chown it reads the profile" "$READ" read
  expect "fetched into lib/contracts" "$(owner $CON/$ID/manifest.json)" www-data
  before=$(stamp $CON/$ID/manifest.json)
  install 0.24.56 www-data
  expect "updated to 0.24.56" "$(installed)" 0.24.56
  block
  door
  unblock
  expect "the bind still reads it, GitHub unreachable" "$READ" read
  expect "the profile is kept untouched in lib/contracts" "$(stamp $CON/$ID/manifest.json)" "$before"
  expect "lib/contracts holds only it" "$(profiles $CON)" "$ID "
  expect "no spare folder" "$(owner $SPARE)" absent
  note "$RESULT; updated as www-data to $(installed); lib/contracts: $(profiles $CON)(stamp kept); spare $(owner $SPARE)"
  end
fi

if want 6; then
  begin 6 "a 0.24.54 site (every profile inside) bound to one, updated to 0.24.56"
  provision_as_root 0.24.54
  n=$(in_j "find $CON -name manifest.json | wc -l")
  bind_param tracy-business/j6/1.2.6
  door
  expect "0.24.54 reads its bundled profile" "$READ" read
  install 0.24.56 root
  expect "updated to 0.24.56" "$(installed)" 0.24.56
  expect "the update kept the bound profile and the one it names, nothing else" "$(profiles $CON)" "tracy-business/j6/1.2.5 tracy-business/j6/1.2.6 "
  block
  door
  unblock
  expect "the bind still reads it, GitHub unreachable" "$READ" read
  expect "no spare folder" "$(owner $SPARE)" absent
  note "$RESULT; 0.24.54 carried $n profiles; after update lib/contracts: $(profiles $CON)"
  end
fi

if want 7; then
  begin 7 "profiles in the spare folder, then 0.24.56 installed again"
  provision_as_root 0.24.56
  bind_param $OTHER
  door
  bind_param $ID
  door
  expect "both fetched into the spare folder" "$(profiles $SPARE)" "$OTHER $ID "
  install 0.24.56 root
  expect "the reinstall kept only the bound one" "$(profiles $SPARE)" "$ID "
  block
  door
  unblock
  expect "the bind still reads it, GitHub unreachable" "$READ" read
  note "$RESULT; spare before reinstall: $OTHER $ID; after: $(profiles $SPARE)"
  end
fi

if want 8; then
  begin 8 "Joomla's cache purge leaves the spare folder"
  provision_as_root 0.24.56
  bind_param $ID
  door
  before=$(stamp $SPARE/$ID/manifest.json)
  as_www "cd $WEB && php cli/joomla.php cache:clean >/dev/null"
  expect "the cache purge ran (cache/ emptied of its folders)" "$(in_j "find $WEB/cache $WEB/administrator/cache -mindepth 1 -type d | head -1")" ""
  expect "the spare profile is untouched" "$(stamp $SPARE/$ID/manifest.json)" "$before"
  block
  door
  unblock
  expect "the bind reads it, GitHub unreachable" "$READ" read
  note "cache:clean; spare stamp kept; $RESULT offline"
  end
fi

if want 9; then
  begin 9 "an emptied tmp: the next bind fetches again"
  provision_as_root 0.24.56
  bind_param $ID
  door
  in_j "find $WEB/tmp -mindepth 1 -delete"
  expect "tmp is empty" "$(in_j "ls -A $WEB/tmp")" ""
  door
  expect "the bind reads the profile" "$READ" read
  expect "fetched into the spare folder again" "$(owner $SPARE/$ID/manifest.json)" www-data
  note "$RESULT; spare manifest owner $(owner $SPARE/$ID/manifest.json)"
  end
fi

if want 10; then
  begin 10 "a custom tmp_path outside the webroot"
  in_j "mkdir -p $ALTTMP && chown www-data:www-data $ALTTMP && sed -i \"s|public \\\$tmp_path = .*|public \\\$tmp_path = '$ALTTMP';|\" $WEB/configuration.php"
  expect "tmp_path is set" "$(in_j "grep -o 'tmp_path = .*' $WEB/configuration.php")" "tmp_path = '$ALTTMP';"
  provision_as_root 0.24.56
  bind_param $ID
  door
  expect "the bind reads the profile" "$READ" read
  expect "the profile is under the custom tmp_path, owned by www-data" "$(owner $ALTTMP/com_claudecowork/contracts/$ID/manifest.json)" www-data
  expect "nothing in the site's tmp/ or lib/contracts" "$(owner $SPARE) $(owner $CON)" "absent absent"
  note "$RESULT; $ALTTMP/com_claudecowork/contracts/$ID owner $(owner $ALTTMP/com_claudecowork/contracts/$ID/manifest.json)"
  end
fi

if want 11; then
  begin 11 "neither lib/contracts nor tmp_path writable"
  provision_as_root 0.24.56
  in_j "chown root:root $WEB/tmp && chmod 755 $WEB/tmp"
  bind_param $ID
  since=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  door
  case "$RESULT" in "contract_unavailable: "*"cannot create administrator/components/com_claudecowork/lib/contracts/ja-fresco/j6; cannot create tmp_path/com_claudecowork/contracts/ja-fresco/j6"*) got=both ;; *) got="$RESULT" ;; esac
  expect "refused naming both places" "$got" both
  case "$ANSWER" in *Warning*|*Fatal*|*Deprecated*|*"<b>"*) got=dirty ;; *) got=clean ;; esac
  expect "the answer is JSON with no PHP message in it" "$got" clean
  expect "no PHP warning or fatal in the web server's log" "$(docker logs --since "$since" "$J" 2>&1 | grep -E 'PHP (Warning|Fatal|Parse)' | head -2)" ""
  expect "no marker written anywhere" "$(in_j "find $WEB -name '*.fetch-failed' | head -1")" ""
  note "$RESULT"
  end
fi

if want 12; then
  begin 12 "uninstalling the package removes the spare folder"
  provision_as_root 0.24.56
  bind_param $ID
  door
  expect "the spare profile is there" "$(owner $SPARE/$ID/manifest.json)" www-data
  pkg=$(sql "SELECT extension_id FROM jos_extensions WHERE element='pkg_claudecowork'")
  out=$(docker exec -w $WEB "$J" php cli/joomla.php extension:remove "$pkg" -n 2>&1 | tr -s ' \n' ' ')
  echo "  remove: ${out:0:200}"
  expect "the package is gone" "$(sql "SELECT COUNT(*) FROM jos_extensions WHERE element IN ('pkg_claudecowork','com_claudecowork')")" 0
  expect "tmp_path/com_claudecowork is gone" "$(owner $WEB/tmp/com_claudecowork)" absent
  note "uninstalled as root; tmp/com_claudecowork $(owner $WEB/tmp/com_claudecowork)"
  end
fi

if want 13; then
  begin 13 "no network: a readable refusal, a backoff, then recovery"
  provision_as_root 0.24.56
  bind_param $ID
  block
  t0=$(date +%s)
  door
  first="$RESULT"
  got="$RESULT"
  case "$RESULT" in "contract_unavailable: "*"(fetch failed: download failed: "*) got=refused ;; esac
  expect "refused with the download's reason" "$got" refused
  expect "within the door's time (under 20 s)" "$(( $(date +%s) - t0 < 20 ))" 1
  expect "a marker beside the spare profile, owned by www-data" "$(owner $SPARE/$ID.fetch-failed)" www-data
  unblock
  door
  second="$RESULT"
  got="$RESULT"
  case "$RESULT" in "contract_unavailable: "*"(retried after 300 s)"*) got=backoff ;; esac
  expect "with the network back, still inside the backoff: refused without a new try" "$got" backoff
  # The backoff elapsing: the marker's time moved 301 s back.
  as_www "php -r '\$f=\"$SPARE/$ID.fetch-failed\"; \$m=json_decode(file_get_contents(\$f), true); \$m[\"at\"]-=301; file_put_contents(\$f, json_encode(\$m));'"
  door
  expect "after the backoff the bind reads the profile" "$READ" read
  expect "the profile is in the spare folder, the marker gone" "$(owner $SPARE/$ID/manifest.json) $(owner $SPARE/$ID.fetch-failed)" "www-data absent"
  note "1st: ${first:0:200}; 2nd (network back): ${second:0:200}; after 301 s: $RESULT"
  end
fi

say "cases"
printf '%s\n' "${ROWS[@]}"
finish
