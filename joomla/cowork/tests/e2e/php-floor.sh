#!/usr/bin/env bash
# End-to-end: a site whose PHP is older than the engine needs (7.4). The package refuses to install
# there, naming both versions, and a site that already has it — its host moved it to an older PHP —
# keeps every page and answers the door with `php_too_old` JSON instead of an error page.
#
#   joomla/cowork/tests/e2e/php-floor.sh                                     # Joomla 3.10 on PHP 5.6, 7.0 and 7.3
#   JOOMLA_IMAGE=joomla:4-apache OLD_PHP_IMAGE=php:7.3-apache joomla/cowork/tests/e2e/php-floor.sh
#                                                                            # Joomla 4 on PHP 7.3 (built here: mysqli added)
#   (KEEP: joomla-stand.sh)
#
# Needs docker and node. The site is installed on its own image's PHP as joomla-stand.sh does; then a
# second container on OLD_PHP_IMAGE serves the SAME webroot volume and database — the site after a
# PHP downgrade. For Joomla 3 the old PHPs are Joomla's own images (OLD_PHP_IMAGES; only their Apache and
# PHP serve here, the webroot is the 3.10 one): 3-php5.6 (5.6.39), 3.9-php7.0 (7.0.33), 3.10-php7.3
# (7.3.33) — Joomla 3 runs on PHP 5.3.10 and up, and many Joomla 3 sites are still on 5.6 or 7.0. Joomla 4 (the
# only later Joomla that runs below PHP 7.4: 7.2.5 and up; Joomla 5 needs 8.1, 6 needs 8.3) has
# none older than 7.4, so php:7.3-apache is given mysqli in a throwaway image.
#
# ## Why
#
# Measured on 0.24.72 (29/10/2026). Joomla 3.10.6 / PHP 7.3.33: installing stopped at "syntax error,
# unexpected '?', expecting function (T_FUNCTION) or const (T_CONST)" (the package's script.php) and
# installed nothing; with the package already there, the front door answered an HTML 500 ("Class
# '\Tracy\…\EngineFactory' not found") and the administrator door Joomla's 403 page. Joomla 4.4.14 /
# PHP 7.3.33: the system plugin took EVERY page of the site down, "syntax error, unexpected 'bool'".
#
# ## What it proves
#
# 1. On the old PHP the pages answer 200; both doors answer `{"ok":false,"error":"php_too_old",
#    "code":"php_too_old",…,"php":<this PHP>,"min":"7.4.0"}` to the site's token, and the engine's own
#    `unauthorized` to a wrong one.
# 2. Installing the package there is refused with the sentence naming both versions, and the version
#    installed stays what it was.
# 3. Back on the site's own PHP, the door answers as before.
set -euo pipefail
E2E_NAME=phpfloor
JOOMLA_IMAGE="${JOOMLA_IMAGE:-joomla:3.10-php7.4-apache}"
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

OLD="$P-oldphp"
# One image, or several in turn (OLD_PHP_IMAGES): each serves the same installed site.
OLD_PHP_IMAGES="${OLD_PHP_IMAGE:-${OLD_PHP_IMAGES:-joomla:3-php5.6-apache joomla:3.9-php7.0-apache joomla:3.10-php7.3-apache}}"
trap 'docker rm -f "$OLD" >/dev/null 2>&1 || true; docker rmi "cowork-e2e-$E2E_NAME-oldphp:local" >/dev/null 2>&1 || true; cleanup' EXIT
# runnable <image>: the image itself, or for a bare php image one given mysqli and mod_rewrite.
runnable() {
  if [ "$1" != "${1#joomla:}" ]; then echo "$1"; return; fi
  # Joomla needs mysqli, which a bare php image does not carry, and mod_rewrite, which the Joomla
  # image enables and its .htaccess expects (without it Apache answers the home page with a 302).
  BUILT="cowork-e2e-$E2E_NAME-oldphp:local"
  printf 'FROM %s\nRUN a2enmod rewrite >/dev/null && (docker-php-ext-install mysqli >/dev/null 2>&1 || (apt-get update >/dev/null && apt-get install -y -q $PHPIZE_DEPS >/dev/null && docker-php-ext-install mysqli >/dev/null))\n' "$1" |
    docker build -q -t "$BUILT" - >/dev/null
  echo "$BUILT"
}

FRONT='http://localhost/index.php?option=com_claudecowork&task=api.exec&format=json'
ADMIN='http://localhost/administrator/index.php?option=com_claudecowork&task=api.exec&format=json'
# knock <container> <url> <token>: the info answer's status and body, one line.
knock() {
  docker exec "$1" curl -sS -X POST -H 'Content-Type: application/json' --data-binary "{\"token\":\"$3\",\"action\":\"info\"}" -w ' %{http_code}' "$2" | tr -s ' \n' ' '
}
version_now() { sql "SELECT JSON_VALUE(manifest_cache, '\$.version') FROM jos_extensions WHERE element = 'pkg_claudecowork'"; }
VERSION=$(version_now)

