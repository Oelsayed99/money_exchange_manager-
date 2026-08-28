# MonyMonk

A currency exchange and ledger management application built on **Laravel 12 + Inertia + React + TypeScript**, with a domain layer designed so that money is never silently wrong.

> **Status:** all seven phases complete. See [`docs/HANDOFF.md`](docs/HANDOFF.md) for what was deliberately left undone.

---

## The problem this is built around

Exchange software fails quietly. A rounding step in the wrong place, a float where a decimal belonged, or an exponent assumed for one currency and applied to another — none of these throw, and all of them produce a ledger that is subtly, unrecoverably wrong.

The design decisions here follow from that, and each one is recorded as an ADR in [`docs/adr/`](docs/adr).

## Architecture decisions

| ADR | Decision |
|---|---|
| [0001](docs/adr/0001-frontend-architecture.md) | React + TypeScript (strict) + Inertia |
| [0002](docs/adr/0002-testing-database-and-static-analysis.md) | Testing database and static analysis |
| [0003](docs/adr/0003-money-representation.md) | Decimal strings over integer minor units |
| [0004](docs/adr/0004-localization-and-rtl.md) | Localisation and RTL |
| [0005](docs/adr/0005-no-rounding.md) | Nothing rounds — supersedes parts of 0003 |
| [0006](docs/adr/0006-roles-and-permissions.md) | Enum-backed roles and permissions |
| [0024](docs/adr/0024-brand-assets.md) | Brand assets, the theme-aware wordmark, and what checks them |
| [0025](docs/adr/0025-one-recording-screen.md) | One recording screen, switched from its heading |
| [0026](docs/adr/0026-the-profit-section.md) | The profit section: one flat-margin route, a stated cost rate, two halves |

### Money is a decimal string, not integer minor units

Minor units require one agreed exponent per currency, and the exponent varies — USD 2, KWD 3, JPY 0 — while exchange rates need more precision than any of them. An integer-cents design forces a second representation for rates, and a conversion at every boundary between the two. That boundary is exactly where precision is lost.

A single decimal representation, `DECIMAL(28,10)` with bcmath arithmetic, spans both amounts and rates and removes the boundary entirely.

### Nothing rounds

Addition and subtraction are exact. Multiplication is exact **or it throws** `PrecisionLoss`.

An earlier iteration implemented seven rounding modes by hand, because bcmath truncates rather than rounds — `bcadd('0.999', '0', 2)` is `'0.99'`, not `'1.00'`. That system was then removed outright. A ledger that cannot represent a result exactly should refuse it, not quietly approximate it.

### Permissions are enums, and the administrator has no bypass

`App\Enums\Permission` and `App\Enums\Role` back every authorisation check. A typo in a loose permission string produces a check that silently never matches, failing open or closed depending on where it sits — neither is acceptable. An enum turns that into a compile-time error.

Spatie's usual `Gate::before` super-admin hook is deliberately **not** used. The administrator role holds every permission explicitly, so what an administrator can do is always enumerable rather than implied.

Permissions ship with the modules they protect. Only `currencies.view` and `currencies.manage` exist today, because currencies are the only module built. Guards for unbuilt features cannot be tested, and untested guards are worse than absent ones because they look like protection.

## Structure

```
app/
  Domain/Money/          Money, Decimal, CurrencySpec
    Exceptions/            CurrencyMismatch, PrecisionLoss
  Enums/                 Permission, Role
  Http/Controllers/      Currency, Locale, Auth, Settings
  Console/Commands/      AssignRole
resources/js/            React + TypeScript (Inertia pages, shadcn-style components)
tests/Feature/           Pest — currency, authorisation, localisation, roles
docs/adr/                Architecture decision records
```

## Localisation and RTL

English and Arabic, with right-to-left layout as a first-class concern rather than a stylesheet afterthought — covered by `LocalizationTest` and [ADR 0004](docs/adr/0004-localization-and-rtl.md).

## Testing and CI

Pest feature tests cover currency management, authorisation boundaries, localisation and role assignment. Rounding behaviour was tested at a tie, above a tie and below a tie, in both signs, while it existed.

GitHub Actions runs on every push:

- [`lint.yml`](.github/workflows/lint.yml) — code style and static analysis
- [`tests.yml`](.github/workflows/tests.yml) — test suite, including a PHP 8.3 matrix leg

The 8.3 leg matters: `composer.json` requires `^8.3`, so the implementation must not reach for PHP 8.4's `bcround()` or `BcMath\Number`.

## Running locally

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
composer run dev
```

```bash
php artisan test        # Pest
npm run lint            # ESLint + Prettier
```

Grant yourself a role:

```bash
php artisan app:assign-role you@example.com administrator
```

---

**Stack:** Laravel 12 · PHP 8.3 · Inertia · React · TypeScript · Tailwind · shadcn/ui · MySQL · Pest · Playwright · GitHub Actions
