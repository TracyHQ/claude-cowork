# Tracy Claude Cowork — WordPress

A WordPress plugin that lets Tracy read a site — its database and its files — over one
token-authenticated endpoint. It only reads: nothing here writes to the site it is installed on.

```
POST /wp-admin/admin-ajax.php?action=claude_cowork
```

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
wp plugin activate claude-cowork
wp option update claude_cowork_token "$(openssl rand -hex 24)"
curl -s -X POST 'http://localhost:8899/wp-admin/admin-ajax.php?action=claude_cowork' \
  -H 'content-type: application/json' -d '{"token":"…","action":"site.stats"}'
```

Mount `claude-cowork/` straight into `wp-content/plugins/` — the plugin needs no build step of
its own beyond the copied engine, so an edit is live on the next request.

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

Set at **Settings → Claude Cowork**, stored in the `claude_cowork_token` option. An empty or
short token means every request is refused, so installing the plugin does not by itself open
anything. Clearing the field is how a site owner revokes access without uninstalling.

It is deliberately not exposed through the settings REST endpoint: this value is the key to the
site's contents, and a setting that can be read remotely is one more place it can leak from.

## What is not here yet

Reading works. The rest of a Migrate — installing this plugin from Tracy, and the WordPress
stack on the fleet — is not written yet. WordPress keeps its own address in the database
(`siteurl` and `home`, plus absolute URLs inside serialized options and post content), so
standing a copy up at another address is a different job from Joomla's, where one line of
`configuration.php` covers it.
