# ADR 0026 — The Profit Section

- **Status:** Accepted
- **Date:** 2026-08-28
- **Amends:** [0008](0008-rate-driven-deal-entry.md) (how the cost rate is stated)

Four changes to the profit half of the exchange screen, asked for by the owner.

## Decision 1 — There is no spread

The first version of this ADR removed one of the three spread types, because
`SpreadType::FixedAmount` computed `customer value − the figure typed`, which is
character for character what `ProfitMethod::FixedAmount` computes. The owner's reply was
that the other two should come out of the spread as well, and they were right: once the
duplicate was gone, "Spread" was a profit method whose only content was a second
question, and the two answers to that question were the only thing that changed the
arithmetic. **A question whose answer is a method is a method.**

So `SpreadType` is deleted and `ProfitMethod` has the list in full:

| | |
|---|---|
| Rate difference | customer rate against a cost rate |
| Currency units per unit delivered | 0.02 on a rate of 3.67 means the currency cost 3.65 |
| A percentage of the value | 0.02 means two hundredths of a per cent |
| Fixed amount | a standing agreed margin |
| Entered by hand | negotiated for this deal |
| No profit | our own money, moved |

The two middle entries are Section 3's warning made structural. It says "do not assume
that 0.02 always means 2%", and the old shape complied by printing that sentence next
to a select. It is now impossible to state a margin without having said which reading
it is, because the reading is the thing you picked.

`spread_type` is dropped and `spread_value` becomes `profit_value` — it never only held
a spread anyway; a fixed amount and a hand-entered figure went in the same column.

Both migrations **refuse to run** rather than reinterpret a recorded deal. Dropping
`spread_type` from a row that has one throws the meaning away, leaving a transaction
that says "percentage" without saying whether the figure beside it was a percentage.
Nothing guesses. Nothing in this database had one.

The first of them exposed a separate problem: the original migration built its CHECK
constraints from `SpreadType::values()` — it asked the application what it believed
*today* — so a fresh install would have got a narrowed constraint while every existing
install had the old one. Those lists are literal now. A migration describes the schema
at the moment it ran.

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

### What this does not fix — **closed by [0027](0027-which-leg-carries-the-margin.md)**

> The section below describes the state after this ADR and before 0027, which fixed it.
> Kept because the reasoning that ruled out inverting the rate still applies.

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

## Decision 3 — Profit, and what comes off it

The calculation was one column: customer rate, cost rate, customer value, cost value,
gross, fees, expenses, commissions, net. The figure the operator is looking for was
ninth.

The first attempt split it into *cost* and *profit*, which the owner corrected: the two
cards are **profit** and **what is taken off the deal** — expenses and commissions paid.
That is the better line. Cost value is not money leaving your pocket, it is the other
half of the margin calculation, and putting it in a card of its own separated it from
the subtraction it belongs to. Expenses and commissions genuinely are money gone.

So the profit card carries the whole working — both rates, both values, gross, fees, net
— and the second card carries the two deductions. Side by side while the column is full
width, stacked once it becomes the narrow rail where two columns would put three digits
on a line.

## Decision 4 — Colour is the last thing that says it

Green on the profit, red on the deductions, and the profit card turns red too when the
deal loses money.

The colour is decoration. Each panel is named, each carries an icon, and the profit
panel **renames itself** from "Profit" to "Loss" — Section 13 forbids saying anything
with colour alone, and a red border is not something a screen reader can read out. The
e2e test asserts the rename, not the colour, for the same reason.

Each panel is a named landmark (`aria-labelledby` on the heading it already shows,
rather than an `aria-label` repeating the same words), so the two halves are reachable
as regions.

The amounts themselves stay in the ordinary text colour. Tinting every figure to match
its panel makes all of them look like a status, and one of them is the answer.
