<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Statement\CounterpartyStatement;
use App\Domain\Statement\StatementFilename;
use App\Domain\Statement\StatementRow;

/**
 * A counterparty statement as a table.
 *
 * The mode is not re-read here. The statement arrives already built, and in Client
 * mode its profit figures were never queried — so the column is absent because there
 * is nothing to put in it, not because this class chose to leave it out. Deciding it
 * again would be a second place for the two to disagree. See ADR 0009.
 */
final class StatementExport implements Exportable
{
    public function __construct(
        private readonly CounterpartyStatement $statement,
        private readonly StatementFilename $filenames,
    ) {}

    public function title(): string
    {
        return $this->filenames->title($this->statement);
    }

    /** @return list<string> */
    public function headings(): array
    {
        $headings = [
            __('statements.columns.date'),
            __('transactions.list.columns.type'),
            __('statements.columns.details'),
            __('statements.columns.in'),
            __('statements.columns.out'),
            __('statements.columns.position'),
            __('common.currency'),
            // What actually changed hands, when it was not this statement's currency.
            __('statements.columns.moved'),
            __('statements.columns.rate'),
        ];

        if ($this->statement->mode->showsProfit()) {
            $headings[] = __('statements.columns.profit');
        }

        return $headings;
    }

    /** @return iterable<list<string>> */
    public function rows(): iterable
    {
        foreach ($this->statement->rows as $row) {
            yield $this->row($row);
        }
    }

    public function basename(): string
    {
        // The PDF's name without its extension, so the two documents for one view sit
        // together when somebody sorts a folder by name.
        return substr($this->filenames->download($this->statement), 0, -4);
    }

    /** @return list<string> */
    private function row(StatementRow $row): array
    {
        $cells = [
            $row->occurredAt->toDateString(),
            __('transactions.types.'.$row->type->value),
            trim(implode(' · ', array_filter([$row->description, $row->reference]))),
            // Plain decimals, never the grouped form. A thousands separator in a CSV
            // splits the cell or is read as text, and the column stops adding up.
            $row->in?->toDisplayString() ?? '',
            $row->out?->toDisplayString() ?? '',
            $row->balanceAfter->toDisplayString(),
            $this->statement->currency->code,
            $row->movedAmount === null ? '' : $row->movedAmount->toDisplayString().' '.$row->movedAmount->currency->code,
            $row->rate ?? '',
        ];

        if ($this->statement->mode->showsProfit()) {
            $cells[] = $row->profit?->toDisplayString() ?? '';
        }

        return $cells;
    }
}
