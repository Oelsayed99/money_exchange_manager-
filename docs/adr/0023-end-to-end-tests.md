# ADR 0023 — End-to-End Tests

- **Status:** Accepted
- **Date:** 2026-08-25
- **Context:** Phase 7, Step 7.7 — the last item in the phase

Playwright had been configured since Phase 1 with no browsers installed and no tests,
which quietly implied coverage that did not exist. The choice was to write the four
flows the assessment names or to remove it. These are the four.

## Which flows, and why only four

End-to-end tests are slow, brittle and expensive to keep. They earn their place only
where a bug would cost money and no cheaper test can see the whole path:

1. **Exchange with the live preview.** The one flow where the arithmetic, the interface
   and the ledger must all agree. Unit tests cover each; only this covers the path
   between them.
2. **Credit deposit to settlement.** What the four-bucket model exists for, walked
   through the screens a clerk actually uses.
3. **Language switch preserving filters.** Not that the words change — that the page and
   its filters survive, laid out the other way round.
4. **Profit-hidden export.** Where a bug sends a client the margin taken off them.

## Decision 1 — A dedicated database, guarded in the seeder

`finance_e2e`, rebuilt by `globalSetup` before every run. The name is passed explicitly
on every command rather than inherited from `.env`, and **`E2eSeeder` refuses to run
against any other database**.

The guard is in the seeder rather than the config because a config file is easy to edit
and easy to edit wrongly, and `migrate:fresh` pointed at the wrong environment has
already destroyed this application's data twice.

The fixture is fixed, not random: nine deposits totalling **3,957,540**, the figures from
the owner's own statement. A test that asserts an amount needs to know what that amount
should be.

## Decision 2 — Serial, not parallel

One database, one set of balances. Two tests recording a movement at once would be
testing each other's data.

## Decision 3 — Layer defences, then prove the tests see through them

The profit tests were checked by breaking the application on purpose.

Breaking **one** guard leaked nothing. Breaking two leaked nothing. Only with all three
broken — the query that never selects the columns, the row that never populates them, and
the export that never emits the heading — did anything reach a client's copy, and then
**two tests failed immediately**.

That is worth recording twice over: the tests are not vacuous, and the hiding is layered
deeply enough that no single careless change exposes a margin.

## What writing them found

Every one of these is a real property of the application that no unit test had stated:

- **The cost rate is per unit *delivered*.** Setting the owner's deal up as a *purchase*
  of dollars rather than a *sale* applies the rate to the pounds and values the deal at a
  hundred and thirty million. The field says "what the delivered currency cost you", and
  it means it. The first draft of the test got this wrong, which is a fair sign an
  operator could.
- **The movements form defaults to the first currency in sort order**, not to anything
  the chosen client holds — so a client whose money is all in pounds shows four zeroes
  until somebody notices the currency selector.
- **An exchange settled in cash never reaches a client's statement**, because no entry
  touches their accounts. Documented in ADR 0009, and confirmed here by a test that
  assumed otherwise and failed.
- **The language is stored against the user, not the browser**, so it survives from one
  test file to the next. Two specs failed on this before the helper started setting it
  explicitly. The same is true of a real user with two devices.

## Consequences

- `npm run test:e2e`. Requires MySQL and a Chromium install (`npx playwright install
  chromium`), so it is not part of `npm test`.
- Roughly 18 seconds for 13 tests. Slow enough to keep out of a tight loop, fast enough
  to run before pushing.
- Screenshots and traces on failure, both gitignored.
- The suite assumes the seeded fixture. Changing `E2eSeeder`'s figures breaks tests that
  assert them, which is intended — those figures are the point.
