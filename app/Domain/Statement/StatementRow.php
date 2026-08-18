<?php

declare(strict_types=1);

namespace App\Domain\Statement;

use App\Domain\Money\Money;
use App\Enums\BalanceBucket;
use App\Enums\TransactionType;
use Illuminate\Support\Carbon;

/**
 * One line of a counterparty statement.
 *
 * Modelled on the sheet this replaces: a date, what it was, money in, money out, and
 * where that leaves things. One difference, and it is the important one — the position
 * is not a signed number. See {@see CounterpartyStatement}.
 *
 * Exactly one of {@see $in} and {@see $out} is set. An entry either brought value from
 * the party or sent value to them; there is no line that does both, and a row that
 * could would be two rows.
 */
final readonly class StatementRow
{
    public function __construct(
        public int $transactionId,
        public TransactionType $type,
        public Carbon $occurredAt,
        public ?string $reference,
        public ?string $description,
        /** Which of the four positions this line moved. */
        public BalanceBucket $bucket,
        /** Value from them to us. */
        public ?Money $in,
        /** Value from us to them. */
        public ?Money $out,
        /** This bucket's balance once this line is applied. */
        public Money $balanceAfter,
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
