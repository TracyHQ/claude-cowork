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
// would move), and a whole column of a row (a menu item's title), which Joomla needs.
//
// A contact's `email_to` is the one column that is listed, as unmeasured: Joomla needs no address on a contact, and the column
// does two jobs. It is the address the page prints and where the contact form sends its mail, so with it empty the page prints
// no address and draws no form. That is what a plan asks when a site has no address to take the form's mail. A contact form's
// field `hint` goes with it, a word held in a field's params that nothing else reads.
// Reproducible: keys sorted, no time, no path.
//
// Beside them, `rows`: where `content.contract rows.remove` may take a whole row, per entity, read from the contract's own
// rows and held against the kit's. Lists: lists of one length under one parent, read across by index, the way an ACM's
// repeater prints them; every list of that length goes in, slot or not, or the row would part from it. Keys: a subform's
// `name0`, `name1` under `name`. A parent with lists of two lengths above one proves nothing, and neither does a kit row
// that spans two indexes, so neither is listed and a slot there is refused by name.

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

/** A contact's address, or the hint of a contact form's field: words of the form, which goes when the address does. */
const isFormWord = (slot) =>
  (kindOf(slot) === 'contact' && slot.column === 'email_to') ||
  (kindOf(slot) === 'field' && slot.column === 'params' && slot.jsonPath?.at(-1) === 'hint')

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
    if (isFormWord(slot)) {
      unmeasured.push(slot.key)
      continue
    }
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

const JSON_COLUMNS = ['params', 'attribs', 'images', 'urls', 'metadata']

/** One entity's decoded JSON columns, with `jatools-config` opened: [{ column, nestedJson, body }]. */
function bodiesOf(row) {
  const out = []
  for (const column of JSON_COLUMNS) {
    let body = null
    try {
      body = JSON.parse(row?.[column] ?? 'null')
    } catch {}
    if (!body || typeof body !== 'object') continue
    out.push({ column, nestedJson: null, body })
    if (typeof body['jatools-config'] === 'string')
      out.push({ column, nestedJson: 'jatools-config', body: JSON.parse(body['jatools-config']) })
  }
  return out
}

/** Which row of a table each kit row's slots say they are in: false when one kit row spans two indexes of a parent. */
function kitAgrees(kit, map, list) {
  const bySlot = new Map(map.slots.map((s) => [s.key, s]))
  const depth = list.parent.length
  for (const block of kit.blocks ?? [])
    for (const kitList of block.lists ?? [])
      for (const row of kitList.rows ?? []) {
        const indexes = new Set()
        for (const entry of row.slots ?? []) {
          const slot = bySlot.get(slotKeyOf(typeof entry === 'string' ? entry : entry?.id))
          const path = slot?.jsonPath
          if (!slot || slot.entity !== list.entity || slot.column !== list.column || !Array.isArray(path)) continue
          if ((slot.nestedJson ?? null) !== list.nestedJson) continue
          if (JSON.stringify(path.slice(0, depth)) !== JSON.stringify(list.parent)) continue
          if (list.arrays.includes(path[depth]) && Number.isInteger(path[depth + 1])) indexes.add(path[depth + 1])
        }
        if (indexes.size > 1) return false
      }
  return true
}

/** @returns {Record<string, object[]>} entity to its provable rows; lists sorted by name, so the file is reproducible */
export function reviewedRows(kit, map, lock) {
  const out = {}
  const seen = new Set()
  for (const slot of map.slots) {
    const path = slot.jsonPath
    if (!Array.isArray(path) || path.length < 2 || !WORD_KINDS.has(kindOf(slot))) continue
    const nestedJson = slot.nestedJson ?? null
    const place = bodiesOf(lock.entities?.[slot.entity]).find((b) => b.column === slot.column && b.nestedJson === nestedJson)
    if (!place) continue
    const walk = (steps) => steps.reduce((value, step) => (value && typeof value === 'object' ? value[step] : undefined), place.body)
    const add = (parent, entry) => {
      const id = JSON.stringify([slot.entity, slot.column, nestedJson, parent])
      if (seen.has(id)) return
      seen.add(id)
      const list = { column: slot.column, ...(nestedJson ? { nestedJson } : {}), parent, ...entry }
      if (entry.arrays && !kitAgrees(kit, map, { entity: slot.entity, column: slot.column, nestedJson, parent, arrays: entry.arrays })) return
      ;(out[slot.entity] ??= []).push(list)
    }
    for (let at = 0; at < path.length; at++) {
      const parent = path.slice(0, at)
      const holder = walk(parent)
      if (!holder || typeof holder !== 'object' || Array.isArray(holder)) continue
      const step = path[at]
      if (Number.isInteger(path[at + 1]) && Array.isArray(holder[step])) {
        const lists = Object.entries(holder).filter(([, value]) => Array.isArray(value))
        const long = new Set(lists.map(([, value]) => value.length).filter((n) => n > 1))
        if (long.size === 1 && holder[step].length > 1)
          add(parent, { arrays: lists.filter(([, v]) => v.length === holder[step].length).map(([k]) => k).sort(), count: holder[step].length })
      } else if (at > 0 && String(path[at - 1]) !== '' && typeof holder[step] === 'object') {
        const name = String(path[at - 1])
        const keys = Object.keys(holder)
        if (keys.length && keys.every((k) => k.startsWith(name) && /^\d+$/.test(k.slice(name.length))))
          add(parent, { keys: keys.sort() })
      }
    }
  }
  return Object.fromEntries(Object.entries(out).sort(([a], [b]) => (a < b ? -1 : 1)))
}

function main([kitFile, profile, out]) {
  if (!kitFile || !profile || !out) throw new Error('usage: hideable-slots.mjs <template-kit.json> <contract profile dir> <hideable-slots.json>')
  const kit = JSON.parse(readFileSync(kitFile, 'utf8'))
  const mapBytes = readFileSync(join(profile, 'content-map.json'))
  const id = JSON.parse(readFileSync(join(profile, 'manifest.json'), 'utf8')).id
  if (kit.contract !== id) throw new Error(`the kit is of ${kit.contract}, the profile is ${id}`)
  const file = existsSync(out) ? JSON.parse(readFileSync(out, 'utf8')) : { contracts: {} }
  const map = JSON.parse(mapBytes.toString('utf8'))
  const lock = JSON.parse(readFileSync(join(profile, 'presentation-lock.json'), 'utf8'))
  const entry = { ...hideableSlots(kit, map), rows: reviewedRows(kit, map, lock) }
  file.contracts[id] = { contentMapSha256: createHash('sha256').update(mapBytes).digest('hex'), ...entry }
  file.contracts = Object.fromEntries(Object.entries(file.contracts).sort(([a], [b]) => (a < b ? -1 : 1)))
  writeFileSync(out, `${JSON.stringify(file, null, 2)}\n`)
  console.log(`${id}: ${entry.measured.length} measured, ${entry.unmeasured.length} unmeasured, ${Object.keys(entry.withRow).length} with their row, rows on ${Object.keys(entry.rows).length} entities`)
}

if (process.argv[1] === fileURLToPath(import.meta.url)) main(process.argv.slice(2))
