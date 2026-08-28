# ADR 0026 — The Profit Section

- **Status:** Accepted
- **Date:** 2026-08-28
- **Amends:** [0008](0008-rate-driven-deal-entry.md) (how the cost rate is stated)

Four changes to the profit half of the exchange screen, asked for by the owner.

## Decision 1 — One way to state a flat margin, not two

`SpreadType::FixedAmount` — "A flat amount for the deal" — computed
`customer value − the figure typed`. So does `ProfitMethod::FixedAmount`. Character for
character the same arithmetic, reached through two different lists, and the operator
picking between "Fixed amount" and "A flat amount for the deal" was choosing nothing.

Removed. The two spread types left are the ones Section 3 exists to keep apart: 0.02 as
units of margin per unit exchanged against 0.02 per cent, which on a 50,000 deal differ
by a factor of about fifty.

The `spread_type` CHECK constraint is narrowed by migration. It **refuses to run** if
any transaction was recorded under the removed value rather than restating it: what an
operator chose is a fact about the deal, and quietly rewriting it as a different profit
method would change what the ledger claims happened. Nothing in this database used it.

That migration exposed a second problem. The original migration built its constraints
from `SpreadType::values()` — it asked the application what it believed *today* — so a
fresh install would have got a two-value constraint while every existing install had
three. The lists are now written out literally in that migration. A migration describes
the schema at the moment it ran.

## Decision 2 — The cost rate is stated like a rate

It was a bare number box labelled "Cost rate", with "per unit" left to a line of hint
text underneath. That is how a test written with the specification open still set a deal
up backwards and valued it at a hundred and thirty million (ADR 0023).

It now reads `1 USD = [ 51.20 ] EGP`, in the same grammar as the deal rate above it, and
what the customer was charged is printed directly beneath in the same units — so the
difference the method is named after can be read rather than worked out.

**It does not follow the swap on the deal rate above.** The ledger holds cost per unit
delivered; turning it over means dividing, and this application does not divide into a
figure the margin is derived from. Inverting 51.48 and truncating at twelve decimal
places, then multiplying by 2,574,000, does not give back 50,000.

So the orientation is fixed and written on screen. When the deal rate is currently
quoted the other way — which is what **buying** does — the screen says so, in amber,
next to the box.

### What this does not fix

Selling is now natural: the rate reads `1 USD = 51.48 EGP` and the cost `1 USD = 51.20
EGP`, one under the other.

Buying is not. Buying 50,000 USD for 2,574,000 EGP makes EGP the delivered leg, so the
cost rate is USD per EGP and the operator must type **0.019531** where they are thinking
"51.20". Making it visible is an improvement on hiding it, and it is not a fix.

The real fix is that the margin on that deal is in pounds, not dollars: quote the cost
as 51.20 EGP per USD, compute the whole comparison in the delivered currency, and it is
exact by multiplication with no division anywhere. That changes which currency a profit
is recorded in, so it is the owner's decision and not a detail to slip into a layout
change.

Until then, the working advice is the one ADR 0023 arrived at: **set the deal up as a
sale of the currency leaving the till.**

## Decision 3 — Two halves, not eleven rows

The calculation was one column: customer rate, cost rate, customer value, cost value,
gross, fees, expenses, commissions, net. The figure the operator is looking for was
ninth.

Now two panels — what it cost, what it made — side by side while the column is full
width and stacked once it becomes the narrow rail, where two columns would put three
digits on a line.

## Decision 4 — Colour is the last thing that says it

Red on the cost, green on the profit, red on both when the deal loses money.

The colour is decoration. Each panel is named, each carries an icon, and the profit
panel **renames itself** to "What you lost" — Section 13 forbids saying anything with
colour alone, and a red border is not something a screen reader can read out. The e2e
test asserts the rename, not the colour, for the same reason.

Each panel is a named landmark (`aria-labelledby` on the heading it already shows,
rather than an `aria-label` repeating the same words), so the two halves are reachable
as regions.

The amounts themselves stay in the ordinary text colour. Tinting every figure to match
its panel makes all of them look like a status, and one of them is the answer.
