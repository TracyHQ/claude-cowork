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
| `content.list` with `search` (unreleased) | Finds rows by title instead of paging to them: a substring of the title (or name) and of the alias where the kind has one, for thirteen kinds, ignoring case in both. The answer carries `search` back — the echo is how a caller knows this plugin read the request — and `matched`, the count over all pages. Any other kind is refused, never answered unfiltered. See "Finding a row by its title" below. |
| `content.update`, `content.delete`, `media.upload` | The write catalog (ADR 0080): sixteen kinds behind two generic verbs — `article`, `category`, `tag`, `field`, `fieldValue` (one stored custom field value, see below), `menuItem`, `menutype`, `redirect`, `banner`, `bannerClient`, `contact`, `newsfeed`, `module`, `templateStyle`, `user` (name/email/block only), `extensionParams`. Whitelisted columns only; tree-shaped kinds refuse create and never accept `alias`; delete is Joomla's own trash (`-2`), so it reverts. Plus one file under `images/` or `media/`. |
| `apply.revert`, `apply.list` | Every edit above is recorded under the caller's `apply_id`, so a whole deliverable goes back to exactly what was there. |
| `site.identity` (unreleased) | Global Configuration's site name (`sitename`) and site description (`MetaDesc`) — read, and set under an `apply_id` so `apply.revert` takes it back. Those two keys of `configuration.php` and no other. See "The site name and description" below. |
| `template.siteSettings` (unreleased) | A template's logo, logo for dark backgrounds and small screens, name, slogan and favicon: the eight logo/name/favicon keys of a T4 site profile (`templates/<t>/local/etc/site/<profile>.json`), or the favicon of a template without T4. Read, and set under an `apply_id` so `apply.revert` takes it back. See "A template's logo, name and favicon" below. |
| `extension.install` | One `https` `.zip` URL the site downloads itself and hands to Joomla's own installer. No uninstall and no way to name a local path: a caller holding the token can add to a site, never quietly remove from it. |
| `extension.enable` | Switch one installed extension on or off — the `enabled` column nothing else in the catalog can reach (`extensionParams` writes `params` alone). Refuses a core row and refuses this component. **In** the undo log, unlike install: a switch is perfectly reversible. |
| `db.snapshot`, `db.rollback` | Copy the tables aside before something rewrites the schema, and rename them back if it does not land. Two renames per table, so a rollback is metadata and finishes in one request; what it displaces goes to the trash, not a `DROP`. |
| `core.upgrade`, `files.restore` | The migration hop (prepare/finalise toward a supported Joomla) and restoring a webroot from a signed URL — operational, outside the undo log like install. |

An install is deliberately **not** in the undo log: installing is additive, and Joomla owns the
uninstall. `extension.list` reports what is already there, so a caller can tell "already
installed" from "installed in another version" without guessing.

