<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Enums\LedgerAccountSubkind;
use App\Enums\LedgerOwnerType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerAccount;
use InvalidArgumentException;

/**
 * Finds — and creates on first use — the one ledger account for a given
 * (subkind, owner, currency).
 *
 * Accounts are created lazily rather than pre-generated for every combination. A
 * system with a dozen currencies, fifty custody locations and hundreds of parties has
 * tens of thousands of possible accounts, almost all of which will never hold an
 * entry; the chart of accounts should describe what actually happened.
 *
 * The guarantee that matters is uniqueness: the same inputs always resolve to the same
 * row. Two accounts meaning the same thing would split one balance across both, and
 * neither would look wrong on its own.
 */
final class LedgerAccountResolver
{
    /** @var array<string, LedgerAccount> */
    private array $memo = [];

    /** Money in a custody location. */
    public function forAccount(Account $account, Currency $currency): LedgerAccount
    {
        return $this->resolve(LedgerAccountSubkind::Cash, $account->getKey(), $currency);
    }

    /** One of a counterparty's four positions. */
    /**
     * A counterparty's running account in one currency.
     *
     * One per party per currency, and signed: a debit balance means they owe us, a
     * credit balance means we are holding theirs. There used to be four of these.
     */
    public function forCounterparty(Counterparty $counterparty, Currency $currency): LedgerAccount
    {
        return $this->resolve(LedgerAccountSubkind::ClientAccount, $counterparty->getKey(), $currency);
    }

    /** A system account: profit, fees, equity, or an FX clearing account. */
    public function system(LedgerAccountSubkind $subkind, Currency $currency): LedgerAccount
    {
        if ($subkind->ownerType() !== LedgerOwnerType::System) {
            throw new InvalidArgumentException(
                "[{$subkind->value}] belongs to a {$subkind->ownerType()->value} and cannot be resolved as a system account."
            );
        }

        return $this->resolve($subkind, null, $currency);
    }

    /** Drop the in-memory memo. Needed between tests, and after a rebuild. */
    public function flush(): void
    {
        $this->memo = [];
    }

    private function resolve(LedgerAccountSubkind $subkind, ?int $ownerId, Currency $currency): LedgerAccount
    {
        $ownerType = $subkind->ownerType();

        if ($ownerType !== LedgerOwnerType::System && $ownerId === null) {
            throw new InvalidArgumentException(
                "[{$subkind->value}] requires an owner of type {$ownerType->value}, but none was given."
            );
        }

        $code = LedgerAccount::codeFor($subkind, $ownerId, $currency->code);

        if (isset($this->memo[$code])) {
            return $this->memo[$code];
        }

        // firstOrCreate against the unique code, so two concurrent postings that both
        // need a brand-new account cannot produce two rows for the same thing.
        $ledgerAccount = LedgerAccount::query()->firstOrCreate(
            ['code' => $code],
            [
                'subkind' => $subkind,
                'kind' => $subkind->kind(),
                'owner_type' => $ownerType,
                'owner_id' => $ownerType === LedgerOwnerType::System ? null : $ownerId,
                'currency_id' => $currency->getKey(),
                'is_active' => true,
            ],
        );

        return $this->memo[$code] = $ledgerAccount;
    }
}
