# ADR 0016 — Reading the Audit Trail

- **Status:** Accepted
- **Date:** 2026-08-20
- **Context:** Phase 6, Step 6.4 — the "audit improvements" the phase asks for

## The gap

The trail has been written since Phase 1: every create, change, delete and restore on transactions, reconciliations, accounts, counterparties, currencies and users, with the actor, the source, the IP address and the before-and-after of each field.

None of it could be read from anywhere in the application.

That is worse than it sounds. A record only ever written is a record nobody checks, and one nobody checks is one nobody notices has stopped working. The trail could have been silently broken for weeks — by a model losing its trait, by a migration, by anything — and every test would still have passed, because the tests write and read it in the same breath. Making it visible is what turns it from a table into evidence.

## Decision 1 — Administrators only

`audit.view` goes to administrators and to nobody else. Operators and viewers are both refused, and a test asserts it for each.

The trail is not more ledger. It carries IP addresses, user agents, and the before-and-after of changes made by other people — it is a record *about* the people using the system, and reading it is a different kind of act from reading the books. A clerk needs the ledger; they do not need to know which address their colleague logged in from.

## Decision 2 — Only what changed

An update writes the whole attribute set on some paths. Rendering all of it would list thirty unchanged fields around the one that moved, which is how somebody stops reading an audit trail — not by deciding to, but by finding it useless.

So each row shows the fields whose value actually differs, old struck through beside new. Absent and empty-string are drawn differently, because *"this field had no value"* and *"this field was cleared"* are different events and a blank cell says neither.

## Decision 3 — Redaction passes straight through

The recorder replaces secrets with `[redacted]` rather than omitting them, so the trail says a password changed without saying what to. The screen renders that as it finds it, and a test posts a real secret through a user update and asserts the string appears nowhere in the page props while `[redacted]` does.

The screen also says this in a line at the bottom, so somebody reading the trail knows a redacted field is a recorded change and not a gap.

## Decision 4 — The actor is the stored label, never a lookup

`actor_label` is a snapshot taken when the change happened, stored alongside a `user_id` that carries no foreign key — deliberately, so the trail outlives the account. The screen shows the label.

Joining to `users` would make a deleted account erase its own history, which is the opposite of what an audit trail is for.

## Consequences

- Ordering is `created_at DESC, id DESC`. Rows written in the same second are common — a single request writes several — and without the tiebreak a page boundary repeats one and drops another.
- Search covers the actor label and the record id. Searching the changed *values* would need the JSON columns indexed and is not worth it yet.
- Found while building: the actor label prefers **email** over display name. Worth knowing before reading the screen, and the reason is sound — an email identifies a person where two staff may share a first name.
