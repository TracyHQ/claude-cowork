// The git and gh half of a release, shared by release-wordpress.mjs, release-wordpress-theme.mjs
// and release-joomla-template.mjs.
//
// Nobody pushes to `main`: every change, a release included, reaches it through a pull request.
// The Joomla core release already went that way by hand (a `release/…` branch, the tag on the
// release commit, a merge commit into main — PR #245); this is the same path, for the scripts.
//
// The ORDER is the point. The manifests on main (wordpress/update.json, wordpress/theme/…/update.json,
// joomla/template/update.xml) are read by installed sites every quarter hour, and a site must never
// read a version whose package cannot be downloaded yet. So:
//
//   1. commit the bumped files on `release/<tag>`, tag that commit;
//   2. push the branch and the tag, nothing else (not `--tags`: every stray local tag would go too);
//   3. create the GitHub release on that tag, with its zip — the package is now downloadable;
//   4. only then open the PR to main and merge it with a MERGE commit, so the tagged commit is in
//      main's history (a squash or rebase would leave the tag on a commit main never had).
//
// If main moved in between, the merge commit is still right. If the merge cannot happen (a conflict,
// a failing check), the script stops and says so: the release exists, but no site is offered it
// until the PR merges. `gh pr merge` runs without `--admin` — the rules that hold for a person hold
// for the script.
//
// Every function takes `run` (execFileSync-shaped: cmd, args, opts → trimmed stdout, throws on a
// non-zero exit) so release-pr.test.mjs can stand in for git and gh without touching GitHub.

export const releaseBranch = (tag) => `release/${tag}`

// Why a command failed, in its own words: execFileSync puts them on the error, not in its message.
const why = (err) => String(err?.stderr || err?.message || err).trim().split('\n').slice(-3).join('\n  ')

// Runs before anything is edited or built: a release from a stale checkout publishes a build nobody
// can trace back through history, and a branch or tag left over from an earlier attempt would make
// the push fail after the build — or worse, publish under a name that already means something.
export function preflight({ run, die, tag, dryRun = false, staleHint = 'Merge and pull before cutting a release.', dirtyHint = 'Commit or stash them first.' }) {
  try {
    run('git', ['fetch', 'origin', 'main', '--tags', '--quiet'])
  } catch {
    die('could not fetch origin/main — there is no way to tell whether this checkout is current')
  }
  if (run('git', ['rev-parse', 'HEAD']) !== run('git', ['rev-parse', 'origin/main'])) {
    die(`HEAD differs from origin/main. ${staleHint}`)
  }
  if (run('git', ['status', '--porcelain'])) die(`the working tree has uncommitted changes. ${dirtyHint}`)
  if (run('git', ['tag', '--list', tag])) die(`tag ${tag} already exists — that number has been used`)

  const branch = releaseBranch(tag)
  if (run('git', ['branch', '--list', branch])) {
    die(`a local branch ${branch} already exists — a release of this number was started before.\n  Delete it (\`git branch -D ${branch}\`) if that attempt published nothing.`)
  }
  if (run('git', ['ls-remote', '--heads', 'origin', branch])) {
    die(`origin already has a branch ${branch} — a release of this number was started before.\n  Look at it (and at its PR) before deleting it.`)
  }
  if (!dryRun) {
    try {
      run('gh', ['auth', 'status'])
    } catch {
      die('gh is not logged in (`gh auth login`) — the release and its pull request are made with it')
    }
  }
}

