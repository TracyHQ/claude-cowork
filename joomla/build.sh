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
cp pkg_claudecowork.xml build/
( cd build && zip -qr ../dist/pkg_claudecowork.zip . -x '*.DS_Store' )

echo "dist/pkg_claudecowork.zip  ($(du -h dist/pkg_claudecowork.zip | cut -f1))"
