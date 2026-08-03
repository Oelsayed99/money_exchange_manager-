# Repository & Architecture Assessment

**Project:** Multi-Currency Financial Management Application
**Repository:** `/Users/omarelsayed/Finance`
**Date:** 2026-08-03
**Status:** Pre-implementation. No code written.

> Supersedes `docs/IMPLEMENTATION_PLAN.md` (deleted — it was a pre-specification draft containing assumptions since corrected, notably a wrong universal-USD base currency).

---

## 1. Confirmed repository state

Findings below are from direct inspection, not estimation.

- **There are no existing implemented features.** Zero of the specification is built.
- **There is no existing database schema.** No migrations, no models, no tables.
- **There is no legacy architecture to preserve.** No framework install, no `composer.json`, no `package.json`, no `.git`.
- **The proposed architecture is a greenfield recommendation**, not a migration path.
- **Repository risks are currently design risks, not discovered implementation defects.** Nothing exists to contain a defect.

Current contents:

```
/Users/omarelsayed/Finance/
└── docs/
    ├── ASSESSMENT.md                     (this file)
    └── adr/0001-frontend-architecture.md
```

The directory was originally named `"Finance "` with a trailing space, which breaks Composer, npm, Docker mounts, and shell scripting. It was empty; it has been renamed to `Finance`.

### Verified host toolchain

| Tool | Version | Note |
|---|---|---|
| PHP | 8.5.7 CLI | Exceeds the 8.3+ floor; see Risk R11 |
| Composer | 2.10.1 | |
| Node | 26.3.0 | |
| npm | 11.16.0 | |
| MySQL | 9.6.0 (Homebrew, arm64) | `mysqladmin ping` → `mysqld is alive` |
| Git | 2.54.0 | |
| PostgreSQL | absent | Not required — spec mandates MySQL |
| Docker | absent | |

### Not part of this project

`/Users/omarelsayed/claude space` is **Telo Studio**, an animated-documentary production system (Node CLI scripts, no database, no web framework). It has zero overlap with this specification and is untouched. Adjacent repos `Sites/yaxigo` (Laravel 13, SQLite, `lang/ar` + `lang/en`), `Sites/portfolio-update`, and `containers/php-auth-admin-system` were inspected only to gauge stack familiarity; no code is shared.

---

## 2. Approved technology stack

Final. Not reopened.

| Layer | Choice |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.3+ |
| Database | MySQL (9.6 local), InnoDB, `utf8mb4` |
| Frontend | React + Inertia.js |
| Types | TypeScript, `strict` mode |
| Styling | Tailwind CSS |
| Components | Reusable shadcn-style primitives |
| Charts | Recharts |
| Backend tests | Pest |
| Frontend tests | Vitest + React Testing Library |
| E2E tests | Playwright |
| Permissions | `spatie/laravel-permission` |
| Static analysis | Larastan/PHPStan, Pint, ESLint |

Livewire is not used. See ADR 0001.

---

## 3. Modular-monolith structure

Modular monolith per Section 16 — not microservices. Controllers thin; financial logic in tested domain services and value objects; one controlled posting service; authorization enforced on the backend.

