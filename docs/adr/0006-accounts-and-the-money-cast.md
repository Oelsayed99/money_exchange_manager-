# ADR 0006 — Accounts, and Money at the Database Boundary

- **Status:** Accepted
- **Date:** 2026-08-04
- **Context:** Phase 2, Step 2.1

## Decision 1 — Accounts carry no balance column

`accounts` stores what a custody location *is*, never what it holds. Balances derive from the ledger (Section 7), and a stored balance column would immediately become a second source of truth competing with it — the exact failure Section 7 exists to prevent.

The only monetary figure stored is the **declared opening balance**, held per currency on the `account_currency` pivot. Section 6 also lists "Opening balance" as a transaction type; the column is the declaration, and Phase 3 posts it to the ledger so that even the opening position has an entry behind it. A test asserts no `balance` or `current_balance` column exists.

## Decision 2 — An unheld currency is not a zero balance

`openingBalance()` returns `null` when the account does not deal in that currency, and a zero `Money` when it does and the balance is zero. Collapsing the two would let a typo — the wrong currency picked on a form — look like a legitimate zero.

## Decision 3 — The currency registry is a per-request singleton

Every monetary column needs its currency's precision in order to be read. A naive cast would query per row. `CurrencyRegistry` loads all currencies once per request and holds them.

Deliberately **not** a persistent cache: an administrator changing a currency's precision must take effect on the next request, not whenever a cache happens to expire. A test asserts repeated lookups issue no further queries.

## Decision 4 — `MoneyCast` refuses what it cannot verify

Reading a monetary column yields a `Money` that already knows its precision. Writing a `Money` whose currency contradicts the row's `currency_id` throws `CurrencyMismatch` — storing AED in a row labelled USD produces a number that silently means something other than what it says.

**A real limitation, found by a failing test.** With a custom pivot class, Eloquent casts extra `attach()` attributes *in isolation*: `formatAttachRecord` runs the cast before merging the foreign keys, so `currency_id` genuinely is not available. The cast therefore:

- accepts a **plain decimal string** in that position — a bare number asserts no currency, so there is nothing for the row to contradict; it is validated and normalised to the storage scale;
- **refuses a `Money`** with an explicit message, rather than storing an amount whose currency was never checked.

`Account::setOpeningBalance()` is the sanctioned path for writing a `Money`, because it knows both sides and can compare them.

## Decision 5 — Account identifiers are masked and redacted

`identifier` holds a bank or wallet account number. `masked_identifier` reveals only the last four characters, so a screen or a screenshot does not carry a full account number.

It is also added to `auditRedacted()`: the audit trail records **that** the identifier changed, never the number. An account number sitting in an append-only, undeletable log is a liability, and Step 1.5 built the trail so it cannot be edited afterwards.

## Decision 6 — Soft deletes on accounts, restrict on currencies

Accounts soft-delete, so one referenced by history can be retired without the history losing what it pointed at (assumption A8).

`account_currency.currency_id` is `restrictOnDelete`: a currency in use cannot be removed. `account_id` cascades, because a force-deleted account's currency links are meaningless on their own.

## Deferred

- **Counterparty link.** `credit_trust`, `customer_balance` and `partner_custody` accounts belong to a specific party. The column arrives with the `counterparties` table so it lands together with its foreign key rather than dangling.
- **Notes.** Section 4 lists notes as an account field, and also requires a polymorphic notes system across the application. Building the polymorphic module once and attaching it avoids adding a text column now and migrating off it later.
- **UI.** Accounts have no screens yet.
