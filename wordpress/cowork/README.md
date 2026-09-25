# Tracy Claude Cowork — WordPress

A WordPress plugin that lets Tracy work on a site — read its database and its files, and apply an
approved change back to it — over one token-authenticated endpoint.

```
POST /wp-admin/admin-ajax.php?action=claude_cowork
```

## What it can do

Every action, and what happens to it once the site is **sealed** — bound to a content-only
quickstart contract (below). A read answers the same either way. A write is either refused with
`content_only`, or still allowed under the rule in the last column.

| Action | Kind | Unbound site | Sealed site |
| --- | --- | --- | --- |
| `info` | read | ok | ok |
| `site.stats` | read | ok | ok |
| `site.counts` | read (answered by the plugin, not the engine) | ok | ok |
| `db.tables` | read | ok | ok |
| `db.dump` | read | ok | ok |
| `db.cleanup` | write | ok | `content_only` |
| `db.restore` | write | ok | `content_only` |
| `db.purge` | write | ok | `content_only` |
| `files.list` | read | ok | ok |
| `files.pack` | read | ok | ok |
| `file.read` | read | ok | ok |
| `plugin.list` | read | ok | ok |
| `plugin.install` | write | ok | `content_only` |
| `plugin.activate` | write | ok | `content_only` |
| `plugin.selfUpdate` | write | ok | `content_only` |
| `theme.list` | read | ok | ok |
| `theme.install` | write | ok | `content_only` |
| `theme.activate` | write | ok | `content_only` |
| `theme.style` | write | ok | `content_only` |
| `theme.palette` | read with no `colors`, else write | ok | read ok; write `content_only` |
| `core.manifest` | read | ok | ok |
| `language.install` | write | ok | `content_only` |
| `content.list` | read | ok | ok |
| `content.get` | read | ok | ok |
| `content.update` | write | ok | `content_only` — slot values go through `content.contract` `apply` |
| `content.language` | write | ok | `content_only` |
| `content.delete` | write | ok | `content_only` — demo posts are hidden through `content.contract` `demoTrim.apply` |
| `media.upload` | write | ok | allowed only at `wp-content/uploads/tracy-content/<sha256 of the bytes>.(png\|jpg\|webp)`, under an `apply_id` that does not start with `contract-` |
| `apply.revert` | write | ok | allowed only for an `apply_id` holding exactly one `content.contract` receipt, and only the latest one (its `afterRevision` must be the current revision); the site must pass its inspect afterwards |
| `apply.list` | read | ok | ok |
| `content.contract` | read (`inspect`, `demoTrim.plan`, `sourceLanguage.plan`, `siteLanguage.plan`) or write (`bind`, `apply`, `demoTrim.apply\|revert`, `sourceLanguage.set\|revert`, `siteLanguage.set\|revert`, `multilingual.retire\|restore`) | `inspect` and `bind` (with `contract`); the rest need a bound site | every operation |
| `content.read` | read (answered by the plugin, not the engine): the Content API v1 reader, `params: {query, scope: published\|editorial}` | ok | ok |
| `content.identity` | write, opt-in: a content key for the site and a content uid for every page, post, template part, synced pattern, navigation and attachment (post meta only) | ok | ok |

An install is deliberately **not** in the undo log: installing is additive, and WordPress owns the
uninstall.

## The content contract

A site built from a Tracy quickstart is **sealed**: it keeps the design the release shipped, and
the customer's agent changes words — the value of every slot the profile's `content-map.json`
names — and nothing else. The profiles live in [`lib/contracts/`](lib/contracts/README.md), one
directory per `<design>/wp<major>/<version>`, copied byte-for-byte from TCH.

- The seal is one option, `_tracy_content_contract` (JSON, not autoloaded). Which profile a site
  uses comes from the binding, else the `claude_cowork_contract` option, else the `contract`
  parameter of `inspect`/`bind`. A corrupt store, or a bound profile this plugin does not carry,
  refuses every write with `contract_unavailable` — it never reads as an unbound site.
