# ADR 0014 — Reconciliation

- **Status:** Accepted
- **Date:** 2026-08-19
- **Context:** Phase 6, Step 6.2

## The requirement

Phase 6 asks for a reconciliation workflow, and the assessment sketches the table: `account_id`, `currency_id`, period, `statement_balance`, `ledger_balance`, `difference`, `status`.

`ledger:verify` already proves the ledger agrees with **itself** — every transaction balances per currency, and the cached balances match the entries. It says nothing about whether the ledger agrees with **the world**: the cash actually in the safe, the balance the bank actually reports. That is the gap.

## Decision 1 — A reconciliation never writes a balance

This is the load-bearing decision. A reconciliation records a comparison and stops there.

If a difference turns out to be a real error, it is corrected by posting a balance adjustment through the ledger like any other movement, and the reconciliation records which transaction did it. Letting a reconciliation set a balance directly would make it a back door around double entry — the one place in the system where a number could be written without a matching entry.

So `ReconciliationStatus` has no case meaning *fixed*. It has `Balanced`, `Open` and `Resolved`, and `Resolved` means *explained*, not *corrected*.

## Decision 2 — The balance is computed as of a day, not read from the cache

`ledger_balances` holds what an account holds **now**. A reconciliation asks what it held on a particular day, and the two differ the moment anything is backdated — which is routine: a Friday deal entered on Monday.

So `ledgerBalanceAsOf` sums entries up to the close of that day, using the account's own kind to decide which direction increases it rather than assuming an asset.

## Decision 3 — Having computed it, it is stored

The ledger figure could be recomputed on every read. That is exactly the problem.

A reconciliation saying *"on 30 June the ledger held 1,000"* would silently become *"held 1,250"* the moment somebody posted a 15 June entry, and the only evidence that anything had changed would be gone. Storing it means the movement is visible: `drift()` compares the stored figure against a fresh computation for the same day, and a non-zero result says an entry dated on or before the count was posted after it.

That is not necessarily an error — backdating is normal — but it means a reconciliation somebody signed off no longer describes the ledger, and they should be told.

## Decision 4 — The figures are frozen, in two places

`counted_amount`, `ledger_amount`, `difference`, `as_of`, `account_id` and `currency_id` cannot be edited after the record is written. A mistaken count is superseded by a new one, not rewritten.

Enforced by a MySQL trigger **and** by the model, exactly as ledger entries are: the model refuses first so the failure is a legible exception at the call site, and the trigger catches anything that goes around the model. A test asserts the database refuses a raw `DB::table()->update()`.

Only the explanation, the resolver, and the linked adjustment may be added afterwards.

## Decision 5 — The expected figure is hidden until asked for

The form does not prefill the count with what the ledger says, and does not show it until somebody presses a button.

A figure sitting in the box invites agreement. A reconciliation that agrees because the answer was already on screen has checked nothing, and would be worse than not reconciling at all — it would produce a signed-off record asserting a match that was never tested.

## Decision 6 — One count per account, currency and day

Unique on `(account_id, currency_id, as_of)`. Two records for one day would leave nobody able to say which was the count.

## Consequences

- Two new permissions. `reconciliations.manage` is separate from posting, because reconciling writes no entry; the adjustment that corrects a difference needs the posting permission on its own merits. Operators get both — the person who counts the safe is the person who knows why it disagrees. Viewers get read only.
- The dev database was migrated additively (`migrate`, never `migrate:fresh`) and the role seeder re-run so existing users inherit the new permissions.
- Still outstanding in Phase 6: a spreadsheet (xlsx) writer, and whatever "audit improvements" turns out to mean.
