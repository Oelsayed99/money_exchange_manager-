# ADR 0004 — Localization, RTL, and the Theme Boundary

- **Status:** Accepted
- **Date:** 2026-08-03
- **Context:** Phase 1, Step 1.3

Section 12 states that Arabic compatibility must not be postponed and that localization discipline starts from the first implementation phase. This step establishes that foundation *before* the first real screen, so no interface is ever built monolingual and retrofitted.

## Decision 1 — Direction travels with the locale, as data

`config/locales.php` holds each locale's native name and direction; `App\Support\Locale` reads it. Adding another RTL language later is a data change in one file, not a hunt for hardcoded `=== 'ar'` checks.

## Decision 2 — Locale resolution order: user → session → default

`SetLocale` middleware prefers the authenticated user's saved locale, falls back to the session (so a guest can switch language on the login screen), then the configured default.

An unsupported value is **ignored rather than trusted**, at both ends: rejected by validation on the way in, and re-checked on the way out. The locale reaches the translator and the `html lang` attribute, so it must never be arbitrary user input. A stored-but-unsupported locale silently degrades to the default, which is covered by a test.

The middleware runs *before* `HandleInertiaRequests`, so shared props — including the translation bundle — are built against the resolved locale.

## Decision 3 — Translations shipped as a bundle in Inertia shared props

Groups are listed explicitly (`common`, `nav`, `currencies`) rather than globbed, so adding one is deliberate and server-only strings cannot leak into a page payload.

Each group is merged **over the fallback locale**, so an untranslated key renders as English rather than as a raw dotted key. Section 12 forbids hardcoded strings in the interface; it does not require every string to exist in every language before a page can render.

`translate()` returns the key itself when nothing matches. A visible `currencies.fields.code` is an obvious bug; an empty string silently swallows the mistake.

## Decision 4 — Arabic validation messages are a deliberate subset

`lang/ar/validation.php` covers the rules this application actually uses, plus attribute names. Laravel resolves each key individually and falls back per-key, so a partial file yields correct Arabic where it matters without shipping a hundred machine-quality strings. The file carries an instruction to extend it whenever a new rule reaches a user-facing form.

No translation package was added. Section 16 discourages unnecessary packages, and per-key fallback makes one unnecessary.

## Decision 5 — Direction is synchronised by a router subscription, not a hook

The initial `dir` and `lang` are server-rendered into `app.blade.php`. Inertia visits replace the page component without touching the `html` element, so after a language switch those attributes would go stale — and the entire RTL layout keys off `dir`.

A `router.on('success')` subscription in `app.tsx` re-applies them. Done globally rather than as a hook so it covers every page, including any that do not use a shared layout.

## Decision 6 — Language switching redirects back

`LocaleController` returns `back()` rather than to a fixed route. That is what keeps the current URL, its query string, its filters and its page number intact across a switch, as Section 12 requires. Covered by a test asserting `/currencies?page=3&sort=code` survives the switch.

## Decision 7 — Theme applied in a blocking script, before paint

Section 13 requires no theme flash. The starter kit applied the theme from a React effect, which runs *after* first paint — a dark-mode user saw a white frame first. It now runs as a synchronous inline script in the server-rendered `head`, with a matching `html` background colour so the first painted frame is never wrong. The script is wrapped in try/catch because `localStorage` throws in some private-browsing modes, and failing the render over a theme preference would be worse than defaulting to light.

## Decision 8 — Physical direction utilities are banned by lint

**A real bug found this way.** With RTL enabled, the login page rendered `?Forgot passwordPassword` — the label and the reset link collapsed into each other. Cause: `ml-auto`. Under `dir="rtl"` it still pushes from the *left*, so the element meant to sit at the far end had nothing pushing it there.

A survey found roughly 70 physical-property usages across 17 files, all inherited from the starter kit and shadcn primitives.

- **Our own components and pages were converted** to logical properties (`ms-`/`me-`/`ps-`/`pe-`, `text-start`/`text-end`, `border-s`/`border-e`).
- **An ESLint rule now rejects the physical forms**, verified against a deliberate violation rather than assumed to work.
- **`resources/js/components/ui/**` is exempted** and tracked as known RTL debt. Those are vendored shadcn/Radix primitives whose `left-`/`right-` utilities interact with Radix's own positioning; they need per-component visual verification, not a blind rewrite. Same for `welcome.tsx`, which will be replaced wholesale.

This is enforced from the foundation phase rather than audited at the end, because retrofitting direction-awareness across a built interface is exactly the expensive path the specification warns against.

## Known gaps at the end of this step

- **Auth pages are still hardcoded English.** They lay out correctly in RTL but are not translated.
- **Vendored `components/ui/**` still carries physical properties.** Scheduled for a dedicated RTL pass.
- **Theme preference is stored client-side only.** The `users.theme` column exists but nothing writes it yet; the appearance toggle still uses `localStorage`.
- **No permission checks on currency management.** Routes are behind `auth`; the Section 14 matrix arrives with the roles and permissions step.
