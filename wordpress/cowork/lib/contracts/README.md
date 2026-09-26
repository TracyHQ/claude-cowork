# Content contract profiles

One directory per profile id, `<design>/wp<major>/<version>`, holding the files that map a quickstart
site's content and record the presentation it shipped with. A profile is a recommendation, not a lock
(Tracy ADR 0022, 26/09/2026): slots are the fast, reversible road for the words a visitor reads, and
everything else on the site still changes the usual WordPress way. Writing the database directly is the
one thing Tracy refuses.

| File | Required | What |
| --- | --- | --- |
| `manifest.json` | yes | The quickstart release this profile describes, and its `id`. |
| `content-map.json` | yes | The entities (options, pages, template parts) and the text slots inside them — the fast, reversible road for a visitor's words. |
| `presentation-lock.json` | yes | The presentation as shipped: theme file hashes, pinned options, template-part hashes, each page's skeleton. A difference is reported as a drift warning, not refused. |
| `demo-trim-map.json` | no | The vendor's demo posts a bound site may hide, pinned to the three files above by `baseHash`. |
| `editions.json` | no | Which Polylang languages the release ships copies for, and the post id of each copy. |
| `superseded.json` | no | Earlier base hashes of this same profile a bound site may still carry (a released profile corrected in place). Accepted only while `presentation-lock.json` is the same bytes; a binding moves to the current hash on its next validated write. Generated in TCH by `scripts/build-wordpress-superseded.mjs`. |

**These files are COPIED from TCH** (`packages/cms/tracy-wordpress-quickstart/contracts/<id>/`)
and must stay byte-identical to their source. Every site bound to a profile stores the hash of its
three base files; a byte changed here makes every one of those sites answer `contract_failed`
("Installed content contract changed") until the bytes are put back. Do not edit a profile in
place: regenerate it in TCH, then copy the whole directory.

`build.sh` copies this tree into the plugin as it stands, so a profile added here ships with the
next release and a profile removed here stops shipping — there is no list to keep in step.
