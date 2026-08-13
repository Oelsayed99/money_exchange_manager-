<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where money physically sits (Section 4).
 *
 * These are custody locations, not accounting classifications. The distinction matters:
 * a physical safe and a bank account are both places money is held, and the system has
 * to be able to say which one a given balance is in.
 */
enum AccountType: string
{
    case Bank = 'bank';
    case CashWallet = 'cash_wallet';
    case Safe = 'safe';
    case PersonalCustody = 'personal_custody';
    case BusinessCustody = 'business_custody';
    case MobileWallet = 'mobile_wallet';
    case ExchangeAccount = 'exchange_account';
    case PartnerCustody = 'partner_custody';
    case CustomerBalance = 'customer_balance';

    /**
     * Money received from a counterparty and held as a liability, repayable in any
     * currency, earning nothing. The "half transaction" model of Section 4.
     */
    case CreditTrust = 'credit_trust';

    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return __('accounts.types.'.$this->value);
    }

    /**
     * Whether this type holds money belonging to somebody else.
     *
     * A credit/trust account is a liability, not an asset: the balance is what is owed
     * back, and it must never be summed together with money the business owns.
     */
    public function isLiability(): bool
    {
        return $this === self::CreditTrust;
    }
}
