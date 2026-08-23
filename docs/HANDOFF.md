# Project Handoff

**Written:** 2026-08-16 · **At commit:** `d3e86f0` · **Local and remote in sync.**

---

## 1. What is being built

A multi-currency financial management application for a money-exchange business: currency exchanges, credit/trust holdings, transfers between custody locations, counterparty balances, profit tracking, and reporting — in English and Arabic with full RTL.

**Repository:** `/Users/omarelsayed/Finance` → `git@github.com:Oelsayed99/money_exchange_manager-.git` (private; note the trailing hyphen in the name — legal but likely a slip, renaming was offered and not taken up).

**Authoritative specification:** supplied by the owner across two messages, 24 sections. Not stored in the repo. `docs/ASSESSMENT.md` is the Section 24 assessment derived from it; `docs/posting-rules.md` is the agreed Section 7 design.

**Real-world input:** the owner supplied a screenshot of the spreadsheet this replaces — a per-counterparty EGP statement for سالم التجريبي. It drives several tests. Key figures: nine credit deposits totalling **3,957,540**; a settlement of **2,574,000** leaving **1,383,540**; deliveries at rates 51.48, 51.48, 51.48, 50.8. Its single signed running-balance column flips between `(899,510)` and `50,490` — the exact problem the four-bucket model solves.

---

## 2. Stack (final, not to be reopened)

Laravel 12.64 · PHP 8.5.7 · MySQL 9.6 · mPDF · React 19 + Inertia 2 · TypeScript strict · Tailwind 4 · shadcn/ui · Recharts (installed, unused) · Pest · Vitest + RTL · Playwright (configured, **no browsers installed, zero e2e tests**) · spatie/laravel-permission · Larastan level 8.

Recorded in `docs/adr/0001-frontend-architecture.md`. An earlier Livewire choice was superseded once Section 16 arrived.

---

## 3. Architecture — the load-bearing decisions

### Money
- Decimal strings over **bcmath**, never floats. `DECIMAL(28,10)` amounts, rates to 12 dp.
- **Nothing rounds.** Removed on owner instruction. `Decimal::round()` no longer exists. Addition, subtraction and multiplication are exact; multiplication **throws** `PrecisionLoss` rather than discard a digit. Division **truncates** (mathematically unavoidable) and never rounds.
- Display pads to the currency's precision, never cuts: USD `1000` → `1000.00`, but `1000.123456` stays in full.
- Money crosses every boundary as a **string** (risk R1). JS `number` is float64.
- `Money` carries no conversion. Cross-currency add/subtract/compare throws `CurrencyMismatch`; `equals()` returns false instead.

### The ledger
- **Per-currency balancing, no base currency.** Every transaction balances independently within each currency it touches, checkable with **no exchange rate involved** — which is why a posted transaction cannot drift when rates move.
- Exchanges join currencies through **`fx_position` clearing accounts**. Once profit is recognised these net to zero when valued at cost — a standing correctness check, tested.
- Entries are **append-only**, enforced by MySQL triggers *and* the model. Corrections are reversals, never edits.
- Balances are **derived**; `ledger_balances` is a rebuildable cache. `ledger:rebuild` / `ledger:verify`.
- `PostingService` is the only writer. Locks in ascending ledger-account id order; idempotency keys; wrapped in a DB transaction.
- **Available balance excludes pending inflows.** Promised money is not spendable.
- A **reversed** transaction keeps its entries and keeps counting; the reversing entries cancel it. Removing it too would cancel twice.

### Counterparties — Section 5
Four independent buckets per party per currency, never netted: `custody` / `receivable` (assets), `payable` / `credit_trust` (liabilities). Custody and credit_trust are mirrors. Keyed uniquely on (counterparty, bucket, currency) so **there is nowhere to put a combined figure**. Negative positions refused with a message naming the mirror bucket.

### Audit
Append-only `audit_logs`, DB triggers, actor stored twice (`user_id` **without** FK, plus `actor_label` snapshot) so the trail outlives the user. Secrets **redacted not omitted** — a password change is recorded, its value is not. Account identifiers likewise.

