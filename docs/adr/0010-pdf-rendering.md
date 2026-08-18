# ADR 0010 — Rendering Statements as PDF

- **Status:** Accepted
- **Date:** 2026-08-18
- **Context:** Phase 5, Step 5.3

## The requirement

> "I can print a PDF any time with the mode I chose (me mode or client mode)."

The browser's own print already worked. It is not a document: it carries the application chrome's assumptions, it varies by browser, and it cannot state on every page which copy it is.

## Decision 1 — mPDF, not DomPDF

DomPDF is the usual Laravel choice and is the wrong one here. It performs no complex text shaping, so Arabic renders as isolated, unjoined letters in visual order — a client named سالم التجريبي would receive a document with their own name mangled. That is not a cosmetic defect on a statement sent to a counterparty.

Options weighed:

| | Arabic shaping | External binary | Notes |
|---|---|---|---|
| DomPDF | ✗ | none | Rejected on shaping alone |
| mPDF | ✓ | none | Chosen |
| Browsershot / spatie-pdf | ✓ | Chromium (~150 MB) | Best fidelity; the owner runs no Docker and this adds a browser to the deploy |

mPDF shapes Arabic, lays out right-to-left, and is pure PHP. `autoScriptToLang` and `autoLangToFont` are enabled so it selects a font that can actually draw the script in front of it; without them an Arabic name silently renders as empty boxes.

Installing it surfaced six pre-existing advisories against `league/commonmark`, a Laravel transitive dependency unrelated to this work. Updated to 2.9.0 in the same commit; `composer audit` is clean.

## Decision 2 — The document is not the screen rendered to paper

`resources/views/pdf/counterparty-statement.blade.php` is written for a page: repeating column headers, page numbers, and the copy's identity in the footer of every sheet so a page separated from the rest still says whether it was meant to leave the building.

mPDF supports a subset of CSS — no flexbox, no grid — so it is tables and a small stylesheet rather than the application's Tailwind.

Two things learned the hard way, both recorded in the template:

- **Widths must be in the `<style>` block, and layout tables must be `dir="ltr"`.** mPDF lays a right-to-left table out shrink-to-fit and centres it, which pulled both ends of the header and footer into the middle of the page. Only visible in Arabic. The fix is to force the layout tables left-to-right and place the cells by hand.
- **Numbers are wrapped in `dir="ltr"`.** Digits do not mirror; only the text around them does.

## Decision 3 — The document is built through the same path as the screen

`CounterpartyStatementController::resolve()` reads the filters and builds the statement, and both `show()` and `pdf()` call it. The PDF link carries the page's own query string.

Two copies of that resolution would be two chances for a client copy on screen to become an internal one in a file. Since the mode is resolved once, and in Client mode the profit columns are never selected (ADR 0009), there is no route by which a margin can reach a client's document.

## Decision 4 — Assert on the contents, not the bytes

The obvious test — search the PDF for the margin figure — is worthless. mPDF subsets the embedded fonts, so drawn text becomes glyph ids in a private encoding; searching those bytes finds nothing whether the figure is on the page or not, and the test passes while checking nothing.

`StatementPdf::html()` is public for this reason. The security property is asserted against the contents mPDF is given, in both directions: the figure is present in the internal copy and absent from the client copy. The bytes are checked separately for being a valid, non-trivial PDF.

## Consequences

- `mpdf/mpdf` is a runtime dependency. It writes font subsets to `storage/framework/cache/mpdf`, created on demand.
- Amounts print without thousands separators, matching the rest of the application. The sheet being replaced used them; whether to group digits app-wide is an open question for the owner, not a decision to take quietly inside a PDF template.
- Only the counterparty statement has a document so far. The dashboard and any account statement will need their own.
