# ADR 0020 — Arabic and RTL Review

- **Status:** Accepted
- **Date:** 2026-08-25
- **Context:** Phase 7, Step 7.4

Phase 7 asks for an Arabic and RTL review. This is what it found, what was fixed, and
what turned out not to be a problem after all.

## Finding 1 — 131 strings had no Arabic translation

The worst of them by far: **all 121 of Laravel's validation messages.**

Nothing failed. A missing key falls back to English and the page renders, so this was
invisible without looking for it. The effect was that an Arabic operator filling in a
form got the interface in Arabic and the error in English — at the exact moment they had
made a mistake, which is the worst possible place for it. Validation messages are the
most-read text in a data-entry application.

Also missing: `auth` (the "these credentials do not match our records" a failed sign-in
shows), `passwords` (reset flow) and `pagination`.

All translated. `pagination` is worth noting: `«` means *previous* in a left-to-right
page and *next* in a right-to-left one, so translating the words while keeping the
glyphs would have sent the reader backwards.

**Guarded by a test**, in three directions:

1. Every English key has an Arabic one — a gap here renders in English for an Arabic user.
2. Every Arabic key has an English one — an orphan is a typo or unreachable.
3. No Arabic string is still identical to its English source — a copied line somebody
   meant to return to.

Test (2) immediately found something going the other way: `validation.attributes` was
filled in for Arabic and **empty for English**, so English messages said "name ar" where
Arabic said "الاسم بالعربية". The English was the worse of the two.

## Finding 2 — Every sign-in screen was hardcoded English

All six auth pages: zero used `useTranslations`. The login screen is the first thing an
Arabic user sees, and it carries the language switcher — which is why `locale.update`
was made reachable by guests in Phase 1. Switching to Arabic there changed the sidebar
of a page you were not yet looking at, and nothing on the page in front of you.

All six localised. The strings live under their own keys inside `auth`, because Laravel
already owns `auth.password` for "the provided password is incorrect" and a label called
"Password" would have silently overwritten it.

## Finding 3 — The mobile menu's close button sat on the wrong edge

`SheetContent` pins its close button with `right-6`. In English the sheet opens from the
left and that is its inner corner; in Arabic it opens from the right and the same rule
puts the button against the screen edge instead. Same in `DialogContent` with `right-4`.

Both are now `end-*`, which resolves to the same corner in both directions.

## Finding 4 — The rest of the vendored CSS debt is not actually rendered

`HANDOFF.md` has carried "vendored `components/ui/**` still uses physical CSS
properties — RTL debt" since Phase 1. Checked properly:

- **`sidebar.tsx`** is not hardcoded at all. Its `left-0`/`right-0` are the two branches
  of `side === 'left' ? … : …`, and the correct side is passed from the locale.
- **`dropdown-menu.tsx`**'s physical properties are entirely in `inset` variants and in
  `CheckboxItem` / `RadioItem`. This application renders none of them — only `Item`,
  `Label`, `Content`, `Separator`, `Trigger` and `Group`.
- `alert`, `tooltip`, `navigation-menu`, `select`: unused or unaffected.

So the debt was theoretical rather than visible. The dropdown properties were converted
anyway — four words against a subtly mirrored menu the first time somebody reaches for a
checkbox item.

## Consequence — three files now diverge from upstream shadcn

`sheet.tsx`, `dialog.tsx` and `dropdown-menu.tsx` carry a comment saying so. Re-adding
any of them with the shadcn CLI silently reverts the fix, and the ESLint exemption for
`components/ui/**` means nothing would complain.

## Not covered by this review

Stated plainly, because a review that implies more than it did is worse than none:

- **Nobody read the Arabic aloud.** The translations are mine. Grammar, register and the
  right word for a trade term are worth a native speaker's eye — particularly the
  financial vocabulary, where "أمانة", "عهدة" and "مستحق" carry distinctions this
  application depends on.
- **No screenshots were compared.** Layout was reasoned about from the CSS and verified
  by test where testable; the PDF is the one artefact actually looked at in both
  directions.
