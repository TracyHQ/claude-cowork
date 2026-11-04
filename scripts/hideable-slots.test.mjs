import assert from 'node:assert/strict'
import { test } from 'node:test'

import { reviewedRows, hideableSlots, rowOf, slotKeyOf } from './hideable-slots.mjs'

// A made-up block: one list of two rows, a lone sentence and a few slots no one may empty.
const json = (key, path, type, sample) => ({ key, entity: key.split('.')[0], column: 'params', type, sample, jsonPath: path })
const map = {
  slots: [
    json('module-1.0', ['cards', 'cards[title]', 0], 'text', 'Made-up title'),
    json('module-1.1', ['cards', 'cards[link]', 0], 'url', '/made-up'),
    json('module-1.2', ['cards', 'cards[picture]', 0], 'image', 'images/made-up/a.png'),
    json('module-1.3', ['cards', 'cards[picture]', 1], 'image', 'images/made-up/b.png'),
    json('module-1.4', ['cards', 'cards[title]', 1], 'text', ''),
    json('module-1.5', ['solo', 'solo[link]', 0], 'url', '/alone'),
    { key: 'article-2.0', entity: 'article-2', column: 'attribs', type: 'text', sample: 'A whole sentence', jsonPath: ['body'], xpath: '/html/body/div/p' },
    { key: 'article-2.1', entity: 'article-2', column: 'attribs', type: 'text', sample: 'A loose word', jsonPath: ['body'], xpath: '/html/body/div/text()[2]' },
    { key: 'article-2.2', entity: 'article-2', column: 'attribs', type: 'url', sample: '/a', jsonPath: ['body'], xpath: '/html/body/div/a/@href' },
    { key: 'languageString-9.0', entity: 'languageString-9', column: 'value', type: 'text', sample: 'A made-up string' },
    { key: 'menuItem-3.0', entity: 'menuItem-3', column: 'title', type: 'text', sample: 'Made-up page' },
    { key: 'contact-4.0', entity: 'contact-4', column: 'email_to', type: 'text', sample: 'nobody@example.invalid' }
  ]
}
const kit = { blocks: [{ frames: [{ id: 'block-1.2', canHide: true }, { id: 'block-1.3', canHide: false }] }] }

test('a kit id names its content map slot', () => assert.equal(slotKeyOf('block-156.3'), 'module-156.3'))

test('a row is the index of a parallel array, or the parent of a subform field', () => {
  assert.equal(rowOf(json('module-1.0', ['cards', 'cards[title]', 2], 'text', 'x')), 'module-1|cards#2')
  assert.equal(rowOf(json('article-2.0', ['list', 'list3', 'title'], 'text', 'x')), 'article-2|list/list3')
  assert.equal(rowOf(json('article-2.0', ['title'], 'text', 'x')), null)
})

test('each slot lands where the kit and its shape put it', () => {
  const out = hideableSlots(kit, map)
  assert.deepEqual(out.measured, ['module-1.2'], 'a frame the kit emptied')
  assert.deepEqual(out.unmeasured, ['article-2.0', 'languageString-9.0', 'module-1.0'], 'word slots: no verdict')
  assert.deepEqual(Object.keys(out.withRow), ['module-1.1'], 'a link goes with its row')
  assert.deepEqual(out.withRow['module-1.1'], ['module-1.0', 'module-1.2'])
})

test('never listed: an address that would move, an empty slot, a whole column, a frame with no verdict or no row', () => {
  const out = hideableSlots(kit, map)
  const listed = new Set([...out.measured, ...out.unmeasured, ...Object.keys(out.withRow)])
  for (const key of ['article-2.1', 'article-2.2', 'module-1.4', 'menuItem-3.0', 'contact-4.0', 'module-1.3', 'module-1.5'])
    assert.equal(listed.has(key), false, key)
})

test('a canHide false frame with a row is listed to go with it', () => {
  const out = hideableSlots(
    { blocks: [{ frames: [{ id: 'block-1.2', canHide: false }] }] },
    { slots: map.slots.slice(0, 3) }
  )
  assert.deepEqual(out.withRow['module-1.2'], ['module-1.0', 'module-1.1'])
  assert.deepEqual(out.measured, [])
})

test('rows: lists of one length under one parent are one table, with every list of that length, slot or not', () => {
  const lock = { entities: { 'module-1': { params: JSON.stringify({ 'jatools-config': JSON.stringify({ grid: { title: ['a', 'b'], link: ['#', '#'], ids: [['1'], ['2']], columns: ['3'] } }) }) } } }
  const slot = (key, step, i) => ({ key, entity: 'module-1', column: 'params', nestedJson: 'jatools-config', type: 'text', jsonPath: ['grid', step, i], sample: 'x' })
  const map = { slots: [slot('module-1.0', 'title', 0), slot('module-1.1', 'title', 1), slot('module-1.2', 'link', 0), slot('module-1.3', 'link', 1)] }
  assert.deepEqual(reviewedRows({ blocks: [] }, map, lock), {
    'module-1': [{ column: 'params', nestedJson: 'jatools-config', parent: ['grid'], arrays: ['ids', 'link', 'title'], count: 2 }]
  })
})

test('rows: lists of two lengths above one prove nothing, and neither does a kit row across two indexes', () => {
  const body = (grid) => ({ entities: { 'module-1': { params: JSON.stringify({ 'jatools-config': JSON.stringify({ grid }) }) } } })
  const slot = (key, step, i) => ({ key, entity: 'module-1', column: 'params', nestedJson: 'jatools-config', type: 'text', jsonPath: ['grid', step, i], sample: 'x' })
  const map = { slots: [slot('module-1.0', 'title', 0), slot('module-1.1', 'link', 1)] }
  assert.deepEqual(reviewedRows({ blocks: [] }, map, body({ title: ['a', 'b'], link: ['#', '#', '#'] })), {})
  const kit = { blocks: [{ lists: [{ rows: [{ slots: ['block-1.0', 'block-1.1'] }] }] }] }
  assert.deepEqual(reviewedRows(kit, map, body({ title: ['a', 'b'], link: ['#', '#'] })), {})
})

test('rows: a subform row is a key named after its parent, and a parent with any other key is none', () => {
  const lock = (features) => ({ entities: { 'article-1': { attribs: JSON.stringify({ features }) } } })
  const map = { slots: [{ key: 'article-1.0', entity: 'article-1', column: 'attribs', type: 'text', jsonPath: ['features', 'features0', 'title'], sample: 'x' }] }
  assert.deepEqual(reviewedRows({ blocks: [] }, map, lock({ features0: { title: 'x' }, features1: { title: 'y' } })), {
    'article-1': [{ column: 'attribs', parent: ['features'], keys: ['features0', 'features1'] }]
  })
  assert.deepEqual(reviewedRows({ blocks: [] }, map, lock({ features0: { title: 'x' }, other: { title: 'y' } })), {})
})
