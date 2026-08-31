<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every kind of movement the ledger records.
 *
 * Each maps to a posting rule in docs/posting-rules.md §3.
 *
 * ## In and Out replaced nine types
 *
 * Money received, money paid, a loan given, a loan received, a receivable settlement, a
 * payable settlement, a credit deposit, a credit settlement and a refund were nine
 * names for two events: money came in from somebody, or money went out to them. Several
 * of them already produced identical entries and were kept apart only by intent — and
 * asking an operator at the counter to classify intent is asking them to get it wrong.
 *
 * What remains of the distinction is the running balance: out beyond in means they owe
 * us, in beyond out means we are holding theirs. See ADR 0032.
 */
enum TransactionType: string
{
    case OpeningBalance = 'opening_balance';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Transfer = 'transfer';
    /** Money we took from somebody, whatever the reason. */
    case In = 'in';

    /** Money we paid to somebody, whatever the reason. */
    case Out = 'out';

    case CurrencyExchange = 'currency_exchange';
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

    /** Whether this type prices a margin. Only the exchange screen does that. */
    public function isExchange(): bool
    {
        return $this === self::CurrencyExchange;
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
        return $this->clientEffect() !== null;
    }

    /** Whether it moves money between two of our own locations. */
    public function needsDestinationAccount(): bool
    {
        return $this === self::Transfer;
    }

    /**
     * Whether the amount may be recorded in a currency other than the one that moved.
     *
     * Take 10,000 dollars and record it against the client as pounds at an agreed rate;
     * pay a client's pounds out to them in euros. Both are one movement with two
     * currencies, and both facts are kept. Only client movements do this — our own cash
     * and our own capital move in one currency by definition.
     */
    public function mayConvert(): bool
    {
        return $this->clientEffect() !== null;
    }

    /**
     * Which way this moves the client's running balance, if it moves it at all.
     *
     * Out is positive: paying somebody puts them into debt to us, or works off what we
     * owed them. In is the reverse. Everything else leaves the relationship alone.
     */
    public function clientEffect(): ?ClientEffect
    {
        return match ($this) {
            self::Out => ClientEffect::TheyOweUsMore,
            self::In => ClientEffect::WeOweThemMore,
            default => null,
        };
    }
}
