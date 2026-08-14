<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The role a ledger account plays — the chart of accounts, as one enum.
 *
 * Every account in the system is one of these, owned by a custody location, a
 * counterparty, or nobody. See docs/posting-rules.md §2.
 */
enum LedgerAccountSubkind: string
{
    /** Money in a custody location. Owned by an Account. */
    case Cash = 'cash';

    /** Our money, physically held by them. Owned by a Counterparty. */
    case Custody = 'custody';

    /** Their money, owed to us. Owned by a Counterparty. */
    case Receivable = 'receivable';

    /** Our money, owed to them. Owned by a Counterparty. */
    case Payable = 'payable';

    /** Their money, physically held by us. Owned by a Counterparty. */
    case CreditTrust = 'credit_trust';

    /** The open leg of an exchange. System-owned, one per currency. */
    case FxPosition = 'fx_position';

    case TradingProfit = 'trading_profit';
    case FeesIncome = 'fees_income';
    case Expense = 'expense';
    case CommissionExpense = 'commission_expense';
    case OpeningEquity = 'opening_equity';
    case Capital = 'capital';
    case AdjustmentEquity = 'adjustment_equity';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $subkind): string => $subkind->value, self::cases());
    }

    public function kind(): LedgerAccountKind
    {
        return match ($this) {
            self::Cash, self::Custody, self::Receivable => LedgerAccountKind::Asset,
            self::Payable, self::CreditTrust => LedgerAccountKind::Liability,
            self::FxPosition => LedgerAccountKind::Clearing,
            self::TradingProfit, self::FeesIncome => LedgerAccountKind::Income,
            self::Expense, self::CommissionExpense => LedgerAccountKind::Expense,
            self::OpeningEquity, self::Capital, self::AdjustmentEquity => LedgerAccountKind::Equity,
        };
    }

    /** What kind of thing owns an account of this subkind. */
    public function ownerType(): LedgerOwnerType
    {
        return match ($this) {
            self::Cash => LedgerOwnerType::Account,
            self::Custody, self::Receivable, self::Payable, self::CreditTrust => LedgerOwnerType::Counterparty,
            default => LedgerOwnerType::System,
        };
    }

    /**
     * The counterparty bucket this subkind corresponds to, if any.
     *
     * The bridge between the ledger and the four positions of Section 5, so that a
     * party's statement and their ledger accounts cannot drift apart.
     */
    public function bucket(): ?BalanceBucket
    {
        return match ($this) {
            self::Custody => BalanceBucket::Custody,
            self::Receivable => BalanceBucket::Receivable,
            self::Payable => BalanceBucket::Payable,
            self::CreditTrust => BalanceBucket::CreditTrust,
            default => null,
        };
    }

    public static function forBucket(BalanceBucket $bucket): self
    {
        return match ($bucket) {
            BalanceBucket::Custody => self::Custody,
            BalanceBucket::Receivable => self::Receivable,
            BalanceBucket::Payable => self::Payable,
            BalanceBucket::CreditTrust => self::CreditTrust,
        };
    }

    public function label(): string
    {
        return __('ledger.subkinds.'.$this->value);
    }
}