```
app/
├── Domain/
│   ├── Money/            Money, CurrencyCode, Rate value objects; bcmath arithmetic; rounding
│   ├── Currencies/       Currency registry, precision + rounding settings
│   ├── Accounts/         Accounts, custody locations, per-account currencies
│   ├── Counterparties/   Parties; custody / receivable / payable / credit separation
│   ├── Ledger/           LedgerAccount, LedgerEntry, PostingService, PostingRule registry,
│   │                     BalanceProjector, reversal engine, integrity verifier
│   ├── Transactions/     Transaction, TransactionLeg, draft state machine
│   ├── Exchange/         ProfitMethod strategies, ProfitCalculator, ProfitBreakdown
│   ├── Credit/           Credit deposit, settlement allocation, aging, liability views
│   ├── Notes/            Polymorphic notes, visibility rules
│   ├── Reporting/        Read models, query builders with profit-visibility gating
│   ├── Reconciliation/
│   └── Audit/
├── Http/
│   ├── Controllers/      Thin. No financial calculation.
│   ├── Requests/         Form Requests for all validation
│   ├── Resources/        View models for Inertia props — the serialization boundary
│   └── Middleware/       SetLocale, ShareInertiaProps, theme bootstrap
├── Policies/             One per model; checked in routes AND services
├── Enums/                Transaction types, statuses, account kinds, profit methods
└── Providers/

resources/js/
├── types/                Strict types incl. Money, CurrencyCode, TransactionType
├── lib/                  money.ts (display only), i18n, formatting
├── components/ui/        shadcn-style primitives
├── components/           MoneyInput, MoneyDisplay, DataTable, FilterPanel, ConfirmDialog
├── charts/               Recharts wrappers (RTL-aware)
├── layouts/
└── pages/                Inertia pages

lang/en, lang/ar          Server-side strings, validation, enum labels
tests/Unit, tests/Feature Pest
tests/e2e                 Playwright
resources/js/**/*.test.tsx Vitest + RTL
```

**Hard rules:** Domain code knows nothing about HTTP. React never computes money. No transaction screen ever increments a balance column directly — every write goes through `PostingService`.

---

## 4. Proposed database model

Amounts `DECIMAL(28,10)`, rates `DECIMAL(28,12)`, `utf8mb4` throughout, MySQL strict mode pinned.

| Table | Key columns / purpose |
|---|---|
| `users`, spatie `roles`/`permissions` | Auth and the Section 14 matrix |
| `user_preferences` | `locale`, `theme`, `reporting_currency_id` |
| `currencies` | `code`, `name`, `name_ar`, `symbol`, `decimal_places`, `rounding_mode`, `is_active`, `sort_order`. Admin-managed — adding a currency needs no code change |
| `exchange_rates` | `base_currency_id`, `quote_currency_id`, `rate`, `rate_type`, `source`, `effective_at` |
| `accounts` | Custody locations. `type` enum (11 values incl. `credit_trust`), `owner`, `provider`, `identifier_masked`, `counterparty_id?`, `color`, `icon`, `is_active` |
| `account_currency` | Supported currencies per account |
| `counterparties` | `type`, `phone`, `email`, `country`, `preferred_currency_id` |
| `ledger_accounts` | **Chart of accounts.** `kind` (asset/liability/equity/income/expense), `subkind` (`cash`, `custody`, `receivable`, `payable`, `credit_trust`, `fx_position`, `trading_profit`, `fees_income`, `expense`, `opening_equity`), polymorphic owner, **`currency_id` — single-currency** |
| `transactions` | `type` (20), `status` (draft/pending/posted/reversed), `occurred_at`, `counterparty_id?`, `profit_method`, `profit_currency_id`, `customer_rate`, `cost_rate`, `spread_type`, `spread_value`, `gross_profit`, `fees_charged`, `expenses`, `external_commissions`, `net_profit`, `profit_status`, **`idempotency_key` unique**, `reversal_of_id?`, `posted_by`, `posted_at` |
| `transaction_legs` | `role` (received/delivered/fee/expense/commission), `currency_id`, `amount`, `account_id?`, `counterparty_id?`, `direction`, source/destination |
| `ledger_entries` | **Append-only.** `transaction_id`, `ledger_account_id`, `currency_id`, `direction`, `amount`, `sequence`. No updates, no deletes, no `updated_at` |
| `ledger_balances` | Rebuildable cache: `confirmed_balance`, `available_balance`, `last_entry_id` |
| `credit_settlements` | Settlement → original deposit link; `amount_applied`, `rate_used`. Enables partial and multi-currency settlement |
| `notes` | **Polymorphic.** `notable_type`, `notable_id`, `body`, `visibility` (internal/external), `created_by`, full-text indexed |
| `audit_logs` | Polymorphic; `event`, `old_values`, `new_values`, `user_id`, `ip`, `user_agent` |
| `reconciliations` | `account_id`, `currency_id`, period, `statement_balance`, `ledger_balance`, `difference`, `status` |
| `report_presets` | Saved presets; `filters` JSON |
| `settings` | Global rounding and system configuration |

