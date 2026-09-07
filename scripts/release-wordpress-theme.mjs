#!/usr/bin/env node
// Cut a WordPress THEME release: one command, one number, three places that cannot disagree.
//
//   node scripts/release-wordpress-theme.mjs 0.1.1
//   node scripts/release-wordpress-theme.mjs 0.1.1 --dry-run   check and build, publish nothing
//
// Why this file exists, and how it differs from release-wordpress.mjs (the plugin).
//
// The theme is not written here. `wordpress/theme/tracy/` is a mirror of the TCH repository, where
// the 152 style variations, inspirations.json and the `Version:` line in style.css are generated
// from the vendored design library and gated. So this script does NOT write the version into the
// theme: it READS it, and refuses when the number asked for is not the number the mirrored tree
// already carries. Bump there, `node scripts/sync-cms-themes.mjs <this repo>` there, commit here,
// release here — in that order, and the number is only ever decided in one place.
//
// What it does write is `wordpress/theme/update.json`, the single announcement every installed
// site reads. A release that should not spread is un-announced by editing that one file.
//
// It does not run in CI, for the same reason the plugin's release does not: publishing to every
// installed site because someone pushed to main removes the last place to stop.

import { execFileSync } from 'node:child_process'
import { readFileSync, renameSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const themeDir = join(repo, 'wordpress', 'theme')
const styleFile = join(themeDir, 'tracy', 'style.css')
const manifestFile = join(themeDir, 'update.json')

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

if (!version || !/^\d+\.\d+\.\d+$/.test(version)) {
  die('a version of the form x.y.z is required, e.g. node scripts/release-wordpress-theme.mjs 0.1.1')
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
  die('the working tree has uncommitted changes. Commit them first — the theme is synced from TCH, and an uncommitted mirror is a release nobody can reproduce.')
}
if (run('git', ['tag', '--list', `wordpress-theme-v${version}`])) {
  die(`tag wordpress-theme-v${version} already exists — that number has been used`)
}

// ── 2. the number is the mirrored tree's, not this script's ──────────────────────────────────
const declared = readFileSync(styleFile, 'utf8').match(/^\s*Version:\s*(.+)$/m)?.[1]?.trim()
if (!declared) die('no `Version:` line found in wordpress/theme/tracy/style.css')
if (declared !== version) {
  die(
    `the mirrored theme says ${declared}, not ${version}.\n` +
      `  The number lives in TCH: bump packages/cms/tracy-wordpress-theme/package.json, run its\n` +
      `  build, then \`node scripts/sync-cms-themes.mjs ${repo}\` there and commit the mirror here.`
  )
}

// ── 3. the announcement ──────────────────────────────────────────────────────────────────────
const zipName = `tracy-${version}.zip`
const packageUrl = `https://github.com/TracyHQ/claude-cowork/releases/download/wordpress-theme-v${version}/${zipName}`
const manifest = JSON.parse(readFileSync(manifestFile, 'utf8'))
manifest.version = version
manifest.package = packageUrl
manifest.url = `https://github.com/TracyHQ/claude-cowork/releases/tag/wordpress-theme-v${version}`
writeFileSync(manifestFile, `${JSON.stringify(manifest, null, 2)}\n`)

// Read back off disk rather than trusted from the variables that wrote it.
const written = JSON.parse(readFileSync(manifestFile, 'utf8'))
if (written.version !== version) die(`the manifest says ${written.version}, not ${version}`)
if (written.package !== packageUrl) die('the manifest points at an asset other than the one about to be created')
console.log(`· theme header and manifest both say ${version}`)

// ── 4. package ───────────────────────────────────────────────────────────────────────────────
run('bash', ['build.sh'], { cwd: themeDir, stdio: 'inherit' })
renameSync(join(themeDir, 'dist', 'tracy.zip'), join(themeDir, 'dist', zipName))
console.log(`· wordpress/theme/dist/${zipName}`)

if (dryRun) {
  console.log('\n--dry-run: stopping here. update.json was edited — `git checkout` it if you do not want that.\n')
  process.exit(0)
}

// ── 5. one commit, one tag, one release ──────────────────────────────────────────────────────
run('git', ['add', 'wordpress/theme/update.json'])
run('git', ['commit', '-m', `release(wordpress-theme): ${version}`])
run('git', ['tag', `wordpress-theme-v${version}`])
run('git', ['push', 'origin', 'HEAD', '--tags'])

run('gh', [
  'release',
  'create',
  `wordpress-theme-v${version}`,
  join(themeDir, 'dist', zipName),
  '--title',
  `WordPress theme ${version}`,
  '--notes',
  `Tracy theme ${version}. Sites on ${version} or newer need do nothing; older ones update themselves.`
])

console.log(`
✓ wordpress-theme-v${version} released.

  wordpress/theme/update.json is the single source of truth and now points at this build. Sites
  running the theme check it about twice a day and update themselves. To withdraw this release,
  set that file back to the previous version and push: no site is touched.
`)