There is no shell, no arbitrary file write, and no action that is not named and bounded above.
(`template.siteSettings` writes JSON files, but only one shape of path and only eight named keys.)

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
./build.sh                                    # → dist/pkg_claudecowork.zip + dist/tracy-release.json (needs node)
node --test ../../scripts/release-manifest.test.mjs
tests/e2e/updater.sh                          # the self-updater on a real Joomla (docker), see below
docker run --rm -v "$PWD/../..":/w -w /w/joomla/cowork php:8.3-cli php tests/run.php
docker run --rm -e COWORK_TEST_READS=paged -v "$PWD/../..":/w -w /w/joomla/cowork php:8.3-cli php tests/run.php
```

Mount the repository root, not this folder: the release checks read `joomla/update.xml` and
`joomla/update.json` one level up, so a mount of `joomla/cowork` alone stops at them. The second run
is the same suite with the test writers reading through the list-and-read walk instead of in bulk;
the two must agree test for test (CI runs the first; run the second when you change how rows are read).

The suite must pass on a PHP with `intl` and on one without it. The official `php` images carry no
`intl`, so there the tests stand in for `Normalizer`; a PHP that has it (Homebrew, distro packages,
and the PHP the CI job installs: `setup-php` loads `intl` by default) runs the real one. To run the
suite that way in docker, add it to the image first: `apt-get update && apt-get install -y libicu-dev`
and `docker-php-ext-install intl`. The tests that need no `Normalizer` start a fresh PHP with
`disable_classes=Normalizer`, which on a PHP with `intl` leaves the class declared and empties its
methods rather than removing it; the code treats that as no `Normalizer` (see `tests/content-search.php`).

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
is taken without one.

**The site takes a release by itself, within a quarter hour of the next visit.**
`plg_system_claudecoworkupdate` reads `joomla/update.xml` after a response has gone to the browser,
at most once every 15 minutes, and installs the package (and `tpl_tracy`) when a newer version is
announced. Its `autoupdate` switch turns that off. What it did is written to
`administrator/logs/plg_system_claudecoworkupdate.php` (since 0.21.0; before it `Log::add` had no
logger registered for its category, so every line went nowhere).

## What a release puts on a site, and who put it there (since 0.21.0)

A site Tracy keeps in git sees an update of this package as a few hundred changed files nobody
claims: the updater writes them after the response, and nothing used to say so. Two records answer
the two questions separately.

### The release manifest: "these are the bytes of release X"

`build.sh` writes `tracy-release.json` (`scripts/release-manifest.mjs`): every file the package puts
on a site, by the path it lands at under the site root, with its sha256.

```json
{
  "schema": 1,
  "product": "joomla-package",
  "element": "pkg_claudecowork",
  "version": "0.21.0",
  "tag": "joomla-v0.21.0",
  "generatedAt": "2026-10-02T17:26:37.671Z",
  "path": "administrator/components/com_claudecowork/tracy-release.json",
  "roots": ["administrator/components/com_claudecowork/", "components/com_claudecowork/",
            "plugins/system/claudecoworkapi/", "plugins/system/claudecoworkupdate/", "plugins/system/tracyaccess/"],
  "files": { "administrator/components/com_claudecowork/lib/Engine.php": "<sha256>", "…": "…" }
}
```

- **Where it is.** Inside the package, installed at `path` (the component lists it in its `<files>`),
  and in `dist/tracy-release.json`, which is **attached to the GitHub release as an asset of its own**:
  `gh release create joomla-v<x> dist/pkg_claudecowork-<x>.zip dist/tracy-release.json`. The copy on a
  site names a tag; the asset under that tag is the copy nobody on the site can change, so a file is
  "Tracy's" only when its bytes hash to what the asset says. The template's manifest is
  `templates/tpl_tracy/tracy-release.json`, attached by `scripts/release-joomla-template.mjs`.
- **What it lists.** Read off the extension manifests the installer follows — `<files>`,
  `<administration>`, `<languages>`, `<media>`, `<scriptfile>`, the package's own manifest and
  script — so a file Joomla would not install is not listed and one it would install is never
  forgotten. It never lists itself. `roots` are the folders the release owns whole (shared folders
  such as `administrator/language/` are not roots, though files in them are listed).
- **Proven on a real install** by `tests/e2e/updater.sh` on Joomla 5.4.8 and 6.1.3 (02/10/2026): after
  each install every entry exists with the listed hash, and nothing under `roots` is unlisted —
  160 entries for a build with extra files, 156 for this package, 827 for `tpl_tracy`.

### What an upgrade leaves behind — measured, and why the manifest lists no deletions

Installed over a build that shipped four more files, on Joomla 5.4.8 and 6.1.3 alike:

| File the old build shipped and the new one does not | After the upgrade |
| --- | --- |
| `lib/ObsoleteProbe.php` (inside `<folder>lib</folder>`) | **still there** |
| `lib/contracts/obsolete-probe/manifest.json` (nested in that folder) | **still there** |
| `plugins/system/claudecoworkapi/src/Extension/ObsoleteProbe.php` (inside a plugin's `<folder>src</folder>`) | **still there** |
| `administrator/components/com_claudecowork/obsolete-probe.php` (its own `<filename>` line, dropped) | **deleted** |

Joomla removes what the old manifest named **line by line** and the new one does not; it never
removes a file from inside a folder both name. So a file that leaves `lib/` stays on every site that
ever had it (why `build.sh` clears its engine copy: the same thing, one step earlier). The manifest
therefore lists what a release installs and nothing about deletions: which files disappear depends
on the version a site came from, and the previous release's manifest — the copy on disk before the
update — already says what that was. A file under `roots` that the current manifest does not list is
a leftover of an earlier release, or somebody else's.

### The update receipt: "and this is who installed it"

The package now has an installer script of its own (`script.php`). Its `postflight` — which every path
that installs the package runs through — adds one row to `#__claudecowork_update_log`:

