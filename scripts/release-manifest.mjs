#!/usr/bin/env node
// Writes `tracy-release.json`: every file a release puts on a site, by the path it lands at in the
// webroot, with the sha256 of its bytes.
//
//   node scripts/release-manifest.mjs joomla-package   joomla/cowork               <out>
//   node scripts/release-manifest.mjs joomla-template  <staged tpl_tracy>          <out>
//   node scripts/release-manifest.mjs wordpress-plugin <staged claude-cowork>      <out>
//   node scripts/release-manifest.mjs wordpress-theme  <staged theme folder>       <out>
//
// Why it exists. A site Tracy keeps in git sees its own extension update as a pile of changed files
// nobody claims: the updater writes them after a response has gone out, and nothing records that
// it did. With this file in the package AND attached to the release as an asset of its own, the
// question "are these the bytes of release X?" has an answer that does not depend on anything the
// site says about itself: the copy on disk names the tag, and the asset under that tag on
// github.com/TracyHQ/claude-cowork names the hashes.
//
// Generated, never typed. For Joomla the paths come from the extension manifests — the same
// `<files>`, `<languages>`, `<media>` and `<scriptfile>` the installer itself follows — so a file
// Joomla would not install is not listed, and a file it would install is never forgotten. For
// WordPress the folder in the zip IS the install path, so the staged folder is walked as it is.
//
// The manifest does not list itself (a file cannot carry its own hash); `path` says where it sits.

import { createHash } from 'node:crypto'
import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { basename, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

export const MANIFEST_NAME = 'tracy-release.json'
export const SCHEMA = 1

/** Files no build puts in a zip (`zip -x '*.DS_Store'`), so no site ever has them. */
const SKIP = new Set(['.DS_Store'])

const sha256 = (file) => createHash('sha256').update(readFileSync(file)).digest('hex')
const posix = (p) => p.split(sep).join('/')

/** Every file under `dir`, as paths relative to it, in a stable order. */
function walk(dir, base = dir) {
  const out = []
  for (const name of readdirSync(dir).sort()) {
    if (SKIP.has(name)) continue
    const full = join(dir, name)
    const st = statSync(full)
    if (st.isDirectory()) out.push(...walk(full, base))
    else if (st.isFile()) out.push(posix(relative(base, full)))
  }
  return out
}

// ── a Joomla manifest, read as the installer reads it ──────────────────────────────────────────
// No XML dependency: the manifests are ours and plain, and a release must build on a machine with
// node alone. Comments go first, because the manifests explain themselves at length and a comment
// quoting `<files>` must not be mistaken for one.

const stripComments = (xml) => xml.replace(/<!--[\s\S]*?-->/g, '')

function attrs(tag) {
  const out = {}
  for (const m of tag.matchAll(/([a-zA-Z_:-]+)\s*=\s*"([^"]*)"/g)) out[m[1]] = m[2]
  return out
}

/** `<name …>body</name>` blocks directly in `xml`, with their attributes. */
function blocks(xml, name) {
  const out = []
  const re = new RegExp(`<${name}(\\s[^>]*)?>([\\s\\S]*?)</${name}>`, 'g')
  for (const m of xml.matchAll(re)) out.push({ attrs: attrs(m[1] ?? ''), body: m[2] })
  return out
}

/** Leaf elements (`<folder>x</folder>`, `<filename>y</filename>`, `<language>`, `<file>`). */
function leaves(xml, name) {
  return blocks(xml, name).map((b) => ({ attrs: b.attrs, text: b.body.trim() }))
}

function text(xml, name) {
  return leaves(xml, name)[0]?.text ?? ''
}

export function parseJoomlaManifest(xmlSource) {
  const xml = stripComments(xmlSource)
  const root = xml.match(/<extension(\s[^>]*)?>/)
  if (!root) throw new Error('not a Joomla extension manifest: no <extension> element')
  const admin = blocks(xml, 'administration')[0]?.body ?? ''
  // Top level is what is left once <administration> is cut out: its <files> and <languages> are
  // the administrator's, and the installer sends them somewhere else.
  const top = xml.replace(/<administration[\s\S]*?<\/administration>/g, '')
  const fileBlocks = (src) =>
    blocks(src, 'files').map((b) => ({
      folder: b.attrs.folder ?? '',
      entries: [
        ...leaves(b.body, 'folder').map((l) => ({ kind: 'folder', name: l.text, attrs: l.attrs })),
        ...leaves(b.body, 'filename').map((l) => ({ kind: 'file', name: l.text, attrs: l.attrs })),
        ...leaves(b.body, 'file').map((l) => ({ kind: 'package-part', name: l.text, attrs: l.attrs }))
      ]
    }))
  const languageBlocks = (src) =>
    blocks(src, 'languages').map((b) => ({
      folder: b.attrs.folder ?? '',
      languages: leaves(b.body, 'language').map((l) => ({ tag: l.attrs.tag, path: l.text }))
    }))
  return {
    attrs: attrs(root[1] ?? ''),
    name: text(top, 'name'),
    version: text(top, 'version'),
    packagename: text(top, 'packagename'),
    scriptfile: text(top, 'scriptfile'),
    files: fileBlocks(top),
    languages: languageBlocks(top),
    media: blocks(top, 'media').map((b) => ({
      destination: b.attrs.destination ?? '',
      folder: b.attrs.folder ?? '',
      entries: [
        ...leaves(b.body, 'folder').map((l) => ({ kind: 'folder', name: l.text })),
        ...leaves(b.body, 'filename').map((l) => ({ kind: 'file', name: l.text }))
      ]
    })),
    adminFiles: fileBlocks(admin),
    adminLanguages: languageBlocks(admin)
  }
}

/** Joomla's element for a component or template: the name, lower case, spaces as underscores. */
const cmd = (s) => s.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_.-]/g, '')

