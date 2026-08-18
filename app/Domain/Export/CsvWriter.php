<?php

declare(strict_types=1);

namespace App\Domain\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes an export as CSV.
 *
 * ## The byte-order mark
 *
 * Excel on Windows assumes the system codepage for a CSV unless the file opens with a
 * UTF-8 BOM, and reads سالم التجريبي as mojibake without one. The three bytes are
 * ugly and they are the difference between an Arabic name arriving intact and
 * arriving as rubbish, so they go in.
 *
 * ## Formula injection
 *
 * A spreadsheet treats a cell beginning `=`, `+`, `-`, `@`, tab or carriage return as
 * a formula, so a counterparty named `=HYPERLINK(...)` becomes executable content in
 * whatever opens the file. Text cells that begin with one are prefixed with an
 * apostrophe, which spreadsheets read as "this is text" and strip on display.
 *
 * Numbers are exempted by inspection, not by trust: a negative amount legitimately
 * begins with `-`, and quoting it would turn every figure in the column into text and
 * break every sum built on top of it.
 */
final class CsvWriter
{
    private const string BOM = "\xEF\xBB\xBF";

    /** Characters a spreadsheet may read as the start of a formula. */
    private const string DANGEROUS = "=+-@\t\r";

    public function response(Exportable $export): StreamedResponse
    {
        $filename = $export->basename().'.csv';

        return new StreamedResponse(
            function () use ($export): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                fwrite($handle, self::BOM);

                fputcsv($handle, array_map($this->escape(...), $export->headings()), escape: '');

                foreach ($export->rows() as $row) {
                    fputcsv($handle, array_map($this->escape(...), $row), escape: '');
                }

                fclose($handle);
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                // Exports carry live figures; a cached copy is a stale statement.
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }

    /** Neutralise a cell a spreadsheet might execute, without mangling a number. */
    private function escape(string $value): string
    {
        if ($value === '' || $this->isNumeric($value)) {
            return $value;
        }

        return str_contains(self::DANGEROUS, $value[0]) ? "'".$value : $value;
    }

    /**
     * Whether the cell is a plain decimal.
     *
     * Deliberately its own check rather than `is_numeric`, which accepts scientific
     * notation, hexadecimal and leading whitespace — none of which this application
     * ever produces, and all of which would let something through unescaped.
     */
    private function isNumeric(string $value): bool
    {
        return preg_match('/^-?\d+(\.\d+)?\z/', $value) === 1;
    }
}
