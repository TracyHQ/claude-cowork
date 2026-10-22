#!/usr/bin/env node
// Writes one release asset per Joomla contract profile, and the index a site checks them against.
//
//   node scripts/contract-assets.mjs <lib/contracts> <asset dir> <index file>
//
// Why it exists (TCH #1568). The package used to carry every profile under lib/contracts/: 225 of
// them, 529 MB unpacked, and every site that installed it kept all of them. A site needs only the
// profile it is held to, so the package now carries none; each profile is attached to the release
// as `<id with "/" as "--">.zip`, holding the profile's files at the zip root, and the component
// fetches its own (lib/ContractFetch.php). The index ships inside the package — it is what a fetched
// zip is checked against — and Tracy reads it too, so its shape is fixed:
//
//   { "contracts": { "<id>": { "asset": "<id with / as -->.zip", "sha256": "<hex of the zip>", "bytes": 123,
//                               "template": "<manifest.template>", "names": "<manifest.quickstart.contract>" } } }
//
// `template` and `names` are copied from the profile's manifest so Tracy can pick candidates without
// downloading every asset; each is left out when the manifest has none.
//
// Reproducible: entries sorted, a fixed timestamp, fixed permissions, no extra fields. The same tree
// gives the same bytes, so the same sha256, whoever builds it and whenever.

import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { deflateRawSync } from 'node:zlib'

/** The shape EngineFactory and ContractFetch accept; a folder of another shape is not a profile. */
export const PROFILE_ID = /^[a-z][a-z0-9-]{1,40}\/[a-z][a-z0-9]{0,9}\/[0-9]+\.[0-9]+\.[0-9]+$/

export const assetName = (id) => `${id.split('/').join('--')}.zip`

const SKIP = new Set(['.DS_Store'])
/** 1980-01-01 00:00, the earliest DOS date: no build time leaks into the bytes. */
const DOS_TIME = 0
const DOS_DATE = (0 << 9) | (1 << 5) | 1

const CRC_TABLE = (() => {
  const t = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    t[n] = c >>> 0
  }
  return t
})()

