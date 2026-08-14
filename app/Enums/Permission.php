<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every permission the application recognises.
 *
 * An enum rather than loose strings so that a typo in a policy or a Blade guard is a
 * compile-time problem instead of a permission that silently never matches — which
 * would fail *open* or *closed* depending on where it appeared, and neither is
 * acceptable in a financial system.
 *
 * Permissions are added as the modules they protect are built. Declaring the whole
 * Section 14 matrix now — credit accounts, liability reports, profit visibility —
 * would mean shipping guards for features that do not exist and cannot be tested.
 * The named credit permissions arrive with the credit module.
 */
enum Permission: string
{
    case ViewCurrencies = 'currencies.view';
    case ManageCurrencies = 'currencies.manage';

    case ViewAccounts = 'accounts.view';
    case ManageAccounts = 'accounts.manage';

    case ViewCounterparties = 'counterparties.view';
    case ManageCounterparties = 'counterparties.manage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
