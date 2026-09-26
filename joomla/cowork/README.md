# Tracy Claude Cowork — Joomla

A Joomla component that lets Tracy work on a site — read its database and its files, and apply an
approved change back to it — over one token-authenticated endpoint.

```
index.php?option=com_claudecowork&task=api.exec&format=json
```

Installing it generates a token and stores it in the component's Options (`script.php`). Copy
that string into whatever is pairing with the site — installing by hand is therefore a complete
way to connect a site, with no admin session handed to anyone. Clearing the field revokes access:
an empty token refuses every request.

## What it can do

| Actions | |
| --- | --- |
| `info`, `site.stats`, `db.*`, `files.*`, `file.read`, `extension.list`, `core.manifest` | Reading, in pieces small enough to finish on a host that stops PHP after thirty seconds. `core.manifest` is the site's own record of which extensions are CMS core (ADR 0070 addendum). |
| `content.list`, `content.get` | The read half of the content mirror (ADR 0071): paged summaries with checksums, then full rows — the same bytes an apply will compare against. |
| `content.update`, `content.delete`, `media.upload` | The write catalog (ADR 0080): fifteen kinds behind two generic verbs — `article`, `category`, `tag`, `field`, `menuItem`, `menutype`, `redirect`, `banner`, `bannerClient`, `contact`, `newsfeed`, `module`, `templateStyle`, `user` (name/email/block only), `extensionParams`. Whitelisted columns only; tree-shaped kinds refuse create and never accept `alias`; delete is Joomla's own trash (`-2`), so it reverts. Plus one file under `images/` or `media/`. |
| `apply.revert`, `apply.list` | Every edit above is recorded under the caller's `apply_id`, so a whole deliverable goes back to exactly what was there. |
| `extension.install` | One `https` `.zip` URL the site downloads itself and hands to Joomla's own installer. No uninstall and no way to name a local path: a caller holding the token can add to a site, never quietly remove from it. |
| `extension.enable` | Switch one installed extension on or off — the `enabled` column nothing else in the catalog can reach (`extensionParams` writes `params` alone). Refuses a core row and refuses this component. **In** the undo log, unlike install: a switch is perfectly reversible. |
| `db.snapshot`, `db.rollback` | Copy the tables aside before something rewrites the schema, and rename them back if it does not land. Two renames per table, so a rollback is metadata and finishes in one request; what it displaces goes to the trash, not a `DROP`. |
| `core.upgrade`, `files.restore` | The migration hop (prepare/finalise toward a supported Joomla) and restoring a webroot from a signed URL — operational, outside the undo log like install. |

An install is deliberately **not** in the undo log: installing is additive, and Joomla owns the
uninstall. `extension.list` reports what is already there, so a caller can tell "already
installed" from "installed in another version" without guessing.

There is no shell, no arbitrary file write, and no action that is not named and bounded above.

## Layout

| Path | What |
| --- | --- |
| `lib/` | The engine. **No Joomla dependency**, unit tested on its own (`tests/run.php`). |
| `com_claudecowork/site/` | The endpoint: one thin controller that hands a request to the engine. |
| `com_claudecowork/administrator/` | Enough for Joomla to install it and show its Options, where the token is set. |
| `pkg_claudecowork.xml` | Package wrapper. |

`lib/` at the repo root is the source of truth; `build.sh` copies it into the component. Never
edit the copy — that is how the two silently diverge.

## Build and test

```bash
./build.sh                                    # → dist/pkg_claudecowork.zip
docker run --rm -v "$PWD":/w -w /w php:8.3-cli php tests/run.php
```

## Updates from inside the site

A site administrator sees a new version on the Extensions screen because the extension declares
where to ask and the answer sits in this repository. Cutting a release is therefore two files, in
one commit:

1. the version in the extension's own manifest, and
2. ``joomla/update.xml`` — the version, and the release asset it points at.

`tests/run.php` fails when they disagree, because the failure is otherwise invisible: the site
asks, gets an older number than it already has, and reports "up to date" forever.

**Joomla and WordPress both record the update address when the extension is INSTALLED.** A site
running a version cut before this existed has no address to ask, and stays silent until somebody
updates it once by hand. That first update is the price of adding this late; every one after it
is a click.

## Why the door is ALSO a system plugin

The component answers `index.php?option=com_claudecowork&task=api.exec` after Joomla has routed
the request — at the end of a chain every other system plugin runs first. A site behind a
"coming soon" page, an offline switch, a maintenance screen or a firewall extension has one of
those plugins answering every front-end request itself, and the component is never reached.
Measured 2026-09-04 on a Joomla 6.0.3 site: every `index.php?option=…` URL, core `com_ajax`
included, returned the same coming-soon page.

