# Posting Rules

**Status:** Agreed. The four open questions were resolved on 2026-08-14; see §9.
**Date:** 2026-08-14
**Required by:** Section 7 — "Before implementing this section, define and document: the posting rules for every transaction type; which ledger accounts or balance buckets each transaction affects; how reversals work; how pending and partially settled transactions affect available and confirmed balances; how concurrency is controlled; how cached balances are reconciled with ledger totals."

This document answers all six. Nothing in Phase 3 gets written until it is agreed, because a posting rule discovered to be wrong after balances exist is a data migration, not a bug fix.

---

## 1. Principles

**The ledger is the only source of truth.** No table stores a current balance as a fact. Balances are sums over `ledger_entries`, with a cache that is rebuildable from zero at any time.

**Every transaction balances independently within each currency it touches.** Not in a base currency — per currency. For every `(transaction, currency)`:

```
sum(debits) = sum(credits)
```

This is the central invariant, and it is checkable without any exchange rate at all. That is exactly why Section 2's requirement holds — a posted transaction cannot drift when rates move later, because no stored value depends on a current rate.

**Entries are append-only.** No update, no delete. A mistake is corrected by a reversal that references the original. Both remain.

**Ledger accounts are single-currency.** `Cash · Office safe · USD` and `Cash · Office safe · EGP` are two accounts. This is what makes per-currency balancing trivial rather than a special case.

---

## 2. Chart of accounts

Each ledger account has a **kind** (its accounting nature), a **subkind** (its role), an **owner** (a custody location, a counterparty, or the system), and exactly one **currency**.

| Subkind | Kind | Owner | Meaning |
|---|---|---|---|
| `cash` | asset | Account | Money in a custody location |
| `custody` | asset | Counterparty | Our money, physically held by them |
| `receivable` | asset | Counterparty | Their money, owed to us |
| `payable` | liability | Counterparty | Our money, owed to them |
| `credit_trust` | liability | Counterparty | Their money, physically held by us |
| `fx_position` | clearing | System | The open leg of a currency exchange |
| `trading_profit` | income | System | Profit from rate spread |
| `fees_income` | income | System | Fees charged |
| `expense` | expense | System | Costs incurred |
| `commission_expense` | expense | System | Commissions paid externally |
| `opening_equity` | equity | System | The counter-side of declared opening positions |
| `capital` | equity | System | Owner contributions and drawings |
| `adjustment_equity` | equity | System | The counter-side of deliberate corrections |

The four counterparty subkinds are exactly the four buckets built in Step 2.2. They are never netted.

**Loans reuse `receivable` and `payable` rather than adding two more buckets.** Section 5 names four positions and the interface already presents four; a loan is distinguished by the transaction type that created it, which is recorded and reportable. Adding `loan_receivable` would split the same fact across two columns that always have to be added back together.

### Debit and credit conventions

| Kind | Debit | Credit |
|---|---|---|
| asset, expense, clearing | increases | decreases |
| liability, equity, income | decreases | increases |

---

## 3. Posting rules for every transaction type

Section 6 lists **19** types. Each is given below as the entries it produces. `CUR` is the transaction's currency unless stated.

### Reference and setup

**1. Opening balance** — for a custody location:
```
DR  cash · account · CUR
CR  opening_equity · CUR
```
For a counterparty position, the bucket determines the side:
```
custody      DR custody · party · CUR       CR opening_equity · CUR
receivable   DR receivable · party · CUR    CR opening_equity · CUR
payable      DR opening_equity · CUR        CR payable · party · CUR
credit_trust DR opening_equity · CUR        CR credit_trust · party · CUR
```
The opening positions declared in Phase 2 are posted through this type, so even the starting position has an entry behind it.

### Movements within our own money

**2. Deposit** — money enters a custody location from outside the tracked system, with no counterparty: owner capital going in.
```
DR  cash · account · CUR
CR  capital · CUR
```

**3. Withdrawal** — the reverse: owner drawings.
```
DR  capital · CUR
CR  cash · account · CUR
```

> **Decided (Q1).** Both meanings are real and are modelled separately. Types 2 and 3 are owner capital going in and out. Separately, **every cash movement carries a `method`** recording how the money physically moved — the `ايداع` / `تحويل` / `كاش` seen in the statement. Method is a field, not a type; the same deposit method can apply to a credit deposit, a receivable settlement or a capital injection, and duplicating types per method would multiply nineteen into sixty.

**4. Transfer between my accounts** — same currency only.
```
DR  cash · destination · CUR
CR  cash · source · CUR
```
A transfer between two of our own accounts in *different* currencies is not a transfer; it is a currency exchange, and must be recorded as type 10.

### Counterparty movements

**5. Money received from a party**
```
DR  cash · account · CUR
CR  receivable · party · CUR
```

**6. Money paid to a party**
```
DR  payable · party · CUR
CR  cash · account · CUR
```

