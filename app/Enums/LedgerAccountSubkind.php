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

    /**
     * One running account per counterparty per currency.
     *
     * There were four here — custody, receivable, payable, credit held — kept apart so
     * that "they owe me" and "I am holding their money" could never be confused. In use
     * that turned out to be four ways of describing one relationship: money went out to
     * them, money came in from them, and what matters is which way the difference runs.
     *
     * An asset, so the sign reads the way the owner thinks: **positive means they owe
     * us**, negative means we are holding theirs. See ADR 0032.
     */
    case ClientAccount = 'client_account';

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
            self::Cash, self::ClientAccount => LedgerAccountKind::Asset,
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
            self::ClientAccount => LedgerOwnerType::Counterparty,
            default => LedgerOwnerType::System,
        };
    }

    /** Whether this account belongs to a counterparty rather than to us or the system. */
    public function isCounterpartyPosition(): bool
    {
        return $this === self::ClientAccount;
    }

    public function label(): string
    {
        return __('ledger.subkinds.'.$this->value);
    }
}