function crc32(buf) {
  let c = 0xffffffff
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

/** Every file under `dir`, relative and with "/" separators, in a stable order. */
function files(dir, prefix = '') {
  const out = []
  for (const name of readdirSync(dir).sort()) {
    if (SKIP.has(name)) continue
    const full = join(dir, name)
    const st = statSync(full)
    if (st.isDirectory()) out.push(...files(full, `${prefix}${name}/`))
    else if (st.isFile()) out.push(`${prefix}${name}`)
  }
  return out
}

/** A zip of `entries` ([name, bytes]): deflated, no folders, no extra fields, fixed time and mode. */
export function zip(entries) {
  const locals = []
  const centrals = []
  let offset = 0
  for (const [name, bytes] of entries) {
    const nameBuf = Buffer.from(name, 'utf8')
    const packed = deflateRawSync(bytes, { level: 9 })
    const stored = packed.length >= bytes.length
    const body = stored ? bytes : packed
    const crc = crc32(bytes)
    const local = Buffer.alloc(30)
    local.writeUInt32LE(0x04034b50, 0)
    local.writeUInt16LE(20, 4)
    local.writeUInt16LE(0x0800, 6) // UTF-8 names
    local.writeUInt16LE(stored ? 0 : 8, 8)
    local.writeUInt16LE(DOS_TIME, 10)
    local.writeUInt16LE(DOS_DATE, 12)
    local.writeUInt32LE(crc, 14)
    local.writeUInt32LE(body.length, 18)
    local.writeUInt32LE(bytes.length, 22)
    local.writeUInt16LE(nameBuf.length, 26)
    local.writeUInt16LE(0, 28)
    const central = Buffer.alloc(46)
    central.writeUInt32LE(0x02014b50, 0)
    central.writeUInt16LE(0x031e, 4) // made by unix, 3.0: the mode below is a unix mode
    central.writeUInt16LE(20, 6)
    central.writeUInt16LE(0x0800, 8)
    central.writeUInt16LE(stored ? 0 : 8, 10)
    central.writeUInt16LE(DOS_TIME, 12)
    central.writeUInt16LE(DOS_DATE, 14)
    central.writeUInt32LE(crc, 16)
    central.writeUInt32LE(body.length, 20)
    central.writeUInt32LE(bytes.length, 24)
    central.writeUInt16LE(nameBuf.length, 28)
    central.writeUInt32LE((0o100644 << 16) >>> 0, 38) // a regular file, rw-r--r--
    central.writeUInt32LE(offset, 42)
    locals.push(local, nameBuf, body)
    centrals.push(central, nameBuf)
    offset += 30 + nameBuf.length + body.length
  }
  const directory = Buffer.concat(centrals)
  const end = Buffer.alloc(22)
  end.writeUInt32LE(0x06054b50, 0)
  end.writeUInt16LE(entries.length, 8)
  end.writeUInt16LE(entries.length, 10)
  end.writeUInt32LE(directory.length, 12)
  end.writeUInt32LE(offset, 16)
  return Buffer.concat([...locals, directory, end])
}

/** Every profile folder `<slug>/<platform>/<version>/` under `contractsDir`, sorted. */
export function profiles(contractsDir) {
  const ids = []
  const dirs = (d) => readdirSync(d).filter((n) => !SKIP.has(n) && statSync(join(d, n)).isDirectory()).sort()
  for (const slug of dirs(contractsDir))
    for (const platform of dirs(join(contractsDir, slug)))
      for (const version of dirs(join(contractsDir, slug, platform))) ids.push(`${slug}/${platform}/${version}`)
  return ids.sort()
}

/** Writes the assets and the index; returns the index. */
export function writeAssets(contractsDir, assetDir, indexFile) {
  mkdirSync(assetDir, { recursive: true })
  const contracts = {}
  for (const id of profiles(contractsDir)) {
    const dir = join(contractsDir, id)
    if (!PROFILE_ID.test(id)) throw new Error(`${id}: not a profile id`)
    const manifestFile = join(dir, 'manifest.json')
    if (!existsSync(manifestFile)) throw new Error(`${id}: no manifest.json`)
    const manifest = JSON.parse(readFileSync(manifestFile, 'utf8'))
    if (manifest.id !== id) throw new Error(`${id}: manifest.json names another id`)
    const { template } = manifest
    const names = manifest.quickstart?.contract
    if (template !== undefined && typeof template !== 'string') throw new Error(`${id}: manifest.json template is not a string`)
    if (names !== undefined && typeof names !== 'string') throw new Error(`${id}: manifest.json quickstart.contract is not a string`)
    const bytes = zip(files(dir).map((name) => [name, readFileSync(join(dir, name))]))
    const asset = assetName(id)
    writeFileSync(join(assetDir, asset), bytes)
    contracts[id] = { asset, sha256: createHash('sha256').update(bytes).digest('hex'), bytes: bytes.length }
    if (template !== undefined) contracts[id].template = template
    if (names !== undefined) contracts[id].names = names
  }
  if (!Object.keys(contracts).length) throw new Error(`${contractsDir}: no profiles`)
  const index = { contracts }
  writeFileSync(indexFile, JSON.stringify(index, null, 2) + '\n')
  return index
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const [contractsDir, assetDir, indexFile] = process.argv.slice(2)
  if (!contractsDir || !assetDir || !indexFile) {
    console.error('usage: node scripts/contract-assets.mjs <lib/contracts> <asset dir> <index file>')
    process.exit(2)
  }
  const index = writeAssets(contractsDir, assetDir, indexFile)
  const total = Object.values(index.contracts).reduce((n, e) => n + e.bytes, 0)
  console.log(`${Object.keys(index.contracts).length} contract assets, ${(total / 1048576).toFixed(1)} MB`)
}
