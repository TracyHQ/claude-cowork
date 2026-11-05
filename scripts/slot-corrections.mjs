#!/usr/bin/env node
// Writes one contract's entry of joomla/cowork/lib/slot-corrections.json from Tracy's template kit. A released contract
// is never edited and a bound site stores its hash, so what the kit knows better than the contract rides beside it, as
// receiver data tied to the sha256 of the content map it was read against. A site holds no kit, so the receiver carries it.
//
//   node scripts/slot-corrections.mjs <template-kit.json> <contract profile dir> <slot-corrections.json>
//
// Three rules, each read from the kit and the map, never a hand-kept list:
//
//   limits  a text slot whose measured fit is under the kit's one limit: writers are given the kit's number, and the kit
//           read the frame the fit only estimated. Capped by the map's own number, which the door still holds. A fit the
//           door already treats as the box of the demo words (demo length + 3) refuses nothing and is not listed.
//   icons   a picture the contract keeps because it is an icon on a transparent ground (kind icon, replace keep,
//           transparent). The receiver takes a transparent drawing there and still refuses an opaque picture.
//   alts    the alt of a gallery row whose sample is empty, beside the row's occupied picture. It has no box, so it takes
//           ALT_LIMIT, and the empty sample stops refusing it.
//
// Reproducible: keys sorted, no time, no path.

import { createHash } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { slotKeyOf } from './hideable-slots.mjs'

/** An alt is read aloud, not laid out: the usual ceiling for a screen reader's single reading. */
export const ALT_LIMIT = 125
/** SlotFit::DEMO_BOX_SLACK: a limit this close to the demo words is already no limit at the door. */
const DEMO_BOX_SLACK = 3

const sorted = (entries) => Object.fromEntries(entries.sort(([a], [b]) => (a < b ? -1 : 1)))
const characters = (text) => [...text].length

/** What a visitor reads of a sample, as SlotFit::visibleLength counts it. */
const visibleLength = (sample) =>
  characters(
    String(sample ?? '')
      .replace(/\(\(\/?[a-z][a-z0-9]*(?:\s[^()]*)?\/?\)\)/gi, ' ')
      .replace(/\s+/gu, ' ')
      .trim()
  )

/** The limit a measured fit holds a text to, as SlotFit::checkText reads it, or null. */
function fitLimit(fit) {
  if (fit?.measured !== true) return null
  if (Number.isInteger(fit.maxCharacters)) return fit.maxCharacters
  const all = Object.values(fit.maxChars ?? {}).filter(Number.isInteger)
  return all.length ? Math.min(...all) : null
}

/** Every text slot the kit's blocks list, wherever they list it: content map key to its one limit. */
export function kitLimits(kit) {
  const out = new Map()
  const walk = (x) => {
    if (Array.isArray(x)) x.forEach(walk)
    else if (x && typeof x === 'object') {
      if (typeof x.id === 'string' && x.type === 'text' && Number.isInteger(x.maxCharacters))
        out.set(slotKeyOf(x.id), x.maxCharacters)
      Object.values(x).forEach(walk)
    }
  }
  walk(kit.blocks)
  return out
}

const isGalleryAlt = (slot) =>
  slot.type === 'text' &&
  String(slot.sample ?? '').trim() === '' &&
  slot.group?.kind === 'gallery' &&
  Array.isArray(slot.jsonPath) &&
  slot.jsonPath.some((part) => /\[alt\]$/.test(String(part)))

/** @returns {{limits: Record<string, number>, icons: string[], alts: Record<string, number>}} */
export function slotCorrections(kit, map) {
  const fromKit = kitLimits(kit)
  const limits = []
  for (const slot of map.slots) {
    if (slot.type !== 'text') continue
    const fit = fitLimit(slot.fit)
    const kitLimit = fromKit.get(slot.key)
    if (fit === null || kitLimit === undefined || fit <= visibleLength(slot.sample) + DEMO_BOX_SLACK) continue
    const limit = Math.min(kitLimit, slot.maxCharacters)
    if (limit > fit) limits.push([slot.key, limit])
  }
  const icons = map.slots
    .filter((s) => s.type === 'image' && s.fit?.kind === 'icon' && s.fit.replace === 'keep' && s.fit.transparent === true)
    .map((s) => s.key)
    .sort()
  const picturedGroups = new Set(
    map.slots
      .filter((s) => s.type === 'image' && s.group?.id && String(s.sample ?? '').trim() !== '')
      .map((s) => `${s.entity}|${s.group.id}`)
  )
  const alts = map.slots
    .filter((s) => isGalleryAlt(s) && picturedGroups.has(`${s.entity}|${s.group.id}`))
    .map((s) => [s.key, ALT_LIMIT])
  return { limits: sorted(limits), icons, alts: sorted(alts) }
}

const COMMENT =
  "What Tracy's template kit knows better than a released contract, per contract, written by scripts/slot-corrections.mjs and tied to the sha256 of the content map it was read against. limits: the kit's limit in place of the slot's fit. icons: a kept icon that takes a transparent drawing. alts: a gallery alt that may fill its empty sample, with its limit. Receiver data, not contract bytes: it changes no pinned contract hash. A slot not listed is held as before."

function main([kitFile, profile, out]) {
  if (!kitFile || !profile || !out) throw new Error('usage: slot-corrections.mjs <template-kit.json> <contract profile dir> <slot-corrections.json>')
  const kit = JSON.parse(readFileSync(kitFile, 'utf8'))
  const mapBytes = readFileSync(join(profile, 'content-map.json'))
  const id = JSON.parse(readFileSync(join(profile, 'manifest.json'), 'utf8')).id
  if (kit.contract !== id) throw new Error(`the kit is of ${kit.contract}, the profile is ${id}`)
  const file = existsSync(out) ? JSON.parse(readFileSync(out, 'utf8')) : { _comment: COMMENT, contracts: {} }
  const entry = slotCorrections(kit, JSON.parse(mapBytes.toString('utf8')))
  file.contracts[id] = { contentMapSha256: createHash('sha256').update(mapBytes).digest('hex'), ...entry }
  file.contracts = sorted(Object.entries(file.contracts))
  writeFileSync(out, `${JSON.stringify(file, null, 2)}\n`)
  console.log(`${id}: ${Object.keys(entry.limits).length} limits, ${entry.icons.length} icons, ${Object.keys(entry.alts).length} alts`)
}

if (process.argv[1] === fileURLToPath(import.meta.url)) main(process.argv.slice(2))
