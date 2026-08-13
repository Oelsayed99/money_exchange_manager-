<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Enums\AccountType;
use App\Enums\BalanceBucket;
use App\Enums\CounterpartyType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();

    $this->usd = Currency::query()->where('code', 'USD')->sole();
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
describe('the separation Section 5 requires', function (): void {
    it('has no single balance column anywhere', function (): void {
        expect(Schema::getColumnListing('counterparties'))
            ->not->toContain('balance')
            ->not->toContain('receivable')
            ->not->toContain('payable')
            ->not->toContain('custody');
    });

    // The case that makes the requirement real: a party who simultaneously owes money
    // and holds money on the business's behalf. Netting these to a single figure
    // destroys the information needed to chase one and reconcile the other.
    it('holds custody and receivable against the same party at once', function (): void {
        $party = Counterparty::factory()->withPositions([
            'custody' => ['USD' => '5000'],
            'receivable' => ['USD' => '1200'],
        ])->create();

        expect($party->openingBalance(BalanceBucket::Custody, $this->usd)?->toDisplayString())->toBe('5000.00')
            ->and($party->openingBalance(BalanceBucket::Receivable, $this->usd)?->toDisplayString())->toBe('1200.00');
    });

    it('keeps a receivable and a payable to the same party apart', function (): void {
        $party = Counterparty::factory()->withPositions([
            'receivable' => ['USD' => '800'],
            'payable' => ['USD' => '300'],
        ])->create();

        // Emphatically not 500.
        expect($party->openingBalance(BalanceBucket::Receivable, $this->usd)?->toDisplayString())->toBe('800.00')
            ->and($party->openingBalance(BalanceBucket::Payable, $this->usd)?->toDisplayString())->toBe('300.00');
    });

    it('keeps every bucket separate in every currency', function (): void {
        $party = Counterparty::factory()->withPositions([
            'custody' => ['USD' => '100', 'AED' => '200'],
            'receivable' => ['USD' => '300', 'AED' => '400'],
            'payable' => ['USD' => '500', 'AED' => '600'],
            'credit_trust' => ['USD' => '700', 'AED' => '800'],
        ])->create();

        expect($party->openingPositions())->toBe([
            'custody' => ['USD' => '100.00', 'AED' => '200.00'],
            'receivable' => ['USD' => '300.00', 'AED' => '400.00'],
            'payable' => ['USD' => '500.00', 'AED' => '600.00'],
            'credit_trust' => ['USD' => '700.00', 'AED' => '800.00'],
        ]);
    });

    it('refuses two opening positions for the same bucket and currency', function (): void {
        $party = Counterparty::factory()->create();

        $party->openingBalances()->create([
            'bucket' => BalanceBucket::Custody->value,
            'currency_id' => $this->usd->id,
            'amount' => '1',
        ]);

        expect(fn () => $party->openingBalances()->create([
            'bucket' => BalanceBucket::Custody->value,
            'currency_id' => $this->usd->id,
            'amount' => '2',
        ]))->toThrow(QueryException::class);
    });

    it('refuses a bucket the system does not recognise', function (): void {
        $party = Counterparty::factory()->create();

        expect(fn () => $party->openingBalances()->create([
            'bucket' => 'vibes',
            'currency_id' => $this->usd->id,
            'amount' => '1',
        ]))->toThrow(ValueError::class, 'not a valid backing value');
    });

    it('updates an existing position rather than adding a second', function (): void {
        $party = Counterparty::factory()->withPositions(['custody' => ['USD' => '100']])->create();

        $party->setOpeningBalance(BalanceBucket::Custody, $this->usd, $this->usd->money('250'));

        expect($party->openingBalances()->count())->toBe(1)
            ->and($party->openingBalance(BalanceBucket::Custody, $this->usd)?->toDisplayString())->toBe('250.00');
    });
});

describe('bucket semantics', function (): void {
    it('classifies each bucket as an asset or a liability', function (): void {
        expect(BalanceBucket::Custody->isAsset())->toBeTrue()
            ->and(BalanceBucket::Receivable->isAsset())->toBeTrue()
            ->and(BalanceBucket::Payable->isLiability())->toBeTrue()
            ->and(BalanceBucket::CreditTrust->isLiability())->toBeTrue();
    });

    // Custody and credit/trust are mirrors: the business's money held by them, versus
    // their money held by the business.
    it('pairs each bucket with its mirror', function (): void {
        expect(BalanceBucket::Custody->mirror())->toBe(BalanceBucket::CreditTrust)
            ->and(BalanceBucket::CreditTrust->mirror())->toBe(BalanceBucket::Custody)
            ->and(BalanceBucket::Receivable->mirror())->toBe(BalanceBucket::Payable)
            ->and(BalanceBucket::Payable->mirror())->toBe(BalanceBucket::Receivable);
    });

    it('mirrors symmetrically for every bucket', function (BalanceBucket $bucket): void {
        expect($bucket->mirror()->mirror())->toBe($bucket)
            ->and($bucket->mirror()->isAsset())->toBe($bucket->isLiability());
    })->with(BalanceBucket::cases());
});

describe('declaring opening positions', function (): void {
    it('distinguishes an undeclared position from a declared zero', function (): void {
        $party = Counterparty::factory()->withPositions(['custody' => ['USD' => '0']])->create();

        expect($party->openingBalance(BalanceBucket::Custody, $this->usd)?->isZero())->toBeTrue()
            ->and($party->openingBalance(BalanceBucket::Receivable, $this->usd))->toBeNull();
    });

    // A negative receivable is a payable. Allowing it would quietly undo the
    // separation the whole model exists to maintain.
    it('refuses a negative position and names the bucket it belongs in', function (): void {
        $party = Counterparty::factory()->create();

        expect(fn () => $party->setOpeningBalance(BalanceBucket::Receivable, $this->usd, $this->usd->money('-1')))
            ->toThrow(InvalidArgumentException::class, 'A negative receivable is a payable');
    });

    it('refuses a negative position in every bucket', function (BalanceBucket $bucket): void {
        $party = Counterparty::factory()->create();

        expect(fn () => $party->setOpeningBalance($bucket, $this->usd, $this->usd->money('-0.01')))
            ->toThrow(InvalidArgumentException::class);
    })->with(BalanceBucket::cases());

    it('refuses an amount in a currency other than the one named', function (): void {
        $party = Counterparty::factory()->create();

        expect(fn () => $party->setOpeningBalance(BalanceBucket::Custody, $this->usd, $this->aed->money('1')))
            ->toThrow(CurrencyMismatch::class);
    });

    it('keeps full precision', function (): void {
        $party = Counterparty::factory()->create();

        $party->setOpeningBalance(BalanceBucket::Custody, $this->usd, $this->usd->money('1234.5678901234'));

        expect($party->openingBalance(BalanceBucket::Custody, $this->usd)?->toStorageString())
            ->toBe('1234.5678901234');
    });

    it('refuses to delete a currency a position uses', function (): void {
        Counterparty::factory()->withPositions(['custody' => ['USD' => '1']])->create();

        expect(fn () => $this->usd->delete())->toThrow(QueryException::class);
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
