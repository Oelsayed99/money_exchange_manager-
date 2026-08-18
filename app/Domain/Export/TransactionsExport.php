<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Models\Transaction;
use App\Models\TransactionLeg;
use Illuminate\Database\Eloquent\Builder;

/**
 * The transaction list as a table.
 *
 * ## One row per leg, not per transaction
 *
 * On screen a transaction shows its legs stacked in a cell, which reads well and
 * exports terribly: a spreadsheet cannot sum a cell containing two amounts in two
 * currencies. So an exchange becomes two rows sharing a transaction id, each with one
 * amount and one currency — the shape a pivot table or a SUMIF can actually work on,
 * which is the whole reason somebody asked for a CSV.
 *
 * ## Chunked
 *
 * The ledger is the one table here with no natural ceiling. Rows are yielded in chunks
 * so an export of a hundred thousand entries streams instead of being assembled in
 * memory first.
 */
final class TransactionsExport implements Exportable
{
    private const int CHUNK = 500;

    /** @param Builder<Transaction> $query */
    public function __construct(private readonly Builder $query) {}

    public function title(): string
    {
        return __('transactions.list.title');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            __('transactions.list.columns.date'),
            'ID',
            __('transactions.list.columns.type'),
            __('transactions.list.columns.status'),
            __('transactions.list.columns.counterparty'),
            __('transactions.roles.received'),
            __('statements.columns.in').' / '.__('statements.columns.out'),
            __('common.currency'),
            __('transactions.list.columns.reference'),
            __('transactions.exchange.description'),
        ];
    }

    /** @return iterable<list<string>> */
    public function rows(): iterable
    {
        /** @var Transaction $transaction */
        foreach ($this->query->lazyById(self::CHUNK) as $transaction) {
            foreach ($transaction->legs->sortBy('sequence') as $leg) {
                yield $this->row($transaction, $leg);
            }
        }
    }

    public function basename(): string
    {
        return 'transactions-'.now()->toDateString();
    }

    /** @return list<string> */
    private function row(Transaction $transaction, TransactionLeg $leg): array
    {
        return [
            $transaction->occurred_at->toDateString(),
            (string) $transaction->id,
            __('transactions.types.'.$transaction->type->value),
            __('transactions.statuses.'.$transaction->status->value),
            $transaction->counterparty->name ?? '',
            __('transactions.roles.'.$leg->role->value),
            // Plain decimal. The grouped form belongs on a page, not in a column
            // something is going to add up.
            $leg->amount->toDisplayString(),
            $leg->currency->code ?? '',
            $transaction->reference ?? '',
            $transaction->description ?? '',
        ];
    }
}
