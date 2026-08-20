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

    case ViewTransactions = 'transactions.view';

    /** Prepare a transaction, without committing it to the ledger. */
    case RecordTransactions = 'transactions.record';

    /** Commit a prepared transaction, which is the point of no return. */
    case PostTransactions = 'transactions.post';

    /** Reverse something already posted. Never a deletion. */
    case ReverseTransactions = 'transactions.reverse';

    /** Discard a draft. Only ever a draft — nothing posted can be deleted. */
    case DeleteDraftTransactions = 'transactions.delete_draft';

    /** Read the reconciliation record. */
    case ViewReconciliations = 'reconciliations.view';

    /**
     * Record a count and explain a difference.
     *
     * Separate from posting, because reconciling writes no ledger entry — a difference
     * is corrected by posting an adjustment, which needs the posting permission on its
     * own merits.
     */
    case ManageReconciliations = 'reconciliations.manage';

    /**
     * Read the audit trail.
     *
     * Deliberately not given to operators or viewers. The trail carries IP addresses,
     * user agents and the before-and-after of every change including other people's —
     * it is a record *about* the people using the system, and reading it is a
     * different kind of act from reading the ledger.
     */
    case ViewAudit = 'audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
