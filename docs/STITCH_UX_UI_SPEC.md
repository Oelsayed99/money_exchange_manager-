# MonyMonk — Complete UX/UI Specification for Stitch AI

## 1. Product summary

MonyMonk is a bilingual English/Arabic financial operations web application for money-exchange offices. It records currency exchanges, money in/out, transfers, fees, expenses, client balances, physical/account balances, profit, statements, reconciliation, and an immutable audit trail.

The UI must feel like a serious daily operations tool: fast, compact, calm, trustworthy, and easy to scan. It is not a generic analytics dashboard and not a consumer banking app.

### Core accounting concepts the UI must communicate

- Every amount always displays with a currency code.
- Different currencies are never added together.
- One counterparty has one signed running balance per currency: positive means they owe the business; negative means the business owes/holds money for them.
- The ledger is append-only. Posted transactions cannot be edited; errors are corrected by reversals.
- Internal profit is visible only in “My copy” statement mode and excluded from “Client’s copy.”
- Reconciliation asks for the physical count before revealing the ledger’s expected amount.
- The entire product supports English LTR and Arabic RTL. In Arabic, the sidebar moves to the right and layouts mirror logically.

## 2. Users and permissions

- Administrator: full access, including audit trail and management screens.
- Operator/accountant: records exchanges and movements, views operational pages based on permissions.
- Navigation items are hidden when the user lacks permission; protected URLs remain guarded.

## 3. Global application shell

### Desktop

- Collapsible left sidebar in English; right sidebar in Arabic.
- Sidebar header: MonyMonk logo/wordmark, linking to Dashboard.
- Main navigation with icon + label:
  1. Dashboard
  2. Record
  3. Transactions
  4. Reconciliation
  5. Accounts
  6. Counterparties
  7. Currencies
  8. Audit trail (admin only)
- “Record” opens Currency Exchange by default and treats Record a Movement as the same navigation group.
- Sidebar footer: language switcher, then user avatar/name menu.
- User menu: Settings and Log out.
- Main content: top header with sidebar toggle and breadcrumbs, then page content.

### Mobile/tablet

- Sidebar becomes a slide-over sheet.
- Header contains menu trigger, compact breadcrumbs/title, and user access.
- Tables remain horizontally scrollable rather than crushing financial columns.
- Forms collapse from multi-column grids into a single column.
- Primary submit buttons become full width where appropriate.

### Global components

- Breadcrumbs.
- Page heading: H1 plus short explanatory sentence.
- Flash success/error message.
- Buttons: primary, outline, ghost, destructive, icon-only; loading spinner and disabled state.
- Inputs: text, email, password, search, number, date, currency amount, select, checkbox.
- Inline validation message directly below the relevant field.
- Money display: tabular numerals, amount + currency, optional explicit plus/minus sign.
- Status badges: active/inactive, posted/pending/draft/reversed, balanced/difference/explained, and counterparty relationship status.
- Empty state: bordered quiet panel with a short message.
- Loading, error, success, empty, disabled, focus, hover, and validation states for every interactive element.

## 4. Visual direction

- Tone: premium bookkeeping workstation; precise and calm, not flashy fintech.
- Light and dark modes, plus “System.”
- Neutral warm-gray or slate surfaces, crisp 1px borders, restrained shadows.
- One strong primary accent (deep teal, forest, or ink blue).
- Semantic colors: emerald for inflow/positive success, amber for outflow/warnings, red for loss/destructive/error, muted gray for neutral/empty.
- 8px spacing system; 10–12px card radius; compact table density.
- Typography: modern highly legible sans serif with excellent Arabic companion; tabular numerals for money, dates, and rates.
- Icons: simple Lucide-style outline icons.
- Avoid glassmorphism, gradients, oversized marketing cards, and decorative charts.

## 5. Public landing page (`/`)

### Header

- Brand logo/wordmark.
- Right-side actions: Open dashboard if signed in; otherwise Sign in and Sign up.

### Hero

- Headline: “The books an exchange office actually keeps.”
- Supporting text explaining money in/out and one balance per client per currency.
- Primary CTA: Start free / Open the dashboard.
- Secondary CTA: Sign in.
- Trust note: “No card. Your books are yours and nobody else can see them.”

