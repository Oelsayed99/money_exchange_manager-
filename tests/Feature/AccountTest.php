<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountCurrency;
use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();

    $this->usd = Currency::query()->where('code', 'USD')->sole();
    $this->aed = Currency::query()->where('code', 'AED')->sole();
});

describe('schema', function (): void {
    it('stores an account with its custody details', function (): void {
        $account = Account::factory()->create([
            'name' => 'Emirates NBD current',
            'type' => AccountType::Bank,
            'owner' => 'Omar',
            'provider' => 'Emirates NBD',
        ])->fresh();

        expect($account?->name)->toBe('Emirates NBD current')
            ->and($account?->type)->toBe(AccountType::Bank)
            ->and($account?->owner)->toBe('Omar')
            ->and($account?->is_active)->toBeTrue();
    });

    it('supports every custody type in the specification', function (AccountType $type): void {
        expect(Account::factory()->ofType($type)->create()->fresh()?->type)->toBe($type);
    })->with(AccountType::cases());

    // A8: soft deletes on reference data, so an account referenced by history can be
    // retired without the history losing what it pointed at.
    it('soft deletes rather than removing the row', function (): void {
        $account = Account::factory()->create();

        $account->delete();

        expect(Account::query()->whereKey($account->id)->exists())->toBeFalse()
            ->and(Account::withTrashed()->whereKey($account->id)->exists())->toBeTrue();
    });

    it('carries no balance column, because balances come from the ledger', function (): void {
        $columns = Schema::getColumnListing('accounts');

        expect($columns)->not->toContain('balance')
            ->and($columns)->not->toContain('current_balance');
    });
});

describe('supported currencies', function (): void {
    it('holds several currencies with their own opening balances', function (): void {
        $account = Account::factory()->holding(['USD' => '1000.50', 'AED' => '3670'])->create();

        expect($account->currencies)->toHaveCount(2)
            ->and($account->openingBalance($this->usd)?->toDisplayString())->toBe('1000.50')
            ->and($account->openingBalance($this->aed)?->toDisplayString())->toBe('3670.00');
    });

    it('defaults an opening balance to zero', function (): void {
        $account = Account::factory()->create();
        $account->currencies()->attach($this->usd->id);

        expect($account->openingBalance($this->usd)?->isZero())->toBeTrue();
    });

    // "No opening balance" and "does not deal in this currency" are different facts.
    it('distinguishes an unheld currency from a zero balance', function (): void {
        $account = Account::factory()->holding(['USD' => '0'])->create();

        expect($account->openingBalance($this->usd)?->isZero())->toBeTrue()
            ->and($account->openingBalance($this->aed))->toBeNull()
            ->and($account->supports($this->usd))->toBeTrue()
            ->and($account->supports($this->aed))->toBeFalse();
    });

    it('refuses the same currency twice on one account', function (): void {
        $account = Account::factory()->holding(['USD' => '1'])->create();

        expect(fn () => $account->currencies()->attach($this->usd->id))
            ->toThrow(QueryException::class);
    });

    it('refuses to delete a currency an account holds', function (): void {
        Account::factory()->holding(['USD' => '1'])->create();

        expect(fn () => $this->usd->delete())->toThrow(QueryException::class);
    });

    it('releases its currency links when the account is force deleted', function (): void {
        $account = Account::factory()->holding(['USD' => '1'])->create();

        $account->forceDelete();

        expect(DB::table('account_currency')->where('account_id', $account->id)->exists())->toBeFalse();
    });
});

describe('opening balances keep full precision', function (): void {
    it('stores an exact decimal without rounding it', function (): void {
        $account = Account::factory()->holding(['USD' => '1234.5678901234'])->create();

        // Ten decimal places is the storage scale; nothing beyond it was accepted.
        expect($account->openingBalance($this->usd)?->toStorageString())->toBe('1234.5678901234');
    });

    it('returns a Money that knows its own currency', function (): void {
        $account = Account::factory()->holding(['AED' => '3670'])->create();

        $balance = $account->openingBalance($this->aed);

        expect($balance)->toBeInstanceOf(Money::class)
            ->and($balance?->currency->code)->toBe('AED');
    });

    it('never loses a hundredth over a large value', function (): void {
        $account = Account::factory()->holding(['USD' => '99999999999.99'])->create();

        expect($account->openingBalance($this->usd)?->toDisplayString())->toBe('99999999999.99');
    });
});