| Column | |
| --- | --- |
| `id` | `INT UNSIGNED AUTO_INCREMENT` |
| `at` | `DATETIME`, UTC |
| `element` | `pkg_claudecowork` |
| `from_version` | the version installed before (read in `preflight`); `NULL` on a first install |
| `to_version`, `tag` | the version installed, and its release tag `joomla-v<to_version>` |
| `manifest_sha256` | sha256 of the `tracy-release.json` the install just put on disk; `NULL` if absent |
| `trigger` | `auto-updater`, `door`, `admin` or `unknown` — **a reserved word in MySQL/MariaDB: quote it** |
| `user_id` | the signed-in user for `admin`, else `0` |
| `apply_id` | the `apply_id` the door's caller sent with `extension.install`, else `NULL` |

`trigger` comes from evidence the request carries, never from the time:

1. **An install context** a Tracy caller sets around its own `Installer::install()` — the self-updater
   says `auto-updater`, the door's `extension.install` says `door` with its `apply_id`
   (`$GLOBALS['claudecowork_install_context']`, restored in a `finally`).
2. **The call stack**, when no context was set: the self-updater's class, or the engine's
   `extensionInstall`. This is what names the updater already on a site — 0.20.x predates the context,
   and it is the one that installs the first release with a receipt.
3. **A signed-in user**: `admin` (Extensions → Update or Install in the administrator).
4. Otherwise **`unknown`**: the CLI, provisioning, anything else.

The end-to-end run records `auto-updater` (released 0.20.3 → a build of this tree, from the call
stack), `unknown` (CLI) and `auto-updater` (from the context), in that order. A receipt proves the
path, not the bytes: anyone with the database can write a row, so it counts only where its tag and
manifest hash match the manifest asset of that tag. `tpl_tracy` writes no receipt — it is a mirror
of Tracy's generator and carries no installer script; its files are proven by its manifest alone.

### Testing the self-updater end to end, without loosening it

`tests/e2e/updater.sh` (docker, node, curl) builds three versions of this tree, starts a throwaway
Joomla, MariaDB and an nginx that answers for `raw.githubusercontent.com` and `github.com` on the
test network (Docker network aliases, a TLS certificate from a CA made for the run and trusted inside
the Joomla container), announces a build there, and requests a page. The updater runs as shipped:
its manifest URLs and the "only a release asset of TracyHQ/claude-cowork" rule have no switch, not
even behind `JDEBUG`, because a switch that widens where a site downloads code from is one somebody
eventually leaves on. `JOOMLA_IMAGE=joomla:5-apache` runs it on Joomla 5; `KEEP=1` leaves it up.

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
| `data-tracy-src="category:<id>:<alias>[ block:<module type>]"` | each `<a>` linking to a category page, in module output (not menu modules) and in the component output (unreleased) |
| `data-tracy-src="tag:<id>:<alias>[ block:<module type>]"` | each `<a>` linking to a tag page, same places (unreleased) |

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

