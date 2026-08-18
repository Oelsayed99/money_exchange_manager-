# ADR 0009 — The Counterparty Statement

- **Status:** Accepted
- **Date:** 2026-08-18
- **Context:** Phase 5, Step 5.2

## The requirement

The owner's words:

> "A table for each client working with me, each table shows that client's transactions with me and all the details: in, in currency, out, out currency, difference (he gave money in, didn't withdraw; took more, didn't pay it back, and the rest of the details). And there is me mode where it shows the profit I made and its type."

The two cases named in that sentence are already the two liability/asset pairs the ledger keeps apart: *gave money in and didn't withdraw* is `credit_trust`, *took more and didn't pay it back* is `receivable`. See ADR 0007.

## Decision 1 — One statement, one currency

In, out and a position only mean something inside a single currency. The sheet being replaced was a single-currency page for one party, and that was correct.

So the statement takes a currency, and the screen offers only the currencies that party has actually traded — read from their ledger accounts, not from the currency list. Offering a statement in a currency they have never touched produces a page of zeros that invites the reader to conclude something from it.

## Decision 2 — The position is labelled, never signed

The original sheet's running column flipped between `(899,510)` and `50,490`. The parenthesis alone distinguished *they are holding our money* from *they owe us money* — two different obligations, one chased and one reconciled — and on a printed page handed to somebody it is the easiest thing in the world to misread.

Every position on the statement therefore carries its meaning in words: *Client credit with us*, *Owed to us*, *Owed to them*, *Our money held by them*. Balances are tracked per bucket and reported per bucket. `CounterpartyStatement` has no method returning a single net figure, and a test asserts that no `balance()`, `net()` or `total()` appears on it.

Only buckets in play are shown. A party who has only ever left money on deposit gets one column, not four columns of zeros.

## Decision 3 — In and out are about the relationship, not the arithmetic

The four buckets sit on both sides of the balance sheet, so the same sign means opposite things:

| Movement | Meaning | Column |
|---|---|---|
| `credit_trust` up | they handed money over | **in** |
| `credit_trust` down | we paid some of it back | **out** |
| `receivable` up | they took money and now owe it | **out** |
| `receivable` down | they settled | **in** |

The rule: increasing what we owe them, or reducing what they owe us, both mean value came from them. A loan to a client is *out* even though it increases one of our assets.

## Decision 4 — Me mode and client mode split at the query

Per the owner's decision of 2026-08-18, the mode is a toggle rather than a permission — anyone who can open a counterparty can switch it.

That does not make where it is applied a free choice. In `StatementMode::Client` the profit columns are **never selected**. Inertia serialises props into the HTML document, so a figure hidden by a React condition is still in the page source and in whatever that page is printed from; the only way for it not to leak is for it not to be there. Tested by asserting a known margin figure appears in one mode's rendered page and not the other's.

A consequence worth having: if a `profit.view` permission is ever wanted, it decides which mode is offered and nothing else has to change.

## Decision 5 — Profit belongs to the deal, not to each of its legs

A transaction touching two of a party's buckets produces two lines. The margin is attached to the first only; repeating it would show it twice and total it twice.

Margins are totalled **per currency**, not converted and summed. There is no base currency (ADR 0003), and inventing a reporting rate here would put an exchange rate inside a document whose whole purpose is to be exact.

## Decision 6 — A declared opening balance that was never posted is reported, not merged

Counterparties carry declared opening positions from Phase 2. Those are declarations; the ledger is the source of truth, and the statement is built from ledger entries alone.

Silently adding a declaration to the figures would make the statement disagree with `ledger:verify`, with the dashboard, and with itself the moment somebody posts the opening balance properly — at which point it would count twice. So it is surfaced as a warning on the page, naming the amount and saying it is not included.

## Consequences

- The statement reads the ledger directly rather than `ledger_balances`. Balances are derived everywhere else in this application, and a statement with its own opinion would be a second source of truth on the one document a client actually sees.
- Exchanges settled in cash do not appear on a party's statement even when the party is recorded on the transaction, because no entry touches their accounts. This is correct — the statement is their account with the business, not a list of deals they were involved in.
- Printing is the browser's, for now. A dedicated PDF is the next step.
