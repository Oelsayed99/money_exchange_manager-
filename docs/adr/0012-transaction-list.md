# ADR 0012 — The Transaction List

- **Status:** Accepted
- **Date:** 2026-08-18
- **Context:** Phase 5, Step 5.5

## The gap

The ledger had no screen of its own. A counterparty's statement showed their side of it, and that was all — a transaction touching no counterparty (moving money between our own safes, an expense, a capital deposit) was perfectly present in the database and invisible in the interface. `ledger:verify` could pass on a ledger nobody could look at.

## Decision 1 — Read-only

Corrections are reversals through `PostingService`, never edits; entries are append-only and the database enforces it with triggers. Offering an edit control here would suggest otherwise, and the suggestion is worse than the missing feature. The page says so in a line at the bottom rather than leaving somebody to discover it by trying.

## Decision 2 — No amount column

Each row lists its **legs**, each with its own currency. An exchange moves two currencies and neither is "the" amount; a single column would have to pick one and would hide half of every deal — the same instinct the four buckets and the per-currency statement exist to resist.

Inflow and outflow are marked with a direction arrow rather than a sign, for the same reason the statement labels its positions instead of parenthesising them.

## Decision 3 — The currency filter goes through the legs

`whereHas('legs', …)` rather than a column on `transactions`. A transaction has no single currency, so there is no column that could answer this correctly. Filtering by EGP returns an EGP/USD exchange, which is right: it *is* an EGP transaction, and also a USD one.

## Decision 4 — Ordering breaks ties by id

`occurred_at DESC, id DESC`. Several transactions commonly share a date — `occurred_at` is a date in practice, not a timestamp — and without the tiebreak, MySQL is free to return them in any order, which means a page boundary can repeat one row and drop another. Paging silently loses records without it.

## Consequences

- Fifty rows a page, with legs eager-loaded. Enough to scan; small enough that the eager load stays reasonable.
- Search covers reference and description with `LIKE`. Fine at this size; it will want a proper index or full-text if the ledger grows large.
- The list is not filtered by profit visibility. It shows movements, not margins — there is no profit column on it at all.
- A viewer can read it. Reading the ledger is a lesser thing than writing to it, and `transactions.view` already existed for exactly this.
