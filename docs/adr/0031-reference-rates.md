# ADR 0031 — Reference Rates on the Dashboard

- **Status:** Accepted
- **Date:** 2026-08-29
- **Guards:** [0003](0003-money-representation.md), [0005](0005-no-rounding.md), and the no-base-currency rule

## The request

Live rates for the currencies traded, at the top of the dashboard, so the person about to
quote a price can see where the market is.

## The danger, stated first

This application's central promise is that **a deal recorded in June cannot change value
in December**. Nothing stored depends on a current rate; every transaction balances
within each currency on its own; there is no base currency anywhere.

A live rate feed is the one thing that could quietly undo that. Not by design — by
somebody, later, reaching for a figure that is already sitting there.

So the boundary is the feature:

- Reference rates are **never** a `Money`.
- They are **never** multiplied by an amount.
- No ledger entry has ever been derived from one, and none can be.
- The rate on a deal is still typed by hand, and what is recorded is the two amounts that
  actually moved.

`ReferenceRatesTest` enforces the last point structurally: it walks `Domain/Ledger`,
`Domain/Exchange`, `Domain/Statement` and `Domain/Reconciliation` and fails if any of
them so much as mentions the rates namespace. No unit test can prove a negative about
code nobody has written yet; a structural one can.

## Decision 1 — open.er-api.com

Keyless, and it covers the currencies this business actually trades. Every wrapper around
the European Central Bank — Frankfurter and the rest — omits the Egyptian pound and the
dirham, which rules them out for exactly the two currencies that matter most here.

`RateProvider` is an interface because the free feed is a starting point rather than a
commitment: a business quoting intraday will want an hourly source behind a key, and
swapping one in should be a line in a service provider.

## Decision 2 — daily, and it says so

The free feed publishes **once a day**. The strip prints when it was last published and
labels itself *reference only, not used in any deal*, rather than implying it is live.
For a business quoting intraday that distinction is the difference between a useful
reference and a misleading one.

## Decision 3 — the digits are read as text

`json_decode` turns `50.252612` into a floating-point number. These figures are display
only, so a float would not be visibly wrong — but the moment a float rate exists in the
codebase, it is available to be multiplied by. The response body is scanned for the
provider's own characters instead, so there is no floating-point rate anywhere in this
application, display or otherwise.

## Decision 4 — it can never break a page

Every failure path returns null: the feed switched off, a timeout, a bad status,
unreadable JSON, a provider error. The strip simply does not appear. A dashboard that
fails because somebody else's server is down is a worse dashboard, and the ledger is what
it is there to show.

`RATES_ENABLED=false` makes no outbound request at all, which is also what the end-to-end
suite runs with — a test suite that reaches a third party is a suite that fails when they
have an outage.