**Category and tag links (unreleased).** A category or tag name printed as a link — a breadcrumb, a
blog's filter chips, the category line of a news card, a sidebar category list — is stamped on the
`<a>` itself, since the item around it usually belongs to something else (the article a card shows).
The link is tied to a record the same way article links are (`TaxonomyLinks`: candidates by id or
alias, confirmed by building the route Joomla's category/tag view builds). A category or tag that a
published menu item opens directly is skipped: at that address Joomla prints the menu item's title,
not the category's. Menu modules get no such stamps for the same reason.

## Derived contracts for imported sites (unreleased)

A site imported into Tracy was never a quickstart: there is no profile to hold it to. `content.contract`
`operation: derive` (`label` `[a-z0-9-]{3,80}`, `requestId`) binds it to a **derived** contract instead —
a content map computed from the site's own rows, in the same `content-map` schema, so `inspect`,
`apply`, `apply.revert` and `content.read` work on it unchanged. In Tracy only the provisioner calls
`derive` (at import); the relay's Apply door refuses the operation.

- **Refused** on a site bound to a quickstart, on a site under construction (`tracy_build_baseline`),
  and on an unbound site whose params name a quickstart `contract` (its own bind is still to come). A
  quickstart `bind` on a derived site is refused the same way. The same `requestId` again answers what
  it bound (`replayed: true`); a new one derives again.
- **What is read** (`JoomlaDerivedRows`, public rows only): articles (published, inside their publish
  window: `title`; `introtext`/`fulltext` as HTML; `attribs`/`images`/`urls` nested), site menu items
  (`title`, `params`), site modules (`title`; `content` of `mod_custom`; `params`), `com_content`
  categories (`title`, `description`, `params`), single-row article custom field values of type
  text/textarea/editor/media, and the site template styles that are a home or that a menu item names.
  A value over 2 MB is listed in `unresolved`, not scanned. Extension tables are not read in v1.
- **Memory.** Articles are read 200 at a time (`JoomlaDerivedRows::batches()`) and built into the map
  batch by batch (`DerivedMap::buildBatches`), so no request holds every row; a custom field value
  with no word in it (a number, a hex id, one character) stays in the database. A derive and a map
  build raise `memory_limit` to 256 MB when it is lower (never lowered, never from `-1`).
- **Kept between requests** (`lib/DerivedCache.php`): the built map, gzip-compressed in its own table
  `#__claudecowork_derived_cache` (created by `script.php` on install and update; never a row of the
  contract table, which the content reader snapshots whole), at most 3 MB, else not kept. A map built
  inside a read's snapshot transaction is stored after it commits, so a read never writes; one object
  per request serves the contract and the reader. Keyed by the component version,
  the binding's label, algorithm and `keep`, and a fingerprint of the tables computed in one query (a
  count and a CRC32 checksum of every column read from articles in their publish window, site menu
  items, modules, categories, custom field values and template styles). Any write to those rows
  changes it; the purge after a derived apply also drops the kept map. A fingerprint that cannot be
  read builds the map uncached; a map whose tables moved while it was built is not stored.
- **Leaf slots.** `LeafCodec` finds the words, pictures and links inside a value through five codecs
  (text, HTML, shortcode, JSON, PHP serialize, nested in any order). A slot names its leaf by a path
  (`leaf`); an apply rewrites only that leaf and re-encodes every layer in its own format. A value that
  would not read back is refused as `SLOT_UNWRITABLE`, never stored. Slot keys are
  `<kind>-<id>.<column>.<sha1(leaf) 10>`; the contract hash names the algorithm, not the rows, so an apply
  never changes it.
- **Calibration.** At derive the home page and up to 39 published menu pages are fetched over loopback
  (plain http to 127.0.0.1 with the site's Host, then https resolved to 127.0.0.1; curl only, no proxy,
  no redirect followed, 60 s in all). A nested leaf (a param, a setting) is kept only when a page shows
  its words or its address; the kept keys are stored in the binding (`keep`) so later reads do not
  fetch again. No page loaded: `calibrated: false`, every nested leaf kept. The answer counts
  `byClass {db, nested, unmatched}` — `unmatched` is visible text blocks no leaf holds (theme files,
  language strings), counted, not located.
