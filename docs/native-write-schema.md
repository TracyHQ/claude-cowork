# Native write discovery

`content.list` and `content.get` may return `writeSchema` alongside their normal data.
It describes the installed writer, not the Content API's slots and not a seat grant.
There is no extra discovery request and no change to write authorization or validation.

| Field | Meaning |
| --- | --- |
| `schemaVersion` | `tracy-native-write/v1` |
| `kind` | The native record kind, equal to the response's `kind` |
| `updateFields` | Field names accepted by `content.update` for an existing record |
| `createFields` | Fields for creating a record; `null` means creation is unsupported |
| `requiredOnCreate` | Known required creation fields; `null` means unspecified, not none |
| `delete` | Whether the writer supports trashing this kind |
| `move` | Whether Joomla's nested-record move (`parent_id` / `move_after`) is supported |
| `note` | Additional limits; CMS validation still applies |

The Tracy relay adds `writable` using the current seat and effective workspace/site policy.
It must overwrite any receiver-supplied seat permission. A true value grants the toolset,
not a promise that every record or value will pass the writer's validation.

Joomla generates these lists from its writer's `MAP` and `NESTED` definitions. Relation kinds
are not described yet. WordPress describes `post`, using the writer's post-field allowlists;
other kinds remain undescribed. `post_type` is creation-only. Full-body writes still replace
the entire body, and all existing safeguards remain in force.

No `writeSchema` means unknown (including an older receiver), never an invented empty list or
a claim that the native write door is closed. Consumers must tolerate its absence.
