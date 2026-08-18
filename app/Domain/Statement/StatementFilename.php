<?php

declare(strict_types=1);

namespace App\Domain\Statement;

use Illuminate\Support\Str;

/**
 * Names for a statement document.
 *
 * Separated from the renderer because both the download header and the PDF's own
 * metadata need them, and a file called one thing whose properties say another is the
 * kind of small inconsistency that makes somebody distrust the whole document.
 */
final class StatementFilename
{
    /** What the document calls itself inside its own metadata. */
    public function title(CounterpartyStatement $statement): string
    {
        return sprintf(
            '%s — %s %s (%s)',
            $statement->counterparty->name,
            __('statements.title'),
            $statement->currency->code,
            $statement->mode->label(),
        );
    }

    /**
     * The download filename.
     *
     * Slugging an Arabic name can leave nothing usable behind, so the party's id is
     * the fallback: a predictable filename beats a mangled one, and the full name is
     * on the first line of the document either way.
     */
    public function download(CounterpartyStatement $statement): string
    {
        $slug = Str::slug($statement->counterparty->name);

        if ($slug === '') {
            $slug = 'counterparty-'.$statement->counterparty->getKey();
        }

        return sprintf(
            'statement-%s-%s-%s.pdf',
            $slug,
            strtolower($statement->currency->code),
            ($statement->to ?? now())->toDateString(),
        );
    }
}