class Collector {
  constructor(selfPath) {
    this.files = new Map()
    this.roots = new Set()
    this.selfPath = selfPath
  }

  add(source, dest) {
    if (dest === this.selfPath) return
    if (!existsSync(source)) throw new Error(`the manifest lists ${source}, which does not exist`)
    const prior = this.files.get(dest)
    const hash = sha256(source)
    if (prior !== undefined && prior !== hash) throw new Error(`two different files land at ${dest}`)
    this.files.set(dest, hash)
  }

  /** A `<files>`/`<media>` entry list from `srcDir` into `destDir`, as Installer::parseFiles copies it. */
  entries(srcDir, destDir, entries) {
    for (const e of entries) {
      const src = join(srcDir, e.name)
      if (e.kind === 'file') {
        this.add(src, `${destDir}/${e.name}`)
      } else if (e.kind === 'folder') {
        if (!existsSync(src)) throw new Error(`the manifest lists the folder ${src}, which does not exist`)
        for (const rel of walk(src)) this.add(join(src, rel), `${destDir}/${e.name}/${rel}`)
      }
    }
  }

  /** `<languages folder>`: each file goes to `<languageRoot>/<tag>/<basename>` (Installer::parseLanguages). */
  languages(srcDir, languageRoot, blocksOf) {
    for (const b of blocksOf) {
      for (const l of b.languages) {
        this.add(join(srcDir, b.folder, l.path), `${languageRoot}/${l.tag}/${basename(l.path)}`)
      }
    }
  }
}

