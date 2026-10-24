#!/usr/bin/env node
// Refuses a Joomla package whose engine exempts other media than the API door plugin ships.
//
//   node scripts/joomla-own-media.mjs <QuickstartContract.php> <tracy-release.json>
//
// The engine holds `OWN_MEDIA` (webroot path → sha256): the plugin's own media, which inspect does
// not report as presentation drift while its bytes are the shipped ones. The release manifest lists
// what the package installs, read off the same manifests the installer follows. Every file it lists
// under the plugin's media folder must be in `OWN_MEDIA` with the same hash, and nothing else may be:
// a stale hash makes every site warn about the plugin's own script, an extra entry exempts a file
// no release ships. tests/contracts.php holds the source tree to the same rule.

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const MEDIA_ROOT = 'media/plg_system_claudecoworkapi/'

/** `OWN_MEDIA` as the PHP source declares it. */
export function ownMedia(phpSource) {
  const block = phpSource.match(/private const OWN_MEDIA = \[([\s\S]*?)\];/)
  if (!block) throw new Error('QuickstartContract.php declares no OWN_MEDIA')
  const out = {}
  for (const m of block[1].matchAll(/'([^']+)'\s*=>\s*'([^']+)'/g)) out[m[1]] = m[2]
  return out
}

/** The plugin's media the release manifest lists, path → sha256. */
export function shippedMedia(release) {
  return Object.fromEntries(Object.entries(release.files).filter(([path]) => path.startsWith(MEDIA_ROOT)))
}

/** Every way the two differ, as sentences; empty when they agree. */
export function differences(own, shipped) {
  const out = []
  for (const [path, hash] of Object.entries(shipped)) {
    if (!(path in own)) out.push(`${path} ships but OWN_MEDIA does not list it`)
    else if (own[path] !== hash) out.push(`${path} ships as ${hash}, OWN_MEDIA says ${own[path]}`)
  }
  for (const path of Object.keys(own)) if (!(path in shipped)) out.push(`OWN_MEDIA lists ${path}, which no file ships`)
  return out
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const [php, manifest] = process.argv.slice(2)
  if (!php || !manifest) {
    console.error('usage: joomla-own-media.mjs <QuickstartContract.php> <tracy-release.json>')
    process.exit(2)
  }
  const problems = differences(ownMedia(readFileSync(php, 'utf8')), shippedMedia(JSON.parse(readFileSync(manifest, 'utf8'))))
  if (problems.length) {
    console.error('✘ OWN_MEDIA in QuickstartContract.php does not match the media the package ships:')
    for (const p of problems) console.error(`  ${p}`)
    process.exit(1)
  }
}
