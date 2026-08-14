<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Account;
use App\Models\Counterparty;

/**
 * What owns a ledger account.
 *
 * System accounts — profit, fees, equity, the FX clearing accounts — belong to nobody
 * and exist once per currency.
 */
enum LedgerOwnerType: string
{
    case Account = 'account';
    case Counterparty = 'counterparty';
    case System = 'system';

    /** @return class-string<Account>|class-string<Counterparty>|null */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::Account => Account::class,
            self::Counterparty => Counterparty::class,
            self::System => null,
        };
    }
}
