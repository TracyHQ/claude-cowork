#!/usr/bin/env bash
# Builds the installable plugin zip.
#
# The engine comes from ../joomla/lib — ONE source of truth for both platforms, copied in at
# build time and never edited in place under claude-cowork/. Editing the copy is how the two
# silently diverge, which is the whole reason lib/ knows nothing about either CMS.
#
# The zip's top folder must be the plugin slug: WordPress installs an uploaded archive by
# unpacking it straight into wp-content/plugins/, so the folder inside IS the install path.
set -euo pipefail
cd "$(dirname "$0")"

ENGINE=../joomla/lib

rm -rf build dist && mkdir -p build dist

# Created, not assumed: the copy is gitignored, so a fresh clone does not have this directory
# and `cp` into a missing one fails.
mkdir -p claude-cowork/lib
cp "$ENGINE"/*.php claude-cowork/lib/

cp -R claude-cowork build/claude-cowork
( cd build && zip -qr ../dist/claude-cowork.zip claude-cowork -x '*.DS_Store' )

echo "dist/claude-cowork.zip  ($(du -h dist/claude-cowork.zip | cut -f1))"