- **Pictures and links** on a derived slot take the forms an imported site stores: a file under
  `images/` with or without a leading slash or Joomla's `#joomlaImage://` suffix (written back in the
  slot's own form, with the new file's size), and links `https://…`, `/path`, `index.php?…`, `mailto:`,
  `tel:`, `#anchor` (never `//…`, a backslash or a script). Quickstart profiles keep their own rules.
- **After an apply** (committed, outside the transaction) T4's `media/t4/optimize` is emptied beside the
  writer's usual cache purge, and up to three pages that own the written text (an article's own page, a
  category's list, a menu item's page, a page a module is assigned to, else the home page) are fetched
  again, 15 s in all. Words a fetched page does not show add a warning
  `{code: WRITTEN_NOT_VISIBLE, message, severity: warning, slotKey, url}`; the apply stays `ok` and its
  `apply_id` takes it back. A page that did not load says nothing. Uncalibrated, only a row's own text
  is checked.

**`fieldValue`**, the one new writer kind, is open on every site through `content.update` and
`content.batch`, not only derived ones: one `#__fields_values` row named by `field_id × 10^9 + item_id`
(`FieldValueKey`); only `value` is written; a pair stored in more than one row (a multiple-value
field) reads as none and is refused; no create and no delete; recorded under the `apply_id` like every
other write, so it reverts.

## The site name and description: `site.identity` (unreleased)

A site made from a template keeps the template's words in Global Configuration: `sitename` (the site
name: a page's `<title>` when it has none of its own, added to every `<title>` when the site says so,
and named in the mail Joomla writes; the sender name is `fromname`, not this) and `MetaDesc` (what a page
with no description of its own prints as its meta description). No other door reached
`configuration.php`, so after every page was written in the owner's words the home page could still
say "JA Vega - Modern Joomla Template…" (TCH ledger L24, 05/10/2026).

```
site.identity {}                                   → {ok, fields: {sitename, MetaDesc}, writable}
site.identity {operation: "set", apply_id, fields: {sitename?, MetaDesc?}}
                                                   → {ok, fields: {sitename, MetaDesc}, changed: [...]}
```

- **Two keys, both ways.** A read answers exactly those two (`fields` on a read may name only them);
  a set takes one or both. Any other key is refused with `unsupported`, never ignored: the same file
  holds the database password and the site secret. Nothing else of the file is ever returned.
- **Values.** Strings only, cleaned as Joomla's own Global Configuration form cleans them
  (`filter="string"`: entities decoded, tags removed), and made one line. `MetaDesc` holds Joomla's
  300 characters and may be empty; `sitename` holds 200 and may not (Joomla requires one). Anything
  else is `bad_params`. The answer carries the values as stored.
- **Undo.** What changed is recorded under the `apply_id` with its previous value, so `apply.revert`
  puts the site's own words back — together with the content writes of the same `apply_id`, if any. A
  value already in place is not written; when nothing changes the answer is `unchanged: true` and no
  undo step is recorded, so a retry after a lost reply is harmless. A set takes the site's write lock;
  a read does not. It is not part of `content.batch`: a file is outside the database transaction.
- **How it writes.** As Joomla's own save does (`ApplicationModel::writeConfigFile`, Joomla 4–6): the
  configuration Joomla loaded (`new JConfig()`) with the two values merged in, formatted by Joomla's
  `Registry` as the `JConfig` class, checked to parse as PHP, written in place, and the opcode cache
  for the file dropped. Joomla's installer leaves the file `0444`, owned by the web server (measured on
  5.4.9), so a file the web server owns is made writable for the write, as Joomla does — and then put
  back to its own mode, which Joomla does not. A file the web server can neither write nor chmod is
  refused with `unsupported` and `code: CONFIG_NOT_WRITABLE`; `writable: false` on a read says so in
  advance. A plugin older than this answers `{error: "bad_action", message: "unknown action: site.identity"}`.
