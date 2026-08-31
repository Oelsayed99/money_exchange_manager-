<?php

declare(strict_types=1);

namespace App\Domain\Statement;

use App\Domain\Money\Money;
use App\Enums\TransactionType;
use Illuminate\Support\Carbon;

/**
 * One line of a counterparty statement: a date, what it was, in, out, and the balance.
 *
 * Exactly one of {@see $in} and {@see $out} is set. Money either came from them or went
 * to them; a line that did both would be two lines.
 */
final readonly class StatementRow
{
    public function __construct(
        public int $transactionId,
        public TransactionType $type,
        public Carbon $occurredAt,
        public ?string $reference,
        public ?string $description,
        /** Money we took from them. */
        public ?Money $in,
        /** Money we paid to them. */
        public ?Money $out,
        /**
         * The running balance once this line is applied.
         *
         * Positive means they owe us; negative means we are holding theirs.
         */
        public Money $balanceAfter,
        /**
         * What actually moved, when it was not the currency this statement is in.
         *
         * "10,000 USD at 50.85" against a line recorded as 508,500 EGP. Null when the
         * money moved in the same currency it was recorded in, which is most of the time.
         */
        public ?Money $movedAmount,
        public ?string $rate,
        /**
         * The margin on the transaction behind this line.
         *
         * Null in Client mode, where it was never queried. Also null on the second and
         * later lines of one transaction: the profit belongs to the deal, not to each
         * of its legs, and repeating it per line would show it twice and total it twice.
         */
        public ?Money $profit,
    ) {}
}