`plg_system_claudecoworkapi` answers the same door at `onAfterInitialise`, the earliest event a
system plugin gets: before the router, before any gatekeeper, and on the administrator client too,
where those gatekeepers do not act and before the administrator asks who is logged in. So the door
opens at two addresses — `/index.php?…` and `/administrator/index.php?…` — with the token in the
body as the only credential at either. A caller whose front door is blocked uses the back one.
Nothing in the plugin knows about any particular gatekeeper; it ships inside this package so one
install or update brings it, its install script enables it and orders it first, and both answerers
share one engine wiring (`EngineFactory`) so neither can drift.

## Why a component, not a plugin

This started as `plg_ajax_tracymigration`, reached through `com_ajax`. That works, but
`com_ajax` only exists from **Joomla 3.2** — verified against the Joomla repository: absent at
tags 2.5.0, 3.0.0 and 3.1.5, present at 3.2.0 — so it cannot cover every generation Tracy
supports. `index.php?option=com_x&task=y` is Joomla's oldest routing contract and is stable
from 1.5 through 6. A component needs nobody's permission for an entry point; `com_ajax`
exists precisely to lend one to plugins, which have none of their own.

A component also has somewhere to grow: its own admin screens, ACL and tables, none of which
a plugin can hold.

## Design

The engine does **one bounded piece of work per call** and returns a cursor; the caller keeps
the cursor and loops. Shared hosting kills PHP at `max_execution_time`, so anything that
cannot resume never finishes on a large site.

The component never holds object-storage credentials. For uploads it receives a presigned URL
good for exactly one part.

After a change lands it writes `tracy-changed.json` at the webroot — a timestamp and a coarse
reason, nothing else — because a preview watching the site cannot be called back: Tracy Desk runs
on the customer's machine and has no address. The site writes, whoever is watching reads. Only
changes made THROUGH this component are stamped; an administrator editing in the Joomla backend
is not, and covering that needs a system plugin the package manifest was shaped to allow.

## Transactional content batches (0.13.0)

`content.batch` accepts `apply_id`, a stable `request_id`, and 1–100 `operations`
(`kind`, `id`, `fields`, optional `key` and `expected` fields). A repeated request returns
the committed receipt; reusing its ID with another body fails. A conflict rolls back the
entire batch and its undo log. `apply.revert` replays the apply in reverse order.

The Joomla writer supports `articleAssociation`, `menuAssociation`, `moduleAssignment`
and the narrowly scoped `languageFilter` plugin settings. Relations validate their
existing targets; article/module writes use Joomla Tables. New menu items may supply a
unique alias. All component mutations serialize on the site's database advisory lock.

`extension.install` accepts optional `sha256` and `bytes`; a pinned package is verified
before extraction, including official language-pack download URLs with query strings.
This version does not change the default template or publish an automatic update feed.

Verified on Joomla 6: English seeding, adding French, repeated reconciliation, and
reverting both applies to the original menu and module assignments.

## Versioned quickstart content/display contracts

`content.contract` accepts `operation: inspect` or `apply`. Inspect resolves the trusted packaged
profile and reports slots, page relationships and a revision; apply accepts only scalar changes
with that expected revision, an immutable `contract-` apply ID and request ID. It validates
presentation before and inside the write transaction. The private
`#__claudecowork_content_contract` table stores the installation binding and baseline.

`lib/contracts/tracy-apple/j6/1.1.0` is a verbatim mirror of the canonical TCH profile. `build.sh`
packages it with the engine. Update the canonical TCH files first, then copy the entire profile;
never allow an agent to submit its own presentation lock. Generic structural writes are blocked
once bound. Reverting a contract is transactional and refuses to overwrite a later revision.

The first profile protects module status/schedules/type/position/order, menu assignments including
exclusions, template parameters, layout/override assets, language and effective ACL inheritance.
Joomla `{loadposition ...}` directives are structure, not editable copy. Factual slots require a
provenance reference, whose truth still needs review. Bound media uploads use content-addressed
paths under `images/tracy-content/`. Admin/direct database or filesystem access remains outside
this API boundary; a difference from the quickstart's design is reported as a warning on the next
contract request (Tracy ADR 0022), and refused only when the contract's own write made it.

Release activation is separate from source availability: 0.14.0 has been tested as a local package,
but the default TCH build recipe must also be migrated and pinned before claiming all new sites
use the content contract. The published 1.1.0 profile is not the later, locally modified 8212 demo.

