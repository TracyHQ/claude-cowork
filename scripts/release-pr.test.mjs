// node --test scripts/release-pr.test.mjs
//
// The git/gh half of the three release scripts (scripts/release-pr.mjs), with gh stood in for so
// nothing touches GitHub. What it holds still: the package is downloadable before main announces
// it, main is never pushed to, the merge is a merge commit without --admin, and every stop says
// what exists and how to finish or roll back.

import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'
import { preflight, publishViaPullRequest, releaseBranch } from './release-pr.mjs'

const tag = 'wordpress-v9.9.9'
const branch = releaseBranch(tag)
const release = { assets: ['dist/x-9.9.9.zip', 'dist/tracy-release.json'], title: 'WordPress 9.9.9', notes: 'n' }

class Died extends Error {}
const die = (m) => {
  throw new Died(m)
}

// A stand-in for `run`: answers from `answers` (keyed by "cmd sub…" prefix), records every call,
// and throws for a key whose answer is an Error (or a function returning one).
function fake(answers = {}) {
  const calls = []
  const run = (cmd, args) => {
    const line = [cmd, ...args].join(' ')
    calls.push(line)
    const key = Object.keys(answers)
      .filter((k) => line.startsWith(k))
      .sort((a, b) => b.length - a.length)[0]
    let out = key === undefined ? '' : answers[key]
    if (typeof out === 'function') out = out(line)
    if (out instanceof Error) throw out
    return out
  }
  return { run, calls }
}
const MERGED = JSON.stringify({ state: 'MERGED', mergedAt: '2026-10-27T17:00:00Z', mergeCommit: { oid: 'abcdef1234' } })
const failing = (stderr) => Object.assign(new Error('Command failed'), { stderr })
const at = (calls, prefix) => calls.findIndex((c) => c.startsWith(prefix))
const publish = (run, extra = {}) =>
  publishViaPullRequest({ run, die, log: () => {}, sleep: () => {}, tag, files: ['wordpress/update.json'], message: 'release(wordpress): 9.9.9', release, ...extra })

test('preflight refuses a stale checkout, a used tag, a leftover release branch', () => {
  const ok = { 'git rev-parse': 'abc', 'git status': '', 'git tag': '', 'git branch': '', 'git ls-remote': '' }
  assert.doesNotThrow(() => preflight({ run: fake(ok).run, die, tag }))
  assert.throws(() => preflight({ run: fake({ ...ok, 'git rev-parse HEAD': 'abc', 'git rev-parse origin/main': 'def' }).run, die, tag }), /HEAD differs from origin\/main/)
  assert.throws(() => preflight({ run: fake({ ...ok, 'git status': ' M x' }).run, die, tag }), /uncommitted/)
  assert.throws(() => preflight({ run: fake({ ...ok, 'git tag': tag }).run, die, tag }), /already exists/)
  assert.throws(() => preflight({ run: fake({ ...ok, 'git branch': `  ${branch}` }).run, die, tag }), /local branch release\/wordpress-v9\.9\.9/)
  assert.throws(() => preflight({ run: fake({ ...ok, 'git ls-remote': `abc\trefs/heads/${branch}` }).run, die, tag }), /origin already has/)
  assert.throws(() => preflight({ run: fake({ ...ok, 'gh auth status': failing('not logged in') }).run, die, tag }), /gh is not logged in/)
  // A dry run publishes nothing, so it does not need gh.
  assert.doesNotThrow(() => preflight({ run: fake({ ...ok, 'gh auth status': failing('not logged in') }).run, die, tag, dryRun: true }))
})

test('order: branch commit and tag, push both, release, then the PR and a merge commit', () => {
  const { run, calls } = fake({ 'git status --porcelain': 'M  wordpress/update.json', 'gh pr create': 'https://github.com/o/r/pull/7', 'gh pr view': MERGED })
  assert.deepEqual(publish(run), { merged: true, pr: 'https://github.com/o/r/pull/7' })

  const order = [`git switch --create ${branch}`, 'git commit', `git tag ${tag}`, 'git push', 'gh release create', 'gh pr create', 'gh pr merge', 'gh pr view', 'git switch main']
  const idx = order.map((p) => at(calls, p))
  assert.ok(idx.every((i) => i >= 0), `missing a step in:\n${calls.join('\n')}`)
  assert.deepEqual([...idx].sort((a, b) => a - b), idx, 'steps out of order')

  assert.equal(calls[at(calls, 'git push')], `git push --atomic origin refs/heads/${branch}:refs/heads/${branch} refs/tags/${tag}`)
  assert.ok(!calls.some((c) => c.startsWith('git push') && /\bmain\b|--tags|HEAD/.test(c)), 'nothing but the branch and its tag is pushed')
  assert.match(calls[at(calls, 'gh release create')], /--verify-tag/)
  assert.match(calls[at(calls, 'gh pr create')], new RegExp(`--base main --head ${branch}`))
  assert.equal(calls[at(calls, 'gh pr merge')], 'gh pr merge https://github.com/o/r/pull/7 --merge')
  assert.ok(!calls.some((c) => /--admin|--squash|--rebase/.test(c)))
})

