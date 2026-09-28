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

## Render stamps for the page picker (0.18.0)

Tracy's page picker lets a person click an element of their site and ask for a change. To change
the right record it needs to know what printed that element, and only Joomla knows that while it
renders. So `plg_system_claudecoworkapi` marks the page — for one kind of request only:

| Stamp | Where |
| --- | --- |
| `data-tracy-src="module:<id> block:<module type>"` | first element of every module (id 0, a template's on-the-fly module, is skipped) |
| `<template data-tracy-owner="article:<id>:<alias>">` | inside every article render: the article page and each blog/featured item |
| `<template data-tracy-owner="category:<id>:<alias>">` | inside a category's description (context `com_content.categories`) |
| `data-tracy-src="article:<id>:<alias> block:<module type>"` | each item of an article-list module: `mod_articles`, `mod_articles_news`, `mod_articles_latest`, `mod_articles_category`, `mod_articles_popular`, `mod_related_items`, and `mod_ja_acm` when it links to two articles or more |
| `data-tracy-src="category:<id>:<alias>[ block:<module type>]"` | each `<a>` linking to a category page, in module output (not menu modules) and in the component output (0.18.3) |
| `data-tracy-src="tag:<id>:<alias>[ block:<module type>]"` | each `<a>` linking to a tag page, same places (0.18.3) |

Menu items need nothing: Joomla and T4 already print the id on each `<li>`. The format is shared
with the picker runtime and the resolver in Tracy; change it there first. The rules are plain PHP in
`lib/RenderStamps.php`, tested in `tests/render-stamps.php`.

**Who gets them.** A site-client request carrying `X-Tracy-Preview: pick` — added by Tracy's site
proxy only after it verified a preview ticket, and stripped when a browser sends it — on a site with
a cowork token. A site reachable without the proxy (an imported site) cannot tell that header from
anyone else's; what such a caller gets is the ids of records already public on that page, never
cached. A signed ticket for those sites is a later step. Every other request is byte-identical to
a site without this plugin (measured on Joomla 6.1.3: same session, plugin on vs off, `cmp` equal).

**Never cached.** For a stamped request the plugin switches Joomla caching off at
`onAfterInitialise` (so the conservative view and module caches neither serve nor store), answers
`false` to the page cache's `onPageCacheSetCaching` and `true` to `onPageCacheIsExcluded`, and sends
the response uncachable (`no-store`) with `Vary: Cookie`.

**List modules.** They build their lists in their own helpers and fire no content event per item,
and Joomla has no hook between fetching the items and printing them. What every layout does print
is a link to each article, so the plugin scans the module's own output with a small tag scanner
(not DOMDocument, which would re-serialise the markup), ties each link to an article by building the
candidate's route exactly as the article modules do and requiring an exact match, and stamps the
largest element around the link that links to that one article only. A link it cannot tie to
exactly one article is left alone.

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

**Category and tag links (0.18.3).** A category or tag name printed as a link — a breadcrumb, a
blog's filter chips, the category line of a news card, a sidebar category list — is stamped on the
`<a>` itself, since the item around it usually belongs to something else (the article a card shows).
The link is tied to a record the same way article links are (`TaxonomyLinks`: candidates by id or
alias, confirmed by building the route Joomla's category/tag view builds). A category or tag that a
published menu item opens directly is skipped: at that address Joomla prints the menu item's title,
not the category's. Menu modules get no such stamps for the same reason.

## Content API (`content.read`, `/content.json`)

Released in **0.18.0**. Each content names its own Joomla records (`native`) since **0.18.2**;
batch reads (`ids`) and the records and fields beside the contract slots since **0.18.3**.

The opt-in `content.read` action and authenticated `GET /content.json` share
`JoomlaContentReader`. The reader takes a flat query: `id`, `type`, `locale`, `limit`, `cursor`,
`blocksCursor`, `blockId` and `maxBytes` (8192 to 262144 bytes of JSON), or `ids` with `maxBytes`
only (below). `protocolVersions` is accepted and ignored. `describe` is not a reader parameter: it
is answered by Tracy's relay, from what this reader returns. GET requires the existing site token in `Authorization: Bearer …`;
never put it in a URL. The existing token is a service credential, not a Tracy seat.
The Tracy relay checks the current seat/policy on each request and supplies a server-owned
`contentPrincipal` for cursor isolation. No new write permission is granted.

The common machine contract is TCH `packages/cms/tracy-content-api`. Cross-repository protocol
ownership: TracyHQ/tracy-docs `systems/content-api-v1.md`.

To enable the reader on a site, install the receiver, then explicitly run:

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

### Many details in one read, and reads that overlap (0.18.3)

Every read builds the site's whole projection (all mapped tables, the contract, the router), so
its cost barely depends on how much it answers. `ids` asks for up to 100 details in one call — a
list in the door's `params`, one comma-separated value over GET — and may carry `maxBytes`,
nothing else. Each content that fits is answered exactly as `{id}` answers it; the rest are named
in `pagination.pending` (did not fit, or needs block segments: ask again, or read it alone) and
`pagination.missing` (no such content now). Nothing is cut and nothing pages.

A read writes nothing while it holds its database snapshot, so any number of reads may overlap.
Page identities (no trigger, see `ContentIdentity`) are levelled before the snapshot, and only
when a menu item appeared or went since the last read.

### Records and fields beside the contract slots (0.18.3)

A page prints words that no contract slot holds. `content.read` carries them too, each group as one
block keyed by the Joomla row's **native id** — the id a render stamp names (`article:548` resolves
to a block whose key starts `article-548`). None of these fields has a `slotKey`, so
`content.contract` never writes them; the column says how each one IS written.

| Record | Block key | Field keys | Source | Written through |
| --- | --- | --- | --- | --- |
| article | `article-<id>.images` | `article-<id>.image_intro`, `.image_intro_caption`, `.image_fulltext`, `.image_fulltext_caption` | the `images` column; Joomla's `#joomlaImage://…` suffix removed; alt text on the picture in `images[]` | `content.update` article (`images`) |
| article | `article-<id>.fields` | `article-<id>.field.<name>` | published, public custom field values of type text, textarea, editor (html) and media (image), in the article's language | read-only (values live outside the article row) |
| article | `article-<id>.author` | `article-<id>.author` | `created_by_alias`, else the author's display name (`#__users.name` only) | read-only |
| page | `menuItem-<id>.params` | `menuItem-<id>.params.<name>` | menu item params written as words (see below) | `content.update` menuItem (`params`) |
| page | `menuItem-<id>.megamenu` | `menuItem-<id>.megamenu[<template>:<profile>].caption`, `….column.<row>.<col>` | T4 mega menu: item caption and mega column titles, from the template's navigation profile (`local/etc/navigation/<profile>.json`, else `etc/…`) | read-only (a template file) |
| page (inlined module) | the module's block | `module-<id>.title` | the module title, when the module shows it and is inlined into its one page (a shared module already has its title as the record's own) | `content.update` module (`title`) |
| category (`shared`, id from `category:<id>`) | `category-<id>` | `category-<id>.description`, `category-<id>.image` | the categories readable articles and category pages live in, with their ancestors; public and published | `content.update` category (`title`, `description`, `params`) |
| tag (`shared`, id from `tag:<id>`) | `tag-<id>` | `tag-<id>.description`, `tag-<id>.image` | the published public tags on readable articles | `content.update` tag |

