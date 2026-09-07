#!/usr/bin/env node
// Cut a Joomla TEMPLATE release: one command, one number, three places that cannot disagree.
//
//   node scripts/release-joomla-template.mjs 0.1.1
//   node scripts/release-joomla-template.mjs 0.1.1 --dry-run   check and build, publish nothing
//
// Like release-wordpress-theme.mjs, and for the same reason: `joomla/template/tpl_tracy/` is a
// mirror of the TCH repository, where the 152 token files, the fixture sheets, inspirations.json
// and templateDetails.xml are generated from the vendored design library and gated. The number is
// decided there. This script reads it, refuses when it disagrees, and writes the one thing that
// belongs here — the entry in `joomla/template/update.xml` that every installed site reads.
//
// A new entry is PREPENDED rather than replacing the file: Joomla picks the highest version whose
// targetplatform matches the site, so an older site keeps finding the last version that supported
// it. To withdraw a release, delete its entry and push — no site is touched.

import { execFileSync } from 'node:child_process'
import { readFileSync, renameSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const templateDir = join(repo, 'joomla', 'template')
const manifestInZip = join(templateDir, 'tpl_tracy', 'templateDetails.xml')
const updateFile = join(templateDir, 'update.xml')

const run = (cmd, args, opts = {}) => {
  const out = execFileSync(cmd, args, { cwd: repo, encoding: 'utf8', stdio: 'pipe', ...opts })
  return typeof out === 'string' ? out.trim() : ''
}

const die = (message) => {
  console.error(`\n✘ ${message}\n`)
  process.exit(1)
}

const version = process.argv[2]
const dryRun = process.argv.includes('--dry-run')
const notes = process.argv[3] && !process.argv[3].startsWith('--') ? process.argv[3] : ''

if (!version || !/^\d+\.\d+\.\d+$/.test(version)) {
  die('a version of the form x.y.z is required, e.g. node scripts/release-joomla-template.mjs 0.1.1')
}

// ── 1. releasing from a stale checkout publishes a build nobody can trace back ────────────────
try {
  run('git', ['fetch', 'origin', 'main', '--quiet'])
} catch {
  die('could not fetch origin/main — there is no way to tell whether this checkout is current')
}
if (run('git', ['rev-parse', 'HEAD']) !== run('git', ['rev-parse', 'origin/main'])) {
  die('HEAD differs from origin/main. Merge and pull before cutting a release.')
}
if (run('git', ['status', '--porcelain'])) {
  die('the working tree has uncommitted changes. Commit them first — the template is synced from TCH, and an uncommitted mirror is a release nobody can reproduce.')
}
if (run('git', ['tag', '--list', `joomla-template-v${version}`])) {
  die(`tag joomla-template-v${version} already exists — that number has been used`)
}

// ── 2. the number is the mirrored tree's, not this script's ──────────────────────────────────
const declared = readFileSync(manifestInZip, 'utf8').match(/<version>([^<]+)<\/version>/)?.[1]?.trim()
if (!declared) die('no <version> found in joomla/template/tpl_tracy/templateDetails.xml')
if (declared !== version) {
  die(
    `the mirrored template says ${declared}, not ${version}.\n` +
      `  The number lives in TCH: bump packages/cms/tracy-joomla-template/package.json, run its\n` +
      `  build, then \`node scripts/sync-cms-themes.mjs ${repo}\` there and commit the mirror here.`
  )
}

// ── 3. the announcement ──────────────────────────────────────────────────────────────────────
const zipName = `tpl_tracy-${version}.zip`
const description =
  notes || 'One template, 152 looks: each design inspiration is a style parameter, and the navigation and hero layouts follow it.'
const entry = `    <update>
        <name>Tracy</name>
        <description>${description.replace(/[<&]/g, (c) => (c === '<' ? '&lt;' : '&amp;'))}</description>
        <element>tpl_tracy</element>
        <type>template</type>
        <client>site</client>
        <version>${version}</version>
        <infourl title="Release notes">https://github.com/TracyHQ/claude-cowork/releases/tag/joomla-template-v${version}</infourl>
        <downloads>
            <downloadurl type="full" format="zip">https://github.com/TracyHQ/claude-cowork/releases/download/joomla-template-v${version}/${zipName}</downloadurl>
        </downloads>
        <tags>
            <tag>stable</tag>
        </tags>
        <maintainer>Tracy</maintainer>
        <maintainerurl>https://tracy.ai</maintainerurl>
        <targetplatform name="joomla" version="(5|6)\\.[0-9]+"/>
        <php_minimum>8.1</php_minimum>
    </update>
`

const updates = readFileSync(updateFile, 'utf8')
if (updates.includes(`<version>${version}</version>`)) {
  die(`update.xml already announces ${version} — that number has been used`)
}
const at = updates.indexOf('<updates>')
if (at === -1) die('update.xml has no <updates> element')
writeFileSync(updateFile, `${updates.slice(0, at + '<updates>'.length)}\n${entry}${updates.slice(at + '<updates>'.length)}`)

const written = readFileSync(updateFile, 'utf8')
if (!written.includes(`<version>${version}</version>`)) die('update.xml does not announce the version just written')
if (!written.includes(zipName)) die('update.xml points at an asset other than the one about to be created')
console.log(`· manifest and update server both say ${version}`)

// ── 4. package ───────────────────────────────────────────────────────────────────────────────
run('bash', ['build.sh'], { cwd: templateDir, stdio: 'inherit' })
renameSync(join(templateDir, 'dist', 'tpl_tracy.zip'), join(templateDir, 'dist', zipName))
console.log(`· joomla/template/dist/${zipName}`)

if (dryRun) {
  console.log('\n--dry-run: stopping here. update.xml was edited — `git checkout` it if you do not want that.\n')
  process.exit(0)
}

// ── 5. one commit, one tag, one release ──────────────────────────────────────────────────────
run('git', ['add', 'joomla/template/update.xml'])
run('git', ['commit', '-m', `release(joomla-template): ${version}`])
run('git', ['tag', `joomla-template-v${version}`])
run('git', ['push', 'origin', 'HEAD', '--tags'])

run('gh', [
  'release',
  'create',
  `joomla-template-v${version}`,
  join(templateDir, 'dist', zipName),
  '--title',
  `Joomla template ${version}`,
  '--notes',
  `Tracy template ${version}. Sites on ${version} or newer need do nothing; older ones find it under "Check For Updates".`
])

console.log(`
✓ joomla-template-v${version} released.

  joomla/template/update.xml now announces this build. A site that installed the template with an
  update server recorded finds it under Extensions → Update. To withdraw the release, delete its
  entry from that file and push: no site is touched.
`)