test('--latest=false reaches gh only when asked for', () => {
  const plain = fake({ 'git status --porcelain': 'M x', 'gh pr create': 'u', 'gh pr view': MERGED })
  publish(plain.run)
  assert.doesNotMatch(plain.calls[at(plain.calls, 'gh release create')], /--latest/)
  const look = fake({ 'git status --porcelain': 'M x', 'gh pr create': 'u', 'gh pr view': MERGED })
  publish(look.run, { release: { ...release, latest: false } })
  assert.match(look.calls[at(look.calls, 'gh release create')], /--latest=false/)
})

test('nothing to commit: the tag goes on main as it is, no branch is pushed, no PR', () => {
  const { run, calls } = fake({ 'git status --porcelain': '' })
  assert.deepEqual(publish(run), { merged: true, pr: null })
  assert.equal(calls[at(calls, 'git push')], `git push --atomic origin refs/tags/${tag}`)
  assert.equal(at(calls, 'git commit'), -1)
  assert.equal(at(calls, 'gh pr'), -1)
  assert.ok(at(calls, 'gh release create') > at(calls, 'git push'))
})

test('a failed push stops before anything is published', () => {
  const { run, calls } = fake({ 'git status --porcelain': 'M x', 'git push': failing('rejected') })
  assert.throws(() => publish(run), (e) => e instanceof Died && /nothing was published/.test(e.message) && /git tag -d wordpress-v9\.9\.9/.test(e.message))
  assert.equal(at(calls, 'gh'), -1)
})

test('a failed release stops before the PR: main announces nothing', () => {
  const { run, calls } = fake({ 'git status --porcelain': 'M x', 'gh release create': failing('422') })
  assert.throws(() => publish(run), /no release and main announces nothing/)
  assert.equal(at(calls, 'gh pr'), -1)
})

test('checks not done yet: wait for them, then merge', () => {
  let merges = 0
  const { run, calls } = fake({
    'git status --porcelain': 'M x',
    'gh pr create': 'https://github.com/o/r/pull/7',
    'gh pr merge': () => (++merges === 1 ? failing('the base branch policy prohibits the merge') : ''),
    'gh pr view': MERGED
  })
  assert.equal(publish(run).merged, true)
  assert.ok(at(calls, 'gh pr checks https://github.com/o/r/pull/7 --watch') > at(calls, 'gh pr merge'))
  assert.equal(merges, 2)
})

test('a merge that cannot happen stops: the release exists, how to finish, how to roll back', () => {
  const { run, calls } = fake({
    'git status --porcelain': 'M x',
    'gh pr create': 'https://github.com/o/r/pull/7',
    'gh pr checks': failing('1 failing check'),
    'gh pr merge': failing('Pull request is not mergeable: the merge commit cannot be cleanly created')
  })
  assert.throws(
    () => publish(run),
    (e) =>
      e instanceof Died &&
      /release wordpress-v9\.9\.9 EXISTS/.test(e.message) &&
      /no site is offered this version until https:\/\/github\.com\/o\/r\/pull\/7 is merged/.test(e.message) &&
      /gh pr merge https:\/\/github\.com\/o\/r\/pull\/7 --merge/.test(e.message) &&
      /gh release delete wordpress-v9\.9\.9 --cleanup-tag/.test(e.message) &&
      /gh pr close/.test(e.message)
  )
  assert.equal(calls.filter((c) => c.startsWith('gh pr merge')).length, 2, 'tried once, waited, tried once more — no more')
})

test('gh pr merge succeeds but the PR is only queued: stop, never report it merged', () => {
  for (const view of [JSON.stringify({ state: 'OPEN', mergedAt: null, mergeCommit: null }), failing('HTTP 502')]) {
    const { run, calls } = fake({ 'git status --porcelain': 'M x', 'gh pr create': 'https://github.com/o/r/pull/7', 'gh pr view': view })
    assert.throws(
      () => publish(run),
      (e) =>
        e instanceof Died &&
        (view instanceof Error ? /state could not be read/ : /is OPEN, not MERGED/).test(e.message) &&
        /release wordpress-v9\.9\.9 EXISTS/.test(e.message) &&
        /no site is offered this version until https:\/\/github\.com\/o\/r\/pull\/7 is merged/.test(e.message) &&
        /gh pr view https:\/\/github\.com\/o\/r\/pull\/7 --json state,mergedAt/.test(e.message) &&
        /gh release delete wordpress-v9\.9\.9 --cleanup-tag/.test(e.message)
    )
    assert.equal(calls.filter((c) => c.startsWith('gh pr merge')).length, 1, 'merge ran once and succeeded')
    assert.equal(at(calls, 'git switch main'), -1, 'the checkout stays on the release branch')
  }
})

