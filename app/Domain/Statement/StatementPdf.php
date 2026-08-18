<?php

declare(strict_types=1);

namespace App\Domain\Statement;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use RuntimeException;

/**
 * Renders a counterparty statement as a PDF.
 *
 * ## Why mPDF
 *
 * DomPDF is the usual Laravel choice and is the wrong one here. It does no complex
 * text shaping, so Arabic comes out as isolated, unjoined, reversed glyphs — a client
 * named سالم التجريبي would receive a document with their name mangled. mPDF shapes
 * Arabic, handles right-to-left layout, and needs no external binary, which matters on
 * a machine with no Docker.
 *
 * ## What this does not do
 *
 * It does not decide what goes on the page. The statement arrives already built, in
 * whichever mode was asked for, and in Client mode its profit figures were never
 * queried. Nothing here re-reads the database, so there is no route by which a margin
 * could reach a client's copy — see ADR 0009.
 */
final class StatementPdf
{
    public function __construct(private readonly StatementFilename $filenames) {}

    /**
     * The document's contents, before mPDF turns them into a page.
     *
     * Public because it is the honest place to assert what does and does not reach a
     * client's copy. Once mPDF has subsetted the fonts, the text in the PDF is a
     * sequence of glyph ids in a private encoding — searching those bytes for a figure
     * finds nothing whether the figure is there or not, which makes for a test that
     * passes while checking nothing.
     */
    public function html(CounterpartyStatement $statement): string
    {
        return View::make('pdf.counterparty-statement', [
            'statement' => $statement,
            'rtl' => App::currentLocale() === 'ar',
        ])->render();
    }

    /** The rendered document, as bytes. */
    public function render(CounterpartyStatement $statement): string
    {
        $rtl = App::currentLocale() === 'ar';
        $html = $this->html($statement);

        try {
            $pdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'tempDir' => $this->tempDir(),
                'directionality' => $rtl ? 'rtl' : 'ltr',
                // Let mPDF pick a font that can actually draw the script in front of
                // it. Without this an Arabic name silently renders as empty boxes.
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'margin_top' => 16,
                'margin_bottom' => 16,
                'margin_left' => 12,
                'margin_right' => 12,
            ]);

            $pdf->SetTitle($this->filenames->title($statement));
            $pdf->SetAuthor(config('app.name', 'Finance'));

            // A client copy that reaches the wrong desk should still say what it is.
            $pdf->SetCreator($statement->mode->label());

            $pdf->WriteHTML($html);

            return (string) $pdf->Output('', 'S');
        } catch (MpdfException $exception) {
            throw new RuntimeException(
                'Could not render the statement as a PDF: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * A writable scratch directory for font subsets and page buffers.
     *
     * Under storage rather than the system temp directory so it follows the
     * application's own permissions and gets cleared with the rest of its caches.
     */
    private function tempDir(): string
    {
        $path = storage_path('framework/cache/mpdf');

        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }

        return $path;
    }
}
