<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Enums\AccountType;
use App\Enums\CounterpartyType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();

    $this->usd = Currency::query()->where('code', 'USD')->sole();
    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->aed = Currency::query()->where('code', 'AED')->sole();
});

describe('schema', function (): void {
    it('stores a party with its contact details', function (): void {
        $party = Counterparty::factory()->create([
            'name' => 'Omar Hassan',
            'type' => CounterpartyType::Customer,
            'country' => 'AE',
        ])->fresh();

        expect($party?->name)->toBe('Omar Hassan')
            ->and($party?->type)->toBe(CounterpartyType::Customer)
            ->and($party?->country)->toBe('AE')
            ->and($party?->is_active)->toBeTrue();
    });

    it('supports every party type in the specification', function (CounterpartyType $type): void {
        expect(Counterparty::factory()->ofType($type)->create()->fresh()?->type)->toBe($type);
    })->with(CounterpartyType::cases());

    it('records a preferred currency', function (): void {
        $party = Counterparty::factory()->create(['preferred_currency_id' => $this->aed->id]);

        expect($party->preferredCurrency?->code)->toBe('AED');
    });

    it('soft deletes rather than removing the row', function (): void {
        $party = Counterparty::factory()->create();
        $party->delete();

        expect(Counterparty::query()->whereKey($party->id)->exists())->toBeFalse()
            ->and(Counterparty::withTrashed()->whereKey($party->id)->exists())->toBeTrue();
    });
});

// Section 5 is explicit: these must not be combined into one balance field.
/**
 * One running balance per party per currency.
 *
 * There were four positions here — custody, receivable, payable, credit held — kept
 * apart so that "they owe me" and "I am holding their money" could never be confused.
 * In use those turned out to be four descriptions of one relationship, and the owner's
 * objection was exact: they cannot both be true at once, they are one thing and its
 * difference. See ADR 0032.
 *
 * **Positive means they owe us.** Negative means we owe them.
 */
describe('the running balance', function (): void {
    it('has no bucket column anywhere', function (): void {
        expect(Schema::hasColumn('counterparty_opening_balances', 'bucket'))->toBeFalse();
    });

    it('holds one position per currency', function (): void {
        $party = Counterparty::factory()->create();

        $party->setOpeningBalance($this->usd, $this->usd->money('5000'));
        $party->setOpeningBalance($this->egp, $this->egp->money('-120000'));

        expect($party->openingPositions())->toBe(['USD' => '5000.00', 'EGP' => '-120000.00']);
    });

    it('accepts a negative figure, because that is half the point', function (): void {
        $party = Counterparty::factory()->create();

        $party->setOpeningBalance($this->egp, $this->egp->money('-884620'));

        expect($party->openingBalance($this->egp)?->toDisplayString())->toBe('-884620.00');
    });

    it('refuses two positions for the same currency', function (): void {
        $party = Counterparty::factory()->create();

        $party->setOpeningBalance($this->usd, $this->usd->money('100'));

        expect(DB::table('counterparty_opening_balances')->where('counterparty_id', $party->id)->count())->toBe(1);

        expect(fn () => DB::table('counterparty_opening_balances')->insert([
            'counterparty_id' => $party->id,
            'currency_id' => $this->usd->id,
            'amount' => '200',
            'posted_amount' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(Exception::class);
    });

    it('updates an existing position rather than adding a second', function (): void {
        $party = Counterparty::factory()->create();

        $party->setOpeningBalance($this->usd, $this->usd->money('100'));
        $party->setOpeningBalance($this->usd, $this->usd->money('250'));

        expect($party->openingBalances()->count())->toBe(1)
            ->and($party->openingBalance($this->usd)?->toDisplayString())->toBe('250.00');
    });
});

describe('declaring opening positions', function (): void {
    it('distinguishes an undeclared position from a declared zero', function (): void {
        $party = Counterparty::factory()->create();

        expect($party->openingBalance($this->usd))->toBeNull();

        $party->setOpeningBalance($this->usd, $this->usd->money('0'));

        expect($party->openingBalance($this->usd)?->isZero())->toBeTrue();
    });

    it('refuses an amount in a currency other than the one named', function (): void {
        $party = Counterparty::factory()->create();

        expect(fn () => $party->setOpeningBalance($this->usd, $this->egp->money('100')))
            ->toThrow(CurrencyMismatch::class);
    });

    it('keeps full precision', function (): void {
        $party = Counterparty::factory()->create();

        $party->setOpeningBalance($this->usd, $this->usd->money('1234.5678901234'));

        expect($party->openingBalance($this->usd)?->toStorageString())->toBe('1234.5678901234');
    });

    it('refuses to delete a currency a position uses', function (): void {
        $party = Counterparty::factory()->create();
        $party->setOpeningBalance($this->usd, $this->usd->money('100'));

        expect(fn () => $this->usd->delete())->toThrow(Exception::class);
    });
});

describe('accounts belonging to a party', function (): void {
    it('links a credit trust account to its party', function (): void {
        $party = Counterparty::factory()->create(['name' => 'Omar']);

        $account = Account::factory()->ofType(AccountType::CreditTrust)->create([
            'counterparty_id' => $party->id,
        ]);

        expect($account->counterparty?->name)->toBe('Omar')
            ->and($party->accounts()->count())->toBe(1);
    });

    it('leaves a general custody location unattached', function (): void {
        expect(Account::factory()->ofType(AccountType::Safe)->create()->counterparty)->toBeNull();
    });

    // The account outlives the party record: history must not lose the location it
    // pointed at just because a party was removed.
    it('keeps the account when its party is force deleted', function (): void {
        $party = Counterparty::factory()->create();
        $account = Account::factory()->create(['counterparty_id' => $party->id]);

        $party->forceDelete();

        expect($account->fresh()?->counterparty_id)->toBeNull()
            ->and(Account::query()->whereKey($account->id)->exists())->toBeTrue();
    });
});

describe('audit', function (): void {
    it('records changes to a party', function (): void {
        $party = Counterparty::factory()->create(['name' => 'Before']);

        $party->update(['name' => 'After']);

        $entry = $party->auditLogs()->where('event', 'updated')->sole();

        expect($entry->old_values['name'])->toBe('Before')
            ->and($entry->new_values['name'])->toBe('After');
    });
});
