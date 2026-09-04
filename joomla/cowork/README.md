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