**Section 5 compliance:** a counterparty carries **four independent balance buckets per currency** — custody, receivable, payable, credit/trust — as distinct `ledger_accounts`. Never one combined column. Custody and credit/trust are mirrors: custody is your asset held by them; credit/trust is your liability holding theirs.

---

## 5. Ledger and transaction-posting architecture

### Per-currency balancing — no universal base currency

Section 2 requires historical transactions to be immune to later rate changes. Any stored base-currency conversion violates that the moment a rate moves. The resolution:

> **One ledger per currency. Every transaction balances independently within each currency it touches.**

Every ledger account is single-currency. A currency exchange posts through paired **FX position** accounts:

```
Received leg:   DR  Cash·AED          3,670.00     CR  FX-Position·AED   3,670.00
Delivered leg:  DR  FX-Position·USD   1,000.00     CR  Cash·USD          1,000.00
```

The AED ledger balances. The USD ledger balances. **No exchange rate participates in the integrity check.** That is precisely why a posted transaction can never drift when rates change, and why no base currency is needed for correctness.

**Reporting currency is a presentation-time choice.** When converted totals are requested the user selects the reporting currency and rate set; conversion happens in the read model and is labelled as converted-at-a-rate. No converted value is ever persisted.

### Posting service

`PostingService` is the single write path. A `PostingRule` per transaction type maps a draft to ledger entries.

- **Invariant, enforced in service and by `ledger:verify`:** for every `(transaction, currency)`, `sum(debits) === sum(credits)`.
- Wrapped in a database transaction. `SELECT … FOR UPDATE` on affected `ledger_balances`, locked in deterministic id order to avoid deadlocks.
- **Idempotency:** unique `idempotency_key`; a replay returns the original result rather than posting twice.
- **State machine:** draft → pending → posted → reversed. Drafts deletable per permission. Posted transactions never hard-deleted.
- **Reversal:** a new compensating transaction referencing `reversal_of_id`. History is never rewritten.
- **Balances:** `ledger_balances` updated inside the same DB transaction. `ledger:rebuild` recomputes from entries alone; `ledger:verify` compares cache to projection. The ledger is the source of truth; the cache is disposable.
- **Confirmed vs. available:** available = confirmed − pending outflows.

Per Section 7, the **posting rules for all 20 transaction types are written and reviewed as a document before any Phase 3 code**, covering which buckets each type touches, reversal behaviour, pending/partial settlement effects, concurrency control, and cache reconciliation.

---

## 6. Money precision and rounding strategy

- **Storage:** MySQL `DECIMAL` — exact, never `FLOAT`/`DOUBLE`. Amounts `DECIMAL(28,10)`, rates `DECIMAL(28,12)`.
- **Arithmetic:** a `Money` value object over `bcmath`. Raw `+`/`*` on monetary values is banned and caught by static analysis.
- **Per-currency precision:** `currencies.decimal_places` and `currencies.rounding_mode` (default half-up) are data, not code — satisfying "define precision independently for each currency" and admin-extensibility together.
- **Rounding boundary:** round only when persisting a user-facing amount and when displaying. Intermediate rate math retains full scale. Both calculation **inputs and outputs** are stored (Section 3).
- **Transport — the critical rule:** money crosses the Inertia/HTTP boundary as **strings**. `type Money = { amount: string; currency: CurrencyCode }`. JavaScript's `number` is float64; serializing money as a JSON number corrupts it regardless of a perfect database column.
- **Frontend:** `money.ts` formats and parses for **display only**. No financial computation in React (Section 16).

---

## 7. Profit calculation architecture

`ProfitMethod` enum with one strategy class each: `RATE_DIFFERENCE`, `FIXED_AMOUNT`, `PERCENTAGE`, `MANUAL`, `NONE`.

Each strategy takes an input DTO (delivered amount and currency, received currency, customer rate, cost rate, spread type and value, fees, expenses, commissions, profit currency) and returns a `ProfitBreakdown` DTO — customer value, cost value, gross profit, fees, expenses, commissions, net profit, profit currency, and a line-by-line explanation for the UI.

