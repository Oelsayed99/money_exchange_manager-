# ADR 0015 — Recording Movements

- **Status:** Accepted
- **Date:** 2026-08-20
- **Context:** Phase 6, Step 6.3 (pulled forward)

## The gap

The owner asked how to add credit to somebody, send them money, or record a loan either way. The answer was that they could not: `POST /exchange` was the only route in the application that recorded a transaction.

The ledger had supported all of it since Phase 3 — seventeen types, all posting correctly, all tested. Only currency exchange had a screen. Everything else was reachable from code and nowhere else, which meant the application could not be used for most of the business it was built for.

## Decision 1 — One screen, driven by the type

A single form: what happened, how much, in what currency, from or into which location, with whom. The type decides which fields appear and which are required.

That decision is read from `TransactionType` itself — `needsCounterparty()`, `needsDestinationAccount()`, `needsBucket()` — and sent to the form as metadata, so adding a case to the enum does not mean remembering to update a validator and a React component. There is one description of what a transfer needs, and both sides read it.

Currency exchange and reversal are excluded, and `recordableByHand()` says so on the enum rather than in a list somewhere. An exchange needs two amounts and a rate and has its own screen; a reversal is the consequence of reversing something, and offering it here would let somebody post a reversal that reverses nothing.

## Decision 2 — Show the four positions before recording, not after

"Add 500,000 to their credit" and "reduce what they owe by 500,000" are easy to confuse at a keyboard and impossible to confuse when both figures are on screen next to each other.

So picking a counterparty shows all four of their positions in the chosen currency, and typing an amount shows what the movement would leave behind. All four, **including the zeroes**: a bucket reading `0.00` says nothing is there, where a missing row leaves the reader wondering whether it was checked.

Computed on the server, from the same effect the posting rules apply, so the promise and the posting cannot disagree.

## Decision 3 — The declared effect is bound to the ledger by a test

`TransactionType::bucketEffect()` declares which position each type moves and which way. That is a second statement of something `PostingRules` already knows, and second statements drift.

So a test walks every type declaring an effect, posts one through the real rules, and asserts the ledger moved exactly that bucket in exactly that direction. The declaration cannot rot without a failure that names the type.

The subtlety it protects: money in against what they owe **reduces** the receivable, while lending **increases** it — both are cash crossing the counter, in opposite directions.

## Decision 4 — The negative credit warning warns, and nothing more

Owner decision 4 in `posting-rules.md` §9: credit balances may go negative, always allowed, with a non-blocking warning. Designed in Phase 3 and never built, because no credit screen existed to put it on.

Now it exists, and it is exactly what was agreed. Paying out more than somebody left with you turns their credit negative — which strictly speaking means they now owe you, and belongs in the receivable bucket. The screen says so, suggests a loan given may be what was meant, and records the movement regardless.

Blocking it would override a decision the owner made deliberately and against a recommendation. Recording it silently would waste the recommendation. Saying it out loud and proceeding is the only option that respects both.

## Consequences

- Every transaction type the ledger supports is now reachable through the interface.
- The credit-settlement path is live, so posting-rules §9.4's warning is implemented rather than merely designed.
- No new permission: recording a movement is `transactions.record` and posting it `transactions.post`, exactly as an exchange is.
