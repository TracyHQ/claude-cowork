#!/usr/bin/env bash
# End-to-end: the customer's share image printed as og:image, on a throwaway Joomla: replacing any
# other on a template without T4, and only where a page has none of its own on a T4 template.
#
#   joomla/cowork/tests/e2e/share-image.sh               # Joomla 6 (JOOMLA_IMAGE, KEEP: joomla-stand.sh)
#   T4_QUICKSTART=<folder> joomla/cowork/tests/e2e/share-image.sh   # and the T4 part, see below
#
# Needs docker and node. Nothing leaves this machine.
#
# ## Why a real Joomla
#
# `og:image` is printed by plg_system_claudecoworkapi (onBeforeCompileHead, onAfterRender), which
# tests/run.php never reaches: the rules of the setting are unit tested there, the printing here. A
# T3 or Gavick template prints no share image, so a link shared to Facebook or Zalo showed whatever
# picture the network picked off the page (TCH #1013, D5). Cassiopeia, Joomla's own template, is a
# template without T4 too, and the one a bare Joomla has.
#
# ## What it proves
#
# 1. Before any setting, the home page prints no og:image.
# 2. template.siteSettings `other_shareImage` on Cassiopeia: the home page and another page print
#    exactly one og:image, the customer's picture by its absolute URL.
# 3. apply.revert takes it back: no og:image again.
#
# The T4 part needs a real T4 site, which a bare Joomla is not: T4_QUICKSTART names the folder of a
# published T4 quickstart (its manifest.json, webroot and database files, e.g. ja-spa j6 from
# JoomlArt-Products/ja-products), restored over the stand. Without it the T4 part is skipped, and
# the run says so on its last lines.
#
# 4. On the T4 template, with T4's Open Graph off: every page prints the customer's og:image, once.
# 5. T4's Open Graph on and the articles given an intro image: the home page (an article on JA
#    Spa) prints T4's own og:image only; another page still prints the customer's.
# 6. apply.revert: the home page keeps T4's own; the other page prints none.
set -euo pipefail
E2E_NAME=share
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

# The og:image tags a page prints, one per line.
og_images() { docker exec "$P-joomla" curl -fsSL "http://localhost/$1" | grep -o '<meta property="og:image"[^>]*>' || true; }

say "a customer logo on the site"
# A 1x1 PNG: the door wants a picture file that is on the site, not a real logo.
docker exec -u www-data "$P-joomla" sh -c 'mkdir -p images/tracy-brand && printf %s iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII= | base64 -d > images/tracy-brand/logo-e2e.png'

say "1. before any setting"
expect "the home page prints no og:image" "$(og_images '')" ""

say "2. the share image set on Cassiopeia"
call template.siteSettings '{"operation":"set","apply_id":"e2e-share","template":"cassiopeia","fields":{"other_shareImage":"images/tracy-brand/logo-e2e.png"}}'
WANT='<meta property="og:image" content="http://localhost/images/tracy-brand/logo-e2e.png">'
expect "the home page prints the customer's og:image, once" "$(og_images '')" "$WANT"
expect "so does another page" "$(og_images 'index.php?option=com_users&view=login')" "$WANT"

say "3. apply.revert"
call apply.revert '{"apply_id":"e2e-share"}'
expect "the home page prints no og:image again" "$(og_images '')" ""

if [ -z "${T4_QUICKSTART:-}" ]; then
  say "T4 part SKIPPED: set T4_QUICKSTART to a T4 quickstart folder to prove the T4 fallback"
  finish
  exit 0
fi
# shellcheck source=quickstart-restore.sh
. "$(dirname "$0")/quickstart-restore.sh"
restore_quickstart "$T4_QUICKSTART"
T4="$QS_TEMPLATE"
docker exec -u www-data "$P-joomla" sh -c 'mkdir -p images/tracy-brand && printf %s iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII= | base64 -d > images/tracy-brand/logo-e2e.png'
LOGIN='index.php?option=com_users&view=login'

say "4. the share image set on $T4 (T4)"
expect "before it, the home page prints no og:image" "$(og_images '')" ""
call template.siteSettings "{\"operation\":\"set\",\"apply_id\":\"e2e-share-t4\",\"template\":\"$T4\",\"fields\":{\"other_shareImage\":\"images/tracy-brand/logo-e2e.png\"}}"
expect "the home page prints the customer's og:image, once" "$(og_images '')" "$WANT"
expect "so does another page" "$(og_images "$LOGIN")" "$WANT"

say "5. T4's own Open Graph on the home page"
# What T4's admin writes: system_opengraph in the template's global settings. T4 then prints an
# article's intro image as its og:image (T4\Helper\Metadata::renderOpenGraph).
docker exec -u www-data "$P-joomla" php -r '
  $t = $argv[1]; $g = json_decode(file_get_contents("templates/$t/etc/global.json"), true);
  $g["system_opengraph"] = "1"; @mkdir("templates/$t/local/etc", 0755, true);
  file_put_contents("templates/$t/local/etc/global.json", json_encode($g));' "$T4"
qs_sql "UPDATE ${QS_PREFIX}content SET images = JSON_SET(IF(JSON_VALID(images), images, '{}'), '\$.image_intro', 'images/tracy-brand/t4-own.png')"
docker exec -u www-data "$P-joomla" cp images/tracy-brand/logo-e2e.png images/tracy-brand/t4-own.png
T4_OWN=$(og_images '')
expect "the home page (an article) prints one og:image" "$(printf '%s\n' "$T4_OWN" | grep -c 'og:image' || true)" 1
expect "and it is T4's own" "$(printf '%s' "$T4_OWN" | grep -o 't4-own.png' || true)" "t4-own.png"
expect "another page still prints the customer's" "$(og_images "$LOGIN")" "$WANT"

say "6. apply.revert on $T4"
call apply.revert '{"apply_id":"e2e-share-t4"}'
expect "the home page keeps T4's own og:image" "$(og_images '')" "$T4_OWN"
expect "the other page prints none" "$(og_images "$LOGIN")" ""

finish
