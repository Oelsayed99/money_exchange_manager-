<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * Something that can be written out as a table of cells.
 *
 * One interface for every export, so the CSV writer and any later spreadsheet writer
 * consume the same rows. Section 24's reasoning: an omission decided once — a client
 * copy having no profit column — is then inherited by every format rather than
 * reimplemented in each, where one of them would eventually get it wrong.
 *
 * Rows are `iterable` so a large export can be streamed rather than assembled in
 * memory. Every cell is a string: formatting decisions belong to whatever built the
 * export, not to the writer.
 */
interface Exportable
{
    /** The document's own name, for a title row or a sheet name. */
    public function title(): string;

    /** @return list<string> */
    public function headings(): array;

    /** @return iterable<list<string>> */
    public function rows(): iterable;

    /** Filename without an extension; the writer adds its own. */
    public function basename(): string;
}