- **Proven on a real install** (Joomla 5.4.9, this build, 05/10/2026): a set changed exactly the two
  lines of `configuration.php`, left it `0444 www-data`, and the home page's meta description and
  logo text showed the new words on the next request; `apply.revert` left the file byte-identical to
  before; with the file owned by root the set was refused and the file unchanged. Joomla 6.1.3 writes
  its configuration the same way (same `JConfig`, same `Registry` PHP format).

## A template's logo, name and favicon: `template.siteSettings` (unreleased)

A T4 template keeps its logo, its name and slogan and its favicon in a FILE, not in the database:
`etc/site/<profile>.json`, which T4 reads local-first (`templates/<t>/local/` → `templates/<t>/` →
the base theme in `plugins/system/t4/themes/<base>/`), each template style naming its profile in
`typelist-site`. A file in `local/` replaces the template's whole, keys are not merged. No door
reached those files, so a site made from a template kept the template's logo (TCH #1013, T18). A T3
template, or any other, has no favicon setting at all: Joomla prints `templates/<t>/favicon.ico`.

```
template.siteSettings {template}
  T4    → {ok, template, framework: "t4", keys, profiles: {<name>: {source, settings}}, missing}
  other → {ok, template, framework: "t3"|"joomla", keys: ["other_faviconFile"], settings: {other_faviconFile}}
template.siteSettings {operation: "set", apply_id, template, fields?: {...}, profiles?: {<name>: {...}}}
        → {ok, template, framework, keys, changed: [<paths>], cleared, profiles | settings, skipped?}
```

- **Eight keys, by name.** On T4: `site_logo`, `site_logo_small`, `site_logo_dark`,
  `site_logo_dark_small`, `site_logo_2`, `site_name`, `site_slogan`, `other_faviconFile`. On any
  other template: `other_faviconFile` only (its logo is a template style param, written with
  `content.update` kind `templateStyle`). Any other key is refused with `unsupported`, never
  ignored, and nothing is written.
- **Which profiles.** `fields` go into every profile a site style of the template uses (`default`
  always among them); `profiles: {name: {...}}` into that one, its values over `fields` (JA Spa:
  style 13 uses `logo-light`, which wants the light logo as its `site_logo`). A profile a style
  names but no file holds is `skipped` (T4 shows `default` for it); one named in `profiles` that no
  file holds is `bad_params`.
- **Every other byte kept.** Each profile is copied from where T4 reads it — the local copy when
  there is one (JA Nova and JA Voyara ship one), else the template's, else the base theme's — and
  only the given keys change, in place, in the file's own JSON style; a missing key is added at the
  end. Written to `templates/<t>/local/etc/site/<profile>.json`, the file T4's own editor saves.
  The answer reads the profiles back as now written.
- **Values.** A picture is a path relative to the site root under `images/`, `media/` or a
  template's `images/`, with a picture's extension (png, jpg, webp, gif, svg, avif, ico), naming a
  file that is on the site now (`media.upload` first). Upload each new picture under a new name
  (its hash in the name): static files are cached up to four hours, and a new name is never stale.
  Empty clears a picture. A name or slogan is cleaned as `site.identity` cleans words, up to 200
  characters; empty lets T4 show the global site name.
- **Undo.** One step per set, recorded under the `apply_id`: each file's previous bytes, or that it
  did not exist and which folders were made for it. `apply.revert` puts the bytes back, or deletes
  the file and the `local/` folders it made — a template that had no `local/` has none again. A
  set that changes nothing writes and records nothing (`unchanged: true`). An undo row naming any
  other path is refused. A set takes the site's write lock; a read does not.
- **Caches.** After a set and after its revert, T4's optimize cache (`media/t4/optimize/`) is
  emptied — a dark-mode rule there may carry a logo as `content:url(…)` — and Joomla's cache groups
  are purged. T4 reads the profile on every request, and Joomla's page cache is off on Tracy's
  quickstarts (measured 07/10/2026), so the next request shows the new logo.
- **The contract.** `templates/<t>/local/etc/site/*.json` are the customer's settings, not the
  design: `content.contract inspect` neither reports them as `Unexpected presentation file` nor,
  where a lock lists one, as `Presentation asset changed`. Anything else under `local/` still is.
