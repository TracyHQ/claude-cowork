import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ALT_LIMIT, kitLimits, slotCorrections } from './slot-corrections.mjs'

const text = (key, sample, maxCharacters, fit, more = {}) => ({ key, entity: key.split('.')[0], type: 'text', sample, maxCharacters, fit, ...more })
const fit = (n, more = {}) => ({ measured: true, maxCharacters: n, ...more })
const picture = (key, sample, fit, more = {}) => ({ key, entity: key.split('.')[0], type: 'image', sample, maxCharacters: 255, fit, ...more })
const kit = (...slots) => ({ blocks: [{ id: 'block-1', slots: slots.map(([id, n]) => ({ id, type: 'text', maxCharacters: n })) }] })

test('a limit is the kit number when the fit is under it, and only then', () => {
  const map = {
    slots: [
      text('module-1.0', 'Demo', 40, fit(20)),
      text('module-1.1', 'Demo', 40, fit(30)),
      text('module-1.2', 'Demo', 40, fit(25)),
      text('module-1.3', 'Demo', 40, { measured: false }),
      text('module-1.4', 'Demo', 40, undefined)
    ]
  }
  const out = slotCorrections(kit(['block-1.0', 24], ['block-1.1', 24], ['block-1.2', 25], ['block-1.3', 24], ['block-1.4', 24]), map)
  assert.deepEqual(out.limits, { 'module-1.0': 24 })
})

test('a fit the door already reads as the box of the demo words is not listed', () => {
  const map = { slots: [text('module-1.0', 'Demo', 40, fit(7)), text('module-1.1', 'Demo', 40, fit(8))] }
  assert.deepEqual(slotCorrections(kit(['block-1.0', 24], ['block-1.1', 24]), map).limits, { 'module-1.1': 24 })
})

test('the map number caps the limit, since the door still holds it', () => {
  const map = { slots: [text('module-1.0', 'Demo', 40, fit(18)), text('article-2.0', 'Demo', 18, fit(18))] }
  assert.deepEqual(slotCorrections(kit(['block-1.0', 298], ['article-2.0', 298]), map).limits, { 'module-1.0': 40 })
})

test('a fit that only states its widths is read as its narrowest', () => {
  const map = { slots: [text('module-1.0', 'Demo', 50, { measured: true, maxChars: { desktop: 54, phone: 28 } })] }
  assert.deepEqual(slotCorrections(kit(['block-1.0', 46]), map).limits, { 'module-1.0': 46 })
})

test('the kit lists a slot inside a list row as well as in a block', () => {
  const limits = kitLimits({ blocks: [{ id: 'block-1', lists: [{ rowSlots: [{ id: 'block-1.7', type: 'text', maxCharacters: 33 }, { id: 'block-1.8', type: 'url', maxCharacters: 2048 }] }] }] })
  assert.deepEqual([...limits], [['module-1.7', 33]])
})

test('only a kept transparent icon is opened', () => {
  const map = {
    slots: [
      picture('module-1.2', 'images/a.png', { kind: 'icon', replace: 'keep', transparent: true }),
      picture('module-1.1', 'images/a.png', { kind: 'cutout', replace: 'keep', transparent: true }),
      picture('module-1.3', 'images/a.png', { kind: 'icon', replace: 'keep' }),
      picture('module-1.4', 'images/a.png', { kind: 'photo', replace: 'photo' })
    ]
  }
  assert.deepEqual(slotCorrections(kit(), map).icons, ['module-1.2'])
})

test('only an empty gallery alt beside its picture is opened', () => {
  const gallery = (n) => ({ group: { id: `module-1.gallery.${n}`, kind: 'gallery' } })
  const alt = (n) => ({ jsonPath: ['gallery', 'isotope[gallery]', 'isotope[gallery][alt]', n] })
  const map = {
    slots: [
      picture('module-1.5', 'images/a.jpg', { measured: false }, gallery(0)),
      text('module-1.11', '', 40, { measured: false }, { ...gallery(0), ...alt(0) }),
      text('module-1.12', '', 40, { measured: false }, { ...gallery(1), ...alt(1) }),
      text('module-1.13', 'Has words', 40, { measured: false }, { ...gallery(0), ...alt(2) }),
      text('article-2.6', '', 40, undefined, { xpath: '/html/body/img/@alt' })
    ]
  }
  assert.deepEqual(slotCorrections(kit(), map).alts, { 'module-1.11': ALT_LIMIT })
})
