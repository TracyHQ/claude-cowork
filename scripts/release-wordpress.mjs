#!/usr/bin/env node
// Cut a WordPress release: one command, one number, three places that cannot disagree.
//
//   node scripts/release-wordpress.mjs 0.6.1
//   node scripts/release-wordpress.mjs 0.6.1 --dry-run   check and build, publish nothing
//
// Why this file exists. A plugin release lives in three places that have to match: the `Version:`
// header in claude-cowork.php, `version` + `package` in wordpress/update.json, and the release tag
// carrying the zip. Until now all three were edited by hand. On 2026-08-25 the result was tracy.ai
// running 0.4.0 while every document said 0.5.4 and Tracy Desk shipped 0.6.0 — three numbers,
// three answers, and nothing anywhere that looked wrong.
//
// Two things it deliberately does NOT do:
//   - it does not guess the version. A person types it. Guessing patch-versus-minor is the kind of
//     guess that goes wrong on the day it matters, and that number goes straight out to customer
//     sites.
//   - it does not run in CI. Publishing to every installed site because someone pushed to main
//     removes the last place to stop. A release is an act somebody performs.

import { execFileSync } from 'node:child_process'
import { readFileSync, renameSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const cowork = join(repo, 'wordpress', 'cowork')
const pluginFile = join(cowork, 'claude-cowork', 'claude-cowork.php')
const manifestFile = join(repo, 'wordpress', 'update.json')

// `stdio: 'inherit'` makes execFileSync return null rather than a string — that output has already
// gone to the screen. Callers that need the text keep the default 'pipe'; callers that only need
// the command to run do not have to think about it.
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

// ── 0. the number has to be a number ─────────────────────────────────────────────────────────
if (!version || !/^\d+\.\d+\.\d+$/.test(version)) {
  die('a version of the form x.y.z is required, e.g. node scripts/release-wordpress.mjs 0.6.1')
}

// ── 1. the same gate seat-registry grew after a deploy overwrote a newer build (tracy.ai#86) ──
// Releasing from a stale checkout publishes a build nobody can trace back through history.
try {
  run('git', ['fetch', 'origin', 'main', '--quiet'])
} catch {
  die('could not fetch origin/main — there is no way to tell whether this checkout is current')
}
if (run('git', ['rev-parse', 'HEAD']) !== run('git', ['rev-parse', 'origin/main'])) {
  die('HEAD differs from origin/main. Merge and pull --rebase before cutting a release.')
}
if (run('git', ['status', '--porcelain'])) {
  die('the working tree has uncommitted changes. Commit or stash them first.')
}

const existingTags = run('git', ['tag', '--list', `wordpress-v${version}`])
if (existingTags) die(`tag wordpress-v${version} already exists — that number has been used`)

// ── 2 & 3. two places declare the version, both written from the SAME variable ────────────────
const zipName = `claude-cowork-${version}.zip`
const packageUrl = `https://github.com/TracyHQ/claude-cowork/releases/download/wordpress-v${version}/${zipName}`

const plugin = readFileSync(pluginFile, 'utf8')
const bumped = plugin.replace(/^(\s*\*\s*Version:\s*)(.+)$/m, `$1${version}`)
if (bumped === plugin) die('no `Version:` line found in the plugin header')
writeFileSync(pluginFile, bumped)

const manifest = JSON.parse(readFileSync(manifestFile, 'utf8'))
manifest.version = version
manifest.package = packageUrl
manifest.url = `https://github.com/TracyHQ/claude-cowork/releases/tag/wordpress-v${version}`
writeFileSync(manifestFile, `${JSON.stringify(manifest, null, 2)}\n`)

console.log(`· header and manifest both say ${version}`)

// ── 4. read it back off disk and prove it ────────────────────────────────────────────────────
// This runs AFTER the edit, not before: what needs proving is that the two files JUST WRITTEN
// agree, read back from disk rather than trusted from the variables that wrote them.
// `tests/run.php` checks these same three things and CI runs it on every push — but it needs php,
// which the releasing machine may not have. A gate that can be skipped when a dependency is
// missing is an open gate, so it is restated here in something that is always present.
const writtenHeader = readFileSync(pluginFile, 'utf8').match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1]?.trim()
const written = JSON.parse(readFileSync(manifestFile, 'utf8'))
if (writtenHeader !== version) die(`the header says ${writtenHeader}, not ${version}`)
if (written.version !== version) die(`the manifest says ${written.version}, not ${version}`)
if (written.package !== packageUrl) die('the manifest points at an asset other than the one about to be created')
console.log('· header, manifest and package name all say one number')

// ── 5. package ───────────────────────────────────────────────────────────────────────────────
run('bash', ['build.sh'], { cwd: cowork, stdio: 'inherit' })
renameSync(join(cowork, 'dist', 'claude-cowork.zip'), join(cowork, 'dist', zipName))
console.log(`· dist/${zipName}`)

if (dryRun) {
  console.log('\n--dry-run: stopping here. Two files were edited — `git checkout` them if you do not want that.\n')
  process.exit(0)
}

// ── 6 & 7. one commit, one tag, one release ─────────────────────────────────────────────────
run('git', ['add', 'wordpress/cowork/claude-cowork/claude-cowork.php', 'wordpress/update.json'])
run('git', ['commit', '-m', `release(wordpress): ${version}`])
run('git', ['tag', `wordpress-v${version}`])
run('git', ['push', 'origin', 'HEAD', '--tags'])

run('gh', [
  'release',
  'create',
  `wordpress-v${version}`,
  join(cowork, 'dist', zipName),
  '--title',
  `WordPress ${version}`,
  '--notes',
  `Plugin ${version}. Sites on ${version} or newer need do nothing; older ones update themselves.`
])

console.log(`
✓ wordpress-v${version} released.

  The manifest is the single source of truth and it now points at this build. Sites running Tracy
  check it about twice a day and update themselves — nobody has to press anything. To withdraw this
  release, set wordpress/update.json back to the previous version and push: no site is touched.
`)
