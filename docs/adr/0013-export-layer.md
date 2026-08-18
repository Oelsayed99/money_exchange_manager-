# ADR 0013 — The Export Layer

- **Status:** Accepted
- **Date:** 2026-08-19
- **Context:** Phase 6, Step 6.1

## The requirement

Phase 6 is "PDF, Excel, CSV, reconciliation workflow, balance rebuilding and verification, audit improvements, Arabic export rendering". Section 24 adds the constraint that matters most:

> "PDF, Excel, and CSV exporters consume the same view models, so the omission is inherited rather than reimplemented."

The omission being a client copy having no profit column. Three exporters each deciding that separately is three chances for one of them to get it wrong, and the one that gets it wrong is a file already sent to a client.

## Decision 1 — One `Exportable`, many writers

`Exportable` is headings, rows, a title and a basename. `StatementExport` and `TransactionsExport` implement it; `CsvWriter` consumes it. A spreadsheet writer slots in beside `CsvWriter` without either export changing.

`StatementExport` does not re-read the mode. The statement arrives already built, and in Client mode its profit figures were never queried (ADR 0009) — so the column is absent because there is nothing to put in it. Same for the PDF. Three outputs, one decision.

Rows are `iterable` and `TransactionsExport` yields in chunks of 500 via `lazyById`, because the ledger is the one table here with no natural ceiling.

## Decision 2 — The byte-order mark goes in

Excel on Windows assumes the system codepage for a CSV unless the file opens with a UTF-8 BOM, and reads سالم التجريبي as mojibake without one. Three ugly bytes against an Arabic name arriving as rubbish is not a close call.

## Decision 3 — Formula injection is neutralised, without breaking numbers

A spreadsheet treats a cell beginning `=`, `+`, `-`, `@`, tab or carriage return as a formula. A counterparty named `=HYPERLINK("http://evil","click")` is executable content in whatever opens the file — and counterparty names are attacker-controlled in the sense that matters: they are typed in by whoever opens an account.

Text cells beginning with one are prefixed `'`, which spreadsheets read as "this is text" and strip on display.

Numbers are exempted **by inspection, not by trust**: a negative amount legitimately begins with `-`, and quoting it would turn the column into text and break every sum built on it. The check is a strict decimal pattern rather than `is_numeric`, which accepts scientific notation, hex and leading whitespace — none of which this application produces, and all of which would let something through unescaped.

## Decision 4 — CSV gets plain decimals, never grouped

`3957540.00`, not `3,957,540.00`. Grouping is a reading aid for a page; in a column something is going to add up, a thousands separator either splits the cell or turns the number into text. The PDF groups, the CSV does not, and both are right.

## Decision 5 — Transactions export one row per leg

On screen a transaction shows its legs stacked in a cell, which reads well and exports terribly: no spreadsheet can sum a cell holding two amounts in two currencies. So an exchange becomes two rows sharing a transaction id, each with one amount and one currency — the shape a pivot table or a `SUMIF` can work on, which is the reason somebody wanted a CSV rather than a PDF.

## Decision 6 — Exports go through the screen's own query

`TransactionController::filtered()` and `CounterpartyStatementController::resolve()` are each shared by the screen and its exports. Two copies would be two chances for a file to contain something the page did not show.

## Consequences

- No new dependency. CSV is written with `fputcsv`.
- Search and filters are carried in the export link's query string, so what is on screen is what downloads.
- Found by dumping a real file and reading it, not by any test: the statement export's seventh column was headed with the *Custody* bucket name while containing the position label. Tests asserted the figures and the profit omission and would never have caught a wrong heading.
- Still outstanding in Phase 6: a spreadsheet (xlsx) writer, and the reconciliation workflow with its `reconciliations` table.
