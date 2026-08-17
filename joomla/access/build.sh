#!/usr/bin/env bash
# Builds the installable plugin zip.
#
# lib/ is the single source of truth for the JWT verifier and is COPIED into the plugin at build
# time — never edited in place under plg_system_tracyaccess/, which is how the two silently
# diverge. Same rule the reader component follows.
set -euo pipefail
cd "$(dirname "$0")"

PLUGIN=plg_system_tracyaccess

[ -f "$PLUGIN/tracyaccess.xml" ] || { echo "$PLUGIN/tracyaccess.xml is missing" >&2; exit 1; }

rm -rf build dist && mkdir -p build dist

# Created, not assumed: the copy is gitignored, so a fresh clone does not have this directory.
mkdir -p "$PLUGIN/lib"
cp lib/*.php "$PLUGIN/lib/"

cp -R "$PLUGIN" "build/$PLUGIN"
( cd "build/$PLUGIN" && zip -qr "../../dist/$PLUGIN.zip" . -x '*.DS_Store' )

# Listed once into a variable: `unzip -l | grep -q` makes grep close the pipe on its first
# match, unzip takes a SIGPIPE, and pipefail then fails the build that just succeeded.
listing="$(unzip -l "dist/$PLUGIN.zip")"
case "$listing" in *tracyaccess.xml*) : ;; *) echo "built a package with no manifest" >&2; exit 1 ;; esac
case "$listing" in *lib/AccessJwt.php*) : ;; *) echo "built a package with no verifier" >&2; exit 1 ;; esac

echo "dist/$PLUGIN.zip  ($(du -h "dist/$PLUGIN.zip" | cut -f1))"
