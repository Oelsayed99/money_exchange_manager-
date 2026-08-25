# ADR 0022 — Performance Review

- **Status:** Accepted
- **Date:** 2026-08-25
- **Context:** Phase 7, Step 7.6

Measured rather than reasoned about. A harness seeded two volumes — 3 parties with 6
transactions, then 33 with 186 — and counted queries and milliseconds for every screen.

## Finding 1 — Two caches that cached nothing

`CurrencyRegistry` and `LedgerAccountResolver` each hold a lookup for the life of a
request, and each says so in its own docblock: *"registered as a singleton"*, *"loaded
once per request"*.

**Neither was ever registered.** `AppServiceProvider::register()` was the scaffolded
empty method, so the container built a fresh instance on every resolution and each one
reloaded its table. The caching worked perfectly and was thrown away between uses.

The measurement showed `select * from currencies` running **fifteen times on one page** —
once per `Money` the page hydrated, because hydrating a `Money` needs its currency's
precision.

Two `singleton()` calls. `/transactions` went from **58 queries to 8**, and stopped
scaling with row count at all.

This is the reason to measure rather than read. Nothing looked wrong: the class was
right, the docblock was right, the usage was right, and the one line that connected them
did not exist.

## Finding 2 — Reconciliation drift, one query per row

`ReconciliationController::present()` called `drift()` per reconciliation, and each call
summed that account's entries up to that date. Thirty-three rows, thirty-three sums.

`driftFor()` now answers for the whole page in one query, joining each row to its own
cash account and summing against its own day. **38 queries down to 6.**

The direction arithmetic is spelled out in SQL there and read from the account's kind in
the single-row version — a duplication that cannot be avoided without giving up the batch
query, so a test asserts the two agree row for row, and another asserts the count does not
grow with the number of rows.

## Finding 3 — Four queries to ask "is there an opening balance"

The statement asked once per bucket. One query now, for a question whose answer is almost
always "none". 12 queries down to 7.

## What was already right

- **Indexes.** Every filter and sort on a hot path has one: `transactions` on
  `occurred_at`, `(status, occurred_at)`, `(type, occurred_at)` and `counterparty_id`;
  `ledger_entries` on `(ledger_account_id, occurred_at)`, which is the statement's main
  query; `audit_logs` on `created_at`, `event` and `user_id`. Nothing to add.
- **The dashboard aggregates in SQL** rather than loading rows, and was flat from the
  start.
- Every list is paginated except the reconciliation page, which caps at 200.

## Where it stands now

Every screen, at both volumes, unchanged between them:

| | queries |
|---|---|
| `/counterparties` | 2 |
| `/audit`, `/movements`, `/exchange` | 3 |
| `/reconciliations` | 6 |
| statement | 7 |
| `/dashboard`, `/transactions` | 8 |

## Guarded by a test

`QueryCountTest` measures every screen at one volume, multiplies the data by five, and
requires the same number of queries. An N+1 is invisible while the data is small — every
test passes, every page is fast, and the bill arrives on the day the ledger is worth
reading.

Building it produced a false positive worth recording: the first request of a process is
four queries heavier than the rest, because the permission table loads once and is then
cached. That looks exactly like the thing the test hunts, so it warms up first.

## Known and not addressed

- **The dashboard bundle is 414 kB (119 kB gzipped)**, almost all Recharts, against
  ~118 kB for the shared layout. It is a lazily-loaded chunk, so it costs only visitors
  to that page, and on a VPS serving a handful of staff it is not worth a lighter
  charting library. Worth revisiting if the dashboard is ever opened over a phone
  connection.
- **No load testing.** Correct query counts are not the same as acceptable behaviour
  under concurrency, and nothing here has been run against realistic contention. The
  ledger's locking is tested for correctness (`tests/Integration/LedgerConcurrencyTest`)
  but not for throughput.
- **`ledger:rebuild` is unbounded.** It rebuilds every balance from every entry in one
  pass. Fine at today's scale, and the first thing to become a problem at a hundred
  thousand entries.
