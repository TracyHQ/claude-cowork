# Tracy Claude Cowork

The extension Tracy installs on a site so an AI assistant can read it — its database, its files
and what it has installed — over one token-authenticated endpoint.

**This extension only reads.** Every action returns data; none of them changes a row, a file or a
setting on the site it is installed on. A token stolen from it cannot alter that site.

Tracy does write, elsewhere: your work happens on a **private copy** of the site, and reaching
the original is a separate, deliberate act that goes through the platform's own API on a
different credential, which you approve. It does not pass through here. That is a design
decision rather than a stage — this door was built one-way on purpose.

The name is for the way of working, not for a vendor. The endpoint is plain HTTP behind a token,
so Claude, ChatGPT, Gemini or something you wrote yourself all use it the same way.

## Platforms

| Folder | Platform | State |
| --- | --- | --- |
| [`joomla/`](./joomla) | Joomla 4 and 5 | In use |
| [`wordpress/`](./wordpress) | WordPress | Reads a site; the Migrate around it is not written yet |
| `shopify/` | Shopify | Not started |

One repository rather than one per platform, and **one engine per platform inside it**. Each
reader owns its own `lib/`: they start as copies of each other and are expected to drift, because
tuning for an old WordPress must not be a change Joomla has to survive. Side by side in one
repository is what keeps the interesting decisions — how work is cut into resumable pieces, how a
cursor survives a request being killed — comparable while they diverge.

## Two packages per platform

A platform folder holds two separately built, separately released packages. They are split because
they are installed in different places, and one of them must never turn up where the other lives:

| Path | What | Installed on |
| --- | --- | --- |
| `<platform>/reader/` | Claude Cowork — reads a site over one token-authenticated endpoint | the customer's own site |
| `<platform>/access/` | Tracy Access — signs a coworker in from the Cloudflare Access identity that already proved their seat | a fleet clone, and nowhere else |

Everything said above about reading is about the reader. Access is the opposite kind of code — it
creates users and opens sessions — so it is built, versioned and released on its own, and a clone
is the only machine that ever gets it.

## What it is for

Tracy keeps a git repository of a site: every article, page and template as files, with a
history you can read, compare and roll back. This extension is how the site gets in there.

Getting anything back out to the running site is a separate act, deliberately: coworkers draft
and review in the repository, and only an administrator can approve what goes live.

## Licence

GPL-2.0-or-later — see [LICENSE.txt](./LICENSE.txt). An extension built against Joomla's own API
is a derivative work of GPL code, so this is the licence it must carry rather than one picked by
preference.

---

*Claude is a trademark of Anthropic PBC. This is built by [Tracy](https://tracy.ai) and is not
affiliated with, endorsed by or sponsored by Anthropic.*
