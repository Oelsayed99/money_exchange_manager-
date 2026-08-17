# ADR 0008 — Rate-Driven Deal Entry

- **Status:** Accepted
- **Date:** 2026-08-18
- **Context:** Phase 5, Step 5.1

## The requirement

The owner described how they actually work:

> "I prefer that I choose the type of the transaction, which am I selling or buying. Example: I want 100k USD from someone, I will pay him in AED, the rate is 3.67 USD to AED and then it calculates how much I should pay him. Or the opposite: someone wants to buy from me EGP and pay in EUR, the rate is EUR to EGP 54.20 — you make the calculation how much should I get from him."

The exchange screen did the opposite. It asked for both amounts and derived the rate, because that is the right way round for *storage* (ADR 0003, and the reasoning in `ProfitCalculator`): the amounts are what happened, and the rate is a description of them. Entering both and hoping they agree invites a recorded rate that does not match the money that moved.

Both are correct, at different moments. The rate is known first; the amounts are known last.

## Decision 1 — The rate is an entry aid, not a stored fact

Nothing about the ledger changes. `ExchangeInput` still takes both amounts, `ProfitCalculator` still derives `customer_rate` from them, and the transaction still stores what moved. The rate the operator types is used to *arrive at* the second amount and is then discarded; what gets recorded is the rate the two amounts imply.

This matters when the two differ. The operator types 54.20, the computed figure does not land on a clean settlement amount, they type over it with what they actually paid — and the recorded rate is now 54.200542005420. That is the rate of the deal that happened. The typed one was an intention.

## Decision 2 — A rate carries its two currencies

`RateQuote` holds base, quote and rate, meaning *one unit of base buys this much of quote*. It is never a bare number.

"1 USD = 3.67 AED" and "1 AED = 3.67 USD" are different deals. The owner's two examples quote opposite ways round relative to the currency being traded — the first states the rate with the traded currency first, the second with it second — so the form offers both orientations rather than imposing one. A dealer quotes the way the market quotes.

Swapping the orientation **re-derives** the rate from the amounts rather than inverting the number. Inverting is a division: 1 ÷ 3.67 does not terminate, so flipping and flipping back would not return the operator to the rate they typed. `RateQuote::inverted()` exists and is tested, but the form does not use it for this.

## Decision 3 — Conversion truncates and says so, where `Money` throws

`Money::multipliedBy()` throws `PrecisionLoss` rather than discard a digit. `RateQuote::convert()` does not: it truncates at `Money::SCALE` and reports `Conversion::$exact` as false.

This is a deliberate divergence, not an oversight. Converting from the quote side is a division, and 1,000,000 EGP at 54.20 to the euro is 18,450.1845018450… forever. Refusing would make rate entry unusable for exactly the deals that need it most. The rule the codebase actually holds to is *never lose a digit silently* — and here nothing is silent: the interface shows a warning at the moment it happens, in front of the person who is about to overwrite the figure anyway.

Truncation, never rounding, as everywhere else. The result can never exceed the true value.

## Decision 4 — One endpoint, solving for whichever of the three is missing

`POST /exchange/convert` takes two of {rate, base amount, quote amount} and returns all three plus `exact`.

Exactly two — not one, which is unsolvable, and not three, which would let a caller assert a rate the amounts contradict. Three quantities with two degrees of freedom.

It computes on the server for the same reason the profit preview does (Section 16, ADR 0001): a second implementation in JavaScript would be float arithmetic, and would be free to disagree with the one that runs when the deal is recorded.

The rate comes back padded to `RateQuote::SCALE` whether it was typed or derived, so the caller never has to decide whether "51.48" and "51.480000000000" are the same number. Trimming it for display is the interface's business, and is done with string surgery — parsing it to tidy it would put a float between the server's answer and the operator's eyes.

## Decision 5 — Buying and selling is framing, and is not stored

"I am buying USD, paying in AED" and "I am selling AED, paid in USD" are the same transaction. The choice decides which leg is received and which delivered, and nothing else; the ledger records both identically, so a buy and its mirrored sell cannot disagree.

It is therefore not persisted. Direction is recoverable from the legs, and storing it as well would create a field that could contradict them.

## Consequences

- The received/delivered fieldsets no longer accept typed amounts. They show what the deal above works out to, alongside the account pickers they uniquely own. Two editable places for one amount would let the two disagree.
- `RateQuote::SCALE` is now the single definition of rate precision; `ProfitCalculator::RATE_SCALE` defers to it.
- `RateQuote` uses a private constructor with a validating `of()` factory, mirroring `Money`.
- A deal can still be entered the old way — type both amounts, leave the rate alone — and the rate fills itself in.
