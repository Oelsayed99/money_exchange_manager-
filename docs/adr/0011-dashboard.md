# ADR 0011 — The Dashboard

- **Status:** Accepted
- **Date:** 2026-08-18
- **Context:** Phase 5, Step 5.4

## The requirement

> "There is the dashboard where I can see my overall analysis and can filter by client, time, currency, status (they owe me money, they have credit with me, or closed status no one owes the other)."

## Decision 1 — Every figure is per currency, and none is added across them

There is no base currency (ADR 0003). A single headline total would need a rate, and would then move when the market moved — for reasons having nothing to do with the business. Three currencies means three cards.

This applies to the chart too. Plotting margin in several currencies on one axis compares figures that share no scale: the base-currency mistake, drawn instead of written. The monthly chart therefore appears only once a currency is chosen.

## Decision 2 — Four statuses, not the three that were asked for

The owner named three: they owe me, they have credit, closed. The four-bucket model makes a fourth possible and it is not a corner case — a client can hold money on deposit *and* owe against a loan, and in the sample data that is exactly what happens.

`CounterpartyStatus` is therefore `OwesUs` / `HasCredit` / `Mixed` / `Settled`. Calling a party on both sides either one would be a lie, and netting to decide would be the very thing ADR 0007 exists to prevent.

`forSides()` takes the two sides as booleans rather than a net figure, so there is nothing to net. `across()` resolves several currencies: disagreement is **Mixed**, and choosing a currency resolves it — which is what the currency filter is for.

Status is **derived, never stored**. A stored status is a field that can contradict the positions it claims to describe. It is also why the status filter is applied in PHP: there is no column to put in a `WHERE`.

## Decision 3 — Positions are now; the dates narrow what moved

A date filter changes money in, money out and margin earned. It does not move the positions, which come from the balance cache as they stand today.

"Who owes me" is a question about now. Answering it as of last month would answer a question nobody on this screen is asking, and would quietly disagree with the counterparty statement, which is also current.

Stated on the page rather than left to be discovered.

## Decision 4 — Cash on hand ignores the client filter

The cash in the safe is not any particular client's. Narrowing it by client would produce a figure that looks like an answer — "this client's share of the safe" — to a question that has no meaning. It is filtered by currency only, and labelled as such.

## Decision 5 — Aggregated in SQL

`SUM` over `DECIMAL(28,10)` is exact in MySQL, so aggregating there loses nothing — the objection to floats does not apply to decimal columns. Activity is grouped down to (currency, bucket, direction), a handful of rows however many entries exist, and turned into in/out in PHP where the rule can be written once: *increasing what we owe them, or reducing what they owe us, both mean value came from them.* The same rule as the statement, deliberately.

## Decision 6 — The chart's geometry is float; its figures are not

Recharts draws with SVG coordinates, which are floats; no charting library avoids that. Bar heights therefore go through `Number`.

Everything a reader *sees* — axis ticks, tooltip — is rendered from the exact decimal string that came from the server. The plotted value is never displayed. This is the line: float for where the pixel goes, exact string for what the number is.

## Decision 7 — Which statistics, and which of them need a currency

Four charts, and the constraint above decides the shape of each:

| Chart | Needs a currency | Why |
|---|---|---|
| Margin by month | yes | Bars of pounds beside bars of dollars would be read as a comparison |
| In and out by month | yes | Summing amounts of different currencies into one bar is arithmetic on quantities that cannot be added |
| Where clients stand | **no** | It counts relationships. Counting across currencies is meaningful in a way adding money is not |
| Largest positions | yes | As above, and a ranking implies a common scale |

Two of them refuse to net rather than drawing one bar:

- **In and out** are two bars, not a net line. A month where a million came in and a million went out is not a quiet month, and a net of zero would draw them identically.
- **Largest positions** shows each client's two sides separately. One bar per client would have to net an obligation against a holding to decide its length — the thing ADR 0007 exists to prevent.

The status split is counted **before** the status filter is applied. Narrowing it to the slice already chosen would draw a chart of one bar and call it a breakdown. Settled clients are excluded because they drop out of the list entirely once every bucket is zero, so the slice would always be nought.

## Consequences

- Recharts is used for the first time. The dashboard chunk is ~376 kB (110 kB gzipped), lazily loaded.
- The dashboard has no me/client mode. It is the owner's own analysis screen and shows margin unconditionally, consistent with the toggle-not-permission decision.
- Found while building: `resources/js/app.tsx` globbed `./pages/**/*.tsx`, so the exchange page's test file was being **bundled into production assets** and was resolvable as a page named `exchange/create.test`. The glob now excludes `*.test.tsx`.
