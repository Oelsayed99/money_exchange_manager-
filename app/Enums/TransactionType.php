<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Ledger\BucketEffect;

/**
 * The nineteen transaction types of Section 6.
 *
 * Each maps to a posting rule in docs/posting-rules.md §3. Several types produce
 * identical entries — money received from a party and a receivable settlement, for
 * instance — and are kept apart because the intent differs and reporting needs to
 * distinguish them. The type is stored; the entries do not have to differ for the
 * distinction to survive.
 */
enum TransactionType: string
{
    case OpeningBalance = 'opening_balance';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Transfer = 'transfer';
    case MoneyReceived = 'money_received';
    case MoneyPaid = 'money_paid';
    case LoanGiven = 'loan_given';
    case LoanReceived = 'loan_received';
    case ReceivableSettlement = 'receivable_settlement';
    case PayableSettlement = 'payable_settlement';
    case CurrencyExchange = 'currency_exchange';
    case CreditDeposit = 'credit_deposit';
    case CreditSettlement = 'credit_settlement';
    case Fee = 'fee';
    case Expense = 'expense';
    case ProfitAdjustment = 'profit_adjustment';
    case BalanceAdjustment = 'balance_adjustment';
    case Refund = 'refund';
    case Reversal = 'reversal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return __('transactions.types.'.$this->value);
    }

    /** Whether this type may carry a rate and produce profit (Phase 4). */
    public function isExchange(): bool
    {
        return in_array($this, [self::CurrencyExchange, self::CreditSettlement], true);
    }

    /**
     * Whether the type is produced by the system rather than chosen by an operator.
     *
     * A reversal is never created directly — it is always the consequence of reversing
     * something else, and offering it as an option would let somebody post a reversal
     * that reverses nothing.
     */
    public function isSystemGenerated(): bool
    {
        return $this === self::Reversal;
    }

    /**
     * Whether an operator can record this from the movements screen.
     *
     * An exchange needs two amounts and a rate and has its own screen. A reversal is
     * never created directly — it is the consequence of reversing something else, and
     * offering it here would let somebody post a reversal that reverses nothing.
     */
    public function recordableByHand(): bool
    {
        return ! in_array($this, [self::CurrencyExchange, self::Reversal], true);
    }

    /** Whether the movement is between the business and somebody else. */
    public function needsCounterparty(): bool
    {
        return $this->bucketEffect() !== null;
    }

    /** Whether it moves money between two of our own locations. */
    public function needsDestinationAccount(): bool
    {
        return $this === self::Transfer;
    }

    /**
     * Whether the operator must say which position it opens.
     *
     * Only an opening balance: every other type's bucket follows from what it is,
     * which is the point of having types at all.
     */
    public function needsBucket(): bool
    {
        return $this === self::OpeningBalance;
    }

    /**
     * Which of a counterparty's positions this moves, and which way.
     *
     * Read against the posting rules, not invented: money in against what they owe
     * *reduces* the receivable, while lending *increases* it, and both are cash
     * crossing the counter in opposite directions. See docs/posting-rules.md §2.
     */
    public function bucketEffect(): ?BucketEffect
    {
        return match ($this) {
            // They pay us: what they owe shrinks.
            self::MoneyReceived, self::ReceivableSettlement => new BucketEffect(BalanceBucket::Receivable, false),

            // We hand money over against a promise: what they owe grows.
            self::LoanGiven => new BucketEffect(BalanceBucket::Receivable, true),

            // We take money on a promise: what we owe grows.
            self::LoanReceived => new BucketEffect(BalanceBucket::Payable, true),

            // We pay them: what we owe shrinks.
            self::MoneyPaid, self::PayableSettlement, self::Refund => new BucketEffect(BalanceBucket::Payable, false),

            // Their money, into and out of our keeping.
            self::CreditDeposit => new BucketEffect(BalanceBucket::CreditTrust, true),
            self::CreditSettlement => new BucketEffect(BalanceBucket::CreditTrust, false),

            default => null,
        };
    }
}
