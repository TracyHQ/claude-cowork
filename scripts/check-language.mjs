#!/usr/bin/env node
/**
 * Refuses any Vietnamese that reaches this repository.
 *
 * This code is installed on other people's servers and read by the people whose servers they
 * are, so every word in it is a public surface (ADR 0016). The rule is not a style preference
 * and reviewing for it by eye does not work: someone fluent in a language does not perceive it
 * as the wrong one. That is the whole reason this is a script and not a checklist item.
 *
 * Matches on Vietnamese words rather than on diacritics, because the comments this repository
 * started with had none — `khong phu thuoc Joomla` would pass any accent-based check.
 */
import { readFileSync, existsSync } from 'node:fs'
import { execSync } from 'node:child_process'

/** Common words with no English meaning, so an English sentence cannot trip this by accident. */
const WORDS = [
  'khong', 'duoc', 'nhung', 'phai', 'nen', 'cua', 'nay', 'vay', 'roi', 'chua',
  'nguoi', 'viec', 'lam', 'giu', 'tra', 'dung', 'thi', 'neu', 'vi sao', 'boi vi',
  'không', 'được', 'nhưng', 'phải', 'của', 'này', 'người', 'việc', 'làm', 'giữ'
]
/** Two hits on one line is prose; one can be a variable name or a foreign product name. */
const THRESHOLD = 2

/**
 * `check-language.mjs` excludes itself: the list of words it looks for is, necessarily, a list
 * of the words it forbids. Left in scope it fails on its own source — which it did, the first
 * time it was run from a clean clone after being committed.
 */
// Anchored on the SEGMENT, not on the start of the path: the same file is `scripts/…` when the
// checker runs inside `joomla/` and `joomla/scripts/…` when it runs from the repository root,
// and a rule that only matched the first shape made the checker fail on its own word list the
// moment a second platform gave anyone a reason to run it from the root.
const SKIP = /(^|\/)(LICENSE\.txt$|dist\/|\.git\/|node_modules\/|scripts\/check-language\.mjs$)/

/**
 * Tracked files, plus whatever the build produced.
 *
 * The built copy of `lib/` is gitignored, so a check that only read the index would never see
 * it — and that copy is precisely where a well-meant edit goes to be forgotten, since editing
 * it appears to work right up until the next build overwrites it. Run this after the build and
 * both the source and what actually ships are covered.
 *
 * Scope is the working directory, so each package's job checks its own files by running this
 * from its own folder. Where the built copy lands differs per package, so it is named on the
 * command line instead of hardcoded here — one checker for every platform.
 */
const tracked = execSync('git ls-files', { encoding: 'utf8' }).split('\n')
const built = process.argv
  .slice(2)
  .filter((dir) => existsSync(dir))
  .flatMap((dir) => execSync(`find ${dir} -type f`, { encoding: 'utf8' }).split('\n'))
const files = [...new Set([...tracked, ...built])].filter((f) => f && !SKIP.test(f))

const pattern = new RegExp(`\\b(${WORDS.join('|')})\\b`, 'gi')
const offences = []

for (const file of files) {
  let text
  try {
    text = readFileSync(file, 'utf8')
  } catch {
    continue // binary, or gone
  }
  text.split('\n').forEach((line, i) => {
    const hits = line.match(pattern)
    if (hits && hits.length >= THRESHOLD) {
      offences.push(`${file}:${i + 1}: ${line.trim().slice(0, 90)}`)
    }
  })
}

if (offences.length > 0) {
  console.error(`Vietnamese found in ${offences.length} line(s). This repository is public and English only:\n`)
  for (const o of offences) console.error(`  ${o}`)
  process.exit(1)
}
console.log(`Language check passed across ${files.length} files.`)