- `content.contract` takes `operation`:
  - `inspect` (default): `{ok, bound, contract, revision, ids, entities[], slots{}, demoTrim, sourceLanguage, multilingual, siteLanguage, problems: []}`.
    A site that does not match answers `contract_failed` with every `problems[]` named: a theme
    file changed or added under `fileRoots`, a pinned option, a template part, an entity
    missing or ambiguous, its status, or its **skeleton** — the sha256 of its content with every
    slot masked as `{{slot}}`.
  - `bind` — inspect, then store the binding. Refused when already bound (`conflict`) or on any
    problem: the baseline is the released lock, never a snapshot of the site as found.
  - `apply` — `expected_revision`, `apply_id` (prefix `contract-`), `request_id`, `changes:
    {slotKey | locale::slotKey: value}`, optional `evidence`. Every value is checked before any
    write: known slot, `maxCharacters`, no `<` `>` or control characters, no `{directive}`
    (identity tokens `{site.*}` `{contact.*}` `{social.*}` are allowed), links limited to
    `https://`, same-host `http://`, `mailto:`, `tel:`, a path or an anchor. One read and one
    write per row. The same `request_id` with the same content replays the stored result; a new
    request under a used `apply_id` is refused. With Polylang, a write of `blogname` or
    `blogdescription` also writes the same value as every language's string translation of it
    (`PLL_MO`, keyed by the value before the write, the profile's `sample` and the new value —
    Polylang serves those entries in front of the option), the undo entry keeps each
    language's `translations` as they were, and `apply.revert` restores them with the option.
  - `demoTrim.plan | apply | revert` (prefix `dtrim-`) — hide the vendor's demo posts listed in
    `demo-trim-map.json`, at most 300 per call; a row the customer already moved is skipped and
    reported; refused while the site has a Polylang language the profile ships no edition for.
  - `sourceLanguage.plan | set | revert` (prefix `srclang-`) — respell the source edition's
    locale (`en_US` → `en_GB`, `en_AU`, `en_CA`, `en_NZ`) in Polylang and `WPLANG`.
  - `siteLanguage.plan | set | revert` (prefix `slang-`) — which edition is the site's DEFAULT
    language. `plan` answers `{ok, current: <Polylang slug of the default>, editions: [slugs],
    onRecord}`. `set` takes `language` (a tag as the questionnaire spells it — `vi`, `de-de` —
    or the Polylang slug, matched like a retire's `keep`: exact, else primary subtag; a tag the
    archive ships no edition of is `bad_params`), `apply_id`, `request_id`, and makes that
    edition Polylang's default: `default_lang` in the `polylang` option, with `hide_default`
    set to `0` for a non-source edition so every edition keeps its `/<slug>/` prefix and the
    links the archive baked into its navigation blocks keep answering (`force_lang` and
    `rewrite` are left as they are; the reply's `rewrite` says what was written), `WPLANG` set to the
    edition's `wpLocale` from `editions.json`, and `page_on_front` / `page_for_posts` moved to
    that edition's copies when Polylang's translation groups name them. Nothing is translated
    and no row moves. Reply `{ok, status: 'completed', language: <slug>, from: <slug>, wplang,
    rewrite}`; the edition already the default answers `alreadySet: true`; a second `set` while
    one is on record under another `apply_id` is `conflict`. The binding records
    `siteLanguage: {language, from, wplangFrom, pageOnFrontFrom, pageForPostsFrom,
    hideDefaultFrom, applyId, requestId, at}` and `inspect` reports `siteLanguage: {language,
    from, applyId} | null`. `revert` puts all five values back as recorded (`WPLANG` absent
    again when it was absent, `hide_default` as it was),
    clears the log and takes the record off: `{ok, status: 'reverted', language: <slug back>}`.
    `apply.revert` refuses an `slang-` id on a sealed site; on an unbound site it undoes the
    same step from the log. No Polylang is `unavailable`; no editions profile is `unsupported`.
  - `multilingual.retire` (prefix `mlang-`) — `keep: [tags as the questionnaire spells them:
    "en-us", "vi", "de-de"]`, `apply_id`, `request_id`. Sets the LIVE edition set: every edition
    `editions.json` ships whose tag (or primary subtag) is not in `keep` is retired — each of its
    published pages, posts and navigation twins (`<menu>-<slug>`, since Polylang gives
    `wp_navigation` no language) goes to `draft`, raw, never deleted — and an edition an earlier
    call under the same `apply_id` retired comes back from its logged `before` when kept again.
    The source edition is always live. At most 300 rows per call: repeat until `status:
    'completed'`. Reply `{ok, status: running|completed, moved, restored, remaining,
    retired: [slugs], live: [slugs], applyId}`. Rows the customer added (no language, or one the
    archive ships no edition of) and rows a demo trim already hid are never touched. The binding
    records `multilingual: {status, applyId, requestId, retired, live, at}` and `inspect` reports
    `multilingual: {retired, live, applyId} | null`. A second `apply_id` while one is on record is
    `conflict`; a tag the archive ships no edition of is `bad_params`; no Polylang is
    `unavailable`. Run `demoTrim.apply` BEFORE a retire: the trim then owns the demo rows of
    every edition, and a restore brings back only what the retire hid.
  - `multilingual.restore` (`apply_id` = the `mlang-` id on record) — puts every row of that
    pass back (newest first), clears its log and takes the record off the binding:
    `{ok, restored, applyId}`. `apply.revert` refuses an `mlang-` id on a sealed site.
  - While a retired set is on record, `lib/MultilingualHooks.php` (loaded on every request,
    engine-free) keeps those languages out of Polylang's switcher — the html list and dropdown
    through `pll_the_languages`, the raw list through `pll_the_languages_args` +
    `pll_the_language_link` (Polylang 3.8.9 returns the raw list before any output filter) —
    and out of `hreflang` through `pll_rel_hreflang_attributes`. `pll_languages_list()` is not
    changed: the language still exists, it is just not live.
- Error codes: `content_only` (a structural write on a sealed site), `contract_unavailable`
  (store or profile unusable), `contract_failed` (the site or the request does not pass),
  `conflict` (already bound / a trim, relabel or retired set already on record), `writer_busy`
  (another writer holds the site's lock).

## Content API (`/content.json`)

`GET <home>/content.json` with `Authorization: Bearer <token>` answers the site's live content in
the `tracy-content/v1` shape (schema and validator live in TCH `packages/cms/tracy-content-api`):
summaries by default, one content in full by `id`, `type`/`locale`/`limit` filters, and signed
cursors that expire (409) as soon as anything the listing read changes. It is read only — it
renders nothing, runs no shortcode and writes nothing — and every answer is `private, no-store`.
The same reader answers the `content.read` action, whose `scope` (`published` by default, or
`editorial` to include drafts, scheduled and private rows) and `principal` (`site-token`, or
`seat:<opaque label>` so a cursor belongs to one seat) are set by the server that holds the token
and relays a seat — never by an agent. A token in the query string is never read. The path is only
taken when no file or post already answers it. `?id=…&blockId=…` reads one block without the page
body, so a page whose HTML alone exceeds the 256 KiB budget stays readable block by block.

Each request reads from one REPEATABLE READ snapshot, released once it is answered; a write that
lands during a request is either wholly in the answer or wholly out of it, and the next page's
cursor answers 409. A site with a persistent object cache answers 501 until that is measured.

Content ids are opaque and survive a new title, slug or order: each row gets a random uid once.
Until `content.identity` has run on a site, the reader answers 501; after it, rows WordPress
inserts get their uid at once. Writes still go through `content.contract` `apply`.

## Layout

| Path | What |
| --- | --- |
| `lib/` | The engine. **No CMS dependency**, unit tested on its own (`tests/run.php`). |
| `claude-cowork/claude-cowork.php` | The endpoint, the settings screen, and nothing else. |

`lib/` is the source of truth for this platform; `build.sh` copies it into the plugin folder.
Never edit the copy — that is how the two silently diverge.

## Why this platform keeps its own engine

It began as a copy of the Joomla one, and it is meant to drift. WordPress spans generations with
their own quirks — what an old PHP on an old WordPress tolerates is not what Joomla 5 needs — and
a shared engine makes every tuning for one platform a change the other has to survive. The two
started identical, and each is free to stop being so without asking the other's permission.

What IS shared is the layer below: the fleet host, the provisioning, DNS, the tunnel and the
door in front of a copy. A server does not care which CMS wrote the files on it.

## Build and test

```bash
./build.sh          # → dist/claude-cowork.zip, installable through Plugins → Add New → Upload
```

Against a real WordPress:

```bash
docker compose up -d                                       # wordpress + mariadb
wp plugin activate claude-cowork                           # which is also what mints the token
TOKEN=$(wp option get claude_cowork_token)
curl -s -X POST 'http://localhost:8899/wp-admin/admin-ajax.php?action=claude_cowork' \
  -H 'content-type: application/json' -d "{\"token\":\"$TOKEN\",\"action\":\"site.stats\"}"
```

Mount `claude-cowork/` straight into `wp-content/plugins/` — the plugin needs no build step of
its own beyond the copied engine, so an edit is live on the next request.

## Updates from inside the site

A site administrator sees a new version on the Extensions screen because the extension declares
where to ask and the answer sits in this repository. Cutting a release is therefore two files, in
one commit:

1. the version in the extension's own manifest, and
2. ``wordpress/update.json`` — the version, and the release asset it points at.

`tests/run.php` fails when they disagree, because the failure is otherwise invisible: the site
asks, gets an older number than it already has, and reports "up to date" forever.

**Joomla and WordPress both record the update address when the extension is INSTALLED.** A site
running a version cut before this existed has no address to ask, and stays silent until somebody
updates it once by hand. That first update is the price of adding this late; every one after it
is a click.

## Why admin-ajax, not the REST API

The REST API is the modern answer and the wrong one here. It arrived in **WordPress 4.7**
(December 2016), a security plugin can switch it off or filter it, and reaching it over a pretty
permalink depends on rewrite rules a broken `.htaccess` takes away.

`admin-ajax.php` has existed since **WordPress 2.8**, is a real file at a real path, and needs
neither permalinks nor a filter's permission. It is the oldest stable routing contract WordPress
has — the same test the Joomla component applied when it chose `index.php?option=` over
`com_ajax`.

One endpoint and not two: a second door is a second thing to keep safe, for a convenience nobody
asked for.

## The token

**The site mints its own on activation** and keeps it in the `claude_cowork_token` option: 24
random bytes as hex, the same shape the Joomla component seeds in its install script. Nothing is
handed a token from outside, so a package that was ever shared carries none.

That is what makes the two ways in agree. An owner whose server cannot reach GitHub installs the
zip in their own browser and copies the token off **Settings → Claude Cowork**; Tracy installs the
same package through the same admin screens and reads the same field. One value, one place it
comes from.

**An upgrade never re-mints it.** Activation runs again on every upgrade, and a new token would cut
off every caller already holding the old one.

An empty or short token means every request is refused, so installing the plugin does not by itself
open anything. Clearing the field is how a site owner revokes access without uninstalling —
deactivating and activating the plugin again mints a new one, because activation is what mints.

It is deliberately not exposed through the settings REST endpoint: this value is the key to the
site's contents, and a setting that can be read remotely is one more place it can leak from.

## The whole path, proven

2026-08-16, on tracy.ai (WordPress 7.0.2): Tracy installed this plugin itself during a Migrate,
exported the site (11,208 files, 175 MB, 56 tables), and the fleet stood a copy up at its own
address in about forty seconds. The Apply side ran against the live site under one `apply_id` — a
post, a post meta, an option, a media file — and one `revert_apply` took all four back, the file
included.

Two things that surprised nobody but are worth writing down. WordPress keeps its own address in
the database (`siteurl` and `home`, plus absolute URLs inside serialized options and post
content), so standing a copy up elsewhere is a different job from Joomla's, where one line of
`configuration.php` covers it — that rewriting is the fleet's, not this plugin's. And a site whose
`uploads/` has been made read-only answers every `media.upload` with `could not write`, while every
content edit succeeds; that is the folder's permissions talking, not this plugin.

## Navigation links

A `core/navigation-link` (or submenu) block that carries a page id but an empty `url` is given the page's permalink at render time (`lib/NavigationLinks.php`). The Tracy Business archive's translated menus were captured that way, so without this every item of a Vietnamese menu pointed nowhere (measured 25/09/2026).
