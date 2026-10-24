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
# 🔒 CLEARED, NOT JUST CREATED. The copy is gitignored, so it survives between builds — and a file
# that MOVED or was deleted in lib/ keeps shipping from here, silently, for as long as the working
# copy lives. Measured 2026-09-12: `language-packs.json` moved out of `lib/contracts/` and the
# package still carried it in the old place as well as the new one, with every gate green.
rm -rf com_claudecowork/administrator/lib
mkdir -p com_claudecowork/administrator/lib
cp lib/*.php com_claudecowork/administrator/lib/
# 🔒 NO CONTRACT PROFILE TRAVELS IN THE PACKAGE (TCH #1568). All 225 of them were 529 MB unpacked and a
# 70 MB zip, and every site kept every one (a webroot measured going from 182 MB to 628 MB). Each
# profile is a release asset of its own, dist/contracts/<id with "/" as "--">.zip; the component
# carries only the index of them, lib/contracts.json (id → asset, sha256, bytes), and a site fetches
# the one it is held to and checks it against that index (lib/ContractFetch.php). lib/contracts/
# holds nothing else the engine reads: its README is for this repository.
node ../../scripts/contract-assets.mjs lib/contracts dist/contracts com_claudecowork/administrator/lib/contracts.json
# The language-pack catalog is receiver-wide, not part of any contract, so it is copied beside the
# engine rather than swept up by the contracts copy above. Named explicitly because a file the
# package forgets is a receiver that reports every locale as unverified, with no error anywhere.
cp lib/language-packs.json com_claudecowork/administrator/lib/
# The reviewed ACM layouts whose links content.contract may empty (empty_links): receiver data like the
# catalog above, not contract bytes. Without it a receiver empties no link at all, again with no error.
cp lib/link-layouts.json com_claudecowork/administrator/lib/

# The release manifest: every file the package puts on a site, by its webroot path, with its sha256
# — read off the extension manifests the installer follows, after the engine copy above and before
# anything is zipped. It ships inside the component (administrator/components/com_claudecowork/
# tracy-release.json) and beside the zip in dist/, to be attached to the release as its own asset.
node ../../scripts/release-manifest.mjs joomla-package . com_claudecowork/administrator/tracy-release.json
# 🔒 The engine exempts the API door plugin's own media from inspect only by the hashes it holds
# (QuickstartContract::OWN_MEDIA). A script changed without them would make every T3 site warn about
# it after the update, so a package whose hashes are not the ones it ships is not built.
node ../../scripts/joomla-own-media.mjs com_claudecowork/administrator/lib/QuickstartContract.php com_claudecowork/administrator/tracy-release.json

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
# The self-updater takes the releases Tracy announces instead of showing them and waiting — Joomla
# core notifies and stops there. Same package, same update, for the same reason as the two above.
( cd plg_system_claudecoworkupdate && zip -qr ../build/packages/plg_system_claudecoworkupdate.zip . -x '*.DS_Store' )
cp pkg_claudecowork.xml build/
# The package's own installer script: the update receipt (#__claudecowork_update_log).
cp script.php build/
( cd build && zip -qr ../dist/pkg_claudecowork.zip . -x '*.DS_Store' )
cp com_claudecowork/administrator/tracy-release.json dist/tracy-release.json

echo "dist/pkg_claudecowork.zip  ($(du -h dist/pkg_claudecowork.zip | cut -f1))"
echo "dist/tracy-release.json    (attach to the release as its own asset)"
echo "dist/contracts/*.zip       ($(ls dist/contracts | wc -l | tr -d ' ') profiles; attach every one to the release)"
