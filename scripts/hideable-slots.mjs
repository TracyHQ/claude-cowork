#!/usr/bin/env node
// Writes one contract's entry of joomla/cowork/lib/hideable-slots.json from Tracy's template kit, the file that
// measures whether a block still looks whole without a slot. A site holds no kit, so the receiver carries the verdicts.
//
//   node scripts/hideable-slots.mjs <template-kit.json> <contract profile dir> <hideable-slots.json>
//
// The kit gives a verdict on picture frames and on fixed words (those go through language overrides, not slots). It
// gives none on word and link slots, and the file says which is which:
//
//   measured    a frame the kit emptied, and the block held (canHide: true).
//   unmeasured  a word slot the kit has no verdict on. Its page is what shows whether it holds.
//   withRow     a link, or a frame the kit says looks wrong emptied alone (canHide: false): the receiver takes it only
//               when another slot of its row goes too. Listed with that row.
//
// Never listed, so always refused: a slot that is empty already, one a text node or attribute addresses (its address
// would move), and a whole column of a row (a menu item's title, a contact's email), which Joomla or a form needs.
// Reproducible: keys sorted, no time, no path.

import { createHash } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ADDRESS_MOVES = /\/(?:text\(\)(?:\[\d+\])?|@[A-Za-z-]+)$/
const WORD_KINDS = new Set(['module', 'article'])

/** The kit names a module `block-<id>`; the content map names it `module-<id>`. */
export const slotKeyOf = (id) => String(id).replace(/^block-/, 'module-')

/** Which row of its entity a JSON slot belongs to: `[acm, field, 2]` is row 2 of the acm, `[list, row3, field]` is row3. */
export function rowOf(slot) {
  const path = slot.jsonPath
  if (!Array.isArray(path) || path.length < 2) return null
  const last = path[path.length - 1]
  const row = typeof last === 'number' || /^\d+$/.test(String(last)) ? `${path[0]}#${last}` : path.slice(0, -1).join('/')
  return `${slot.entity}|${row}`
}

const movable = (slot) => !ADDRESS_MOVES.test(String(slot.xpath ?? ''))
const occupied = (slot) => String(slot.sample ?? '').trim() !== ''
const kindOf = (slot) => String(slot.entity).split('-')[0]

/** @returns {{measured: string[], unmeasured: string[], withRow: Record<string, string[]>}} */
export function hideableSlots(kit, map) {
  const frames = new Map(kit.blocks.flatMap((b) => b.frames ?? []).map((f) => [slotKeyOf(f.id), f]))
  const rows = new Map()
  for (const slot of map.slots) {
    const row = occupied(slot) && movable(slot) ? rowOf(slot) : null
    if (row) rows.set(row, [...(rows.get(row) ?? []), slot])
  }
  const others = (slot) => {
    const row = rows.get(rowOf(slot)) ?? []
    const rest = row.filter((s) => s.key !== slot.key)
    return rest.some((s) => s.type !== 'url') ? rest.map((s) => s.key).sort() : []
  }
  const measured = []
  const unmeasured = []
  const withRow = {}
  for (const slot of map.slots) {
    if (!occupied(slot) || !movable(slot)) continue
    const frame = frames.get(slot.key)
    if (frame) {
      if (frame.canHide === true) measured.push(slot.key)
      else if (frame.canHide === false && others(slot).length) withRow[slot.key] = others(slot)
      continue
    }
    const held = slot.jsonPath || slot.xpath
    if (slot.type === 'url' && WORD_KINDS.has(kindOf(slot)) && slot.jsonPath && !slot.xpath && others(slot).length) withRow[slot.key] = others(slot)
    else if (slot.type === 'text' && ((WORD_KINDS.has(kindOf(slot)) && held) || kindOf(slot) === 'languageString')) unmeasured.push(slot.key)
  }
  const sorted = Object.fromEntries(Object.entries(withRow).sort(([a], [b]) => (a < b ? -1 : 1)))
  return { measured: measured.sort(), unmeasured: unmeasured.sort(), withRow: sorted }
}

function main([kitFile, profile, out]) {
  if (!kitFile || !profile || !out) throw new Error('usage: hideable-slots.mjs <template-kit.json> <contract profile dir> <hideable-slots.json>')
  const kit = JSON.parse(readFileSync(kitFile, 'utf8'))
  const mapBytes = readFileSync(join(profile, 'content-map.json'))
  const id = JSON.parse(readFileSync(join(profile, 'manifest.json'), 'utf8')).id
  if (kit.contract !== id) throw new Error(`the kit is of ${kit.contract}, the profile is ${id}`)
  const file = existsSync(out) ? JSON.parse(readFileSync(out, 'utf8')) : { contracts: {} }
  const entry = hideableSlots(kit, JSON.parse(mapBytes.toString('utf8')))
  file.contracts[id] = { contentMapSha256: createHash('sha256').update(mapBytes).digest('hex'), ...entry }
  file.contracts = Object.fromEntries(Object.entries(file.contracts).sort(([a], [b]) => (a < b ? -1 : 1)))
  writeFileSync(out, `${JSON.stringify(file, null, 2)}\n`)
  console.log(`${id}: ${entry.measured.length} measured, ${entry.unmeasured.length} unmeasured, ${Object.keys(entry.withRow).length} with their row`)
}

if (process.argv[1] === fileURLToPath(import.meta.url)) main(process.argv.slice(2))
