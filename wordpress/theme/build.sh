#!/usr/bin/env bash
# Builds the installable theme zip: `./build.sh` for `tracy`, `./build.sh <slug>` for a template's
# own theme mirrored beside it (`wp-ja-kinetic`).
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

slug="${1:-tracy}"
case "$slug" in
  *[!a-z0-9-]*|'') echo "a theme slug is lower-case letters, digits and dashes: $slug" >&2; exit 1 ;;
esac
[ -f "$slug/style.css" ] || { echo "$slug/style.css is missing" >&2; exit 1; }

mkdir -p dist && rm -f "dist/$slug.zip"
# update.json is the folder's announcement, read raw off main by the sites: never inside the zip.
zip -qrX "dist/$slug.zip" "$slug" -x '*.DS_Store' "$slug/update.json"

# The listing is read ONCE into a variable: piping it into `grep -q` under `set -o pipefail`
# fails the script even when the pattern matches, because grep leaves early and unzip dies of
# SIGPIPE. Measured here on the first build.
listing=$(unzip -l "dist/$slug.zip")
case "$listing" in
  *"$slug/style.css"*) ;;
  *) echo "built a package with no stylesheet header in it" >&2; exit 1 ;;
esac
styles=$(printf '%s\n' "$listing" | grep -c "$slug/styles/.*\\.json" || true)
# The Tracy theme carries every design inspiration; a template's theme carries its own look only.
if [ "$slug" = tracy ]; then
  [ "$styles" -eq 152 ] || { echo "expected 152 design inspirations in the zip, found $styles" >&2; exit 1; }
else
  [ "$styles" -ge 1 ] || { echo "expected the theme's own style variation in the zip, found none" >&2; exit 1; }
fi

echo "dist/$slug.zip  ($(du -h "dist/$slug.zip" | cut -f1), $styles style variation(s))"
