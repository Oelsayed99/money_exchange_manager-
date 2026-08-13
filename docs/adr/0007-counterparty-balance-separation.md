# ADR 0007 — Counterparties and the Four Balance Buckets

- **Status:** Accepted
- **Date:** 2026-08-04
- **Context:** Phase 2, Step 2.2

## The requirement

Section 5 states that a party may both owe money and hold money on the business's behalf, that custody, receivable and payable are distinct concepts, and — explicitly — "Do not combine these three concepts into one balance field."

This is not a tidiness preference. Netting a receivable against a payable produces a number that is correct in total and useless in practice: it cannot tell you what to chase, what to settle, or what a disputed statement should say. The loss is invisible until somebody argues about it, by which point the underlying figures are gone.

## Decision 1 — Four buckets, not three

`BalanceBucket` has four cases, paired as mirrors:

| Bucket | Whose money | Who holds it | Side |
|---|---|---|---|
| `custody` | the business's | the party | asset |
| `receivable` | the party's | owed to the business | asset |
| `payable` | the business's | owed to the party | liability |
| `credit_trust` | the party's | the business | liability |

Section 5 names the first three. The fourth is Section 4's credit/trust concept seen from the party's side, and it is the exact mirror of custody — their money in the business's hands rather than the business's money in theirs. Modelling it as a fifth special case elsewhere would have left the two mirrors described in two different vocabularies.

`mirror()` makes the pairing explicit, and a test asserts it is symmetric and flips asset/liability for every case.

## Decision 2 — The separation is structural, not conventional

There is no `balance` column on `counterparties`, and no method that nets buckets together. A position lives in `counterparty_opening_balances`, keyed uniquely on **(counterparty, bucket, currency)**.

That composite key is what makes the requirement enforceable rather than aspirational: there is nowhere to put a combined figure. A database `CHECK` constraint additionally rejects any bucket outside the enum, so an unrecognised position — one no report would ever show — cannot be stored.

A test asserts the absence of `balance`, `receivable`, `payable` and `custody` columns.

## Decision 3 — Negative positions are refused, and told where to go

`setOpeningBalance()` rejects a negative amount with a message naming the mirror bucket: a negative receivable *is* a payable.

Permitting it would quietly undo the separation — the same information recorded in the wrong place with the wrong sign, netting correctly in a total while being wrong in every statement. Forcing the caller to say which side they mean is the whole point.

## Decision 4 — Undeclared is not zero

`openingBalance()` returns `null` when no position was declared and a zero `Money` when zero was declared. One is silence; the other is a statement that the parties were square. A reconciliation needs to tell them apart.

## Decision 5 — Positions are read grouped, in a meaningful order

`openingPositions()` iterates `BalanceBucket::cases()` rather than the query result, so ordering is stable and semantic — assets before liabilities — instead of whatever order rows happen to return in. A statement shows what is owed and what is held side by side and lets the reader draw the conclusion.

## Decision 6 — Accounts may belong to a party

The `accounts.counterparty_id` column, deferred from Step 2.1 so it would arrive with its foreign key, lands here. A credit/trust account, a customer balance and a partner's custody each belong to somebody; a safe in the office does not.

`nullOnDelete`: force-deleting a party must not remove the custody location, because history points at it. Counterparties soft-delete in normal use anyway.

## Deferred

- **Balances themselves.** These are *opening* positions only. Live balances derive from the ledger in Phase 3, which will also post these opening figures so even the starting position has an entry behind it.
- **Notes**, pending the polymorphic module.
- **Statements and UI.** Section 5 asks for transaction history and an account statement per party; both need the ledger.
