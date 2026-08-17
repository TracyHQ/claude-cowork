# Tracy Claude Cowork — Joomla

A Joomla component that lets Tracy read a site — its database and its files — over one
token-authenticated endpoint, and install an extension onto it.

```
index.php?option=com_claudecowork&task=api.exec&format=json
```

Installing it generates a token and stores it in the component's Options (`script.php`). Copy
that string into whatever is pairing with the site — installing by hand is therefore a complete
way to connect a site, with no admin session handed to anyone. Clearing the field revokes access:
an empty token refuses every request.

Everything is read-only except `extension.install`, which takes one `https` `.zip` URL the site
downloads itself and hands to Joomla's own installer. There is no uninstall, no file write and
no way to name a local path: a caller holding the token can add to a site, never quietly remove
from it. `extension.list` reports what is already there, so a caller can tell "already
installed" from "installed in another version" without guessing.

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
2. ``joomla/reader/update.xml`` — the version, and the release asset it points at.

`tests/run.php` fails when they disagree, because the failure is otherwise invisible: the site
asks, gets an older number than it already has, and reports "up to date" forever.

**Joomla and WordPress both record the update address when the extension is INSTALLED.** A site
running a version cut before this existed has no address to ask, and stays silent until somebody
updates it once by hand. That first update is the price of adding this late; every one after it
is a click.

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
