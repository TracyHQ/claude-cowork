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

const SKIP = /^(LICENSE\.txt|dist\/|\.git\/|node_modules\/)/

/**
 * Tracked files, plus whatever the build produced.
 *
 * The built copy of `lib/` is gitignored, so a check that only read the index would never see
 * it — and that copy is precisely where a well-meant edit goes to be forgotten, since editing
 * it appears to work right up until the next build overwrites it. Run this after the build and
 * both the source and what actually ships are covered.
 */
const tracked = execSync('git ls-files', { encoding: 'utf8' }).split('\n')
const built = existsSync('com_claudecowork/administrator/lib')
  ? execSync('find com_claudecowork/administrator/lib -type f', { encoding: 'utf8' }).split('\n')
  : []
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
