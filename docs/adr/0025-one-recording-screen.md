# ADR 0025 — One Recording Screen, Two Forms

- **Status:** Accepted
- **Date:** 2026-08-28
- **Supersedes:** the navigation half of [0015](0015-recording-movements.md)

Exchange and the other movements were two entries in the sidebar. That asked an
operator to classify what they were about to record *before* they had anywhere to
record it — and the classification is not always obvious at the counter. They are the
same act: writing down something that happened.

They are now one screen whose heading is the switch.

## Decision 1 — Two routes, one screen

`/exchange` and `/movements` both remain. The heading switches between them with an
ordinary Inertia visit rather than swapping a component in place.

A single route with a mode parameter was the other option and is worse in three ways:
the back button stops working, neither form can be linked to, and the controller has
to assemble both forms' options — currencies, accounts, counterparties, profit
methods, movement types, buckets — on every visit to serve one of them.

What the operator sees is one screen that changes. What the server does is answer the
question it was actually asked.

## Decision 2 — The trigger lives inside the heading

`<h1><button>…</button></h1>`, not a button wrapping a heading. A `<button>` may not
contain an `<h1>` — its content model is phrasing content — and getting it round the
wrong way would leave the page with no level-one heading at all while looking
identical. `record-heading.test.tsx` asserts the nesting for that reason.

The button's accessible name is the visible title, so no `aria-label` overrides it.

## Decision 3 — One navigation entry, opening on the exchange

`nav.record`, pointing at `/exchange`. A money-exchange business exchanges money all
day and records the other movements occasionally, so the common case is one click and
the other is two. One line in `app-sidebar.tsx` if that turns out to be the wrong way
round.

This needed `NavItem.matches`: `NavMain` decided the current entry by comparing the
entry's own url to `page.url`, so an entry fronting two routes would have highlighted
nothing on half of them. The comparison now also ignores the query string — which
incidentally fixes the transactions entry going unmarked the moment a filter was
applied.

## What this turned up

`transactions.exchange.description` was **written twice** in `lang/en/transactions.php`
and `lang/ar/transactions.php` — once as the page's subtitle and once, eleven lines
later, as `'Notes'`. PHP does not warn about a duplicate key in an array literal; the
later value simply wins. The exchange screen has been subtitled "Notes" since Phase 5,
in both languages.

It surfaced only because the switch shows both forms' descriptions side by side, where
one of them reading "Notes" is obvious.

The second key was dead — nothing referenced it — so it is deleted and the subtitle now
reads what it was written to read.

`TranslationParityTest` grew a fourth case to catch the next one. None of the other
three could: parity compares locales, and both locales had the same duplicate. Counting
is the only way to see it, since the parser cannot report what it discarded. It was
checked by putting the duplicate back; a sweep of every language file found this was
the only one.