/** One Joomla extension (component, plugin, template) from its source folder. */
function joomlaExtension(dir, c) {
  const manifestFile = readdirSync(dir).find((n) => n.endsWith('.xml') && /<extension[\s>]/.test(readFileSync(join(dir, n), 'utf8')))
  if (!manifestFile) throw new Error(`no Joomla manifest in ${dir}`)
  const m = parseJoomlaManifest(readFileSync(join(dir, manifestFile), 'utf8'))
  const type = m.attrs.type
  const media = (root) => {
    for (const b of m.media) c.entries(join(dir, b.folder), `media/${b.destination}`, b.entries)
    for (const b of m.media) c.roots.add(`media/${b.destination}/`)
    return root
  }

  if (type === 'component') {
    const element = cmd(m.name).startsWith('com_') ? cmd(m.name) : `com_${cmd(m.name)}`
    const site = `components/${element}`
    const adminRoot = `administrator/components/${element}`
    for (const b of m.files) c.entries(join(dir, b.folder), site, b.entries)
    for (const b of m.adminFiles) c.entries(join(dir, b.folder), adminRoot, b.entries)
    c.languages(dir, 'language', m.languages)
    c.languages(dir, 'administrator/language', m.adminLanguages)
    c.add(join(dir, manifestFile), `${adminRoot}/${manifestFile}`)
    if (m.scriptfile) c.add(join(dir, m.scriptfile), `${adminRoot}/${m.scriptfile}`)
    if (m.files.length) c.roots.add(`${site}/`)
    c.roots.add(`${adminRoot}/`)
    return media({ type, element, version: m.version })
  }

  if (type === 'plugin') {
    const group = m.attrs.group
    const named = m.files.flatMap((b) => b.entries).find((e) => e.attrs?.plugin)
    if (!group || !named) throw new Error(`${dir}: a plugin manifest needs group="…" and one entry with plugin="…"`)
    const element = named.attrs.plugin
    const root = `plugins/${group}/${element}`
    for (const b of m.files) c.entries(join(dir, b.folder), root, b.entries)
    c.languages(dir, 'administrator/language', m.languages)
    c.add(join(dir, manifestFile), `${root}/${manifestFile}`)
    if (m.scriptfile) c.add(join(dir, m.scriptfile), `${root}/${m.scriptfile}`)
    c.roots.add(`${root}/`)
    return media({ type, element: `plg_${group}_${element}`, version: m.version })
  }

  if (type === 'template') {
    const element = cmd(m.name)
    const admin = m.attrs.client === 'administrator'
    const root = `${admin ? 'administrator/' : ''}templates/${element}`
    for (const b of m.files) c.entries(join(dir, b.folder), root, b.entries)
    c.languages(dir, `${admin ? 'administrator/' : ''}language`, m.languages)
    // The installer copies the manifest whether or not <files> names it; tpl_tracy names it too,
    // and the collector accepts the same bytes at the same path twice.
    c.add(join(dir, manifestFile), `${root}/${manifestFile}`)
    if (m.scriptfile) c.add(join(dir, m.scriptfile), `${root}/${m.scriptfile}`)
    c.roots.add(`${root}/`)
    return media({ type, element, version: m.version })
  }

  throw new Error(`${dir}: extension type "${type}" is not one this generator maps`)
}

/** The Joomla package: its own manifest and script, then every part built from its source folder. */
export function joomlaPackage(coworkDir, selfPath) {
  const manifestFile = 'pkg_claudecowork.xml'
  const m = parseJoomlaManifest(readFileSync(join(coworkDir, manifestFile), 'utf8'))
  if (m.attrs.type !== 'package' || !m.packagename) throw new Error(`${manifestFile} is not a package manifest`)
  const c = new Collector(selfPath)
  // PackageAdapter: the manifest to administrator/manifests/packages/, the script beside it in a
  // folder named for the package (measured on Joomla 5 and 6, see the README).
  c.add(join(coworkDir, manifestFile), `administrator/manifests/packages/${manifestFile}`)
  if (m.scriptfile) c.add(join(coworkDir, m.scriptfile), `administrator/manifests/packages/${m.packagename}/${m.scriptfile}`)
  const parts = m.files.flatMap((b) => b.entries).filter((e) => e.kind === 'package-part')
  if (!parts.length) throw new Error(`${manifestFile} names no parts`)
  for (const part of parts) {
    // build.sh zips each part from the folder of the same name: com_claudecowork.zip ← com_claudecowork/.
    joomlaExtension(join(coworkDir, part.name.replace(/\.zip$/, '')), c)
  }
  const version = m.version
  return finish(c, { product: 'joomla-package', element: `pkg_${m.packagename}`, version, tag: `joomla-v${version}`, path: selfPath })
}

export function joomlaTemplate(templateDir, selfPath) {
  const c = new Collector(selfPath)
  const ext = joomlaExtension(templateDir, c)
  if (ext.type !== 'template') throw new Error(`${templateDir} is not a template`)
  return finish(c, { product: 'joomla-template', element: ext.element, version: ext.version, tag: `joomla-template-v${ext.version}`, path: selfPath })
}