### Owner decisions on the four open questions (recorded in posting-rules §9)
1. **Both** a `method` field (تحويل/ايداع/كاش/cheque/other) **and** Deposit/Withdrawal meaning owner capital.
2. Cross-currency credit settlement recognises **ordinary trading profit**.
3. Partial settlements allocate **FIFO**.
4. Credit balances **may go negative — always allowed** (against recommendation; owner's call). A non-blocking warning is retained, and is now **implemented** on the movements screen (ADR 0015).

### Owner decisions on profit visibility (2026-08-18)

Asked because §23's "profit authorization" was ambiguous and was the last item blocking Phase 4.

5. **"Me mode" / "client mode" is a toggle the owner flips**, not a permission. Anyone who can open a counterparty can switch to me-mode and see profit. The toggle decides which version prints.
6. **§23 "profit authorization" means nothing beyond that.** No maker-checker, no approval queue, no approver role. **Phase 4 is closed.**

Even so, hidden-profit is enforced at the **query** layer, not by a React conditional: Inertia serialises props into the document, so a figure hidden in the component is still in the page source and in the PDF's source data. The split also means a `profit.view` permission, if ever wanted, is a small change rather than a rewrite.

---

## 4. Things tried that failed, and why

Recorded so they are not retried:

| Attempt | Why it failed |
|---|---|
| DomPDF for statements | No complex text shaping: Arabic renders as isolated, unjoined letters. Replaced by mPDF before it was written. |
| mPDF layout tables in RTL | Laid out shrink-to-fit and centred, pulling both ends of the header and footer into the middle of the page. Force `dir="ltr"` on layout tables and place the cells by hand; widths must be in the `<style>` block, not attributes. |
| Searching PDF bytes for a profit figure | mPDF subsets the fonts, so text becomes glyph ids in a private encoding. The search finds nothing whether the figure is there or not — a test that passes while asserting nothing. Assert on `StatementPdf::html()` instead. |
| `--env=testing` to target the test DB | No `.env.testing` exists; it silently used the default env and **wiped the dev database twice**. Correct form: `DB_DATABASE=finance_test php artisan …` |
| Concurrency test under `RefreshDatabase` | Holds an open transaction, so a second connection sees nothing. Blocked 50s then failed. Moved to `tests/Integration` with `DatabaseTruncation`. |
| `app()->runningInConsole()` to detect console vs HTTP | **True under PHPUnit.** Cannot distinguish an artisan command from a test request. |
| `REQUEST_URI` as the same signal | Populated (`/`) even in a test making no request. |
| → resolved | `request()->route() !== null` is the honest signal. |
| Chained `expectsOutputToContain` | Reported a string missing that dumping proved present. Replaced with `Artisan::call()` + `Artisan::output()`. |
| `toThrow(Throwable::class)` | Pest uses `class_exists()`; `Throwable` is an **interface**, so it was treated as a message substring and asserted nothing. |
| `MoneyCast` with `attach()` | Eloquent casts extra pivot attributes **in isolation**, before foreign keys merge, so `currency_id` is unavailable. Cast now accepts a plain decimal there and **refuses a `Money`**; `Account::setOpeningBalance()` is the sanctioned path. |
| `interface` for Inertia `useForm` types | Needs an implicit index signature — use `type` aliases. |
| `??` in a test helper for "explicitly null" | Cannot express it; use `array_key_exists`. |
| Physical CSS (`ml-auto`) with RTL | Broke the login layout. Logical properties only, enforced by an ESLint rule (vendored `components/ui/**` exempted as known debt). |
| React-effect theme application | Runs after first paint → flash. Must be a blocking script in the Blade head. |
| A page test living beside its page | `app.tsx` globbed `./pages/**/*.tsx`, so `create.test.tsx` was **bundled into production assets** and resolvable as a page. Found by reading `vite build` output, not by any test. |
| Excluding it with a negative glob (`['./pages/**/*.tsx', '!./pages/**/*.test.tsx']`) | Fixed the bundle and broke something worse: **Vite stops tracking the glob for new files**, so any page added while the dev server runs 404s until it is restarted. Cost two debugging sessions. Page tests now live in `resources/js/tests/`, and the glob is a single pattern again. Guarded by `resources/js/tests/no-tests-in-pages.test.ts`. |
| Charting exact decimals | Recharts needs numbers for SVG coordinates. Resolved by plotting `Number(amount)` for geometry only and rendering every visible figure from the exact string. |

---

## 5. Current status

**Phases 1 to 5 complete.**

| Phase | State |
|---|---|
| 1 Foundation | ✅ auth, roles/permissions, currencies, precision, locale+theme prefs, shared UI, audit |
| 2 Accounts & parties | ✅ accounts, account currencies, counterparties, four-bucket separation, opening balances, **screens** |
| 3 Transactions & ledger | ✅ drafts, legs, posting rules (17 of 19 wired), posting service, entries, confirmed/available, idempotency, reversals, rebuild/verify, real concurrency test |
| 4 Exchange & profit | ✅ rates, spread, fees/expenses, live preview, exchange screen, profit visibility resolved |
| 5 Dashboard & reports | ✅ rate-driven entry, statement, PDF, dashboard + charts, transaction list |
| 6 Export & reconciliation | 🚧 CSV, reconciliation, movements, audit screen done; **xlsx is the only item left, and may not be wanted** |
| 7 Quality & release | 🚧 baseline cleared, route guard test, backup/recovery; reviews and remaining docs outstanding |

**Tests: 807 backend (2,026 assertions) + 50 frontend.** PHPStan level 8, Pint, tsc, ESLint, Prettier all clean. `ledger:verify --transactions` clean.

**Screens that exist:** login/register/settings, dashboard (placeholder), Currencies, Accounts, Counterparties, Exchange. All bilingual EN/AR with working RTL.

---

## 6. Known gaps, debt and blockers

**No blockers.** One open question (below). Debt:

- **Duplicate ADR numbers**: `0005-no-rounding` + `0005-audit-trail`, and `0006-roles-and-permissions` + `0006-accounts-and-the-money-cast`. Commit messages reference them, so renaming needs care. Numbering resumes at 0008 and is correct from there.
- **`om.he.els@gmail.com` no longer exists** — destroyed by my `migrate:fresh`. Only `test@example.com` (administrator) remains.
- **Playwright**: configured, no browsers, no e2e tests.
- **Vendored `components/ui/**` still uses physical CSS properties** — RTL debt, exempted from lint.
- **No notes module** (Section 4 polymorphic notes) — deferred repeatedly; accounts and counterparties have no notes.
- **Two transaction types unwired**: `CurrencyExchange` is handled by `ExchangeService` not `PostingRules` (by design); `Reversal` only via `PostingService::reverse()` (by design).
- **An exchange settled in cash does not appear on the counterparty's statement**, even with the party recorded on the transaction, because no entry touches their accounts. Correct by design (ADR 0009) but worth knowing before someone reports it as a bug.
- **Drafts have no UI.** Service-level only.
- ~~No way to record anything but an exchange~~ — fixed by the movements screen (ADR 0015).
- **Validating a draft creates ledger accounts** it would use — documented trade-off, leaves empty accounts if discarded.
- **Auth pages still hardcoded English** (they lay out correctly in RTL).
- Owner edits on GitHub web UI have caused divergence once; resolved by rebase, not force-push.

---

## 7. Configuration assumptions (no secrets)

- `.env` is gitignored and verified absent from the pushed tree. `APP_KEY` exists locally only.
- `DB_CONNECTION=mysql`, `DB_DATABASE=finance`, `DB_USERNAME=root`, empty password, `utf8mb4` / `utf8mb4_0900_ai_ci`.
- Test DB `finance_test`, configured in `phpunit.xml`. **Tests run against MySQL, never SQLite** (ADR 0002).
- Dev server: `php artisan serve --port=8090`. **Ports 8000, 8001, 8080, 8085 belong to the owner's other projects — do not touch.**
- Login for testing: `test@example.com` / `password`.
- Git: SSH already authenticated as `Oelsayed99`. Commit email corrected to `oelsayed314@gmail.com` (was `gmaill.com`, a typo — the owner's three other repos still carry it in history).
- `gh` CLI is **not** installed.
- Owner runs macOS; `brew`-installed MySQL; no Docker.
- **Backups:** `php artisan db:backup --verify` writes to `storage/backups` (gitignored, 0600). Same disk as the database — offsite copies are the owner's to arrange. See `docs/BACKUP.md`.

---

## 8. Working agreement

- Section 22 workflow per step: state goal → list files → explain decisions → one coherent feature → tests → format/static analysis → run tests → **report real pass/fail** → update docs → stop.
- Never claim a command ran unless it did.
- Owner reviews at step boundaries and says "continue"; commit + push when asked (they have asked at every step so far).
- Every ADR-worthy decision goes in `docs/adr/`.
- Bilingual from the first commit of any screen — never retrofitted.

---

## 9. Exact next steps

The owner restated the product in their own words on 2026-08-18, and it maps to four pieces of work. The first is done; the rest are in order.

1. ✅ **Rate-driven deal entry.** Type one amount plus the rate, get the other. See ADR 0008.
2. ✅ **The client statement.** `GET /counterparties/{id}/statement`, currency + mode + date filters in the URL, labelled positions, me/client toggle enforced at the query. See ADR 0009.
3. ✅ **PDF of that statement, in either mode.** `GET /counterparties/{id}/statement/pdf`, same query string as the screen. mPDF, chosen because DomPDF cannot shape Arabic. See ADR 0010.
4. ✅ **Dashboard.** Filters by client, period, currency and status, all in the URL. Four statuses, not three — *mixed* is real and common. Positions are current; the dates narrow what moved. See ADR 0011.

5. ✅ **Dashboard statistics.** Margin by month, in-and-out by month, where clients stand (a count, so it needs no currency), and largest positions with both sides kept apart. See ADR 0011.
6. ✅ **Transaction list.** `GET /transactions`, read-only, filters by type, status, client, currency, period and a reference/notes search. See ADR 0012.

**Phase 5 is complete. Phase 6 is under way.**

7. ✅ **CSV export** for the statement and the transaction list, sharing one `Exportable` layer with the PDF so the client-copy omission is inherited rather than re-decided. BOM for Arabic, formula injection neutralised. See ADR 0013.

8. ✅ **Reconciliation.** `GET /reconciliations`. Records a count against the ledger as of a day, never writes a balance, freezes its figures, and surfaces drift when something is backdated past a completed count. See ADR 0014.

9. ✅ **Recording movements.** `GET /movements`. Every type the ledger supports except exchange and reversal, with the counterparty's four positions shown live and the negative-credit warning finally built. See ADR 0015.

10. ✅ **Audit trail screen.** `GET /audit`, administrators only. The trail was written since Phase 1 and readable from nowhere. See ADR 0016.

Remaining in Phase 6:
- **A spreadsheet (xlsx) writer** — the only item left, and **the owner has not confirmed they want it**. It slots in beside `CsvWriter` against the same `Exportable`, but costs a dependency (openspout is the lean choice; maatwebsite/excel pulls in PhpSpreadsheet) for a format the CSVs already cover — Excel opens them correctly, Arabic included, because of the BOM. Ask before building.

**Phase 6 is complete** — xlsx was skipped on the owner's decision (the CSVs open in Excel correctly, BOM and all).

**Phase 7 is under way:**
11. ✅ **Static-analysis baseline cleared.** Level 8 with nothing exempted; the last baselined entry was hiding a broken contract. See ADR 0017.
12. ✅ **Route guard test.** Every route is authenticated or listed as public with a reason; unused file-serving routes removed. See ADR 0018.

13. ✅ **Backup and recovery.** `db:backup --verify` and `db:restore`, plus `docs/BACKUP.md`. Verification caught that every backup would have been unrestorable. See ADR 0019.

Remaining in Phase 7: accessibility review, Arabic/RTL review, performance review, deployment documentation, and either using Playwright or removing it.

**The nightly schedule belongs on the production server, not the owner's Mac.** The local database holds test data only (1 counterparty, 16 transactions); backing it up nightly protects nothing, and the owner said so. `docs/BACKUP.md` is now written server-first. Deployment is the next thing it depends on.

Then Phase 7 (quality and release). Worth pulling forward: **burning down the PHPStan baseline** (20 inherited errors) and either using or removing Playwright.

Still worth pulling forward at some point: a **transaction list screen**, since the ledger has no UI of its own. The client statement covers most of the need.