Formulas per Section 3:

```
Customer Value = Delivered × Customer Rate
Cost Value     = Delivered × Cost Rate
Gross Profit   = Customer Value − Cost Value
Net Profit     = Gross Profit + Fees Charged − Expenses − External Commissions
```

Worked example from the specification: `1,000 × 3.67 − 1,000 × 3.65 = 20.00 AED` gross.

**The `0.02` ambiguity is designed out of the UI.** An explicit `spread_type` selector — *rate spread per unit* / *percentage* / *fixed amount* — is required. There is never a bare numeric field whose meaning must be inferred. This prevents silent 100×-scale profit errors.

**Live preview is computed server-side.** Section 3 requires a live profit preview before saving. If React computed it, there would be two implementations of profit math free to diverge. A debounced preview endpoint returns a `ProfitBreakdown` from the same strategy classes that will run at posting, so preview and persisted value are guaranteed to agree.

Also: estimated vs. finalized profit is an explicit `profit_status`, labelled in the UI. Negative profit is supported, with a warning and a confirmation step before saving an unexpected loss. Recalculation of posted transactions is blocked by guard and by test. The breakdown component renders the **stored** breakdown, never a recomputation.

---

## 8. Authorization and hidden-profit enforcement

`spatie/laravel-permission`, one Policy per model, `can:` route middleware, plus Gate checks inside domain services — defence in depth, so an unguarded future route cannot bypass authorization. Permissions include the Section 14 credit set (view credit accounts, manage credit accounts, settle credit, view liability reports) alongside profit-visibility, reporting, and export permissions.

**Hidden-profit reports are enforced at three layers, per Section 16 and correction 4:**

1. **Query layer.** Report builders take a `ProfitVisibility` object. When profit is hidden, profit columns are never selected and profit joins are never made. The data does not enter the process.
2. **Serialization layer.** View models omit profit keys entirely — absent, not `null`. Absence prevents inference; a `null` still reveals a field exists.
3. **Export layer.** PDF, Excel, and CSV exporters consume the same view models, so the omission is inherited rather than reimplemented.

**Explicitly not relied upon:** hiding profit with a React conditional. Inertia serializes page props into the HTML document — anything fetched is readable in devtools regardless of what renders. Regression tests assert that no profit key appears in Inertia page props and that no profit value appears in raw export bytes for a hidden-profit user.

---

## 9. Localization and RTL strategy — from Phase 1

Section 12 states Arabic compatibility must not be postponed. Concretely, from the first commit:

- **Server:** `lang/en` and `lang/ar` for validation messages, statuses, transaction types, enum labels.
- **Client:** translations delivered via Inertia shared props with a typed `t()` helper. A lint rule bans literal user-facing strings in JSX, so hardcoded English fails CI rather than accumulating.
- **Direction:** `dir` and `lang` set on `<html>` from the user's locale. **Logical Tailwind properties only** — `ms-`/`me-`/`ps-`/`pe-`/`start-`/`end-`/`text-start`/`text-end`. A lint rule rejects `ml-`, `mr-`, `pl-`, `pr-`, `left-`, `right-`, `text-left`, `text-right`.
- **Real RTL, not right-aligned text:** tables, filter panels, dialogs, drawers, navigation, and Recharts axes/legends are direction-aware. Directional icons mirror.
- **Formatting:** dates and numbers via `Intl` with the active locale. Currency shown with symbol and ISO code. Arabic-Indic numerals offered as a user preference.
- **Language switch constraints (Section 12):** switching must not break the page, lose active filters, reset pagination unnecessarily, or discard unsaved form data without warning. Implemented as a locale swap that re-renders against the same Inertia props, with a router guard on dirty forms — not a navigation that resets state.
- **Data integrity:** `utf8mb4` from the first migration. Retrofitting after Arabic data exists is a lossy migration. Exports verified in Phase 6 — CSV with BOM, PDF with an Arabic-capable font such as Noto Naskh Arabic.
- **Testing:** every shared component is tested in both directions from Phase 1.