**7. Loan given**
```
DR  receivable · party · CUR
CR  cash · account · CUR
```

**8. Loan received**
```
DR  cash · account · CUR
CR  payable · party · CUR
```

**9. Receivable settlement** — identical entries to type 5.
**10. Payable settlement** — identical entries to type 6.

> Types 5/9 and 6/10 post the same entries. They are kept apart because the *intent* differs and reporting needs to tell them apart: "money received" against an open invoice is a settlement, whereas an unexpected inflow is not. The type is stored on the transaction; the entries do not need to differ for the distinction to survive.

### Currency exchange — the two-leg model

**11. Currency exchange.** Section 2 requires at least two legs: what entered custody and what left. Each side balances in its own currency, joined by the `fx_position` clearing accounts.

Received leg (currency A entered):
```
DR  cash · account · A          amount received
CR  fx_position · A             amount received
```
Delivered leg (currency B left):
```
DR  fx_position · B             amount delivered
CR  cash · account · B          amount delivered
```
Profit recognition, in the transaction's profit currency (here A):
```
DR  fx_position · A             gross profit
CR  trading_profit · A          gross profit
```

The profit posting is self-balancing within currency A, so the invariant holds. **And it leaves a checkable property:** once profit is recognised, the two `fx_position` accounts valued at the *cost* rate net to zero. A non-zero residual means an unrecognised or mis-stated spread — which makes the clearing accounts a standing correctness check, not just plumbing.

Fees and commissions, where present:
```
DR  cash · account · CUR        fee charged        CR  fees_income · CUR
DR  commission_expense · CUR    commission paid    CR  cash · account · CUR
```

`Net profit = gross profit + fees charged − expenses − external commissions`, per Section 3.

### Credit — the half-transaction model

**12. Credit deposit** — money received and held as a liability, not revenue.
```
DR  cash · account · CUR
CR  credit_trust · party · CUR
```

**13. Credit settlement, same currency**
```
DR  credit_trust · party · CUR
CR  cash · account · CUR
```

**Credit settlement, different currency** — the liability is in A, the repayment leaves in B:
```
DR  credit_trust · party · A    value applied
CR  fx_position · A             value applied
DR  fx_position · B             amount delivered
CR  cash · account · B          amount delivered
```
Plus profit recognition exactly as in type 11. **Decided (Q2): a cross-currency credit settlement recognises ordinary trading profit**, identical to an exchange spread — economically it is one, and it belongs in the same margin figure.

Partial settlement is simply a smaller amount. Every settlement references the deposit(s) it pays down via `credit_settlements`, allocated **oldest deposit first (FIFO)** — decided (Q3). FIFO is what makes credit aging meaningful: "this money has been held eighteen days" is only answerable if settlements consume the oldest tranche first.

### Income, cost and correction

**14. Fee** — `DR cash|receivable · CUR / CR fees_income · CUR`
**15. Expense** — `DR expense · CUR / CR cash · account · CUR`

**16. Profit adjustment**
```
DR  adjustment_equity · CUR   CR  trading_profit · CUR      (increase)
DR  trading_profit · CUR      CR  adjustment_equity · CUR   (decrease)
```

**17. Balance adjustment** — a counted discrepancy. Section 7 forbids editing a balance directly; an adjustment is a transaction like any other.
```
DR  cash · account · CUR      CR  adjustment_equity · CUR   (found)
DR  adjustment_equity · CUR   CR  cash · account · CUR      (short)
```
Requires a reason, and is the most audit-sensitive type in the system.

**18. Refund** — money returned, referencing the original transaction. Entries are the original's, reversed, limited to the refunded amount. Unlike a reversal, a refund is a real economic event with its own date: the original was correct and is not being retracted.

**19. Reversal** — see below.

---

## 4. Reversals

A reversal creates a **new** transaction whose entries are the original's with debits and credits exchanged, carrying `reversal_of_transaction_id`. Nothing is edited; nothing is deleted.

- The original is marked `reversed` but keeps every entry.
- Reversing a reversal is permitted and simply produces a third transaction. It is not an "undo".
- The reversal carries its own date. Reversing in a later period does not silently rewrite the earlier one.
- A partially settled credit deposit cannot be reversed while settlements reference it; the settlements are reversed first. Otherwise the trail would describe a payment against something that no longer happened.

---

## 5. Status, confirmed and available balances

| Status | Meaning | Ledger entries |
|---|---|---|
| `draft` | Being prepared; editable and deletable per permission | None |
| `pending` | Committed to, not yet complete — funds promised or in flight | Written, marked pending |
| `posted` | Complete | Written, confirmed |
| `reversed` | Superseded by a reversing transaction | Retained unchanged |

Two balances follow, and they answer different questions:

```
confirmed = sum of posted entries
available = confirmed − pending outflows
```

