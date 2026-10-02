#!/usr/bin/env bash
# Builds the installable template zip.
#
# tpl_tracy/ is a MIRROR of packages/cms/tracy-joomla-template/src/tpl_tracy in the TCH
# repository, put here by `node scripts/sync-cms-themes.mjs` over there. Nothing in it is edited
# here: the token files, the fixture sheets, inspirations.json and templateDetails.xml are
# generated from the vendored design library, and the gate that proves they match runs there.
#
# The manifest must sit at the ROOT of the zip — that is how Joomla's installer finds it — so the
# archive is made from INSIDE tpl_tracy/, not from a parent folder.
set -euo pipefail
cd "$(dirname "$0")"

[ -f tpl_tracy/templateDetails.xml ] || { echo "tpl_tracy/templateDetails.xml is missing" >&2; exit 1; }

rm -rf build dist && mkdir -p build dist
# Staged, because the mirror is not written here: the release manifest goes into a copy.
cp -R tpl_tracy build/tpl_tracy
find build/tpl_tracy \( -name '.DS_Store' -o -name '*.tpl.xml' \) -delete
# Joomla installs by the <files> list, so the manifest only reaches a site if templateDetails.xml
# names it. The mirror's generator in TCH does not (yet): the line is added to the STAGED copy, once,
# and the release manifest below hashes the copy that ships. When TCH emits it, this is a no-op.
if ! grep -q '<filename>tracy-release.json</filename>' build/tpl_tracy/templateDetails.xml; then
  sed -i.bak 's|<filename>templateDetails.xml</filename>|<filename>templateDetails.xml</filename>\
    <filename>tracy-release.json</filename>|' build/tpl_tracy/templateDetails.xml
  rm -f build/tpl_tracy/templateDetails.xml.bak
  grep -q '<filename>tracy-release.json</filename>' build/tpl_tracy/templateDetails.xml || {
    echo "could not name tracy-release.json in templateDetails.xml" >&2; exit 1
  }
fi
# The release manifest: every file the template puts on a site (templates/tpl_tracy/, its media,
# its language files), with its sha256. Ships in the zip, and in dist/ as its own release asset.
node ../../scripts/release-manifest.mjs joomla-template build/tpl_tracy build/tpl_tracy/tracy-release.json
cp build/tpl_tracy/tracy-release.json dist/tracy-release.json
( cd build/tpl_tracy && zip -qrX ../../dist/tpl_tracy.zip . )

# The listing is read ONCE into a variable: piping it into `grep -q` under `set -o pipefail`
# fails the script even when the pattern matches, because grep leaves early and unzip dies of
# SIGPIPE. Measured here on the first build.
listing=$(unzip -l dist/tpl_tracy.zip)
case "$listing" in
  *" templateDetails.xml"*) ;;
  *) echo "built a package with no manifest at its root" >&2; exit 1 ;;
esac
styles=$(printf '%s\n' "$listing" | grep -c 'media/css/inspirations/.*\.css' || true)
# How many is not typed here: the catalogue grows (152 until 16/09/2026, 154 since), and the
# number the zip must carry is the one the mirrored `inspirations.json` lists, generated in TCH
# from the same vendored library as the style files, so the two can only disagree if a file is lost.
expected=$(grep -o '"name"' tpl_tracy/inspirations.json | wc -l | tr -d ' ')
[ "$expected" -gt 0 ] || { echo "could not count the design inspirations in tpl_tracy/inspirations.json" >&2; exit 1; }
[ "$styles" -eq "$expected" ] || { echo "expected $expected design inspirations in the zip (inspirations.json), found $styles" >&2; exit 1; }

echo "dist/tpl_tracy.zip  ($(du -h dist/tpl_tracy.zip | cut -f1), $styles design inspirations)"