The final Arabic/RTL audit still happens in Phase 7, but it audits a system already built bidirectionally.

---

## 10. Theme strategy

- Three modes: light, dark, **system — the default**.
- Preference persisted to `user_preferences.theme` and mirrored to `localStorage`.
- **No theme flash.** A small blocking inline script in the Blade root template applies the theme class to `<html>` before first paint. This must live in the server-rendered template — a React effect runs after paint and the flash is visible.
- Tailwind `darkMode: 'class'`, with CSS custom properties for semantic tokens (surface, border, text, positive, negative, warning, info) so shadcn-style components theme uniformly.
- **Status colours (Section 13):** green for confirmed positive results, red for losses and dangerous negatives, amber for warnings/drafts/pending/reconciliation issues, blue or neutral for normal activity.
- **Never colour alone.** Every status carries a text label and an icon. Required by Section 13 and by accessibility. Contrast verified in both themes.

---

## 11. Testing architecture

| Layer | Tool | Coverage |
|---|---|---|
| Backend unit | Pest | Money arithmetic, per-currency rounding, each profit strategy, spread-type disambiguation |
| Backend feature | Pest | Posting invariants, reversals, idempotency replay, concurrent posting, authorization matrix, hidden-profit at query + serialization |
| Frontend unit | Vitest + React Testing Library | Components, money display/parse, form validation UX, **both text directions**, theme rendering |
| E2E | Playwright | Exchange with live profit preview; credit deposit → partial multi-currency settlement; language switch preserving filters and pagination; theme persistence without flash; profit-hidden export |
| Static | Larastan, Pint, `tsc --strict`, ESLint | Bans float money arithmetic, physical CSS properties, literal JSX strings |

**Backend tests run against MySQL, not SQLite.** SQLite's type affinity hides `DECIMAL` precision loss and does not enforce the same constraints — a financial ledger must be tested on the engine that runs it.

Section 20 requirements — credit deposit flow, credit settlement flow, multi-currency repayment, partial settlement logic, liability accuracy — are explicit named test suites.

Per Sections 21 and 22: actual pass/fail output is reported. No command or test is ever described as executed unless it was. When something cannot run in this environment, that is stated with the exact command to run locally.

---

## 12. Implementation phases

Section 23 order. Section 22 governs every phase: state goal → list affected files → explain decisions → implement one small coherent feature → add tests → run formatting and static analysis → run tests → report real results → update docs → stop before beginning an unrelated large phase.

| Phase | Contents |
|---|---|
| **1 · Foundation** | Laravel 12 + Inertia + React + strict TS + Tailwind + shadcn primitives. Auth, roles and permissions, currencies, per-currency precision and rounding settings, user language and theme preferences, shared UI system, audit foundation, **polymorphic notes**, Money value object and the string-transport boundary, localization + RTL primitives, system-default theme with no flash |
| **2 · Accounts & Parties** | Accounts and custody locations, account currencies, counterparties, **custody / receivable / payable separation**, opening balances |
| **3 · Transactions & Ledger** | *Posting-rules document first.* Then drafts, legs, posting service, ledger entries, confirmed vs. available balances, duplicate-submission protection, reversals, `ledger:rebuild` / `ledger:verify`. Credit Deposit and Credit Settlement land here as transaction types. Minimal UI, maximal tests — **the critical phase** |
| **4 · Exchange & Profit** | Customer rate, cost rate, spread, fixed and percentage profit, fees, expenses, commissions, server-computed live preview, loss warning, profit authorization |
| **5 · Dashboard & Reports** | Cards, filters, tables, Recharts visualizations, saved report presets, internal profit reports, **profit-hidden external reports**, customer and account statements, credit liability/aging/exposure views |
| **6 · Export & Reconciliation** | PDF, Excel, CSV, reconciliation workflow, balance rebuilding and verification, audit improvements, Arabic export rendering |
| **7 · Quality & Release** | Complete automated testing, accessibility review, security review, performance review, Arabic and RTL review, deployment documentation, backup and recovery documentation |