Pending *inflows* are deliberately excluded from `available`. Money someone has promised is not money you can spend, and a balance that counts it will eventually authorise a payment that bounces.

---

## 6. Concurrency

- Every posting runs inside a database transaction. Partial postings cannot exist.
- Affected `ledger_balances` rows are locked with `SELECT … FOR UPDATE`, **acquired in ascending ledger-account id order**. A fixed order is what prevents two concurrent transactions touching the same pair of accounts from deadlocking.
- Every transaction carries a unique `idempotency_key`. A replay returns the original result rather than posting twice — the double-submitted exchange is a real failure mode, and a retry must be harmless.
- Ledger entries are inserted, never updated, so they need no locks of their own.

---

## 7. Cached balances and reconciliation

`ledger_balances` is a cache, updated inside the same database transaction as the entries that change it.

- `ledger:rebuild` recomputes every balance from entries alone, ignoring the cache entirely.
- `ledger:verify` compares cache to projection and reports differences without changing anything.
- If the two disagree, **the cache is wrong by definition**. It is disposable; the entries are not.

`ledger:verify` is expected to run on a schedule and to be part of the reconciliation workflow Section 7 asks for.

---

## 8. Worked example — the real statement

Validated against the supplied EGP statement for سالم التجريبي. Every row reconciles under these rules.

**Money arriving** (nine tranches, 12–15 June, totalling 3,957,540 EGP) — each a **credit deposit**:
```
DR  cash · bank · EGP            581,000.00
CR  credit_trust · Salem · EGP    581,000.00
```
After nine, `credit_trust · Salem · EGP` stands at 3,957,540.00 credit — the business owes that much. The spreadsheet showed it as `(3,957,540)`; here it is a liability balance that needs no sign convention to interpret.

**50,000 USD delivered at 51.48** (16 June) — a **cross-currency credit settlement**:
```
DR  credit_trust · Salem · EGP    2,574,000.00
CR  fx_position · EGP            2,574,000.00
DR  fx_position · USD               50,000.00
CR  cash · usd account · USD        50,000.00
```
EGP balances. USD balances. The liability falls to 1,383,540.00 — matching the sheet exactly.

**If the cost rate were 51.20**, gross profit would be `50,000 × (51.48 − 51.20) = 14,000.00 EGP`:
```
DR  fx_position · EGP             14,000.00
CR  trading_profit · EGP          14,000.00
```
leaving `fx_position · EGP` at 2,560,000 against `fx_position · USD` of 50,000 — exactly the cost value, so the position is flat.

**The sign flip the spreadsheet could not express.** After the 20 June delivery the sheet shows `50,490` positive, and after 27 June `(899,510)` again. Under these rules there is no flip: `credit_trust` reaches zero and a **receivable** of 50,490 opens alongside it. Two accounts, each unambiguous, instead of one column whose meaning depends on parentheses.

**Row order.** The sheet's running balance follows row order, and rows are out of date sequence (15/06 after 16/06, 20/06 after 21/06). Under the ledger, balances are sums — order of entry does not affect them, and a forgotten transaction inserted later needs nothing recomputed by hand.

---

## 9. Decisions

The four open questions were resolved on 2026-08-14.

**Q1 — Deposit and Withdrawal, and the receive method. Both.**
Types 2 and 3 mean owner capital in and out. Independently, every cash movement records a **method**: `transfer` (تحويل), `deposit` (ايداع), `cash` (كاش), plus `other`. The list is data-extensible in the same way currencies are — adding one is not a code change. Method is deliberately a field rather than a type, because it is orthogonal to intent: money can arrive by transfer as a credit deposit, as a receivable settlement, or as capital.

**Q2 — A cross-currency credit settlement recognises ordinary trading profit.**
Repaying in a different currency at a rate that differs from cost produces real margin, and it is the same kind of margin as an exchange. It posts to `trading_profit` and appears in the same profit reports. No separate FX gain/loss account.

**Q3 — Partial settlements allocate FIFO, oldest deposit first.**
Automatic, and it is what makes credit aging answerable. Manual allocation is not built; if a specific tranche ever needs to be settled at a specific agreed rate, that is a change request rather than something to guess at now.

**Q4 — A credit balance may go negative. Always allowed.**
Recorded as the owner's decision, against the recommendation to block by default. Section 19 permits it: "unless explicitly allowed", and it is explicitly allowed.

The consequence, stated once and then accepted: over-delivery — sending out more than was ever deposited — is a plausible data-entry error, and nothing will stop it. To keep some of that value without contradicting the decision, a settlement that would take a balance below zero raises a **non-blocking warning**: the operator is told, and may proceed. Nothing is ever refused on these grounds.

---

## 10. Ready to build

With those settled, every posting rule in this document is implementable. Phase 3.2 begins with the chart of accounts and the ledger schema; the posting service follows, then the transaction types in the order they are numbered above.