### Six feature cards

Two or three columns depending on viewport, each with outline icon, title, and paragraph:

1. Record money in and out.
2. One directional client balance per currency.
3. Record one currency while actual cash moved in another.
4. Client-ready statements and internal profit copy.
5. Immutable ledger/audit trail.
6. Complete Arabic and English support.

### Privacy panel and footer

- Full-width bordered privacy statement about each business having separate books.
- Footer: “MonyMonk [year] — books for exchange offices.”

## 6. Authentication pages

All auth screens use a centered branded card or simple narrow layout, a title, description, language switcher, inline errors, password visibility/browser affordances, and social sign-in buttons when configured.

### Login (`/login`)

- Continue with Google.
- Continue with Apple.
- “or” separator.
- Email address input.
- Password input.
- Forgot password link aligned with password label.
- Remember me checkbox.
- Full-width Log in button.
- Footer link: “Don’t have an account? Sign up.”

### Register (`/register`)

- Name.
- Business name.
- Email address.
- Password.
- Confirm password.
- Full-width Create account button.
- “Already have an account? Log in.”

### Forgot password (`/forgot-password`)

- Email input.
- Email password reset link button.
- Back to login link.
- Success status message after email is sent.

### Reset password

- Read-only/prefilled email.
- New password.
- Confirm password.
- Reset password button.

### Confirm password

- Security explanation.
- Password input.
- Confirm password button.

### Verify email

- Instructional copy.
- Green success message after resending.
- Resend verification email button.
- Log out link.

## 7. Dashboard (`/dashboard`)

### Header and filters

- H1 “Dashboard”; subtitle “Where everything stands, and what moved.”
- Filter toolbar, responsive and URL-driven:
  - Client select: All + counterparties.
  - Currency select: All + active currency codes.
  - Status select: All, They owe us, We owe them, Both ways, Settled.
  - From date.
  - To date.
  - Clear filters ghost button.
- Helper note: current positions are current; dates filter activity, not position.
- Market reference strip/card: reference exchange rates, published timestamp, and explicit “reference only, not used in any deal.”

### Currency summary cards

Render one grouped summary panel per currency. Each panel clearly labels the currency and contains six compact metric cells:

- Cash on hand.
- Owed to us.
- Owed to them.
- In from clients.
- Out to clients.
- Margin earned (signed).

Do not combine currency totals. Use tabular numerals and tooltip/help copy for accounting meaning.

### Analytics section

Four bordered chart cards:

1. Margin by month — one-currency bar chart with exact-value tooltip.
2. In and out by month — paired bars; inflow and outflow remain separate.
3. Where clients stand — donut/pie counting relationship states; settled clients omitted.
4. Largest positions — horizontal paired bars for owed-to-us and owed-to-them.

Each chart needs title, short interpretation hint, empty state, choose-a-currency state where required, legend, accessible tooltip, and a textual alternative in the redesigned version.

### Clients table

- Columns: Client, Status, Balance, action.
- Status badges: They owe us, We owe them, Both ways, Settled.
- Balance cell lists each currency separately and spells out direction; never rely only on a minus sign.
- Statement action links to that counterparty’s statement.
- Empty filtered state.

## 8. Record workspace

Use a shared “Record” heading with a segmented switch/tabs between Currency exchange and Record a movement. The active page remains visually connected to the same navigation section.

### 8A. Currency exchange (`/exchange`)

This is the primary daily-entry screen. Use a two-column desktop layout: form on the left (about two-thirds) and sticky Calculation preview on the right (about one-third). Stack on mobile.

#### Deal direction fieldset

- Select/toggle: “I am buying” or “I am selling.”
- Subject currency select.
- Subject amount money input.
- Counter/settlement currency select.
- Counter amount money input; auto-calculated but editable.
- Rate input with visible equation, e.g. `1 USD = 3.67 AED`.
- Swap-rate-direction icon button.
- Swap the two currencies/amounts action.
- Helper labels: Paying in / Paid in, Worked out for you, Effective rate.
- Warning if division is inexact: value was truncated, not rounded.
- Validation: currencies must differ; rates and amounts must be positive; exactly two of rate/amount-in/amount-out can solve the third.

