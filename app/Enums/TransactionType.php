<?php

declare(strict_types=1);

namespace App\Enums;

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
}
