# ADR 0002 — Test Database, Static Analysis Level, and Inherited Debt

- **Status:** Accepted
- **Date:** 2026-08-03
- **Context:** Phase 1, Step 1.1 (scaffold)

## Decision 1 — Backend tests run against MySQL, never SQLite

The Laravel starter kit ships `phpunit.xml` configured for SQLite in-memory, and its CI workflow created a SQLite file. Both were changed to MySQL (`finance_test`).

**Why.** SQLite uses type affinity rather than strict typing. It does not enforce `DECIMAL(28,10)` precision, does not apply InnoDB constraint semantics, and differs from MySQL in rounding and in how out-of-range values are handled. Those are precisely the failure modes a multi-currency ledger must never ship. A green SQLite suite would provide false confidence about the exact class of bug that loses money.

**Cost.** Tests need a running MySQL instance locally and a service container in CI. Accepted — correctness of financial arithmetic outranks test-suite convenience.

## Decision 2 — PHPStan runs at level 8, with inherited errors baselined

`phpstan.neon` sets level 8 across `app`, `config`, `database`, and `routes`.

Analysis found 21 pre-existing errors, none in code written for this project:

- **6 in `config/*.php`** — Laravel's `env()` is typed `bool|string`, then passed to `explode`, `parse_url`, and `Str::slug`.
- **15 in the starter kit's auth controllers** — `$request->user()` returns `Authenticatable|null` and is dereferenced without narrowing (`property.nonObject`, `method.nonObject`).

These are recorded in `phpstan-baseline.neon` rather than suppressed by lowering the level or by broad `ignoreErrors` rules.

**Why baseline instead of lowering the level.** Level 8 must apply to all new domain code from the first commit, because that is where money, rates, and balances live. Lowering the level to accommodate inherited scaffolding would permanently weaken analysis of the code that matters most. A broad `ignoreErrors` on `argument.type` would additionally hide real future errors.

**Obligation.** The baseline is debt, not a settled state. Burn it down in Phase 7. Never add to it to silence a new error.

## Decision 3 — `noUncheckedIndexedAccess` enabled

Added to `tsconfig.json` on top of `strict: true`.

**Why.** Indexing into currency maps, rate tables, and report row collections must not silently produce a non-nullable type. Enabling it immediately surfaced three genuine unguarded array reads in `resources/js/hooks/use-initials.tsx`, which were fixed and covered by regression tests in `use-initials.test.tsx`.

## Decision 4 — PHP 8.5 compatibility shim in `config/database.php`

Laravel 12's stock config uses `PDO::MYSQL_ATTR_SSL_CA`, deprecated in PHP 8.5. Its replacement `Pdo\Mysql::ATTR_SSL_CA` only exists from PHP 8.4. Resolved with a `PHP_VERSION_ID >= 80400` ternary, which evaluates one branch only and so never touches the deprecated name on 8.4+.

CI runs a PHP 8.3 / 8.4 matrix specifically so both branches of this shim are exercised.

## Decision 5 — CI workflows check rather than fix

The starter kit's `lint.yml` ran Pint and Prettier in write mode with `contents: write`. A workflow that auto-fixes and discards the result reports success on code that is not actually formatted. Both were switched to check mode (`pint --test`, `format:check`, `eslint` without `--fix`), and the write permission was removed.