#### Received fieldset

- Amount and currency summary.
- “Into” account select, filtered to accounts holding that currency.
- Helper text: what came in and where it went.

#### Delivered fieldset

- Amount and currency summary.
- “From” account select, filtered to accounts holding that currency.
- Helper text: what went out and where it came from.

#### Profit fieldset

- “How the margin is worked out” select:
  - Rate difference.
  - Currency units per unit delivered.
  - Percentage of value.
  - Fixed amount.
  - Entered by hand.
  - No profit.
- Conditional Cost rate input for rate-difference method, quoted in the same direction as the customer rate.
- Conditional Profit value input whose label changes by method: margin per unit, percentage, or profit amount.
- Fees charged money input.
- Expenses money input.
- Commissions paid money input.
- Profit currency/margin basis is explicit and not silently converted.

#### Deal metadata

- Optional Counterparty select with “No counterparty.”
- “How the money moved” select: Bank transfer, Deposit, Cash, Cheque, Other.
- Date input, default today.
- Reference input.
- Notes/description input if retained in the redesign.

#### Calculation preview card

- Empty state: fill both amounts to see calculation.
- Loading/skeleton while server preview calculates.
- Customer rate.
- Cost rate.
- Customer value.
- Cost value.
- Gross profit.
- Fees charged.
- Expenses.
- Commissions.
- Net profit, emphasized and signed.
- Small badges/labels identifying profit side, loss side, or deductions.
- Loss warning panel when net profit is negative, with checkbox: “I understand this records a loss.” Submit remains disabled until confirmed.
- Full-width primary “Record exchange” button.
- Success flash after posting; preserve entered context only if operationally helpful, otherwise reset safely.

### 8B. Record a movement (`/movements`)

#### Main form

- “What happened” select. Supported ledger types include Opening balance, Deposit, Withdrawal, Transfer, In, Out, Fee, Expense, Profit adjustment, Balance adjustment, and Refund; show only types allowed for this screen/user.
- Amount money input.
- Currency select.
- From/into account select.
- Conditional destination account select for transfers.
- Conditional counterparty select for types involving a client.
- Date, default today.
- How the money moved select: Bank transfer, Deposit, Cash, Cheque, Other.
- Reference input.
- Notes input.

#### Optional cross-currency cash panel

Shown only for movement types that may be settled in another currency:

- Actually moved amount.
- In currency select; “Same currency” clears the conversion.
- Rate input.
- Live equation preview, e.g. `1,000 USD @ 3.67 = 3,670 AED`.
- Helper text explaining that both the ledger currency and actual cash movement are retained.

#### Counterparty position preview card

- Empty prompt until a counterparty is selected.
- Loading state while preview is fetched.
- Balance now, direction written in words.
- After this movement, emphasized.
- Amber warning if the movement flips the relationship from one side to the other.
- Full-width “Record movement” button and success flash.

## 9. Transactions (`/transactions`)

- Header: title, explanatory subtitle, Download CSV outline button.
- URL-driven filter row:
  - Type select.
  - Status select: Draft, Pending, Posted, Reversed.
  - Counterparty select.
  - Currency select.
  - From date.
  - To date.
  - Search input for reference or notes.
  - Clear filters.
- Read-only table columns:
  - Date.
  - Type.
  - Counterparty (links to statement).
  - Movement: each transaction leg on its own line with inflow/down-left green icon or outflow/up-right amber icon, exact amount + currency, role label, and optional account.
  - Reference, with description beneath in muted text.
  - Status badge.
- Reversal rows identify the original transaction number.
- Empty state, showing X–Y of total, Previous/Next pagination.
- Footer note explaining append-only behavior and reversals.

## 10. Reconciliation (`/reconciliations`)

### Record a count card (users with manage permission)

- Account/location select.
- Currency select.
- As-of date, default today.
- Counted amount money input.
- Notes input.
- Primary Record a count button.
- Secondary “Show what the ledger says” button with eye icon.
- Before reveal: helper “Hidden until you ask, so the figure does not lead the count.”
- After reveal: exact ledger value appears beside label.