test('gh pr merge succeeds but the PR is CLOSED: reopen before merging, or roll back without gh pr close', () => {
  const { run, calls } = fake({ 'git status --porcelain': 'M x', 'gh pr create': 'https://github.com/o/r/pull/7', 'gh pr view': JSON.stringify({ state: 'CLOSED', mergedAt: null, mergeCommit: null }) })
  assert.throws(
    () => publish(run),
    (e) => {
      assert.ok(e instanceof Died)
      assert.match(e.message, /is CLOSED without being merged/)
      assert.match(e.message, /release wordpress-v9\.9\.9 EXISTS/)
      const reopen = e.message.indexOf('gh pr reopen https://github.com/o/r/pull/7')
      const merge = e.message.indexOf('gh pr merge https://github.com/o/r/pull/7 --merge')
      assert.ok(reopen >= 0 && merge > reopen, 'reopen comes before the merge')
      assert.match(e.message, /git push origin refs\/tags\/wordpress-v9\.9\.9\^\{commit\}:refs\/heads\/release\/wordpress-v9\.9\.9/)
      assert.doesNotMatch(e.message, /gh pr close/, 'a closed PR cannot be closed again')
      assert.doesNotMatch(e.message, /If it is queued/)
      assert.match(e.message, /To roll back: \{ git push origin --delete release\/wordpress-v9\.9\.9 \|\| true; \} && gh release delete wordpress-v9\.9\.9 --cleanup-tag/)
      return true
    }
  )
  assert.equal(at(calls, 'git switch main'), -1, 'the checkout stays on the release branch')
})

// The same path with real git: a bare origin, a clone that releases, and gh stood in for by a
// second clone that merges the PR the way GitHub would (--no-ff). Proves the refspecs push what
// they should and that the tagged commit ends up in main's history even when main moved meanwhile.
test('with real git: main untouched until the merge, the tagged commit then in its history', () => {
  const root = mkdtempSync(join(tmpdir(), 'release-pr-'))
  const git = (cwd, ...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: 'pipe' }).trim()
  const id = ['-c', 'user.name=t', '-c', 'user.email=t@t', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false']
  try {
    const origin = join(root, 'origin.git')
    git(root, 'init', '--quiet', '--bare', '--initial-branch=main', origin)
    const work = join(root, 'work')
    git(root, 'clone', '--quiet', origin, work)
    writeFileSync(join(work, 'update.json'), '{"version":"1.0.0"}\n')
    git(work, ...id, 'add', '.')
    git(work, ...id, 'commit', '--quiet', '-m', 'init')
    git(work, 'push', '--quiet', 'origin', 'HEAD:main')
    const mainBefore = git(work, 'rev-parse', 'HEAD')

    const other = join(root, 'other')
    git(root, 'clone', '--quiet', origin, other)
    let mainAtRelease = null
    const run = (cmd, args) => {
      if (cmd === 'git') return git(work, ...id, ...args)
      const line = args.join(' ')
      if (line.startsWith('release create')) {
        // The tag is on origin, and main has not moved: nothing announces the release yet.
        assert.equal(git(origin, 'rev-parse', `refs/tags/${tag}^{commit}`), git(work, 'rev-parse', 'HEAD'))
        mainAtRelease = git(origin, 'rev-parse', 'refs/heads/main')
        return ''
      }
      if (line.startsWith('pr create')) {
        // Somebody else's PR lands first: main moved meanwhile.
        writeFileSync(join(other, 'other.txt'), 'x\n')
        git(other, ...id, 'add', '.')
        git(other, ...id, 'commit', '--quiet', '-m', 'other')
        git(other, 'push', '--quiet', 'origin', 'HEAD:main')
        return 'https://github.com/o/r/pull/1'
      }
      if (line.startsWith('pr merge')) {
        git(other, 'fetch', '--quiet', 'origin', branch)
        git(other, ...id, 'merge', '--quiet', '--no-ff', '-m', 'Merge pull request #1', 'FETCH_HEAD')
        git(other, 'push', '--quiet', 'origin', 'HEAD:main')
        return ''
      }
      if (line.startsWith('pr view')) return MERGED
      throw new Error(`unexpected gh ${line}`)
    }

    writeFileSync(join(work, 'update.json'), '{"version":"9.9.9"}\n')
    publishViaPullRequest({ run, die, log: () => {}, sleep: () => {}, tag, files: ['update.json'], message: 'release: 9.9.9', release })

    assert.equal(mainAtRelease, mainBefore, 'main did not move before the release existed')
    const tagged = git(origin, 'rev-parse', `refs/tags/${tag}^{commit}`)
    assert.equal(git(origin, 'show', `${tagged}:update.json`), '{"version":"9.9.9"}')
    git(origin, 'merge-base', '--is-ancestor', tagged, 'refs/heads/main') // throws when it is not
    assert.equal(git(origin, 'show', 'refs/heads/main:update.json'), '{"version":"9.9.9"}')
    // The checkout is back on an up-to-date main, the local release branch gone.
    assert.equal(git(work, 'branch', '--show-current'), 'main')
    assert.equal(git(work, 'rev-parse', 'HEAD'), git(origin, 'rev-parse', 'refs/heads/main'))
    assert.equal(git(work, 'branch', '--list', branch), '')
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})
