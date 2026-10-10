#!/usr/bin/env bash
# End-to-end: a module written through the door ends with the ordering the call names, 0 included, on
# a throwaway Joomla.
#
#   joomla/cowork/tests/e2e/module-ordering.sh           # Joomla 6 (JOOMLA_IMAGE, KEEP: joomla-stand.sh)
#
# Needs docker and node. Nothing leaves this machine: every container and network is made here and
# removed at the end.
#
# ## Why a real Joomla
#
# The rule lives in Joomla, not in lib/: `Table\Module::store()` gives a module whose ordering is 0
# the next free slot in its position. Tracy's design-system slot creates one `mod_custom` in
# `header-r` with `ordering: 0` and writes it again the same way; on dskeepjo (tracy-base j6, plugin
# 0.24.8, 10/10/2026) the row was stored at 2, then 3. tests/run.php never reaches JoomlaSiteWriter's
# Table path, so this is the test that would have caught it.
#
# ## What it proves
#
# A module already in the position, then one created with `ordering: 0`:
# 1. the created module is at 0, not the next free slot;
# 2. an update naming `ordering: 0` keeps it at 0;
# 3. an update that names no ordering keeps it where it is (0.24.1);
# 4. an update naming another ordering moves it there;
# 5. apply.revert of that update puts it back at 0;
# 6. a create that names no ordering still gets Joomla's next free slot;
# 7. the asset Joomla mints for a created module sits under com_modules with no rules of its own —
#    the chain the contract's ACL check holds a new row to (lib/QuickstartContract.php expectedAccess).
set -euo pipefail
E2E_NAME=modorder
# shellcheck source=joomla-stand.sh
. "$(dirname "$0")/joomla-stand.sh"

ordering_of() { sql "SELECT ordering FROM jos_modules WHERE id=$1"; }

say "a module already in the position, made through the door"
call content.update '{"apply_id":"e2e-setup","kind":"module","fields":{"title":"Header action","module":"mod_custom","position":"header-r","published":1,"ordering":1,"params":"{}","content":"<p>Call us</p>"}}'
ANCHOR=$(answered_id)
expect "the module already there is at 1" "$(ordering_of "$ANCHOR")" "1"

say "1. a create naming ordering 0"
call content.update '{"apply_id":"e2e-ds","kind":"module","fields":{"title":"Tracy design system","module":"mod_custom","position":"header-r","published":1,"ordering":0,"params":"{}","content":"<style>:root{--x:1}</style>"}}'
DS=$(answered_id)
expect "the created module is at 0" "$(ordering_of "$DS")" "0"

say "2. an update naming ordering 0"
call content.update "$(printf '{"apply_id":"e2e-ds","kind":"module","id":%s,"fields":{"content":"<style>:root{--x:2}</style>","ordering":0}}' "$DS")"
expect "it stays at 0" "$(ordering_of "$DS")" "0"

say "3. an update naming no ordering"
call content.update "$(printf '{"apply_id":"e2e-words","kind":"module","id":%s,"fields":{"title":"Tracy design system (worn)"}}' "$DS")"
expect "it keeps its 0" "$(ordering_of "$DS")" "0"

say "4. an update naming another ordering"
call content.update "$(printf '{"apply_id":"e2e-move","kind":"module","id":%s,"fields":{"ordering":5}}' "$DS")"
expect "it is at 5" "$(ordering_of "$DS")" "5"

say "5. apply.revert of that update"
call apply.revert '{"apply_id":"e2e-move"}'
expect "it is back at 0" "$(ordering_of "$DS")" "0"

say "6. a create naming no ordering"
call content.update '{"apply_id":"e2e-setup","kind":"module","fields":{"title":"Another action","module":"mod_custom","position":"header-r","published":1,"params":"{}","content":"<p>Write to us</p>"}}'
OTHER=$(answered_id)
expect "Joomla gives it the next free slot" "$(ordering_of "$OTHER")" "2"

say "7. the asset Joomla mints for the created module"
expect "it sits under com_modules, with no rules of its own" \
  "$(sql "SELECT CONCAT(p.name, ' ', a.rules) FROM jos_modules m JOIN jos_assets a ON a.id=m.asset_id JOIN jos_assets p ON p.id=a.parent_id WHERE m.id=$DS")" \
  "com_modules {}"

finish