describe('identifier masking', function (): void {
    it('reveals only the last four characters', function (): void {
        $account = Account::factory()->create(['identifier' => 'AE070331234567890123456']);

        expect($account->masked_identifier)->toEndWith('3456')
            ->and($account->masked_identifier)->not->toContain('AE07033')
            ->and($account->masked_identifier)->toHaveLength(mb_strlen('AE070331234567890123456'));
    });

    it('masks a short identifier entirely', function (): void {
        expect(Account::factory()->create(['identifier' => '123'])->masked_identifier)->toBe('•••');
    });

    it('has nothing to mask when there is no identifier', function (): void {
        expect(Account::factory()->create(['identifier' => null])->masked_identifier)->toBeNull();
    });

    // An account number sitting in an append-only, undeletable log is a liability.
    it('records that the identifier changed without recording the number', function (): void {
        $account = Account::factory()->create(['identifier' => 'AE0703311111111111111']);

        $account->update(['identifier' => 'AE0703322222222222222']);

        $entry = $account->auditLogs()->where('event', 'updated')->sole();

        expect($entry->new_values)->toHaveKey('identifier')
            ->and($entry->new_values['identifier'])->toBe('[redacted]');

        expect(json_encode(DB::table('audit_logs')->where('id', $entry->id)->sole()))
            ->not->toContain('22222222222');
    });
});

describe('money cast', function (): void {
    // The row says USD; handing it AED would store a number that means something
    // other than what the row claims.
    it('rejects writing an amount in the wrong currency', function (): void {
        $account = Account::factory()->holding(['USD' => '1'])->create();
        $pivot = AccountCurrency::query()->where('account_id', $account->id)->sole();

        expect(function () use ($pivot) {
            $pivot->opening_balance = $this->aed->money('1');
            $pivot->save();
        })->toThrow(CurrencyMismatch::class, 'Cannot store [AED] and [USD]');
    });

    // Eloquent casts extra pivot attributes before the foreign keys are merged, so the
    // currency cannot be checked there. Refusing beats storing an unverified amount.
    it('refuses a Money attached directly, where the currency cannot be checked', function (): void {
        $account = Account::factory()->create();

        expect(fn () => $account->currencies()->attach($this->usd->id, [
            'opening_balance' => $this->aed->money('1'),
        ]))->toThrow(InvalidArgumentException::class, 'is not among the attributes being written');
    });

    it('accepts a Money through the account API, which can check it', function (): void {
        $account = Account::factory()->holding(['USD' => '0'])->create();

        $account->setOpeningBalance($this->usd, $this->usd->money('500.25'));

        expect($account->openingBalance($this->usd)?->toDisplayString())->toBe('500.25');
    });

    it('refuses a mismatched Money through the account API too', function (): void {
        $account = Account::factory()->holding(['USD' => '0'])->create();

        expect(fn () => $account->setOpeningBalance($this->usd, $this->aed->money('1')))
            ->toThrow(CurrencyMismatch::class);
    });

    it('rejects a raw decimal carrying more precision than storage', function (): void {
        $account = Account::factory()->create();

        expect(fn () => $account->currencies()->attach($this->usd->id, ['opening_balance' => '1.12345678901']))
            ->toThrow(InvalidArgumentException::class, 'more than 10 decimal places');
    });

    it('accepts an amount in the row own currency', function (): void {
        $account = Account::factory()->holding(['USD' => '1'])->create();
        $pivot = AccountCurrency::query()->where('account_id', $account->id)->sole();

        $pivot->opening_balance = $this->usd->money('250.75');
        $pivot->save();

        expect($pivot->fresh()?->opening_balance?->toDisplayString())->toBe('250.75');
    });

    it('reads the column back as Money carrying the row currency', function (): void {
        $account = Account::factory()->holding(['AED' => '3670.25'])->create();
        $pivot = AccountCurrency::query()->where('account_id', $account->id)->sole();

        expect($pivot->opening_balance)->toBeInstanceOf(Money::class)
            ->and($pivot->opening_balance?->currency->code)->toBe('AED')
            ->and($pivot->opening_balance?->toStorageString())->toBe('3670.2500000000');
    });

    it('accepts a plain decimal string and interprets it in the row currency', function (): void {
        $account = Account::factory()->create();
        $account->currencies()->attach($this->aed->id, ['opening_balance' => '12.5']);

        $pivot = AccountCurrency::query()->where('account_id', $account->id)->sole();

        expect($pivot->opening_balance?->currency->code)->toBe('AED')
            ->and($pivot->opening_balance?->toDisplayString())->toBe('12.50');
    });
});

describe('currency registry', function (): void {
    it('resolves a specification by id and by code', function (): void {
        $registry = app(CurrencyRegistry::class);

        expect($registry->byId($this->usd->id)->code)->toBe('USD')
            ->and($registry->byCode('aed')->code)->toBe('AED')
            ->and($registry->byCode('AED')->decimalPlaces)->toBe(2);
    });

    it('loads the whole set once rather than per lookup', function (): void {
        $registry = app(CurrencyRegistry::class);
        $registry->byId($this->usd->id);

        DB::enableQueryLog();
        $registry->byId($this->aed->id);
        $registry->byCode('EUR');
        $registry->byCode('EGP');

        expect(DB::getQueryLog())->toBeEmpty();
        DB::disableQueryLog();
    });

    it('complains clearly about a currency that does not exist', function (): void {
        expect(fn () => app(CurrencyRegistry::class)->byCode('ZZZ'))
            ->toThrow(RuntimeException::class, 'No currency with code [ZZZ]');
    });
});
