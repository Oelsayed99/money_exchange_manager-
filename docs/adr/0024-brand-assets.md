# ADR 0024 — Brand Assets

- **Status:** Accepted
- **Date:** 2026-08-25
- **Context:** the application was renamed to **MonyMonk** and given a logo

Until now the interface carried the Laravel starter kit's mark and the words "Laravel
Starter Kit", the root URL served the starter kit's marketing page, and a statement
handed to a client named the client and nothing else. All of that is now the product's
own.

## What was supplied, and what it forces

Five PNGs and a favicon set. **There is no vector anywhere in it** — the supplied
`favicon.svg` is a 940 kB PNG wrapped in an `<svg>` element, not a drawing. Every
decision below follows from that one fact.

## Decision 1 — Raster assets, sized to what the interface shows

`AppLogoIcon` was an inline SVG path coloured by `fill-current`; it is now an `<img>`.
The colour classes at its call sites (`fill-current text-black dark:text-white`) were
removed rather than left as harmless no-ops, because a class that looks like it is
doing something is worse than no class.

The sources are 1254 px and 2172 px wide with large transparent margins. They are
trimmed to their ink and resampled to the size actually displayed — 256 px for the
mark, 96 px tall for the wordmark, both comfortably above 3× for their largest use.
`icon.png` went from 720 kB to 59 kB; each wordmark from ~210 kB to ~27 kB.

They live in `public/brand/` rather than being imported through Vite. Vite would hash
them and give free cache-busting, but mPDF needs a filesystem path to draw the logo on
a statement, and one location that both the interface and the renderer can name is
worth more than the hash.

## Decision 2 — Two wordmark files, swapped by CSS

The lettering is near-black on light and white on dark, and the green chevrons are the
same in both. No CSS filter turns one into the other without dragging the green
somewhere else in the colour wheel, so both files ship and `dark:hidden` / `dark:block`
choose between them. `AppWordmark` is the single place that knows this.

CSS rather than a React hook: the theme class is set by a blocking script in the head
(Section 13), so the correct file is chosen before the first paint rather than swapped
after it.

The cost is that a browser fetches both, ~55 kB once. Accepted.

## Decision 3 — `favicon.svg` is shipped by nobody

It is not linked from the head. A 940 kB download to draw sixteen pixels is not a
trade worth making, and the `.ico` (16/32/48) and the 96 px PNG between them cover
every browser that matters. The file is not in the repository.

## Decision 4 — The supplied manifest was rewritten

Every `src` in the generated `site.webmanifest` read
`/https://monymonk.com/my-favicon/favicon.ico, enter /my-favicon/web-app-manifest-192x192.png?v=…`
— a URL and a fragment of the generator's own instructions pasted into the field. An
installed web app with unreachable icons falls back to a screenshot of the page, which
is the kind of fault nobody reports.

`purpose` was changed from `maskable` to `any`. The icon has only a few percent of
padding, and a maskable icon is cropped to the inner 80% — the monk's crossed legs
would have been clipped by a circular launcher mask.

## Decision 5 — The statement says who produced it

The mark and the wordmark now head every PDF. A statement leaves the building; without
this it is a table of figures carrying the client's name and no indication of whose
books it came from.

In Arabic the pair moves to the right edge **together**. The first attempt used the
file's existing `t-start`/`t-end` classes, which resolve to opposite sides in RTL and
split the lockup across the page — the mark on one edge, the wordmark on the other.
Sides are now fixed (`hug-left`/`hug-right`) and only the cell order flips.

## Decision 6 — The root URL

The starter kit's 790-line marketing page is replaced by a mark, a wordmark, a line
and a button. Nothing here is public — there is no product to explain to a stranger
and no figure that may be shown to one — so the page exists to say which application
you have arrived at and send you where you were going.

Its `<title>` is a child element rather than the `title` prop, because the prop goes
through the global callback in `app.tsx` and rendered "MonyMonk - MonyMonk".

## What checks this

`tests/Feature/BrandingTest.php`. Nothing else can: these paths are strings in markup,
so no compiler resolves them and no bundler fails on them — a renamed file is a broken
image in the sidebar and a missing logo on a client's document, discovered by looking.

The test scans `resources/` for `/brand/…` references and asserts each one exists,
walks the icons linked from the head and named by the manifest, and asserts the two
files the PDF renderer is handed. It was checked by moving an asset aside: the suite
fails.

The scan is recursive by hand. PHP's `glob()` does not expand `**`, so the pattern that
looked recursive in the first draft only ever reached one directory down — and the
assertions were passing on a partial scan.
