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
  assert.deepEqual([entry.measured.length, entry.unmeasured.length, Object.keys(entry.withRow).length], [38, 258, 43])
})

// Arabian Tea House on JA Diner 1.0.8 (dev, 16/11/2026): the plan hid the demo's street, web page and Twitter link of the
// contact page, and each was refused as not listed, so the demo's "1600 Amphitheatre Parkway" stayed on the page.
test('Diner contact: the street, the web page and each social link may go; a link name only with its address', () => {
  for (const key of ['contact-1.1', 'contact-1.5', 'contact-1.9', 'contact-1.11', 'contact-1.13', 'contact-1.15'])
    assert.equal(entry.unmeasured.includes(key), true, key)
  assert.deepEqual(entry.withRow['contact-1.14'], ['contact-1.15'])
  assert.deepEqual(entry.withRow['contact-1.8'], ['contact-1.9'])
  assert.equal(entry.unmeasured.includes('contact-1.0'), false, 'the name Joomla needs')
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

// The 1.0.8 kit rebuilt with template-kit 3.1.3 (TracyHQ/tracy#667) holds a block for the modules the 3.1.0 kit never
// read: module-95 (features), module-104 (chef) and the about page's 115, 116, 117, 121, 122, 123. Their pictures are
// reviewed now; before, a plan's hide of the features pictures was refused and the demo's photos stayed (dev, 16/11/2026).
test('Diner review covers the pictures of the modules the rebuilt kit added', () => {
  assert.deepEqual(entry.withRow['module-95.3'], ['module-95.0', 'module-95.1', 'module-95.11', 'module-95.2', 'module-95.5', 'module-95.7', 'module-95.9'])
  assert.deepEqual(entry.withRow['module-104.0'], ['module-104.1'])
  for (const key of ['module-116.0', 'module-123.2', 'module-123.9']) assert.equal(entry.measured.includes(key), true, key)
  for (const key of ['module-121.1', 'module-122.3']) assert.equal(Array.isArray(entry.withRow[key]), true, key)
})
