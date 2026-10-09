#!/usr/bin/env bash
# End-to-end: a template without T4 prints the customer's share image as og:image, on a throwaway Joomla.
#
#   joomla/cowork/tests/e2e/share-image.sh               # Joomla 6 (JOOMLA_IMAGE, KEEP: joomla-stand.sh)
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

finish
