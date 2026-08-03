# ADR 0005 — Nothing Rounds

- **Status:** Accepted
- **Date:** 2026-08-03
- **Supersedes:** ADR 0003 decisions 2 and 4 (rounding modes, dual rounding boundaries)
- **Decided by:** Repository owner

## Decision

**No amount in this system is ever rounded.** The rounding system introduced in Step 1.2 is removed entirely:

- `RoundingMode` (seven modes) — deleted.
- `Decimal::round()` — deleted.
- `currencies.rounding_mode` — dropped by migration.
- Per-currency rounding policy in `CurrencySpec` and the currency admin form — removed.

## What replaces it

| Operation | Behaviour |
|---|---|
| Addition, subtraction | Exact |
| Multiplication | Exact, **or it throws** |
| Division | Truncated toward zero at `SCALE` — never rounded |
| Display | Exact; padded up to the currency's precision, never cut down to it |

### Multiplication throws rather than rounds

A product needing more than `SCALE` decimal places raises `PrecisionLoss`. `1 × 0.00000000005` does not become `0.0000000001`, and does not become `0.0000000000` — it fails loudly. Discarding a digit is a decision the caller must make explicitly, because whichever way it goes it is somebody's money.

### Division truncates, and this is the one lossy operation

This is mathematics, not preference: `10 ÷ 3` does not terminate, so a finite representation has to stop somewhere. The choice made is **truncation toward zero**, which is materially different from rounding:

- Rounding can *increase* a value. `2.5 → 3`.
- Truncation only ever *drops* digits. `2.5 → 2`, `-2.5 → -2`.

A truncated amount can therefore never exceed the true amount, in either sign. Digits are lost at the tenth decimal place — far below the significance of any currency, but lost nonetheless, which is why `dividedBy()` is the only method that admits to it and why `divisionIsExact()` exists for callers that need to know in advance.

### Display shows what is held

`decimalPlaces` became a **minimum for display**, not a rounding instruction:

- USD `1000` renders as `1000.00` — padded.
- USD `1000.123456` renders as `1000.123456` — **not** rounded to `1000.12`.
- USD `1.005` renders as `1.005` — **not** rounded up to `1.01`.
- JPY (0 decimals) `1234.5` renders as `1234.5` — the digit is not hidden.

A sub-cent balance is visible rather than concealed by presentation. `toCurrencyScale()` was renamed `toDisplayString()` because it no longer scales anything.

## Why this is defensible

The previous design rounded at two boundaries, and a rounded value that reaches storage is indistinguishable from an exact one — the discrepancy is real money, and it is silent. Under this design every stored amount is either exact or was truncated at a documented point, and the only place a digit can be lost is division, which is mathematically forced.

The cost is that a rate too precise to represent now breaks a calculation instead of quietly absorbing it. That is the intended trade: in a ledger, a loud failure is cheaper than a quiet discrepancy.

## Consequences

- `Decimal` exposes `padTo()` (adds zeros, never removes digits), `truncateTo()` (drops toward zero), and `losesPrecisionAt()` (asks whether a reduction would discard anything significant). There is no rounding function to reach for.
- `Money::multipliedBy()` and `dividedBy()` no longer take a rounding-mode argument; there is nothing to pass.
- The currency admin screen has one field fewer.
- Callers that genuinely need to reduce precision must call `truncateTo()` and say so.

## Migration note

`create_currencies_table` was already published, so it was not rewritten. It still creates `rounding_mode`, and `2026_08_03_000003_drop_rounding_mode_from_currencies_table` removes it. The create migration's reference to the deleted `RoundingMode` enum was replaced with the literal `'half_up'` — the minimum change needed to keep an already-published migration runnable.
