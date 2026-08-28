# ADR 0028 — RTL, the Back Button, and What the Tests Were Testing

- **Status:** Accepted
- **Date:** 2026-08-28
- **Extends:** [0020](0020-arabic-rtl-review.md)

Two faults reported by the owner, both in Arabic, plus one found while fixing the
second that mattered more than either.

## 1. The equals sign was in the wrong place

The rate read `= 1 USD [box] EUR` in Arabic. It was written as three separate
left-to-right islands in a row that itself flows right-to-left:

```
<span dir="ltr">1 USD =</span>  <input>  <span dir="ltr">EUR</span>
```

"=" is the **last** character of `1 USD =`, so in a left-to-right island it is the
**rightmost** — and in an RTL row that island sits with its right edge against the
label. The sign ended up next to the word "السعر" instead of next to the box.

A quotation is one formula, not three fragments. The whole of it — codes, sign, input —
is now a single `dir="ltr"` container, so it reads `1 USD = [box] EUR` in both
languages and only the Arabic label beside it turns over. Same for the cost rate.

The test asserts the structure rather than the pixels: the currency codes, the sign and
the input must resolve to one left-to-right run.

## 2. Going back left the page half turned over

Switch to Arabic, press back: the sidebar moved to the other side while the main
container did not. Two separate causes, one behind the other.

**The document attributes never updated.** `dir` and `lang` are kept in step by a
subscription to Inertia's `success` event. `success` fires when a request comes back;
the browser's back and forward buttons restore a **cached** page without making one, so
it never fired. React re-rendered from the restored props — hence the sidebar moving —
while every logical property in the layout went on resolving against the stale `dir`.

Now `navigate` as well, which fires whenever the page changes including history
restores.

**Underneath it, Inertia 2.0.3 crashed.** `getScrollRegions()` read
`window.history.state.scrollRegions` with no guard, and a history entry created by a
full document load has `state === null` until Inertia's queued `replaceState` catches
up. Going back to one threw `Cannot read properties of null (reading 'scrollRegions')`
and rendered a **blank page**. Upstream added the `?.` in a later release; upgrading to
2.3.27 fixes it.

This was not caused by preserving form state across the switch — the same failure
reproduces on the original options, three runs in five. It has been there all along.

## 3. The end-to-end tests were not testing what ships

This is the part worth remembering.

Upgrading Inertia changed nothing: the back-button test kept failing with the *same*
error from the *same* line the upgrade had fixed. `public/hot` was present, so Blade was
loading modules from a running `npm run dev`, whose pre-bundled dependency cache still
held 2.0.3. **Every end-to-end run so far had exercised Vite's dev output rather than
the build**, and the suite would have gone on passing against code nobody was going to
deploy.

`globalSetup` now runs `npm run build` and parks `public/hot` for the length of the run;
`globalTeardown` hands it back, and the setup restores it first in case a run was killed
part-way. Parked rather than deleted, because it belongs to a dev server that is still
running and will want it again.

The lesson generalises past this bug: a test that quietly substitutes a different build
of the thing under test is worse than no test, because it reports success.

## What repeat-each cannot tell you

`--repeat-each` is not a way to run this suite. `globalSetup` seeds the database once
and several tests record movements, so a second pass reads balances the first pass
moved and times out waiting for figures that will never appear. The supported run is a
single pass, which is what `npm run test:e2e` does.

The back-button test was repeated five times on its own — it does not record anything —
and passed five times.

## The server adapter

`inertiajs/inertia-laravel` stays at 2.0.24 against a 2.3.27 client. The protocol is
stable across 2.x and the full suite passes; bumping a second dependency in the same
change would have made the upgrade harder to attribute if something had broken.
