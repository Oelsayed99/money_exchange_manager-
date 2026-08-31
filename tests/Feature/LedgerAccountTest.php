<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\EntryDirection;
use App\Enums\LedgerAccountKind;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LedgerOwnerType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerAccount;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->usd = Currency::query()->where('code', 'USD')->sole();
    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->resolver = app(LedgerAccountResolver::class);
});

describe('accounting nature', function (): void {
    it('knows which direction increases each kind', function (): void {
        expect(LedgerAccountKind::Asset->normalBalance())->toBe(EntryDirection::Debit)
            ->and(LedgerAccountKind::Expense->normalBalance())->toBe(EntryDirection::Debit)
            ->and(LedgerAccountKind::Clearing->normalBalance())->toBe(EntryDirection::Debit)
            ->and(LedgerAccountKind::Liability->normalBalance())->toBe(EntryDirection::Credit)
            ->and(LedgerAccountKind::Equity->normalBalance())->toBe(EntryDirection::Credit)
            ->and(LedgerAccountKind::Income->normalBalance())->toBe(EntryDirection::Credit);
    });

    // A balance is one signed sum rather than two columns subtracted at every call
    // site — the sort of thing that is right in nine places and wrong in the tenth.
    it('signs an entry by whether it moves with or against the account', function (LedgerAccountKind $kind): void {
        $normal = $kind->normalBalance();

        expect($kind->signFor($normal))->toBe(1)
            ->and($kind->signFor($normal->opposite()))->toBe(-1);
    })->with(LedgerAccountKind::cases());

    it('assigns every subkind a kind and an owner type', function (LedgerAccountSubkind $subkind): void {
        expect($subkind->kind())->toBeInstanceOf(LedgerAccountKind::class)
            ->and($subkind->ownerType())->toBeInstanceOf(LedgerOwnerType::class);
    })->with(LedgerAccountSubkind::cases());

    // An asset, and that is what makes the sign read the way the owner thinks: a debit
    // balance means they owe us, a credit balance that we are holding theirs.
    it('classifies the client account as an asset', function (): void {
        expect(LedgerAccountSubkind::ClientAccount->kind())->toBe(LedgerAccountKind::Asset);
    });
});

describe('the counterparty account', function (): void {
    // One account per party per currency, and it is an asset — which is what makes the
    // sign read the way the owner thinks: a debit balance means they owe us.
    it('is a single asset account, so the sign carries the relationship', function (): void {
        $subkind = LedgerAccountSubkind::ClientAccount;

        expect($subkind->isCounterpartyPosition())->toBeTrue()
            ->and($subkind->ownerType())->toBe(LedgerOwnerType::Counterparty)
            ->and($subkind->kind())->toBe(LedgerAccountKind::Asset)
            ->and($subkind->kind()->normalBalance())->toBe(EntryDirection::Debit);
    });

    it('is the only subkind a counterparty owns', function (): void {
        $owned = array_filter(
            LedgerAccountSubkind::cases(),
            fn (LedgerAccountSubkind $s): bool => $s->isCounterpartyPosition(),
        );

        expect($owned)->toHaveCount(1);
    });

    it('leaves our own and the system accounts out of it', function (): void {
        expect(LedgerAccountSubkind::Cash->isCounterpartyPosition())->toBeFalse()
            ->and(LedgerAccountSubkind::TradingProfit->isCounterpartyPosition())->toBeFalse();
    });
});

