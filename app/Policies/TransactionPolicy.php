<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Transaction;
use App\Models\User;

final class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewTransactions->value);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->can(Permission::ViewTransactions->value);
    }

    /** Preparing a transaction, whether or not it is committed immediately. */
    public function create(User $user): bool
    {
        return $user->can(Permission::RecordTransactions->value);
    }

    /** Committing it to the ledger, which is the point of no return. */
    public function post(User $user): bool
    {
        return $user->can(Permission::PostTransactions->value);
    }

    /** Correcting history. Deliberately separate from posting. */
    public function reverse(User $user, Transaction $transaction): bool
    {
        return $user->can(Permission::ReverseTransactions->value);
    }

    /** Only a draft can be discarded, and only because it never touched the ledger. */
    public function delete(User $user, Transaction $transaction): bool
    {
        return $transaction->isDraft() && $user->can(Permission::DeleteDraftTransactions->value);
    }
}