# on_old_php <image>: the site served by that image's PHP — pages, doors, an install — then put away.
on_old_php() {
  say "the same site, served by $1"
  docker run -d --name "$OLD" --network "$NET" --volumes-from "$P-joomla" --entrypoint apache2-foreground "$(runnable "$1")" >/dev/null
  for _ in $(seq 1 30); do docker exec "$OLD" curl -s -o /dev/null http://localhost/ 2>/dev/null && break; sleep 1; done
  OLDPHP=$(docker exec "$OLD" php -r 'echo PHP_VERSION;')
  say "PHP $OLDPHP"

  expect "PHP $OLDPHP: the home page answers 200" "$(docker exec "$OLD" curl -s -o /dev/null -w '%{http_code}' http://localhost/)" "200"
  expect "PHP $OLDPHP: the administrator login page answers 200" "$(docker exec "$OLD" curl -s -o /dev/null -w '%{http_code}' http://localhost/administrator/)" "200"
  WANT="{\"ok\":false,\"error\":\"php_too_old\",\"code\":\"php_too_old\",\"message\":\"This site runs PHP $OLDPHP; Claude Cowork needs PHP 7.4.0 or newer. Ask your host to upgrade PHP on this site, then try again.\",\"php\":\"$OLDPHP\",\"min\":\"7.4.0\"} 200"
  expect "PHP $OLDPHP: the front door answers php_too_old" "$(knock "$OLD" "$FRONT" "$TOKEN")" "$WANT"
  expect "PHP $OLDPHP: the administrator door answers php_too_old" "$(knock "$OLD" "$ADMIN" "$TOKEN")" "$WANT"
  UNAUTHORIZED='{"ok":false,"error":"unauthorized","message":"invalid or missing token"} 200'
  expect "PHP $OLDPHP: a wrong token is refused as the engine refuses it" "$(knock "$OLD" "$FRONT" wrong-token)" "$UNAUTHORIZED"
  expect "PHP $OLDPHP: at the administrator door too" "$(knock "$OLD" "$ADMIN" wrong-token)" "$UNAUTHORIZED"

  say "installing the package on PHP $OLDPHP"
  docker cp "$WORK/src/joomla/cowork/dist/pkg_claudecowork.zip" "$OLD:/tmp/pkg_old.zip"
  if docker exec "$OLD" test -f cli/joomla.php; then
    docker exec "$OLD" chown www-data /tmp/pkg_old.zip
    SAID=$(docker exec -u www-data "$OLD" php cli/joomla.php extension:install --path=/tmp/pkg_old.zip 2>&1 | tr -s ' \n' ' ' || true)
  else
    # Joomla 3 installs the unpacked folder (the zip is over its upload limit), as joomla-stand.sh does.
    docker exec "$OLD" php -r '$z = new ZipArchive(); exit($z->open("/tmp/pkg_old.zip") === true && $z->extractTo("/tmp/pkg_old") ? 0 : 1);'
    docker exec "$OLD" chown -R www-data:www-data /tmp/pkg_old
    SAID=$(docker exec "$OLD" sh -c '
      jar=/tmp/old.cookies; admin=http://localhost/administrator/index.php; rm -f $jar
      token() { grep -o "name=\"[0-9a-f]\{32\}\" value=\"1\"" | head -1 | cut -d\" -f2; }
      form=$(curl -sS -c $jar -b $jar "$admin")
      curl -sS -o /dev/null -c $jar -b $jar "$admin" --data-urlencode username=admin --data-urlencode passwd=e2e-admin-password-1 \
        -d option=com_login -d task=login -d return="$(printf %s "$form" | grep -o "name=\"return\" value=\"[^\"]*\"" | cut -d\" -f4)" -d "$(printf %s "$form" | token)=1"
      t=$(curl -sS -c $jar -b $jar "$admin?option=com_installer&view=install" | token)
      curl -sS -L -c $jar -b $jar "$admin" -d installtype=folder -d install_directory=/tmp/pkg_old -d option=com_installer -d task=install.install -d "$t=1" |
        sed -e "s/<[^>]*>/ /g" | tr -s " \n\t" " "')
  fi
  echo "  Joomla said: $(printf %s "$SAID" | grep -o "Claude Cowork needs PHP .* then install the package again\." | head -1)"
  expect "PHP $OLDPHP: the install is refused, naming both versions" \
    "$(printf %s "$SAID" | grep -o "Claude Cowork needs PHP 7.4.0 or newer; this site runs PHP $OLDPHP. Ask your host to upgrade PHP on this site, then install the package again." | head -1)" \
    "Claude Cowork needs PHP 7.4.0 or newer; this site runs PHP $OLDPHP. Ask your host to upgrade PHP on this site, then install the package again."
  expect "PHP $OLDPHP: and the installed version is still $VERSION" "$(version_now)" "$VERSION"
  docker rm -f "$OLD" >/dev/null
}
for image in $OLD_PHP_IMAGES; do on_old_php "$image"; done

say "back on the site's own PHP"
call info '{}'
expect "the door answers info again" "$(node -e 'const a = JSON.parse(process.argv[1]); process.stdout.write(a.ok + " " + a.info.component)' "$ANSWER")" "true $VERSION"

finish
