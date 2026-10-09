#!/usr/bin/env bash
# End-to-end: once Tracy puts the site name after every page title, the home tab reads the site name
# alone and every other page "Page - Name" (TCH #1013, D1, spec NAME-1), on a throwaway Joomla.
#
#   joomla/cowork/tests/e2e/home-title.sh                # Joomla 6 (JOOMLA_IMAGE, KEEP: joomla-stand.sh)
#   T4_QUICKSTART=<folder> joomla/cowork/tests/e2e/home-title.sh   # and the same on a T4 quickstart
#
# Needs docker and node. Nothing leaves this machine.
#
# ## Why a real Joomla
#
# Joomla formats the title (`HtmlView::setDocumentTitle`) and plg_system_claudecoworkapi changes it at
# onBeforeCompileHead, which tests/run.php never reaches: the rule (lib/HomeTitle.php) is unit tested
# there, the printed <title> here.
#
# ## What it proves
#
# 1. Before: the home tab is Joomla's own ("Home").
# 2. site.identity sets the name and "Page - Site": the home tab is the name alone. Another view
#    reached under the home entry's Itemid (a bare Joomla's login page has no entry of its own, so
#    Joomla titles it by the home entry) keeps Joomla's own "Home - Name": only the home page changes.
# 3. A home entry whose own page title is the name ("Name - Name" in core): the name, once.
# 4. apply.revert: the home tab is Joomla's own again.
# 5. The owner turns "after" on in configuration.php by hand (no mark of Tracy's): Joomla's own title.
# 6. With T4_QUICKSTART: steps 2 and 4 on that T4 template; the login page reads its title, then the
#    name (the run prints the title). Passed on JA Spa j6 1.0.1.
set -euo pipefail
E2E_NAME=hometitle
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

# The <title> a page prints.
title_of() { docker exec "$P-joomla" curl -fsSL "http://localhost/$1" | grep -o '<title>[^<]*</title>' | head -1 | sed 's/<[^>]*>//g' || true; }
LOGIN='index.php?option=com_users&view=login'
NAME='Tamarind Bakery'

say "1. before"
BEFORE=$(title_of '')
expect "the home tab is Joomla's own" "$BEFORE" "Home"

say "2. the name after every page title, set by Tracy"
call site.identity "{\"operation\":\"set\",\"apply_id\":\"e2e-home-title\",\"fields\":{\"sitename\":\"$NAME\"}}"
call site.identity '{"operation":"set","apply_id":"e2e-home-title","fields":{"sitename_pagetitles":2}}'
expect "the home tab is the name alone" "$(title_of '')" "$NAME"
expect "another view under the home entry keeps Joomla's own title" "$(title_of "$LOGIN")" "Home - $NAME"

say "3. a home entry titled by the name"
HOME_ID=$(sql "SELECT id FROM jos_menu WHERE home = 1 AND client_id = 0 LIMIT 1")
sql "UPDATE jos_menu SET params = JSON_SET(params, '\$.page_title', '$NAME') WHERE id = $HOME_ID"
expect "the home tab names the site once" "$(title_of '')" "$NAME"

say "4. apply.revert"
sql "UPDATE jos_menu SET params = JSON_SET(params, '\$.page_title', '') WHERE id = $HOME_ID"
call apply.revert '{"apply_id":"e2e-home-title"}'
expect "the home tab is Joomla's own again" "$(title_of '')" "$BEFORE"
expect "and configuration.php keeps no mark" "$(docker exec "$P-joomla" grep -c tracy_sitename_pagetitles configuration.php || true)" 0

say "5. the owner turns the name on by hand"
docker exec -u www-data "$P-joomla" sed -i "s/public \$sitename_pagetitles = [0-9]*;/public \$sitename_pagetitles = 2;/" configuration.php
SITENAME=$(docker exec "$P-joomla" php -r 'include "configuration.php"; echo (new JConfig)->sitename;')
expect "the home tab is Joomla's own Home - Name" "$(title_of '')" "Home - $SITENAME"
docker exec -u www-data "$P-joomla" sed -i "s/public \$sitename_pagetitles = 2;/public \$sitename_pagetitles = 0;/" configuration.php

if [ -z "${T4_QUICKSTART:-}" ]; then
  say "T4 part SKIPPED: set T4_QUICKSTART to a T4 quickstart folder to prove it on T4"
  finish
  exit 0
fi
# shellcheck source=quickstart-restore.sh
. "$(dirname "$0")/quickstart-restore.sh"
restore_quickstart "$T4_QUICKSTART"

say "6. the same on $QS_TEMPLATE (T4)"
T4_BEFORE=$(title_of '')
T4_LOGIN_PAGE=$(title_of "$LOGIN")
call site.identity "{\"operation\":\"set\",\"apply_id\":\"e2e-home-title-t4\",\"fields\":{\"sitename\":\"$NAME\"}}"
call site.identity '{"operation":"set","apply_id":"e2e-home-title-t4","fields":{"sitename_pagetitles":2}}'
expect "the home tab is the name alone" "$(title_of '')" "$NAME"
expect "another page ($T4_LOGIN_PAGE) reads Page - Name" "$(title_of "$LOGIN")" "$T4_LOGIN_PAGE - $NAME"
call apply.revert '{"apply_id":"e2e-home-title-t4"}'
expect "apply.revert: the home tab is the template's own again" "$(title_of '')" "$T4_BEFORE"

finish
