# ADR 0021 — Accessibility Review

- **Status:** Accepted
- **Date:** 2026-08-25
- **Context:** Phase 7, Step 7.5

What the review found, what was fixed, and — as with the Arabic review — what it did not
cover, because a review that implies more than it did is worse than none.

## Finding 1 — Validation messages were silent

`InputError` rendered a plain paragraph. It appeared, and to anybody using a screen
reader that is all it did: the form did not move on, and nothing said why.

Now `role="alert"`, so the message is announced the moment it renders — which is the
moment it matters.

**Not fully solved, and worth being honest about.** The message is still not *associated*
with its field: that needs `aria-describedby` on every input pointing at an id on the
message, across a dozen forms and roughly a hundred fields. Announcing beats silence;
associating would be better, and is left as known work rather than quietly claimed.

## Finding 2 — No way past the navigation

Every page began with the sidebar. A keyboard or screen-reader user walked every
navigation link again on every page they opened.

A skip link now sits first in the tab order, visible only when focused, pointing at a
`<main id="main" tabIndex={-1}>`. The `tabIndex` is load-bearing: without it the link
scrolls the page but leaves focus where it was, which is the failure mode that makes
people conclude skip links do not work.

The application also had **no `<main>` landmark at all** until this. Now it has one.

## Finding 3 — Twenty-six column headers had no scope

Twenty of forty-six had `scope="col"` — the three oldest screens — and every table
written since had gone without.

On a statement, an unscoped header means a screen reader reads a row of amounts with
nothing attached to them: the figures, and no way to know which column is *in* and which
is *out*. All forty-six are scoped now.

## Finding 4 — Small ones

- One unlabelled input: the per-currency opening balance on the account form. There is
  one per currency, so no single visible label can identify them; each now says which
  currency it belongs to.
- Six spinners, one on each sign-in screen, announced as "loader circle" beside the
  button's own text. Hidden.

## What was already right

Worth recording so it is not undone later:

- `<html lang>` and `dir` are set server-side from the active locale.
- Flash messages already carried `role="status"`.
- Icons beside their own labels were already `aria-hidden` everywhere except those six
  spinners.
- Status is never carried by colour alone — every badge has text, and negative amounts
  have a minus sign as well as a colour.
- `app-header-layout.tsx` has unlabelled icon buttons and is **dead code**; the
  application renders `app-sidebar-layout`. Left alone, and noted so a future reader does
  not fix a file nothing uses.

## Guarded by a test

`resources/js/tests/accessibility.test.ts` checks what a machine can settle: every
control has a name, every column header has a scope, the skip link and landmark exist,
errors and flashes announce, and decorative icons are hidden. These slip in one screen at
a time while every other test stays green.

## Not covered

- **No screen reader was used.** Nothing here was heard, only read. VoiceOver on the
  statement and the exchange form would be the obvious next step.
- **No keyboard walkthrough of the dropdown menus.** The primitives handle focus
  trapping and this review trusted them.
- **Colour contrast was not measured.** The palette is the shadcn default, which is
  designed against WCAG AA, but the amber warnings on tinted backgrounds are the pairing
  most likely to fall short and were not checked with a meter.
- **The charts are not accessible.** Recharts renders SVG with no text alternative, so
  the dashboard's four charts are invisible to a screen reader. The figures they draw are
  all present as text elsewhere on the same page, which is a mitigation rather than a fix.
