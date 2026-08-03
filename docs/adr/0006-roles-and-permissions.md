# ADR 0006 — Roles and Permissions

- **Status:** Accepted
- **Date:** 2026-08-03
- **Context:** Phase 1, Step 1.4

Closes the gap carried since Step 1.3, where currency management was protected only by `auth` — meaning any authenticated user could change the precision of a currency the whole ledger depends on.

## Decision 1 — Permissions and roles are enums, not strings

`App\Enums\Permission` and `App\Enums\Role` back every check. A typo in a loose permission string produces a check that silently never matches — which fails *open* or *closed* depending on where it appears, and neither is acceptable here. An enum makes it a compile-time problem.

## Decision 2 — Permissions are added with the modules they protect

Only `currencies.view` and `currencies.manage` exist today, because currencies are the only thing built.

Section 14 names further permissions — view credit accounts, manage credit accounts, settle credit, view liability reports. Declaring them now would ship guards for features that do not exist and cannot be tested, and untested guards are worse than absent ones because they look like protection. They arrive with the credit module.

## Decision 3 — Administrator holds every permission explicitly, with no bypass

Spatie's usual pattern is a `Gate::before` hook granting a super-admin everything. That is deliberately **not** used.

With an explicit grant, "what can an administrator do" is answered by reading the permission table — which is auditable, exportable, and reviewable by someone who does not read PHP. With a bypass, the answer lives in a closure and the table lies.

The cost is that a newly added permission must be seeded. `RolePermissionSeeder` re-syncs on every run and is idempotent, and a test asserts the administrator's permissions match `Permission::cases()` exactly, so drift fails the build rather than going unnoticed.

## Decision 4 — Three roles

`administrator`, `operator`, `viewer`. Deliberately few: this is an internal system with a small number of people, and a role matrix nobody can hold in their head is a matrix nobody audits.

An operator can *read* currencies but not change them. Currencies define what every stored amount means; altering a currency's precision after balances exist reinterprets history, so it sits with administrators.

## Decision 5 — Authorization runs in the form request, before validation

`CurrencyRequest::authorize()` resolves create-vs-update from the route and defers to the policy. Running there rather than in the controller means an unauthorized request is rejected *before* validation, so the caller learns nothing about what the form expects. A test asserts a forbidden request comes back with no validation errors attached.

## Decision 6 — Client permissions are presentation, never a boundary

Shared Inertia props carry the permissions the user holds, so the interface can avoid offering actions that would be refused — the sidebar hides Currencies, the index hides Create and Edit.

**This is not security.** Section 16 requires backend enforcement, and the tests that matter are the negative ones: a viewer POSTing directly to `/currencies` gets 403 and no row is created. Hiding the button is a courtesy; the policy is the gate.

Only granted permissions are sent. A user cannot enumerate what they are missing.

## Decision 7 — Deletion is denied to everyone, including administrators

`CurrencyPolicy::delete()` returns `false` unconditionally. There is no destroy route, and this method exists so that adding one later fails closed rather than open. Currencies are referenced by ledger history that must stay reproducible (Section 7).

## Test approach

`userWithRole()` and `userWithoutRole()` helpers seed roles from the same seeder the application ships, so a test can never pass against a permission set production does not have. `RefreshDatabase` truncates between tests, so this rebuilds per test rather than once.

## Known gaps

- **No user administration screen.** Roles are assigned by seeder or tinker.
- **No audit foundation yet** — the remaining Section 23 Phase 1 item.
- **`users.theme` is still unused**; the appearance toggle remains client-side.
