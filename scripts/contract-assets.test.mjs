// node --test scripts/contract-assets.test.mjs
//
// The per-profile release assets and their index (TCH #1568): against a fixture tree, and against the
// real Joomla build once `joomla/cowork/build.sh` has run. What a site does with them is
// joomla/cowork/tests/contract-fetch.php.

import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { inflateRawSync } from 'node:zlib'
import { assetName, profiles, writeAssets } from './contract-assets.mjs'

const sha = (b) => createHash('sha256').update(b).digest('hex')

/** The entries of a zip, read from its central directory: name → bytes, plus each entry's unix mode. */
function unzip(buf) {
  const end = buf.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]))
  const count = buf.readUInt16LE(end + 10)
  let at = buf.readUInt32LE(end + 16)
  const out = {}
  for (let i = 0; i < count; i++) {
    const method = buf.readUInt16LE(at + 10)
    const csize = buf.readUInt32LE(at + 20)
    const nameLen = buf.readUInt16LE(at + 28)
    const mode = buf.readUInt32LE(at + 38) >>> 16
    const local = buf.readUInt32LE(at + 42)
    const name = buf.subarray(at + 46, at + 46 + nameLen).toString('utf8')
    const start = local + 30 + buf.readUInt16LE(local + 26) + buf.readUInt16LE(local + 28)
    const body = buf.subarray(start, start + csize)
    out[name] = { bytes: method === 8 ? inflateRawSync(body) : body, mode }
    at += 46 + nameLen + buf.readUInt16LE(at + 30) + buf.readUInt16LE(at + 32)
  }
  return out
}

function scratch(t) {
  const root = mkdtempSync(join(tmpdir(), 'contract-assets-'))
  t.after(() => rmSync(root, { recursive: true, force: true }))
  return root
}

function tree(root, files) {
  for (const [path, body] of Object.entries(files)) {
    mkdirSync(dirname(join(root, path)), { recursive: true })
    writeFileSync(join(root, path), body)
  }
}

test('one zip per profile, its files at the root; an index of every one; README is not a profile', (t) => {
  const root = scratch(t)
  tree(root, {
    'contracts/README.md': 'not a profile',
    'contracts/acme/j6/1.0.0/manifest.json': JSON.stringify({ id: 'acme/j6/1.0.0', template: 'ja_acme' }),
    'contracts/acme/j6/1.0.0/content-map.json': '{"slots":[]}',
    'contracts/acme/j6/1.0.0/.DS_Store': 'junk',
    'contracts/acme/j6/1.1.0/manifest.json': JSON.stringify({ id: 'acme/j6/1.1.0', template: 'ja_acme', quickstart: { contract: 'acme/j6/1.0.0' } })
  })
  const index = writeAssets(join(root, 'contracts'), join(root, 'dist'), join(root, 'contracts.json'))
  assert.deepEqual(Object.keys(index.contracts), ['acme/j6/1.0.0', 'acme/j6/1.1.0'])
  assert.deepEqual(JSON.parse(readFileSync(join(root, 'contracts.json'), 'utf8')), index)
  for (const [id, entry] of Object.entries(index.contracts)) {
    assert.deepEqual(Object.keys(entry), id === 'acme/j6/1.1.0'
      ? ['asset', 'sha256', 'bytes', 'template', 'names'] : ['asset', 'sha256', 'bytes', 'template'])
    assert.equal(entry.asset, assetName(id))
    const zip = readFileSync(join(root, 'dist', entry.asset))
    assert.equal(entry.sha256, sha(zip))
    assert.equal(entry.bytes, zip.length)
  }
  assert.equal(index.contracts['acme/j6/1.0.0'].asset, 'acme--j6--1.0.0.zip')
  // What Tracy reads to pick candidates without downloading an asset.
  assert.equal(index.contracts['acme/j6/1.0.0'].template, 'ja_acme')
  assert.equal(index.contracts['acme/j6/1.1.0'].names, 'acme/j6/1.0.0')
  const entries = unzip(readFileSync(join(root, 'dist', 'acme--j6--1.0.0.zip')))
  assert.deepEqual(Object.keys(entries), ['content-map.json', 'manifest.json'])
  assert.equal(entries['content-map.json'].bytes.toString(), '{"slots":[]}')
  assert.equal(entries['manifest.json'].mode, 0o100644)
})

