# Tracy Claude Cowork

The extension Tracy installs on a site so an AI assistant can work on it — read its database, its
files and what it has installed, and apply an approved change back to it — over one
token-authenticated endpoint.

**Every action is named and bounded.** There is no shell, no arbitrary file write, and nothing the
extension does not implement by hand. Most of the surface reads. The write side installs a plugin,
theme or extension, edits a post or an option, and puts a file into the media library — nothing
else.

**Every change can be taken back.** Each edit is recorded under the caller's `apply_id`, so one
call puts a whole deliverable back to what was there before. Installs are the exception and say so:
installing is additive, and the CMS owns the uninstall.

**Going live is still a decision.** Work is drafted and reviewed in a git repository your team owns
before anything reaches the running site, and clearing the token revokes all of it at once.

The name is for the way of working, not for a vendor. The endpoint is plain HTTP behind a token,
so Claude, ChatGPT, Gemini or something you wrote yourself all use it the same way.

## Platforms

| Folder | Platform | State |
| --- | --- | --- |
| [`joomla/`](./joomla) | Joomla 4 and 5 | In use |
| [`wordpress/`](./wordpress) | WordPress | In use |
| `shopify/` | Shopify | Not started |

One repository rather than one per platform, and **one engine per platform inside it**. Each
platform owns its own `lib/`: they start as copies of each other and are expected to drift, because
tuning for an old WordPress must not be a change Joomla has to survive. Side by side in one
repository is what keeps the interesting decisions — how work is cut into resumable pieces, how a
cursor survives a request being killed — comparable while they diverge.

## Two packages per platform

A platform folder holds two separately built, separately released packages. They are split because
they are installed in different places, and one of them must never turn up where the other lives:

| Path | What | Installed on |
| --- | --- | --- |
| `<platform>/cowork/` | Claude Cowork — reads a site and applies approved changes to it, over one token-authenticated endpoint | the customer's own site |
| `<platform>/access/` | Tracy Access — signs a coworker in from the Cloudflare Access identity that already proved their seat | a fleet clone, and nowhere else |

Everything said above is about the cowork extension. Access is a different kind of code — it
creates users and opens sessions, on a copy — so it is built, versioned and released on its own,
and a clone is the only machine that ever gets it.

## What it is for

Tracy keeps a git repository of a site: every article, page and template as files, with a
history you can read, compare and roll back. This extension is how the site gets in there, and
how an approved change gets back out.

Both directions run through it, and they are not the same act. Bringing the site in happens on a
schedule and needs nobody's permission. Putting a change back is one deliberate call per approved
deliverable, recorded so it can be undone — coworkers draft and review in the repository first,
and only an administrator decides what goes live.

## Licence

GPL-2.0-or-later — see [LICENSE.txt](./LICENSE.txt). An extension built against Joomla's own API
is a derivative work of GPL code, so this is the licence it must carry rather than one picked by
preference.

---

*Claude is a trademark of Anthropic PBC. This is built by [Tracy](https://tracy.ai) and is not
affiliated with, endorsed by or sponsored by Anthropic.*
