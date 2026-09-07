#!/usr/bin/env bash
# Builds the installable theme zip.
#
# tracy/ is a MIRROR of packages/cms/tracy-wordpress-theme/src/tracy in the TCH repository, put
# here by `node scripts/sync-cms-themes.mjs` over there. Nothing in it is edited here: the 152
# style variations, inspirations.json and the `Version:` line in style.css are generated from the
# vendored design library, and the gate that proves they match runs there.
#
# The zip's top folder must be the theme slug: WordPress unpacks an uploaded archive straight into
# wp-content/themes/, so the folder inside IS the install path.
set -euo pipefail
cd "$(dirname "$0")"

[ -f tracy/style.css ] || { echo "tracy/style.css is missing" >&2; exit 1; }

rm -rf dist && mkdir -p dist
zip -qrX dist/tracy.zip tracy -x '*.DS_Store'

# The listing is read ONCE into a variable: piping it into `grep -q` under `set -o pipefail`
# fails the script even when the pattern matches, because grep leaves early and unzip dies of
# SIGPIPE. Measured here on the first build.
listing=$(unzip -l dist/tracy.zip)
case "$listing" in
  *"tracy/style.css"*) ;;
  *) echo "built a package with no stylesheet header in it" >&2; exit 1 ;;
esac
styles=$(printf '%s\n' "$listing" | grep -c 'tracy/styles/.*\.json' || true)
[ "$styles" -eq 152 ] || { echo "expected 152 design inspirations in the zip, found $styles" >&2; exit 1; }

echo "dist/tracy.zip  ($(du -h dist/tracy.zip | cut -f1), $styles design inspirations)"
