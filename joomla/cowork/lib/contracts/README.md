# Quickstart content contracts

A contract binds one published quickstart to its editable content slots and protected presentation.
The canonical files live here; the exact same files ship with the seeder and Joomla Cowork.
Never accept a contract supplied by an agent in a write request.

- `manifest.json`: package identity, archive checksums, contract schema and required receiver.
- `content-map.json`: stable entity identities and allowed scalar content slots.
- `presentation-lock.json`: protected database fields, module assignments and executable asset hashes.

The receiver resolves identities against the installed site and stores the binding and initial snapshot
in its private database table. IDs are installation data, not public files. A missing or ambiguous
identity, changed presentation, unsupported field or incompatible package fails closed.

Content-only means no new module, article or menu; no unpublishing; no layout, ordering, module
assignment, ACM variation, class, CSS, script or font changes. Requests requiring a different structure
must use a separately authorized design workflow. Missing factual content remains a validation issue;
it must never turn into fabricated testimonials, customers, prices or statistics.

## Content and display are one contract

Joomla Module Manager exposes the conditions under which content is visible. Preserve module status
(including publishing schedules), module type AND ACM type/variation, position, ordering, show-title,
language, client, access level and menu assignment mode (all, none, include, exclude) with its exact
menu set. A label such as Home - Hero does not establish which page uses the module.

Also preserve module chrome/tag, header tag/class, module class, Bootstrap size, caching, template
style/layout, menu hierarchy/status/access, and effective access-level/ACL definitions. A different
access ID with the same label is not proof of the same audience. Capture these dependencies without
copying users, passwords or site tokens into the public contract.

Observed read-only in Joomla admin at 127.0.0.1:8212 on 2026-09-12: Home - Hero (178) is published,
uses masthead and Public access, but is assigned only to Home variation. The working demo also has
later content and layout edits; it is not the published 1.1.0 baseline.

## Current delivery boundary

The first contract covers the published `tracy-apple/j6/1.1.0` archive. The capture contains
197 entities, 1,113 scalar slots, page-to-menu/style/assignment relationships and 3,900 protected
files. `assignedModules` are routing candidates, not a claim that every candidate is visible:
rendered positions, responsive rules, access, language and schedules still apply.

The seeder accepts an explicit reviewed mapping through its existing `--copy` argument:
`contract`, `revision` from `content.contract inspect`, `changes` keyed by slot, `evidence` for
factual copy, and `pending` for missing customer inputs. The new receiver is Cowork 0.14.0.
Its package contains the same three canonical JSON files. A bound site's generic structural
write actions are refused; content receipts support replay and reverse-order undo.

This is an opt-in path in the current implementation. The default Joomla build stages still use
the older T4 recipe. Do not advertise the contract as active for all builds until the new receiver
is released/pinned and the default copy/style/seed stages use it. The 8212 working demo does not
match this published archive and needs its own versioned profile after its design is finalized.

ACL comparison uses effective inherited rules. Joomla may materialize a previously absent empty
module asset during a normal save; this must not be confused with a change in audience or edit
permissions. View-level memberships and user-group ancestry are preserved without recording users.

Keeping CSS/layout unchanged does not keep text wrapping or section height identical. The scalar
limits and image aspect-ratio checks reduce overflow; desktop/mobile visual review remains needed.
Evidence references record provenance supplied by the caller; the receiver cannot independently
prove a business claim is true. Joomla plugin directives such as `{loadposition ...}` remain locked.