### Filters

- Account.
- Currency.
- Status: All, Balanced, Difference, Explained.

### Reconciliation cards

Each result is a bordered card containing:

- Account name + currency.
- As-of date.
- Status badge.
- Three figures: Counted, Ledger says, Difference.
- Difference direction: More than expected / Less than expected.
- Optional note.
- Amber drift alert if a backdated ledger entry changed the expected amount after the count.
- If resolved: explanation, resolver name/date, optional adjusting transaction number.
- If open and manageable: Explain action expands a form with Explanation text and optional Adjusting transaction number, plus submit/cancel.
- Balanced records cannot be explained.
- Footer note: figures are immutable and reconciliation never writes a balance.

## 11. Accounts

### Accounts list (`/accounts`)

- Header, description, Add/Create account button (permission-based).
- Horizontally scrollable table:
  - Name.
  - Type.
  - Belongs to.
  - Bank or provider.
  - Masked account number.
  - Opening balances, one line per currency.
  - Status.
  - Edit action.
- Liability account rows show an amber explanation: money held for someone else.
- Empty state.

### Add/Edit account

- Name text input.
- Type select: Bank account, Cash wallet, Safe, Personal custody, Business custody, Mobile wallet, Exchange account, Partner custody, Customer balance, Credit/trust, Other.
- Conditional liability note.
- Belongs to counterparty select; default “Owned by the business.”
- Owner input.
- Bank or provider input.
- Account number input; existing value is never prefilled and displays masked elsewhere.
- Security helper explaining masking and audit redaction.
- “Currencies held” fieldset:
  - Checkbox for every currency.
  - When checked, opening-balance money input appears for that currency.
- Sort order number input.
- Active checkbox.
- Save button with spinner; Cancel link.

## 12. Counterparties

### Counterparties list (`/counterparties`)

- Header, explanatory description, Add/Create button.
- Table columns:
  - Name.
  - Type.
  - Balance: one signed running balance per currency, with explicit “they owe us” or “we owe them.”
  - Status.
  - Actions: Statement and Edit (permission-based).
- Warning badge when a declared opening position was not posted into the ledger.
- Empty state.

### Add/Edit counterparty

- Name.
- Type: Customer, Supplier, Partner, Personal contact, Business, Employee, Other.
- Phone.
- Email.
- Country.
- Preferred currency select, optional.
- Opening balance fieldset: one optional signed money input per available currency.
- Dynamic helper beside each non-zero opening balance: “they owe us” for positive, “we owe them” for negative.
- Active checkbox.
- Save and Cancel.

### Counterparty statement (`/counterparties/:id/statement`)

#### Header/actions

- Counterparty name as H1, statement description below.
- Print button.
- Download CSV button.
- Download PDF primary button.

#### Filter/mode card

- Currency select.
- Mode select: My copy / Client’s copy.
- From date.
- To date.
- Any date / clear dates button.
- Mode helper: internal includes margin; client copy shows only money moved.

#### Summary and warnings

- Opening position.
- Closing position with direction in words.
- Optional warning panel for a declared-but-unposted opening position.
- “No currencies” or “No activity” empty state.

#### Statement table

- Date.
- Details: transaction type/reference/description.
- In (money taken from them).
- Out (money paid to them).
- Balance after, signed and with explicit direction.
- Actually moved and rate details where cross-currency cash was involved.
- Profit column only in My copy mode; it must not exist in client-mode data or exports.
- Totals footer: total in, total out, closing balance, and margin earned only internally.
- Footer assurance that every figure comes from the ledger and currencies are never combined.

## 13. Currencies

### Currencies list (`/currencies`)

- Header, description, Add currency button.
- Table columns:
  - Code.
  - Name.
  - Arabic name.
  - Symbol.
  - Decimal places.
  - Sample formatted amount.
  - Status.
  - Edit action.
- Empty state.

### Add/Edit currency

- Code input, forced uppercase, with ISO 4217 hint.
- Name.
- Name (Arabic), RTL input.
- Symbol.
- Decimal places number input with examples (USD 2, KWD 3, JPY 0).
- Sort order number input.
- Active checkbox.
- Save and Cancel.
- Currencies are deactivated, never deleted, because history references them.