// ── WordPress: the folder in the zip IS the install path ──────────────────────────────────────

const header = (file, field) =>
  readFileSync(file, 'utf8').match(new RegExp(`^[\\s*]*${field}:\\s*(.+)$`, 'm'))?.[1]?.trim() ?? ''

function wordpressFolder(dir, root, selfPath) {
  const c = new Collector(selfPath)
  for (const rel of walk(dir)) c.add(join(dir, rel), `${root}/${rel}`)
  c.roots.add(`${root}/`)
  return c
}

export function wordpressPlugin(pluginDir, selfPathOverride) {
  const slug = basename(resolve(pluginDir))
  const root = `wp-content/plugins/${slug}`
  const selfPath = selfPathOverride ?? `${root}/${MANIFEST_NAME}`
  const version = header(join(pluginDir, `${slug}.php`), 'Version')
  if (!version) throw new Error(`no Version: header in ${slug}/${slug}.php`)
  return finish(wordpressFolder(pluginDir, root, selfPath), { product: 'wordpress-plugin', element: slug, version, tag: `wordpress-v${version}`, path: selfPath })
}

export function wordpressTheme(themeDir, selfPathOverride) {
  const slug = basename(resolve(themeDir))
  const root = `wp-content/themes/${slug}`
  const selfPath = selfPathOverride ?? `${root}/${MANIFEST_NAME}`
  const version = header(join(themeDir, 'style.css'), 'Version')
  if (!version) throw new Error(`no Version: header in ${slug}/style.css`)
  // The tag scheme of scripts/release-wordpress-theme.mjs: `tracy` keeps the short one.
  const tag = slug === 'tracy' ? `wordpress-theme-v${version}` : `wordpress-theme-${slug}-v${version}`
  return finish(wordpressFolder(themeDir, root, selfPath), { product: 'wordpress-theme', element: slug, version, tag, path: selfPath })
}

function finish(c, meta) {
  if (!meta.version) throw new Error('the release has no version')
  if (!c.files.size) throw new Error('the release lists no files')
  const epoch = process.env.SOURCE_DATE_EPOCH
  return {
    schema: SCHEMA,
    product: meta.product,
    element: meta.element,
    version: meta.version,
    tag: meta.tag,
    generatedAt: (epoch ? new Date(Number(epoch) * 1000) : new Date()).toISOString(),
    path: meta.path,
    roots: [...c.roots].sort(),
    files: Object.fromEntries([...c.files.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)))
  }
}

export const JOOMLA_PACKAGE_SELF = `administrator/components/com_claudecowork/${MANIFEST_NAME}`

export function build(kind, dir) {
  switch (kind) {
    case 'joomla-package':
      return joomlaPackage(dir, JOOMLA_PACKAGE_SELF)
    case 'joomla-template': {
      const element = cmd(parseJoomlaManifest(readFileSync(join(dir, 'templateDetails.xml'), 'utf8')).name)
      return joomlaTemplate(dir, `templates/${element}/${MANIFEST_NAME}`)
    }
    case 'wordpress-plugin':
      return wordpressPlugin(dir)
    case 'wordpress-theme':
      return wordpressTheme(dir)
    default:
      throw new Error(`unknown kind "${kind}": joomla-package, joomla-template, wordpress-plugin or wordpress-theme`)
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const [kind, dir, out] = process.argv.slice(2)
  if (!kind || !dir || !out) {
    console.error('usage: node scripts/release-manifest.mjs <joomla-package|joomla-template|wordpress-plugin|wordpress-theme> <dir> <out>')
    process.exit(2)
  }
  try {
    const manifest = build(kind, dir)
    writeFileSync(out, `${JSON.stringify(manifest, null, 2)}\n`)
    console.log(`${MANIFEST_NAME}: ${manifest.tag}, ${Object.keys(manifest.files).length} files → ${manifest.path}`)
  } catch (e) {
    console.error(`✘ ${e.message}`)
    process.exit(1)
  }
}