## Unreleased Joomla 6 Content API pilot

The opt-in `content.read` action and authenticated `GET /content.json` share
`JoomlaContentReader`. GET requires the existing site token in `Authorization: Bearer …`;
never put it in a URL. The existing token is a service credential, not a Tracy seat.
The Tracy relay checks the current seat/policy on each request and supplies a server-owned
`contentPrincipal` for cursor isolation. No new write permission is granted.

The common machine contract is TCH `packages/cms/tracy-content-api` (foundation commit
`1e724aad20c3be7df82aebf9e5e59db87e0a6aa2`, local/unpushed). Cross-repository protocol ownership:
TracyHQ/tracy-docs `systems/content-api-v1.md`. This patch is not a receiver release.

On a **private Joomla 6 test fixture**, install the locally built receiver, then explicitly run:

```sh
php tools/enable-content-reader.php --root=/path/to/joomla --new-site
```

`--new-site` is required when creating a distinct site from a database clone: it rekeys the site
identity and cursor secret. Omit it only when enabling/rechecking the same site. The CLI needs
CREATE TABLE and TRIGGER privileges. It creates private identity/config tables and six AFTER
INSERT/DELETE triggers. Setup stays disabled if a step fails. GET never installs metadata,
backfills identities, adopts orphans, repairs bindings or resumes language jobs. Disable the
reader by setting `#__claudecowork_content_reader.enabled=0`; keep identities across a temporary
disable. Do not drop/recreate the registry to repair an error: that changes published IDs.
Reconcile missing triggers/identities administratively with the reader disabled and a backup.

Scope is bound, public-audience, currently published pages/articles/modules. Publication windows,
category/menu ancestors and viewlevels are applied. Module assignment is an occurrence candidate,
not proof of rendering; visibility remains unknown. Physical contract slots carry current values
and no inferred semantic key. Repeaters, exclusion assignments and media outside the mapped image
slots remain unresolved. `readMapping()` is separate from mutating native inspect. A damaged
binding fails closed; native inspect/apply/revert keep their existing write semantics.

Each request loads a repeatable-read InnoDB snapshot. The revision hashes the authorized content
projection, its contract hash and referenced local image bytes, rather than hidden source rows or
unrelated ACL labels. Local `images/` files are still scanned before and after the DB snapshot;
only image slots and `<img src>` references in readable markup contribute to the fingerprint.
No transaction spans requests and no snapshot cache needs cleanup. Cursor expiry is 300 seconds
and binds site, principal (including scope), filter, revision and owner. Source cost remains
linear across the mapped site and image directory: timing isolation and constant-cost paging
are not claimed. Remote images, CSS backgrounds and srcset bytes remain unresolved.
The pilot caps serialized responses at 262144 bytes, continuing blocks by signed cursor. A scalar
or unsegmentable metadata group that cannot fit returns 413, never truncated content. Item paging
is not advertised until stable repeater mapping exists. Images changed after the final scan are
seen on the next request; this does not claim a filesystem transaction with MariaDB.

Runtime acceptance lives in TCH `scripts/test/content-api-joomla-runtime.test.mjs`; credentials,
DB checkpoints and fixture configuration stay outside either repository. The test restores a full
DB checkpoint and file bytes in `finally`, and recovers a persisted dirty marker on the next run.
SQL stress mutations and real Joomla HTTP API saves are recorded separately. See TCH
`tasks/evidence/content-api-p2/REPORT.md` for measured cases and remaining gaps.

For partial detail, merge present properties as well as ordered blocks. A large raw body can be
deferred to the final segment (with no blocks remaining); its absence in an earlier segment means
not loaded, never an invented null/empty value. The shared P1 schema already permits this.


Content API relay protocol note (unreleased): `contentScope` is server-owned at the Tracy hop;
this pilot supports `published` only and returns501 for another scope. `contentPrincipal` binds
the signed cursor to a verified seat context; a service credential is not a seat. List continuation
can send only `cursor`; explicit filter/limit repetitions must match its signed query. No identity
migration, permission widening or contract repair runs from a read request. WordPress and EmDash
transport/identity evidence is tracked separately; this receiver does not certify their support.

Protocol b0a40e0 discovery: an oversized body with mapped blocks returns 413 with
`error.snapshot` and `error.links.firstBlock`. Follow its signed `blocksCursor` with the same
authorization. Each block continuation retains the snapshot and original expiry; changing the
principal, owner or block is rejected. Stop block scanning when `blocksPagination.nextCursor`
is null; the full-content self link is not another block segment. This does not chunk bodyHtml.