Notes ship in Phase 1 so every later screen gets them natively rather than eleven screens being reopened. Localization, accessibility, authorization, and audit are cross-cutting across all phases, per Section 23.

---

## 13. Explicit assumptions

Each is flagged for override. None is a silent default.

| # | Assumption |
|---|---|
| A1 | **Laravel 12** per the approved stack. (An earlier suggestion of Laravel 13 is withdrawn.) |
| A2 | **MySQL 9.6**, strict mode verified and pinned, `utf8mb4` / `utf8mb4_0900_ai_ci` |
| A3 | Amounts `DECIMAL(28,10)`, rates `DECIMAL(28,12)`, per-currency scale enforced in the domain layer. Chosen over integer minor units because currencies differ in exponent and rate math needs more precision than any single minor unit provides. Section 3 permits either |
| A4 | Money serialized as **strings** across every HTTP boundary and export |
| A5 | **No default reporting currency.** The user selects one whenever converted totals are required; conversion is never persisted |
| A6 | Exchange rates entered manually, with a provider-fetch adapter left as a seam. No external rate API without approval |
| A7 | Single tenant, multi-user. No organization partitioning |
| A8 | Soft deletes on reference data only (accounts, counterparties). Never on ledger entries or posted transactions |
| A9 | Note attachments are schema-ready but not implemented — Section 4 calls them a future extension |
| A10 | Credit balances cannot go negative by default, overridable per account by a permissioned user (Section 19) |
| A11 | Internal system behind authentication. Sanctum present but no public API surface in v1 |
| A12 | **Deferred to Phase 3/4, not assumed now:** cross-currency settlement FX treatment, and settlement allocation order (FIFO vs. manual). Raised when posting rules are drafted |

---

## 14. Risks

Design risks. Nothing is implemented, so none of these is a discovered defect.

| # | Sev | Risk | Mitigation |
|---|---|---|---|
| R1 | Critical | Float contamination — JS `number` is float64, so money as a JSON number corrupts regardless of the DB column | Money as strings; `Money` value object both sides; lint + tests |
| R2 | Critical | A universal base currency would break Section 2 rate-independence | Per-currency balancing; reporting currency is presentation-only |
| R3 | Critical | Profit leaking through exports, Inertia props, or devtools | Query + serialization + export enforcement; regression tests on raw bytes and page props |
| R4 | Critical | Custody / receivable / payable conflation — invisible until a party both owes and holds, then every statement is wrong | Four separate ledger buckets per counterparty per currency |
| R5 | High | Concurrency on cached balances | Row locking in deterministic order; rebuildable projection |
| R6 | High | Duplicate submission posting an exchange twice | Unique idempotency keys; replay returns original |
| R7 | High | `0.02` read as 2% instead of a rate spread | Explicit `spread_type` selector; no bare numeric field |
| R8 | High | Arabic data corruption | `utf8mb4` from the first migration |
| R9 | Medium | MySQL non-strict mode silently truncating | **Verified 2026-08-03.** `@@sql_mode` includes `STRICT_TRANS_TABLES`; `@@default_storage_engine` is InnoDB; Laravel's `'strict' => true` adds session-level modes |
| R10 | Medium | Secrets in version control | **Verified 2026-08-03.** `git check-ignore` confirms `.env`, `vendor`, `node_modules`, `public/build` are all ignored, checked before the first commit |
| R11 | Medium | **Laravel 12 on PHP 8.5.7** — 8.5 is newer than Laravel 12's original target range; dependency deprecations possible | **Resolved 2026-08-03.** Laravel 12.64.0 installs, migrates, and tests clean on PHP 8.5.7; `composer check-platform-reqs` all green. One real deprecation found (`PDO::MYSQL_ATTR_SSL_CA` in stock `config/database.php`) and fixed with a version-safe shim — see ADR 0002 |

---

## Remaining blockers

**None.** The frontend architecture question is resolved by ADR 0001. Every other open item is scheduled to a phase (A12) rather than blocking the start.
