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
# Staged, because the mirror is not written here: the release manifest goes into a copy.
# update.json is the folder's announcement, read raw off main by the sites: never inside the zip.
rm -rf "build/$slug" && mkdir -p build && cp -R "$slug" "build/$slug"
rm -f "build/$slug/update.json"
find "build/$slug" -name '.DS_Store' -delete
# The release manifest: every file the zip puts under wp-content/themes/<slug>/, with its sha256.
# Ships in the zip, and in dist/ to be attached to the release as its own asset — overwritten by
# the next theme's build, which is why scripts/release-wordpress-theme.mjs builds right before it uploads.
node ../../scripts/release-manifest.mjs wordpress-theme "build/$slug" "build/$slug/tracy-release.json"
cp "build/$slug/tracy-release.json" dist/tracy-release.json
( cd build && zip -qrX "../dist/$slug.zip" "$slug" -x '*.DS_Store' )

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
# How many is not typed here: the catalogue grows (152 until 16/09/2026, 154 since), and the
# number the zip must carry is the one the mirrored `inspirations.json` lists — generated in TCH
# from the same vendored library as the style files, so the two can only disagree if a file is lost.
if [ "$slug" = tracy ]; then
  expected=$(grep -o '"name"' "$slug/inspirations.json" | wc -l | tr -d ' ')
  [ "$expected" -gt 0 ] || { echo "could not count the design inspirations in $slug/inspirations.json" >&2; exit 1; }
  [ "$styles" -eq "$expected" ] || { echo "expected $expected design inspirations in the zip (inspirations.json), found $styles" >&2; exit 1; }
else
  [ "$styles" -ge 1 ] || { echo "expected the theme's own style variation in the zip, found none" >&2; exit 1; }
fi

echo "dist/$slug.zip  ($(du -h "dist/$slug.zip" | cut -f1), $styles style variation(s))"
