# ADR 0003 — Money Representation and Rounding

- **Status:** Accepted
- **Date:** 2026-08-03
- **Context:** Phase 1, Step 1.2

## Decision 1 — Decimal strings over bcmath, not integer minor units

Section 3 permits either "database decimals or integer minor units". This project uses decimal strings backed by `DECIMAL(28,10)` columns and bcmath arithmetic.

**Why not minor units.** Minor units require a single agreed exponent per currency, but the exponent varies (USD 2, KWD 3, JPY 0) and exchange rates need far more precision than any of them. An integer-cents design forces a second representation for rates and a conversion at every boundary between them — which is precisely where precision is lost. A single decimal representation spanning amounts and rates removes that boundary.

**Cost.** Arithmetic goes through function calls rather than native integer ops, and every value is a string. Accepted: correctness dominates, and no operation here is hot enough for the difference to matter.

## Decision 2 — Rounding is explicit, never delegated to bcmath's scale argument

bcmath **truncates**. `bcadd('0.999', '0', 2)` is `'0.99'`, not `'1.00'`. Every rounding mode in `Decimal::round()` is therefore implemented by hand: truncate, measure the remainder against half a unit, and decide from the mode whether to move away from zero.

All seven modes from `RoundingMode` are implemented and individually tested at a tie, above a tie and below a tie, in both signs.

**PHP 8.4's `bcround()` and `BcMath\Number` are deliberately unused.** `composer.json` requires PHP `^8.3` and CI runs an 8.3 matrix leg, so the implementation must not depend on them.

## Decision 3 — Addition and subtraction never round; multiplication and division do

Two values already at `SCALE` cannot sum to a third that needs rounding, so `plus()` and `minus()` are exact. Only `multipliedBy()` and `dividedBy()` genuinely create new precision, and only they apply a rounding rule.

This keeps the assessment's rule — round at persistence and at display, not in between — enforceable by construction rather than by discipline.

## Decision 4 — Two distinct rounding boundaries, each with its own override

- `multipliedBy()` / `dividedBy()` round from `WORKING_SCALE` (24) to `SCALE` (10). Their override governs storage precision.
- `toCurrencyScale()` rounds from `SCALE` to the currency's own `decimalPlaces`. Its override governs what a person sees.

The first draft only had an override on the former, which was near-useless: at 10 decimal places the mode almost never changes the result. A test written against the wrong assumption exposed this, and `toCurrencyScale()` gained its own override.

## Decision 5 — Currency conversion is never implicit

`Money` carries no exchange rate and no conversion method. Adding, subtracting or ordering across currencies throws `CurrencyMismatch`. Conversion is an explicit, rate-bearing, auditable operation belonging to the exchange domain (Phase 4).

`equals()` is the one exception: it returns `false` across currencies rather than throwing, because asking whether two amounts are the same is a reasonable question with a correct answer.

## Decision 6 — `CurrencySpec` is a plain object, not the Eloquent model

`Money` is a domain value type and must be constructible and testable without a database. `Currency::spec()` produces the immutable `CurrencySpec` the domain layer uses. This is why the unit tests for money run with no database at all.

## Decision 7 — `numeric-string` carried through the type system

bcmath's PHPStan signatures require `numeric-string`. Rather than cast or suppress, `Decimal::assertValid()` declares `@phpstan-assert numeric-string $value`, so validating a string also narrows its static type. Everything downstream inherits the guarantee and PHPStan level 8 passes with no new baseline entries.

## Bug found by this step

`Decimal`'s validation regex was originally anchored with `$`. PHP's `$` also matches immediately before a trailing newline, so `"1.00\n"` — exactly what a CSV or file import produces — passed validation. Changed to `\z`. Caught by a test written specifically for whitespace variants.

## Open, deferred

- **EGP decimal places: 2.** Matches ISO 4217, consistent with USD, EUR and AED. Currencies with other exponents are supported by the schema, by `CurrencySpec` and by `Money`; none are simply in the initial seeded set.
- The Eloquent cast that stores `Money` on a model, and the TypeScript `Money` type at the Inertia boundary, are Step 1.3. Until then `Money::jsonSerialize()` defines the wire format: `{ amount: string, currency: string }`, never a JSON number.