test('the same tree gives byte-identical zips, whenever it is built', (t) => {
  const root = scratch(t)
  tree(root, { 'contracts/acme/j6/1.0.0/manifest.json': JSON.stringify({ id: 'acme/j6/1.0.0' }) })
  const first = writeAssets(join(root, 'contracts'), join(root, 'a'), join(root, 'a.json'))
  writeFileSync(join(root, 'contracts/acme/j6/1.0.0/manifest.json'), JSON.stringify({ id: 'acme/j6/1.0.0' })) // a new mtime
  const second = writeAssets(join(root, 'contracts'), join(root, 'b'), join(root, 'b.json'))
  assert.deepEqual(second, first)
  assert.deepEqual(readFileSync(join(root, 'b.json')), readFileSync(join(root, 'a.json')))
})

test('a profile folder without a manifest, or with another id, fails the build', (t) => {
  const root = scratch(t)
  tree(root, { 'contracts/acme/j6/1.0.0/content-map.json': '{}' })
  assert.throws(() => writeAssets(join(root, 'contracts'), join(root, 'd'), join(root, 'i.json')), /no manifest\.json/)
  tree(root, { 'contracts/acme/j6/1.0.0/manifest.json': JSON.stringify({ id: 'acme/j6/2.0.0' }) })
  assert.throws(() => writeAssets(join(root, 'contracts'), join(root, 'd'), join(root, 'i.json')), /names another id/)
  tree(root, { 'contracts/acme/j6/1.0.0/manifest.json': JSON.stringify({ id: 'acme/j6/1.0.0', template: 7 }) })
  assert.throws(() => writeAssets(join(root, 'contracts'), join(root, 'd'), join(root, 'i.json')), /template is not a string/)
  tree(root, { 'contracts/acme/j6/1.0.0/manifest.json': JSON.stringify({ id: 'acme/j6/1.0.0', quickstart: { contract: {} } }) })
  assert.throws(() => writeAssets(join(root, 'contracts'), join(root, 'd'), join(root, 'i.json')), /quickstart\.contract is not a string/)
})

test('the real Joomla build: no profile in the component, every profile in the index, every asset as listed', (t) => {
  const cowork = join(dirname(new URL(import.meta.url).pathname), '..', 'joomla', 'cowork')
  const lib = join(cowork, 'com_claudecowork', 'administrator', 'lib')
  if (!existsSync(join(lib, 'Engine.php'))) return t.skip('run joomla/cowork/build.sh first')
  assert.ok(!existsSync(join(lib, 'contracts')), 'the component carries no lib/contracts/')
  const index = JSON.parse(readFileSync(join(lib, 'contracts.json'), 'utf8'))
  const source = profiles(join(cowork, 'lib', 'contracts'))
  assert.ok(source.length > 0)
  assert.deepEqual(Object.keys(index.contracts), source)
  assert.deepEqual(readdirSync(join(cowork, 'dist', 'contracts')).sort(), source.map(assetName).sort())
  for (const id of source) {
    const entry = index.contracts[id]
    const zip = readFileSync(join(cowork, 'dist', 'contracts', entry.asset))
    assert.equal(entry.sha256, sha(zip), id)
    assert.equal(entry.bytes, zip.length, id)
    const manifest = JSON.parse(readFileSync(join(cowork, 'lib', 'contracts', id, 'manifest.json'), 'utf8'))
    assert.equal(entry.template, manifest.template, id)
    assert.ok(typeof entry.template === 'string', id)
    assert.equal(entry.names, manifest.quickstart?.contract, id)
    assert.equal('names' in entry, typeof manifest.quickstart?.contract === 'string', id)
  }
})
