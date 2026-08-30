# ADR 0032 — One Running Balance, In and Out

- **Status:** Accepted
- **Date:** 2026-08-29
- **Supersedes:** [0007](0007-counterparty-balance-separation.md) and the counterparty half of [0029](0029-two-sides-on-the-counterparty-list.md)

## What the owner said

> "Our money with them / Their money with us — how can they owe us and we owe them? It's
> one thing, the difference."

And, on the movement types:

> "No loan, credit, received, paid etc. Make it simply in and out. In with all it means
> (money we took from someone), out (money we paid to someone), and the difference is
> the status."

They are right, and the objection is exact. Within one currency a relationship runs one
way at a time. Four positions and two columns were a model of the *reasons* money moved,
presented as if they were four simultaneous facts.

## What ADR 0007 got right, and where it went wrong

0007's reasoning still holds for what it was actually about: netting a receivable against
a payable **across parties or currencies** gives a number nobody can act on. It was built
from a specification section written before anybody used the software.

What it got wrong was assuming the classification could be made **at the counter**. An
operator taking money across the desk knows the amount, the currency and who from. Asking
them whether it is custody, a credit deposit, a loan received or a payable settlement asks
them to declare an intention that often is not settled yet — and four of those nine types
already produced *identical* ledger entries, distinguished only by the word stored on the
transaction.

## Decision 1 — one account per party per currency

`client_account`, an asset. **Positive means they owe us**, negative that we are holding
theirs. The sign carries what four buckets used to carry, and it is the same thing the
owner says out loud when asked.

An asset rather than a liability so that the sign reads the way they think: money out to
somebody puts them in debt to us, which is a debit, which is positive.

## Decision 2 — nine movement types become two

`in` and `out`. Money came from them, or went to them. Everything else about a movement —
who, when, which account, how it moved, what it was for — is already recorded in its own
field, and none of it needed to be a *type*.

Opening balance, transfer, deposit, withdrawal, fee, expense, adjustments, refund,
reversal and the currency exchange are untouched: none of them is a client movement.

## Decision 3 — record in one currency, move another

The change that motivated the rest. Take 10,000 dollars, agree 50.85, and book it against
the client as **508,500 EGP** — the dollars really arrive in the safe, the client's
account really moves in pounds, and both facts are kept along with the rate:

```
DR  cash · USD           10,000        CR  fx_position · USD      10,000
DR  fx_position · EGP   508,500        CR  client · EGP          508,500
```

The same shape an exchange uses, so each currency balances on its own and no rate appears
in the integrity check. The rate the operator types **is** the rate of record, so the
clearing pair is flat by construction and there is no margin to recognise here — margins
are priced on the Exchange screen, which stays.

## Decision 4 — the interface says which way, in words

A minus sign is the easiest thing on a screen or a printed page to misread, and this is
the one number a client argues about. So every place the balance appears — the list, the
statement, the PDF, the movements panel — prints the figure and says **"they owe us"** or
**"we owe them"** beside it. The PDF prints the magnitude without a sign at all.

The movements panel additionally says when a movement would **turn the relationship
over**. That is the successor to the negative-position warning, and it keeps the owner's
decision from posting-rules §9.4: warned about, never blocked.

## What was given up

At a glance you can no longer tell a deposit you are holding from a debt you are owed.
That distinction was the whole of ADR 0007 and it is genuinely gone — not hidden behind a
click, gone. It was the owner's call, made twice, in their own words, after using the
thing.

What survives it: the reason each movement happened is still on every transaction as its
description, reference and method, and the statement still lists every movement that
built the balance.

## The old data

The migration **refuses to run** while four-bucket history exists. Folding it in would
mean deciding what each old movement meant, and only whoever recorded it knows. The owner
chose to purge and re-seed, the application not yet being in real use.

## What is not done

The end-to-end specs still assert the old vocabulary — "Client credit with us", "Credit
deposit" — and have not been rewritten. The 852 backend tests cover the domain, and the
browser walk-throughs are a second pass.