## 14. Audit trail (`/audit`, administrator only)

- Header and description.
- URL-driven filters:
  - Event: Created, Changed, Deleted, Restored.
  - Record type: Transaction, Reconciliation, Account, Counterparty, Currency, User.
  - Who/user.
  - From date.
  - To date.
  - Search: name or record number.
  - Clear filters.
- Table columns:
  - When.
  - Who: actor, plus IP address or “Command line.”
  - What: event badge, record type/name/id.
  - Change: before/after field values or compact change list.
- Use explicit representations for Nothing recorded before, Empty, and No field changed.
- Previous/Next pagination.
- Footer notices: append-only; secrets such as passwords and account identifiers are recorded as changed but their values are redacted.

## 15. Settings

Shared settings page includes heading/description and vertical sub-navigation: Profile, Password, Appearance.

### Profile

- Name.
- Email address.
- Save button.
- Saved confirmation.
- If unverified: warning, resend verification link, and sent confirmation.
- Delete account section:
  - Destructive warning.
  - Delete account button.
  - Confirmation dialog with irreversible-data explanation.
  - Password field.
  - Cancel and destructive confirm buttons.

### Password

- Current password.
- New password.
- Confirm password.
- Save password button.
- Saved confirmation.

### Appearance

- Three large segmented/toggle choices with icons: Light, Dark, System.
- Preference saves immediately or with clear selected feedback.

## 16. Responsive and RTL requirements

- Every spacing/alignment rule must use logical start/end behavior.
- Arabic reverses sidebar position, breadcrumb direction, icon/text flow, table alignment where semantic, and back/forward controls.
- Currency codes, dates, rates, account numbers, emails, and numeric values remain LTR inside RTL pages.
- Wide financial tables scroll horizontally with sticky headers.
- On mobile, filters can wrap or collapse into a filter sheet, but active filters must remain visible.
- Charts stack in one column; summary metrics become two columns, then one if needed.
- Minimum 44px touch targets and visible keyboard focus.

## 17. Critical states Stitch must design

For every page/component, include:

- Loading skeleton or spinner.
- First-use empty state.
- No filtered results.
- Inline validation error.
- Server/global error.
- Success confirmation.
- Disabled/processing submit.
- Permission-hidden actions.
- Inactive record status.
- Long Arabic names, long references, large 28-digit money values, and many currencies.
- Loss confirmation on exchanges.
- Backdated-ledger drift on reconciliation.
- Relationship direction flip warning on movement.
- Declared but unposted opening balance warning.
- No counterparty/no account/no currency setup edge cases with a clear route to create the missing prerequisite.

## 18. Recommended Stitch AI master prompt

Design a complete responsive bilingual finance operations web app called MonyMonk for money-exchange offices. Create desktop, tablet, and mobile screens in both English LTR and Arabic RTL. Use a collapsible permission-aware sidebar, compact professional accounting layouts, tabular numerals, light/dark themes, exact currency labels, restrained semantic colors, and highly scannable tables. The product records currency exchanges, general money movements, transaction history, reconciliation, accounts, counterparties, statements, currencies, audit logs, authentication, and settings. Do not merge totals across currencies. Always spell out whether a client owes the business or the business owes the client. Posted ledger records are read-only and corrected through reversals. Design every field, card, chart, table, filter, dialog, warning, empty/loading/error/success state, and responsive behavior described in the attached specification. Prioritize the Currency Exchange and Record Movement screens as fast daily operator workflows, with a sticky server-calculated profit/position preview. Make the visual language calm, precise, premium, and trustworthy—closer to a professional bookkeeping workstation than a flashy fintech dashboard.

## 19. Suggested Stitch generation order

1. Global shell + design system + desktop/mobile/RTL variants.
2. Dashboard.
3. Shared Record workspace, then Currency Exchange.
4. Record a Movement.
5. Transactions and Reconciliation.
6. Counterparties list/form/statement.
7. Accounts list/form and Currencies list/form.
8. Audit trail.
9. Settings and authentication.
10. Public landing page.

