#!/usr/bin/env bash
# Builds the installable package. Two zips: the component, then the package wrapping it.
#
# lib/ is the single source of truth for the engine and is COPIED into the component at
# build time — never edited in place under com_claudecowork/. Editing the copy is how the
# two silently diverge.
set -euo pipefail
cd "$(dirname "$0")"

rm -rf build dist && mkdir -p build/packages dist

# Created, not assumed: the copy is gitignored, so a fresh clone does not have this directory
# and `cp` into a missing one fails. It only ever worked here because the directory survived
# from before it was ignored — the first clone of this repository is what found that out.
mkdir -p com_claudecowork/administrator/lib
cp lib/*.php com_claudecowork/administrator/lib/

( cd com_claudecowork && zip -qr ../build/packages/com_claudecowork.zip . -x '*.DS_Store' )
# The auto-login system plugin ships in the SAME package as the component (ADR 0085): one upgrade
# installs both, so a clone never carries a separate floating extension. It must be a system plugin —
# only that hooks `onAfterInitialise` to set the session before the admin decides auth — but it
# travels inside pkg_claudecowork.
( cd plg_system_tracyaccess && zip -qr ../build/packages/plg_system_tracyaccess.zip . -x '*.DS_Store' )
# The API door plugin answers `api.exec` at onAfterInitialise, before routing and on both clients, so a
# gated front end (coming soon, offline, maintenance) cannot hide the component. Same package, same
# update, for the same reason as the plugin above.
( cd plg_system_claudecoworkapi && zip -qr ../build/packages/plg_system_claudecoworkapi.zip . -x '*.DS_Store' )
cp pkg_claudecowork.xml build/
( cd build && zip -qr ../dist/pkg_claudecowork.zip . -x '*.DS_Store' )

echo "dist/pkg_claudecowork.zip  ($(du -h dist/pkg_claudecowork.zip | cut -f1))"
