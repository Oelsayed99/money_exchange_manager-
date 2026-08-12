# ADR 0005 — Audit Trail

- **Status:** Accepted
- **Date:** 2026-08-04
- **Context:** Phase 1, Step 1.5

Section 15 requires the system to track who changed what. Section 7 requires historical statements to remain reproducible. Both are far cheaper to satisfy before accounts and transactions exist than after.

## Decision 1 — Built here, not taken from a package

Laravel provides model events but no audit storage, so Section 16's "do not introduce unnecessary packages when the framework already provides a safe solution" does not settle it either way.

Built in-house because the requirements are specific and the surface is small (~200 lines): append-only enforcement at the database, value redaction rather than omission, an actor snapshot that outlives the user row, and — later — explicit financial events (a credit settled, a partial settlement) recorded through the same path as ordinary row changes. Bending a general-purpose package to all four would cost more than owning it.

## Decision 2 — Append-only, enforced by the database

`audit_logs` carries `created_at` and no `updated_at`. Two MySQL triggers raise `SQLSTATE 45000` on any `UPDATE` or `DELETE`.

**Why triggers and not just application code.** An audit trail the application can quietly rewrite is not evidence of anything. Application guards can be changed, bypassed, or bypassed by accident; a trigger can only be removed by a visible schema change. The `AuditLog` model *also* refuses updates and deletes, but that is convenience — it turns the failure into a clear exception at the call site instead of a SQL error surfacing from a trigger.

Both layers are tested, including raw `DB::table()` writes that bypass Eloquent entirely.

## Decision 3 — The actor is stored twice, on purpose

`user_id` is nullable and carries **no foreign key**. Alongside it, `actor_label` holds a snapshot of the user's email at the time of the change.

A foreign key with cascade delete would let a deleted account take its history with it; a restrictive one would block the deletion. Neither is acceptable. The id alone stops meaning anything once the row is gone, so the label keeps the entry readable long after somebody leaves. Covered by a test that deletes the actor and asserts the entry still names them.

## Decision 4 — Secrets are redacted, not omitted

A changed password is recorded as a change, with the value replaced by `[redacted]` on both sides. Knowing that a password changed and when is exactly what an audit trail is for; the value is precisely what it must never keep.

The redaction list defaults to the model's existing `$hidden`, so a newly added secret is protected because it is already hidden from serialisation, rather than because somebody remembered a second list. A test asserts the plaintext appears nowhere in the stored row.

## Decision 5 — Opt-in per model, and only real changes

The `Auditable` trait is applied per model rather than globally. Auditing everything indiscriminately buries the entries that matter under session and cache churn.

Updates record only the attributes that actually changed, on both sides, with identifiers and timestamps excluded. A save that altered nothing writes nothing.

Currently applied to `Currency` and `User`. Models adopt it as they land.

## Decision 6 — HTTP versus console detected by a resolved route

`source` records where a change came from. The obvious signals both fail:

- `app()->runningInConsole()` is **true under the test runner**, so it cannot distinguish an artisan command from a request made during a test — and would mislabel genuine traffic.
- `REQUEST_URI` is populated (`/`) even in a test that makes no request at all.

A bound route is the honest signal: the router matches one for real traffic and for test requests alike, and never for a console command. A request matching no route is recorded as console, which costs nothing because nothing auditable runs on a 404.

This was found by a failing test, not by reasoning — the first two attempts were both wrong.

## Known gaps at the end of this step

- **No audit viewer.** The trail is written but nothing displays it, and there is no `audit.view` permission yet. Section 23 places audit improvements in Phase 6.
- **No retention or archival policy.** The table grows without bound.
- **Financial events are not yet recorded** — only row lifecycle. The events Section 15 names (credit creation, settlement, partial settlement, notes changes) arrive with the modules that produce them, through `AuditRecorder::record()`.
