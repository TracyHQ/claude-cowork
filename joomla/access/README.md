# Tracy Access for Joomla

A Joomla system plugin that signs a coworker in to a **fleet clone** as themselves, from the
Cloudflare Access token that already proved they hold a seat. One authentication — the email code
Access asks for — and no second password, no shared admin login.

**This is not the reader.** The [Claude Cowork](../reader) component reads a customer's own site
and never writes to it. This plugin does the opposite: it creates users and opens sessions. It
belongs on a clone and nowhere else, which is why it is a separate package and why provisioning
installs it only on the clone.

```
Cloudflare Access (email code, seat book) ─► tunnel ─► clone origin
                                                          │
                                          plg_system_tracyaccess verifies the
                                          Cf-Access-Jwt-Assertion and logs the
                                          coworker in as their own Joomla user
```

## Why this exists

Before it, a coworker on `<label>.tracy.ai` passed Access with their seat email and then logged
into `/administrator` with the customer's admin password, shared by hand. The seat book governed
the outer door; inside was one password nobody could revoke. Now the seat book is the single
source of truth for both doors: pass Access, and you are logged in to Joomla as you.

## How it decides who you are

- The `Cf-Access-Jwt-Assertion` header is verified on **every** request — RS256 signature against
  the team's JWKS, this clone's `aud`, the team issuer, and expiry.
- The bare `Cf-Access-Authenticated-User-Email` header is **never** trusted on its own. A header
  is only as good as what signed it, and that one is signed by nothing.
- A valid token with **no `email` claim** is stood down, not honoured. That is what a service
  token looks like — Tracy's own reads authenticate that way, and a program must not have a
  Joomla user minted for it.
- Fail-closed throughout: anything unproven becomes the normal Joomla login screen, never a way
  in.

## What group people land in

`Manager` by default — the narrowest core Joomla group that can edit content in the backend. Not
Administrator: a clone is a full copy of the customer's site, password hashes and all, and
Administrator can install extensions and manage users, which is the power to walk off with the
lot. A seat is the right to do the work (ADR 0023), not the keys to the box. `Super Users` is
kept for Tracy's provisioning account and given to nobody through the door. The group is a param
for a site that genuinely needs more.

## Layout

| Path | What |
| --- | --- |
| `lib/AccessJwt.php` | RS256 + JWKS verification, pure PHP, **no library** — tested on its own. |
| `plg_system_tracyaccess/` | The system plugin: read the header, verify, log the coworker in. |

`lib/` is the source of truth and `build.sh` copies it into the plugin — never edited in place,
the same rule the reader follows. RS256 by hand rather than a JWT library because *which* library
Joomla ships changes between generations, and this plugin runs on whatever a clone happens to be.

## Build and test

```bash
./build.sh          # → dist/plg_system_tracyaccess.zip
docker run --rm -v "$PWD":/w -w /w php:8.3-cli php tests/run.php
```

The tests mint real RSA keys and sign real tokens: a plugin that trusts the wrong token lets a
stranger into a copy of the customer's whole site, so the crypto is the one thing never mocked.

## Provisioning writes two params

Set at provision time, per clone:

- `team_domain` — e.g. `tracy-ai.cloudflareaccess.com`, used for the issuer and the JWKS URL.
- `aud` — this clone's own Access application tag. Per clone, so a token minted for one clone
  cannot be replayed against another's origin.