- **A favicon without T4.** The setting goes to `templates/<t>/local/etc/site/tracy-favicon.json`;
  the system plugin (`onBeforeCompileHead`) removes the page's favicon links and prints that file.
  A template without the file costs one `is_file` per page.
- A plugin older than this answers `{error: "bad_action", message: "unknown action: template.siteSettings"}`.

## Finding a row by its title: `content.list` `search` (unreleased)

A caller that knows a page by its title used to page through `content.list` until it met it — on a
site of ~1,900 articles, two to eight extra calls, and up to eighteen in one measured run. `search`
asks for the row instead:

```
content.list {kind: "article", search: "roof repair"}
→ {ok, kind, offset: 0, search: "roof repair", matched: 3, items: [...]}
```

- **Match.** A substring of the title (the name, for the kinds that have one) and of the alias where
  the kind has one: the language editions of one article share an alias stem while their titles are
  translated, so a title-or-alias search reaches the editions that share the searched stem
  (Tracy's quickstarts name them that way; a site that names its editions differently may not). Notes, bodies and intro text are not
  searched. There are no wildcards and no patterns: `%` and `_` are ordinary characters (the query
  names its own `ESCAPE` character, so it means the same under any `sql_mode`), and `50%_off` finds
  "50%_off sale", not "500 off sale". The words are bound parameters, never part of the SQL text.
- **Case is ignored in the title and in the alias; accents are not treated alike.** A `LIKE` follows
  the column's collation, and Joomla's own schema (5.4 and 6.1, `installation/sql/mysql`) gives the
  two different ones. A title or name is declared with none, so it takes its table's,
  `utf8mb4_unicode_ci`, which ignores case and accents: `ROOF` finds "Roof repair", and `cafe` finds
  "Café". The `alias` of `article`, `category`, `tag`, `menuItem`, `banner`, `contact` and `newsfeed`
  is declared `utf8mb4_bin`: binary, so a plain `LIKE` on it would compare case. Joomla writes an
  alias lower case (`OutputFilter::stringURLSafe` lower-cases what it returns), so the
  plugin compares the alias lower-cased — `LOWER(alias)` — with the needle lower-cased: `Roof-Repair`
  finds the alias `roof-repair`. That is what lets one call reach the language editions of a page that
  share an alias stem, whatever case the caller typed, and a row that stores an upper-case alias anyway (an import, a
  hand edit) is found too. What stays exact in an alias is the rest of the binary comparison:
  accents (`e` is not `é`), and the composed form against the decomposed one, both of which are
  tried. A space is a space, in the title and in the alias: "roof repair" does not match the alias
  `roof-repair`, so ask for the stem when the caller wants the language editions. A row can come
  back through its alias alone: `roof-repair` finds an article titled "Fix your roof" whose alias is
  `roof-repair-guide`, which is what an alias search is for and not a wrong hit.
- **The needle.** Trimmed; a tab or a line break (including NEL and the Unicode line and paragraph
  separators, which arrive when text is pasted) becomes a space and any other control character is
  dropped. One character is enough (Chinese, Japanese). At most 200 characters once cleaned (and
  4,096 bytes before, which a real needle never comes near); that, a value that is not a string
  (`null` included) and one that is not UTF-8 are refused with `bad_params`. It is made NFC when PHP
  has `Normalizer` (intl, or Joomla's polyfill) and matched as both its NFC and its NFD form, since a
  title may have been stored either way; without `Normalizer` it is matched as typed. Blank after
  cleaning means no filter on a kind that takes `search`: the plain list, answered with `search: ""`
  and no `matched`; a kind that refuses `search` refuses it whatever the value.
- **Four-byte characters.** A character above U+FFFF (an emoji, a rare Chinese character) cannot be
  stored in a table still in `utf8` (utf8mb3), and such a site's database refuses to compare its
  columns with one ("Illegal mix of collations", or "Incorrect string value"). A needle holding one
  therefore **matches nothing** there: the answer is `matched: 0` and no rows, with the echo like
  any other search, not an error. Only that is read this way: the two refusals above, for a needle
  in which every form holds such a character. The same refusal for a needle without one, and any
  other database error, is `read_failed`. (The refusal is matched by the server's English text; a
  server set to another `lc_messages` language answers `read_failed`, the safe side.)
- **The answer.** `search` is in a successful answer **if and only if** the request carried the key (a
  refusal, like any error, carries none). It is the
  proof that this plugin read it: a plugin from before this change ignores the key and answers the
  whole list as `ok`, so a caller that sends `search` must look for the echo and read its absence as
  "not filtered". Its value is the needle **as cleaned** — trimmed, control characters dropped, a
  line break made a space, composed (NFC) where PHP can — not the bytes that were sent, and `""` for a
  blank one; so a caller tests that the key is there, not that its value equals what it sent.
  `matched` (non-empty needle only) is the exact count over all pages, and costs no second query
  when the first page does not fill. The order stays by id and `offset` and `limit` apply to the
  narrowed set. A trashed row is listed like any other: hiding it would make "this is the only match"
  unsafe to say. A request without `search` is answered exactly as it always was.
- **Kinds.** Filtered by title and alias: `article`, `category`, `tag`, `menuItem`. By title:
  `module`, `templateStyle`, `language`, `menutype`. By name and alias: `banner`, `contact`,
  `newsfeed`. By name: `bannerClient` (that table has no alias). By title and name: `field`. Every
  other kind — `user`, `redirect`, `extensionParams`, `languageFilter`, the three association kinds,
  `fieldValue` — is not searched: it is a person, a URL pair, an extension's settings or a relation
  between two rows, not a page a caller finds by its title. `search` on it is refused with
  `bad_params` naming the kind and the ones that work. Never quietly ignored.
- **Not in this change.** Searching bodies or notes, a state or language filter, and an unfiltered
  `total`. What the collation decides is decided by the site's tables, not by this code: the tests
  hold the pattern, the SQL text and the alias comparison (SQLite in its case-sensitive `LIKE` mode
  stands in for the binary column), and none of them follows a MySQL collation. A live site holds the
  rest: a mixed-case title, an accented one, a decomposed one, and what a database's `LOWER()` does to
  a non-ASCII capital in a binary alias. A site whose tables were altered from Joomla's install SQL
  may differ.

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

### Many details in one read, and reads that overlap (unreleased)

Every read builds the site's whole projection (all mapped tables, the contract, the router), so
its cost barely depends on how much it answers. `ids` asks for up to 100 details in one call — a
list in the door's `params`, one comma-separated value over GET — and may carry `maxBytes`,
nothing else. Each content that fits is answered exactly as `{id}` answers it; the rest are named
in `pagination.pending` (did not fit, or needs block segments: ask again, or read it alone) and
`pagination.missing` (no such content now). Nothing is cut and nothing pages.

A read writes nothing while it holds its database snapshot, so any number of reads may overlap.
Page identities (no trigger, see `ContentIdentity`) are levelled before the snapshot, and only
when a menu item appeared or went since the last read.

### Records and fields beside the contract slots (unreleased)

A page prints words that no contract slot holds. `content.read` carries them too, each group as one
block keyed by the Joomla row's **native id** — the id a render stamp names (`article:548` resolves
to a block whose key starts `article-548`). None of these fields has a `slotKey`, so
`content.contract` never writes them; the column says how each one IS written.

| Record | Block key | Field keys | Source | Written through |
| --- | --- | --- | --- | --- |
| article | `article-<id>.images` | `article-<id>.image_intro`, `.image_intro_caption`, `.image_fulltext`, `.image_fulltext_caption` | the `images` column; Joomla's `#joomlaImage://…` suffix removed; alt text on the picture in `images[]` | `content.update` article (`images`) |
| article | `article-<id>.fields` | `article-<id>.field.<name>` | published, public custom field values of type text, textarea, editor (html) and media (image), in the article's language | `content.update` fieldValue (`value`), see "Derived contracts" |
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
