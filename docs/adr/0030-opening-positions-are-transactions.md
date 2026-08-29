# ADR 0030 — Opening Positions Are Transactions

- **Status:** Accepted
- **Date:** 2026-08-29
- **Closes:** the "declared but not posted" warning introduced by [0029](0029-two-sides-on-the-counterparty-list.md)
- **Refines:** [0007](0007-counterparty-balance-separation.md), Decision 2

## The gap

A position typed on a counterparty — custody, receivable, payable, credit held — was a
note on their record. No ledger entry, no date, nothing in the transaction list, and the
statement carried a warning admitting the figure was not in the books.

Every other figure in this application has a transaction behind it. These were the
exception, and ADR 0007 said as much in its Deferred section: *"Live balances derive from
the ledger in Phase 3, which will also post these opening figures."* It never happened.

## Decision — a figure typed is a figure posted

Saving a counterparty posts an `opening_balance` transaction for each position that
changed, dated when the change was made. The posting rules already described the shape;
nothing invented it here.

## Changing a figure is a second transaction

The ledger cannot un-post, so an edit is not an edit:

| | |
|---|---|
| declared 900,000, nothing posted | post 900,000, opening the position |
| raised to 950,000 | post 50,000 more |
| lowered to 800,000 | post 150,000 the other way |
| removed | post 800,000 the other way, then forget the row |

Both transactions stay. Somebody reading the trail sees the figure was raised, when, and
by whom — which is the point of asking for a date at all.

`posted_amount` on the record is what makes this possible. Without it, raising a figure
from 900,000 to 950,000 is indistinguishable from posting 950,000 for the first time.
Existing rows start at zero, because that is exactly what they are: declared, never
posted. Saving such a counterparty posts the whole figure, which is how the two that
predate this change get into the books.

## Two things that had to give

**`TransactionInput` gained a direction.** Amounts stay positive — the type says which
way money went, everywhere in this system. But an opening position is the one thing that
can be *corrected downward*, and the correction is the same two accounts the other way
round. `increasesBucket` says which, and the posting rule reads it.

**The opening balance grew a leg.** The counterparty branch produced entries and no leg,
so the transaction appeared in the list with a blank amount column — found by looking at
one. It now carries a leg, using the statement's own rule for direction: growing what we
owe them, or shrinking what they owe us, both mean value came from them.

## What follows

- The counterparty list's amber "Opening position not posted" marker now keys on the
  unposted *remainder* rather than on having positions at all, so it disappears the
  moment a figure is saved.
- The statement's declared-opening warning reports the same remainder, and is empty for
  anything saved since.
- `ledger:verify --transactions` passes after every change; a test asserts it across a
  create, a raise and a removal.
