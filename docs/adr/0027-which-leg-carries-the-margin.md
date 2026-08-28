# ADR 0027 — Which Leg Carries the Margin

- **Status:** Accepted
- **Date:** 2026-08-28
- **Closes:** the "what this does not fix" section of [0026](0026-the-profit-section.md)
- **Amends:** [0008](0008-rate-driven-deal-entry.md), Section 3's formulas

## The bug

Section 3 states the margin against the delivered leg:

```
Customer Value = Delivered × Customer Rate
Cost Value     = Delivered × Cost Rate
Gross Profit   = Customer Value − Cost Value
```

That is correct for a **sale**. Sell 50,000 USD for 2,574,000 EGP: dollars went out, they
cost 51.20 each, they fetched 51.48, and the 14,000 EGP margin is in pounds.

It is wrong for a **purchase**. Buy the same 50,000 USD *with* pounds and the dollars are
now the received leg, so the cost rate is dollars-per-pound. The operator thinking
"51.20" had to type **0.019531**, and the margin came back in dollars — a currency they
never made it in.

Two earlier attempts to write a test against this specification got it backwards and
valued a deal at 131 million (ADR 0023) and at a hundred and thirty-one million again in
the e2e work. Both times the diagnosis was "set the deal up as a sale". That is advice
about how to hold the tool, not a fix.

## What was rejected

**Inverting the rate.** Take 51.48, compute 1 ÷ 51.48, apply that. It is one line, and
it is the wrong line: `1 / 51.48` truncated at twelve decimal places, multiplied back by
2,574,000, does not give 50,000. This application does not round, and putting a division
into the path that produces the margin is exactly where that promise would go.

**Telling the operator.** The previous commit printed an amber warning saying the box was
the other way round. Honest, and no use — it tells someone their tool is awkward while
still handing them the awkward tool.

## The fix

The margin can honestly be measured on either leg, and the arithmetic is symmetric.
`MarginBasis` says which:

| | margin in | cost rate quoted | gross |
|---|---|---|---|
| `Received` | the received currency | per unit **delivered** | customer value − cost value |
| `Delivered` | the delivered currency | per unit **received** | cost value − customer value |

The sign follows the leg: money **arriving** in the margin currency means more of it is
better, money **leaving** means less of it is. `costYielding()` is the exact inverse of
that subtraction, so the two cannot disagree.

The cost rate is quoted as *margin currency per unit of the other leg* in both rows, so
it is applied by **multiplication** in both. Nothing divides. The one division left in
the calculator is the derived customer rate, which describes money that has already
moved and is not what the margin is computed from.

Every other method follows: a per-unit margin subtracts from the customer rate when the
margin came in and adds when it went out (either way the gross is the margin times the
other leg); a percentage, a fixed amount and a hand-entered figure are all stated in the
margin currency; and fees, expenses and commissions are denominated there too, because
they are added to and taken off it.

The ledger follows as well. The profit entry, the fee income, the expense and the
commission all post against the margin side's `fx_position` and cash account. Each
currency still balances on its own, so the invariant is untouched — and the clearing
pair still nets to zero when valued at the cost rate, mirrored.

## The interface picks it for you

The deal rate reads `1 base = X quote`. The quote currency is the one a margin is
naturally counted in, so **the basis is derived from the way the rate is being quoted**
and the cost rate is rendered in exactly the same grammar — including after the swap.

- Selling USD for EGP: rate `1 USD = 51.48 EGP`, cost `1 USD = 51.20 EGP`, margin EGP.
- Buying USD with EGP: rate `1 USD = 51.20 EGP`, cost `1 USD = 51.48 EGP`, margin EGP.

Same two boxes, same units, both directions. The amber warning is gone because there is
nothing left to warn about.

Swapping the deal rate moves the margin to the other currency. That is a real change and
the screen says so: the hint under the cost rate names the currency the margin will come
out in, and the fee, expense and commission boxes are labelled with it.

## Storage

`margin_basis` is a column, backfilled to `received` for every existing row — which is
what they were, the calculator having had no other behaviour when they were written.

It is *derivable* from `profit_currency_id` against the two legs, and it is stored
anyway. A recorded `customer_rate` of 51.48 does not say on its own whether it means
pounds per dollar or dollars per pound, and a figure in a ledger that cannot be read back
without inference is not a figure in a ledger.

## What proves it

`ProfitCalculatorTest`, "which leg carries the margin". The test that matters is the
symmetry one: give 2,574,000 EGP for 50,000 USD when a dollar cost 51.20, and

- on the delivered leg it is a **14,000 EGP** loss,
- on the received leg it is a **273.4375 USD** loss,

and 14,000 ÷ 51.20 = 273.4375. The same loss, in two currencies. If the basis changed
the answer rather than the wording, that test fails.

`ExchangeServiceTest`, "the margin on the delivered leg", walks the same purchase through
the ledger: the margin posts in pounds, no dollar trading-profit account is opened at
all, the clearing pair is flat at the cost rate, and `ledger:verify --transactions`
passes with fees and commissions attached.
