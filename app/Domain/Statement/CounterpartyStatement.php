<?php

declare(strict_types=1);

namespace App\Domain\Statement;

use App\Domain\Money\Money;
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
 * cannot subtract dirhams from pounds. A party trading in three currencies has three
 * statements, and a summary that lists the three side by side without ever adding them.
 *
 * That a movement can be *recorded* in one currency while the money moved in another
 * does not change this: the statement is the record, and what physically moved is shown
 * beside each line as the detail it is.
 *
 * ## One running balance, and what its sign means
 *
 * There were four positions here — custody, receivable, payable, credit held — kept
 * apart so a parenthesis could never be the only thing distinguishing "they owe us"
 * from "we are holding theirs".
 *
 * In use, four columns turned out to ask the reader to hold the whole model in their
 * head to answer one question. So there is one balance, and the sign carries the
 * distinction, said in words at the foot of the page rather than left to punctuation:
 * **positive means they owe us**, negative that we are holding theirs. See ADR 0032.
 */
final readonly class CounterpartyStatement
{
    /**
     * @param  list<StatementRow>  $rows
     * @param  array<string, Money>  $profit  currency code => margin earned; empty in Client mode
     */
    public function __construct(
        public Counterparty $counterparty,
        public Currency $currency,
        public StatementMode $mode,
        public ?Carbon $from,
        public ?Carbon $to,
        public array $rows,
        /** The balance before the period. */
        public Money $opening,
        /** The balance after it. */
        public Money $closing,
        /** Everything they gave us in the period. */
        public Money $totalIn,
        /** Everything we gave them in the period. */
        public Money $totalOut,
        public array $profit,
        /**
         * An opening figure declared on the record but never posted.
         *
         * Surfaced rather than quietly merged in. The statement is built from the
         * ledger, and a declaration that has not been posted is not in the ledger —
         * adding it here would make the statement disagree with every other figure in
         * the system, and would double once somebody posts it properly.
         */
        public ?Money $declaredOpening,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** Whether the closing balance says they owe us. */
    public function theyOweUs(): bool
    {
        return $this->closing->isPositive();
    }
}
