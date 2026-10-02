#!/usr/bin/env node
// Cut a WordPress THEME release: one command, one number, three places that cannot disagree.
//
//   node scripts/release-wordpress-theme.mjs 0.1.1
//   node scripts/release-wordpress-theme.mjs 0.1.1 --dry-run   check and build, publish nothing
//   node scripts/release-wordpress-theme.mjs 1.1.2 --theme wp-ja-kinetic
//
// `--theme <slug>` releases a template's own theme mirrored beside `tracy/` (the same TCH package
// builds it as a second target). Its sites read `wordpress/theme/<slug>/update.json`, its tag is
// `wordpress-theme-<slug>-v<version>` and its zip `<slug>-<version>.zip`, so the two never share a
// number, a tag or an announcement. Without the flag everything below is the `tracy` theme's, as
// it always was: `wordpress/theme/update.json` is a published address and does not move.
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
import { existsSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const themeDir = join(repo, 'wordpress', 'theme')
const themeAt = process.argv.indexOf('--theme')
const slug = themeAt === -1 ? 'tracy' : process.argv[themeAt + 1]
const styleFile = join(themeDir, slug ?? '', 'style.css')
const manifestFile = slug === 'tracy' ? join(themeDir, 'update.json') : join(themeDir, slug ?? '', 'update.json')
const tagName = (v) => (slug === 'tracy' ? `wordpress-theme-v${v}` : `wordpress-theme-${slug}-v${v}`)

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

if (!slug || !/^[a-z0-9][a-z0-9-]*$/.test(slug)) {
  die('--theme needs the theme folder name under wordpress/theme/, e.g. --theme wp-ja-kinetic')
}
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
if (run('git', ['tag', '--list', tagName(version)])) {
  die(`tag ${tagName(version)} already exists — that number has been used`)
}

// ── 2. the number is the mirrored tree's, not this script's ──────────────────────────────────
const declared = readFileSync(styleFile, 'utf8').match(/^\s*Version:\s*(.+)$/m)?.[1]?.trim()
if (!declared) die(`no \`Version:\` line found in wordpress/theme/${slug}/style.css`)
if (declared !== version) {
  die(
    `the mirrored theme says ${declared}, not ${version}.\n` +
      `  The number lives in TCH: bump packages/cms/tracy-wordpress-theme/package.json, run its\n` +
      `  build, then \`node scripts/sync-cms-themes.mjs ${repo}\` there and commit the mirror here.`
  )
}

// ── 3. the announcement ──────────────────────────────────────────────────────────────────────
const zipName = `${slug}-${version}.zip`
const packageUrl = `https://github.com/TracyHQ/claude-cowork/releases/download/${tagName(version)}/${zipName}`
if (!existsSync(manifestFile)) die(`${manifestFile} is missing: the first announcement of a theme is written by hand, with its requires/requires_php/tested`)
const manifest = JSON.parse(readFileSync(manifestFile, 'utf8'))
manifest.version = version
manifest.package = packageUrl
manifest.url = `https://github.com/TracyHQ/claude-cowork/releases/tag/${tagName(version)}`
writeFileSync(manifestFile, `${JSON.stringify(manifest, null, 2)}\n`)

// Read back off disk rather than trusted from the variables that wrote it.
const written = JSON.parse(readFileSync(manifestFile, 'utf8'))
if (written.version !== version) die(`the manifest says ${written.version}, not ${version}`)
if (written.package !== packageUrl) die('the manifest points at an asset other than the one about to be created')
console.log(`· theme header and manifest both say ${version}`)

// ── 4. package ───────────────────────────────────────────────────────────────────────────────
run('bash', ['build.sh', slug], { cwd: themeDir, stdio: 'inherit' })
renameSync(join(themeDir, 'dist', `${slug}.zip`), join(themeDir, 'dist', zipName))
console.log(`· wordpress/theme/dist/${zipName}`)

// The release manifest the build wrote beside the zip (scripts/release-manifest.mjs): attached as an
// asset of its own, because the copy inside the zip is only checkable against one nobody on a site
// can change. It must name the tag about to be created, or it describes some other build.
const releaseManifest = join(themeDir, 'dist', 'tracy-release.json')
if (JSON.parse(readFileSync(releaseManifest, 'utf8')).tag !== tagName(version)) die('dist/tracy-release.json names another tag than the one about to be created')
console.log('· dist/tracy-release.json (release asset)')

if (dryRun) {
  console.log('\n--dry-run: stopping here. update.json was edited — `git checkout` it if you do not want that.\n')
  process.exit(0)
}

// ── 5. one commit, one tag, one release ──────────────────────────────────────────────────────
// The manifest can already say what this release says — the very first one, where the file was
// written by hand before any release existed. `git commit` refuses an empty change, and refusing
// there would leave a zip built, no tag, and nothing said about why.
const manifestRel = manifestFile.slice(repo.length + 1)
run('git', ['add', manifestRel])
if (run('git', ['status', '--porcelain', manifestRel])) {
  run('git', ['commit', '-m', slug === 'tracy' ? `release(wordpress-theme): ${version}` : `release(wordpress-theme ${slug}): ${version}`])
} else {
  console.log('· the manifest already said this version — nothing to commit')
}
run('git', ['tag', tagName(version)])
run('git', ['push', 'origin', 'HEAD', '--tags'])

run('gh', [
  'release',
  'create',
  tagName(version),
  join(themeDir, 'dist', zipName),
  releaseManifest,
  '--title',
  slug === 'tracy' ? `WordPress theme ${version}` : `WordPress theme ${slug} ${version}`,
  // NOT the repository's "Latest" release. That badge is repo-wide, and the extension is what a
  // person coming here installs first; a look release taking it would answer the wrong question on
  // the repository page. Provisioning is unaffected either way — it resolves the extension by tag
  // prefix and version range (`git ls-remote --tags 'wordpress-v0.6.*'`), never by /releases/latest.
  '--latest=false',
  '--notes',
  `${slug === 'tracy' ? 'Tracy theme' : `Theme ${slug}`} ${version}. Sites on ${version} or newer need do nothing; older ones update themselves.`
])

console.log(`
✓ ${tagName(version)} released.

  ${manifestFile.slice(repo.length + 1)} is the single source of truth and now points at this build. Sites
  running the theme check it about twice a day and update themselves. To withdraw this release,
  set that file back to the previous version and push: no site is touched.
`)
