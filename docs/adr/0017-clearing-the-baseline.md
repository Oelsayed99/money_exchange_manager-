# ADR 0017 — Clearing the Static-Analysis Baseline

- **Status:** Accepted
- **Date:** 2026-08-20
- **Context:** Phase 7, Step 7.1

## What the baseline was

Twenty errors inherited from the Laravel starter kit, baselined on day one so that level 8 could be enforced on all new code without first rewriting scaffolding. The config file said to burn it down in Phase 7 and never to add to it.

Two categories:

- **Six in `config/`** — `env()` is typed `bool|string|null`, and its result was handed straight to `explode`, `parse_url` and `Str::slug`. Cast at the call site; behaviour is identical for every real value and the type is now true.
- **Fourteen `$request->user()`** in the scaffolded auth and settings controllers — typed `User|null`, used as `User`.

## Decision 1 — One narrowing helper, not fourteen null checks

`App\Support\Authenticated::user($request)` returns a `User` or throws Laravel's own `AuthenticationException`.

The alternatives were worse. An `assert()` or an inline `@var` tells the analyser to stop worrying and does nothing at runtime, so a routing mistake that dropped the middleware would surface as "call to a method on null" somewhere deeper. Fourteen inline null checks would say the same thing fourteen times. This says it once, truthfully, and works for form requests as well as controllers.

## Decision 2 — The last error was a real defect

`VerifyEmailController` passed a `User` to `Illuminate\Auth\Events\Verified`, which documents its parameter as `MustVerifyEmail`. `User` did not implement that interface — Laravel ships it commented out — and the starter kit papered over the gap with `/** @var MustVerifyEmail $user */`, an annotation asserting something untrue. A baseline entry then kept the contradiction out of sight.

The event's constructor is untyped, so nothing threw; the flow worked and its test passed. The contract was simply a lie.

**Correction worth recording:** on first reading this looked like a latent `TypeError` on a live route. It was not — the type is a docblock, not a signature. The first fix, guarding with `instanceof`, was therefore wrong twice over: it changed behaviour, and the existing test caught it immediately by failing.

`User` now implements `MustVerifyEmail`. It already had all four methods through the trait on `Illuminate\Foundation\Auth\User`, and the application has verification routes, a controller and passing tests — the interface was the only thing missing. Verified safe before applying: `MAIL_MAILER=log`, and **no route uses the `verified` middleware**, so nothing is enforced and nobody can be locked out.

## Decision 3 — No baseline, and no new one

The file is deleted and the include removed. Level 8 holds across `app`, `config`, `database` and `routes` with nothing exempted.

A baseline is a list of things the analyser found and was told to stop mentioning. The last entry on the last list was hiding a broken contract on a live route for four months.

## Consequences

- Registration now dispatches a verification notification, written to the log. Nothing blocks on it.
- The profile screen's "verify your email" notice becomes reachable, having been permanently false before.
- Email verification exists, is tested, and is **enforced nowhere**. Whether it should be is an open question for the owner, not something to decide from a type error.