An article also lists its tag names in `tags` and its category record as a `parent` relation; a
category names its parent category the same way. Every record carries its own revision, so a new
caption, field value or category description moves exactly the record that shows it — which also
means every page, article and module revision changes once on the upgrade that brings them.

Menu item params are chosen by the **shape of the value**, a heuristic: Joomla keeps a template's own
menu params (Tracy Business `ng_cta_title`, `ng_all_label`…) without a form saying which are text. A
param is offered when its value is a string with a letter in it, contains a space or starts with a
capital, and is not markup, JSON, an address or a path; params named for metadata, classes, icons,
images, links, targets, layouts, order, style or `rel` are never offered. Generated values (dates,
counts, numbering) are not stored anywhere and are never offered as fields.

Each request loads a repeatable-read InnoDB snapshot. The revision hashes the authorized content
projection, its contract hash and referenced local image bytes, rather than hidden source rows or
unrelated ACL labels. Local `images/` files are still scanned before and after the DB snapshot;
only image slots and `<img src>` references in readable markup contribute to the fingerprint.
No transaction spans requests and no snapshot cache needs cleanup. Cursor expiry is 300 seconds
and binds site, principal (including scope), filter, revision and owner. Source cost remains
linear across the mapped site and image directory: timing isolation and constant-cost paging
are not claimed. Remote images, CSS backgrounds and srcset bytes remain unresolved.
The reader caps serialized responses at 262144 bytes (or the caller's `maxBytes`), continuing blocks by signed cursor. A scalar
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


Content API relay protocol note: `contentScope` is server-owned at the Tracy hop;
this reader supports `published` only and returns501 for another scope. `contentPrincipal` binds
the signed cursor to a verified seat context; a service credential is not a seat. List continuation
can send only `cursor`; explicit filter/limit repetitions must match its signed query. No identity
migration, permission widening or contract repair runs from a read request. WordPress and EmDash
transport/identity evidence is tracked separately; this receiver does not certify their support.

Protocol b0a40e0 discovery: an oversized body with mapped blocks returns 413 with
`error.snapshot` and `error.links.firstBlock`. Follow its signed `blocksCursor` with the same
authorization. Each block continuation retains the snapshot and original expiry; changing the
principal, owner or block is rejected. Stop block scanning when `blocksPagination.nextCursor`
is null; the full-content self link is not another block segment. This does not chunk bodyHtml.
