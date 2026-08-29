# ADR 0029 — Two Sides on the Counterparty List

- **Status:** Accepted
- **Date:** 2026-08-29
- **Refines:** [0007](0007-counterparty-balance-separation.md), which is not superseded

## The complaint

The counterparty list carried four money columns — Custody, Receivable, Payable, Credit
held — under two group headings. The owner's words: *four fields for no meaningful
reason*. A list should answer "does this client owe me anything, and do I owe them", and
four columns make the reader assemble that answer from a model they have to remember.

## What ADR 0007 actually forbids

Worth being exact, because this looks like a reversal and is not.

Section 5 forbids **one balance field per party**. The reason given there — and it is the
right one — is that netting a receivable against a payable produces a number that is
correct in total and useless in practice: it cannot tell you what to chase or what to
settle, and the loss is invisible until somebody argues about a statement.

That is a prohibition on combining **the two sides**. It says nothing about summarising
one of them.

Custody and receivable are both *our money with them*, differing in how it got there —
left in their keeping, or owed by them. Payable and credit held are both *their money
with us*. Adding within a side is a summary; adding across is the thing that destroys
information, and nothing here does it.

## Decision — two figures, per currency, each a link

| | |
|---|---|
| **Our money with them** | custody + receivable |
| **Their money with us** | payable + credit held |

Separate fields with no method anywhere that combines them. The four buckets travel to
the client alongside the totals, so the split is available without another request.

Each figure is a **link to that currency's statement**, which is what the four columns
were really for: the split, and the movements behind it. The list answers the question;
the statement shows the working.

`CounterpartyStandingsTest` asserts both halves — that a side is summed, and that
neither the difference nor the total of the two sides appears anywhere in the payload.

## The figures now come from the ledger

They were the **declared opening positions**, which is what the page had before there was
a ledger to ask. That made the drill-down meaningless: a declared opening has no
transactions behind it, by definition.

`CounterpartyStandings` reads the balance cache in **one query for the whole list**. Per
party would be the shape that turned `/transactions` into fifty-eight queries (ADR 0022),
and a list of parties is exactly where it would come back; a test asserts the count.

A party whose only position is a declared opening now shows nothing in those columns,
because nothing has been posted. Saying nothing at all would make the row look wrong to
the person who typed the opening, so the row carries an amber **"Opening position not
posted"** marker instead, pointing at the statement — which already explains it at
length.

## What was given up

At a glance you can no longer tell a deposit to collect from a debt to chase. That is a
real cost and it is the point of the trade: it buys a list you can read across, and the
distinction is one click away rather than gone.

Zero positions are dropped from the list rather than printed. ADR 0007's Decision 4 —
undeclared is not zero — still holds where it matters: the statement prints a declared
zero, because a reconciliation needs to tell silence from "we are square".