// Steps 1–4 above. `files` are repo-relative paths to commit; `release` is what `gh release create`
// needs. When the files already say what this release says (a theme's first release, whose manifest
// was written by hand), there is nothing to commit and nothing to merge: the tag goes on HEAD, which
// is origin/main, and no PR is opened.
export function publishViaPullRequest({ run, die, log = console.log, sleep = sleepSync, tag, files, message, release, prBody }) {
  const branch = releaseBranch(tag)

  run('git', ['switch', '--create', branch])
  run('git', ['add', '--', ...files])
  const changed = Boolean(run('git', ['status', '--porcelain', '--', ...files]))
  if (changed) {
    run('git', ['commit', '-m', message])
  } else {
    log('· the files already said this version — nothing to commit, so no pull request either')
  }
  run('git', ['tag', tag])

  // ── push: the branch and its tag, together or not at all ─────────────────────────────────────
  const refs = changed ? [`refs/heads/${branch}:refs/heads/${branch}`, `refs/tags/${tag}`] : [`refs/tags/${tag}`]
  try {
    run('git', ['push', '--atomic', 'origin', ...refs])
  } catch (err) {
    die(
      `the push failed — nothing was published:\n  ${why(err)}\n\n` +
        `  To start over: git switch main && git branch -D ${branch} && git tag -d ${tag}`
    )
  }
  log(`· pushed ${changed ? `${branch} and ` : ''}tag ${tag}`)

  // ── the package, downloadable before anything announces it ──────────────────────────────────
  try {
    run('gh', ['release', 'create', tag, ...release.assets, '--verify-tag', '--title', release.title, ...(release.latest === false ? ['--latest=false'] : []), '--notes', release.notes])
  } catch (err) {
    die(
      `\`gh release create ${tag}\` failed — the tag is pushed, but there is no release and main announces nothing:\n  ${why(err)}\n\n` +
        `  To finish: create the release for ${tag} with its assets, then open and merge the PR from ${branch}\n` +
        `  with a merge commit. To roll back: git push origin --delete ${changed ? `${branch} ` : ''}refs/tags/${tag}` +
        ` && git switch main && git branch -D ${branch} && git tag -d ${tag}`
    )
  }
  log(`· release ${tag} created`)

  if (!changed) {
    backToMain({ run, log, branch })
    return { merged: true, pr: null }
  }

  // ── the announcement: a PR to main, merged with a merge commit ──────────────────────────────
  let pr
  try {
    pr = run('gh', ['pr', 'create', '--base', 'main', '--head', branch, '--title', message, '--body', prBody ?? message]).split('\n').pop()
  } catch (err) {
    stopUnmerged({ die, tag, branch, pr: branch, reason: `\`gh pr create\` failed:\n  ${why(err)}`, opened: false })
  }
  log(`· ${pr}`)

  const merge = () => run('gh', ['pr', 'merge', pr, '--merge'])
  try {
    merge()
  } catch (first) {
    // Most often the required checks have not finished (or not even started) on a PR opened a
    // second ago. Wait for them once; anything else — a conflict, a red check, a review the rules
    // require — is for a person to decide, not for this script to force.
    log(`· not mergeable yet (${why(first).split('\n')[0]}) — waiting for the checks`)
    sleep(15_000)
    let checks = null
    try {
      run('gh', ['pr', 'checks', pr, '--watch', '--fail-fast'], { stdio: 'inherit' })
    } catch (err) {
      checks = err
    }
    try {
      merge()
    } catch (second) {
      stopUnmerged({ die, tag, branch, pr, reason: `${checks ? 'a check did not pass, and ' : ''}\`gh pr merge\` refused:\n  ${why(second)}`, opened: true })
    }
  }

  // `gh pr merge` exiting 0 is not "merged": with a merge queue or auto-merge it only queued the PR,
  // and a check that fails later would leave the release unannounced behind a success message. Only
  // the PR's own state says main took it.
  let state
  try {
    state = JSON.parse(run('gh', ['pr', 'view', pr, '--json', 'state,mergedAt,mergeCommit']))
  } catch (err) {
    stopUnmerged({ die, tag, branch, pr, reason: `\`gh pr merge\` returned, but the PR's state could not be read:\n  ${why(err)}`, opened: true, queued: true })
  }
  if (state?.state === 'CLOSED') {
    // Closed without merging (by a person, or a queue that gave up and closed it): a closed PR
    // cannot be merged, and `gh pr close` on it fails, so both ways out start elsewhere.
    stopUnmerged({ die, tag, branch, pr, reason: `\`gh pr merge\` returned, but ${pr} is CLOSED without being merged.`, opened: true, closed: true })
  }
  if (state?.state !== 'MERGED') {
    stopUnmerged({ die, tag, branch, pr, reason: `\`gh pr merge\` returned, but ${pr} is ${state?.state ?? 'in an unknown state'}, not MERGED — queued for a merge queue or auto-merge, or held by a rule.`, opened: true, queued: true })
  }
  log(`· ${pr} merged into main (${state.mergeCommit?.oid?.slice(0, 7) ?? 'merge commit'})`)
  backToMain({ run, log, branch })
  return { merged: true, pr }
}

function stopUnmerged({ die, tag, branch, pr, reason, opened, queued = false, closed = false }) {
  die(
    `${reason}\n\n` +
      `  The release ${tag} EXISTS and its package is downloadable, but main does not announce it:\n` +
      `  no site is offered this version until ${opened ? `${pr}` : `a PR from ${branch}`} is merged.\n\n` +
      (queued ? `  If it is queued: watch it (gh pr view ${pr} --json state,mergedAt). Once it is MERGED there is nothing left to do\n  but \`git switch main && git pull\`; if it drops out of the queue, finish as below.\n` : '') +
      `  To finish: ${closed ? `gh pr reopen ${pr} (if ${branch} was deleted, push it again from the tag first:\n    git push origin refs/tags/${tag}^{commit}:refs/heads/${branch}), then ` : opened ? '' : `gh pr create --base main --head ${branch} --fill, then `}fix what stopped it` +
      ` (for a conflict: merge main into ${branch} and push — the tagged commit stays in the history),\n` +
      `    then gh pr merge ${opened ? pr : branch} --merge   (a merge commit: no squash, no rebase).\n` +
      `  To roll back: ${opened && !closed ? `gh pr close ${pr} --delete-branch` : closed ? `{ git push origin --delete ${branch} || true; }` : `git push origin --delete ${branch}`}` +
      ` && gh release delete ${tag} --cleanup-tag --yes && git switch main && git branch -D ${branch} && git tag -d ${tag}`
  )
}

// Leave the checkout where a person expects it. Failing here changes nothing that was published.
function backToMain({ run, log, branch }) {
  try {
    run('git', ['switch', 'main'])
    run('git', ['fetch', 'origin', 'main', '--quiet'])
    run('git', ['merge', '--ff-only', 'origin/main'])
    run('git', ['branch', '-D', branch])
  } catch (err) {
    log(`· could not bring the local main up to date (${why(err).split('\n')[0]}) — \`git switch main && git pull\``)
  }
}

function sleepSync(ms) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms)
}
