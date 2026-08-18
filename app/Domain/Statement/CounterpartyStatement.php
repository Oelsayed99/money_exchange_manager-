<?php

declare(strict_types=1);

namespace App\Domain\Statement;

use App\Domain\Money\Money;
use App\Enums\BalanceBucket;
use App\Enums\StatementMode;
use App\Models\Counterparty;
use App\Models\Currency;
use Illuminate\Support\Carbon;

/**
 * One counterparty's account with the business, in one currency.
 *
 * ## Why one currency
 *
 * "In", "out" and "the difference" only mean something inside a single currency; you
 * cannot subtract dirhams from pounds. The sheet this replaces was a single-currency
 * page for one party, and that was right. A party trading in three currencies has
 * three statements, and a summary that lists the three side by side without ever
 * adding them up.
 *
 * ## Why the position is not one signed number
 *
 * The original sheet carried a single running column that flipped between `(899,510)`
 * and `50,490`, with the sign alone distinguishing "they are holding our money" from
 * "they owe us money". Those are different obligations — one is chased, the other is
 * reconciled — and on a printed page handed to somebody, a parenthesis is the easiest
 * thing in the world to misread.
 *
 * So the position is held per bucket ({@see BalanceBucket}), and a statement reports
 * every bucket the party actually used, each labelled. There is deliberately no method
 * here that returns a single net figure. See ADR 0007.
 */
final readonly class CounterpartyStatement
{
    /**
     * @param  list<StatementRow>  $rows
     * @param  array<string, Money>  $opening  bucket value => balance before the period
     * @param  array<string, Money>  $closing  bucket value => balance after the period
     * @param  list<BalanceBucket>  $buckets  those with activity or a balance, in enum order
     * @param  array<string, Money>  $totalIn  bucket value => everything received in the period
     * @param  array<string, Money>  $totalOut  bucket value => everything delivered in the period
     * @param  array<string, Money>  $profit  currency code => margin earned; empty in Client mode
     * @param  array<string, Money>  $declaredOpening  bucket value => a Phase 2 declaration
     */
    public function __construct(
        public Counterparty $counterparty,
        public Currency $currency,
        public StatementMode $mode,
        public ?Carbon $from,
        public ?Carbon $to,
        public array $rows,
        public array $opening,
        public array $closing,
        public array $buckets,
        public array $totalIn,
        public array $totalOut,
        public array $profit,
        /**
         * Opening positions declared on the counterparty record but never posted.
         *
         * Surfaced rather than quietly merged in. The statement is built from the
         * ledger, and a declaration that has not been posted is not in the ledger —
         * adding it here would make the statement disagree with every other figure in
         * the system, and would double once somebody posts it properly.
         */
        public array $declaredOpening,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