describe('resolution', function (): void {
    it('creates an account for a custody location on first use', function (): void {
        $account = Account::factory()->create(['name' => 'Office safe']);

        $ledger = $this->resolver->forAccount($account, $this->usd);

        expect($ledger->subkind)->toBe(LedgerAccountSubkind::Cash)
            ->and($ledger->kind)->toBe(LedgerAccountKind::Asset)
            ->and($ledger->owner_type)->toBe(LedgerOwnerType::Account)
            ->and($ledger->owner_id)->toBe($account->id)
            ->and($ledger->currency_id)->toBe($this->usd->id);
    });

    // The guarantee everything else rests on: two accounts meaning the same thing
    // would split one balance across both, and neither would look wrong alone.
    it('returns the same row for the same inputs, every time', function (): void {
        $account = Account::factory()->create();

        $first = $this->resolver->forAccount($account, $this->usd);
        $this->resolver->flush();
        $second = $this->resolver->forAccount($account, $this->usd);

        expect($second->id)->toBe($first->id)
            ->and(LedgerAccount::query()->count())->toBe(1);
    });

    it('keeps each currency in its own account', function (): void {
        $account = Account::factory()->create();

        $usd = $this->resolver->forAccount($account, $this->usd);
        $egp = $this->resolver->forAccount($account, $this->egp);

        expect($usd->id)->not->toBe($egp->id)
            ->and(LedgerAccount::query()->count())->toBe(2);
    });

    it('keeps each custody location in its own account', function (): void {
        $a = $this->resolver->forAccount(Account::factory()->create(), $this->usd);
        $b = $this->resolver->forAccount(Account::factory()->create(), $this->usd);

        expect($a->id)->not->toBe($b->id);
    });

    // One account per party per currency — asked for repeatedly, created once.
    it('resolves one account per counterparty per currency', function (): void {
        $party = Counterparty::factory()->create();

        $first = $this->resolver->forCounterparty($party, $this->egp);
        $again = $this->resolver->forCounterparty($party, $this->egp);
        $other = $this->resolver->forCounterparty($party, $this->usd);

        expect($first->id)->toBe($again->id)
            ->and($first->id)->not->toBe($other->id)
            ->and($first->owner_id)->toBe($party->id);
    });

    it('resolves a system account once per currency', function (): void {
        $usd = $this->resolver->system(LedgerAccountSubkind::TradingProfit, $this->usd);
        $egp = $this->resolver->system(LedgerAccountSubkind::TradingProfit, $this->egp);

        expect($usd->owner_id)->toBeNull()
            ->and($usd->owner_type)->toBe(LedgerOwnerType::System)
            ->and($usd->id)->not->toBe($egp->id);
    });

    it('refuses to resolve an owned subkind as a system account', function (): void {
        expect(fn () => $this->resolver->system(LedgerAccountSubkind::Cash, $this->usd))
            ->toThrow(InvalidArgumentException::class, 'cannot be resolved as a system account');
    });

    it('builds a readable, deterministic code', function (): void {
        $account = Account::factory()->create();

        expect($this->resolver->forAccount($account, $this->usd)->code)->toBe("cash:account:{$account->id}:USD")
            ->and($this->resolver->system(LedgerAccountSubkind::FxPosition, $this->egp)->code)
            ->toBe('fx_position:system:0:EGP');
    });
});

describe('database constraints', function (): void {
    it('refuses a duplicate code', function (): void {
        $account = Account::factory()->create();
        $this->resolver->forAccount($account, $this->usd);

        expect(fn () => LedgerAccount::query()->create([
            'code' => "cash:account:{$account->id}:USD",
            'subkind' => LedgerAccountSubkind::Cash,
            'kind' => LedgerAccountKind::Asset,
            'owner_type' => LedgerOwnerType::Account,
            'owner_id' => $account->id,
            'currency_id' => $this->usd->id,
        ]))->toThrow(QueryException::class);
    });

    it('refuses an unrecognised subkind', function (): void {
        expect(fn () => DB::table('ledger_accounts')->insert([
            'code' => 'nonsense:system:0:USD',
            'subkind' => 'nonsense',
            'kind' => 'asset',
            'owner_type' => 'system',
            'owner_id' => null,
            'currency_id' => $this->usd->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    // A system account with an owner would be a second, parallel account belonging to
    // nobody in particular, quietly splitting a balance.
    it('refuses a system account that has an owner', function (): void {
        expect(fn () => DB::table('ledger_accounts')->insert([
            'code' => 'trading_profit:system:9:USD',
            'subkind' => 'trading_profit',
            'kind' => 'income',
            'owner_type' => 'system',
            'owner_id' => 9,
            'currency_id' => $this->usd->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses an owned account with no owner', function (): void {
        expect(fn () => DB::table('ledger_accounts')->insert([
            'code' => 'cash:account:0:USD',
            'subkind' => 'cash',
            'kind' => 'asset',
            'owner_type' => 'account',
            'owner_id' => null,
            'currency_id' => $this->usd->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses to delete a currency an account uses', function (): void {
        $this->resolver->system(LedgerAccountSubkind::TradingProfit, $this->usd);

        expect(fn () => $this->usd->delete())->toThrow(QueryException::class);
    });
});
