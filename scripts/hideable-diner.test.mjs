// Reduced kit metadata comes from the released Diner 1.0.8 kit: only frames and row slot identities used by the generator.
// Contract/map/lock bytes remain the receiver's immutable profile, and the generated review must match them exactly.
import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { hideableSlots, reviewedRows } from './hideable-slots.mjs'
const read = (name) => JSON.parse(readFileSync(new URL(name, import.meta.url)))
const profile = '../joomla/cowork/lib/contracts/ja-diner/j6/1.0.8/'
const kit = read('./fixtures/ja-diner-j6-1.0.8-hide-kit.json')
const mapBytes = readFileSync(new URL(profile + 'content-map.json', import.meta.url))
const map = JSON.parse(mapBytes)
const lock = read(profile + 'presentation-lock.json')
const entry = read('../joomla/cowork/lib/hideable-slots.json').contracts['ja-diner/j6/1.0.8']

test('Diner receiver review is exactly generated from the released kit metadata and immutable profile', () => {
  assert.equal(kit.contract, read(profile + 'manifest.json').id)
  assert.deepEqual(entry, {
    contentMapSha256: createHash('sha256').update(mapBytes).digest('hex'),
    ...hideableSlots(kit, map), rows: reviewedRows(kit, map, lock)
  })
  assert.equal(entry.contentMapSha256, '6df56fb093a69d27c69e841e1fa3275e0a088e9b5fd925198ca5690907c28635')
  assert.deepEqual([entry.measured.length, entry.unmeasured.length, Object.keys(entry.withRow).length], [29, 250, 26])
})

test('Diner menu removal includes all three parallel arrays and preserves the surrounding module settings', () => {
  for (const id of [100, 101, 102]) {
    assert.deepEqual(entry.rows[`module-${id}`], [{
      column: 'params', nestedJson: 'jatools-config', parent: ['menu', 'menu[data]'],
      arrays: ['menu[data][dish-description]', 'menu[data][dish-name]', 'menu[data][dish-price]'], count: 10
    }])
  }
})

test('Diner review grants measured image hiding without inventing independent alt slots', () => {
  const listed = new Set([...entry.measured, ...entry.unmeasured, ...Object.keys(entry.withRow)])
  for (const slot of map.slots.filter((s) => s.entity === 'contact-1' && s.column === 'name'))
    assert.equal(listed.has(slot.key), false)
  assert.equal(entry.measured.includes('contact-1.16'), true)
  assert.equal(entry.measured.includes('module-97.0'), true)
  assert.equal(listed.has('contact-1.16.alt'), false)
  assert.equal(listed.has('module-97.0.alt'), false)
})
