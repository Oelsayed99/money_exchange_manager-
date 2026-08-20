<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\BalanceBucket;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerBalance;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->safe = Account::factory()->create(['name' => 'Main safe']);
    $this->bank = Account::factory()->create(['name' => 'Bank']);
    $this->party = Counterparty::factory()->create(['name' => 'سالم التجريبي']);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);

    $this->viewer = User::factory()->create();
    $this->viewer->assignRole(Role::Viewer->value);
});

function movementPayload(array $overrides = []): array
{
    $test = test();

    return [
        'type' => 'credit_deposit',
        'occurred_at' => '2026-06-10',
        'currency_id' => $test->egp->id,
        'amount' => '500000',
        'account_id' => $test->safe->id,
        'counterparty_id' => $test->party->id,
        ...$overrides,
    ];
}

function bucketOf(BalanceBucket $bucket): string
{
    $test = test();

    $account = app(LedgerAccountResolver::class)->forBucket($bucket, $test->party, $test->egp);
    $row = LedgerBalance::query()->where('ledger_account_id', $account->id)->first();

    return $row === null ? '0' : $row->confirmed()->toDisplayString();
}

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->get('/movements')->assertRedirect('/login');
    });

    it('refuses a viewer', function (): void {
        $this->actingAs($this->viewer)->get('/movements')->assertForbidden();
        $this->actingAs($this->viewer)->post('/movements', movementPayload())->assertForbidden();
    });

    it('lets an operator record', function (): void {
        $this->actingAs($this->operator)->get('/movements')->assertOk();
    });
});

describe('the form', function (): void {
    it('offers every type that can be recorded by hand', function (): void {
        $props = $this->actingAs($this->operator)->get('/movements')->viewData('page')['props'];

        $offered = collect($props['types'])->pluck('value');

        // An exchange needs two amounts and a rate; a reversal is never created directly.
        expect($offered)->not->toContain('currency_exchange')
            ->and($offered)->not->toContain('reversal')
            ->and($offered)->toContain('credit_deposit', 'loan_given', 'loan_received', 'transfer');
    });

    // The form shows and requires the right fields from what the type declares, rather
    // than from a second copy of the rules living in React.
    it('says what each type needs', function (): void {
        $props = $this->actingAs($this->operator)->get('/movements')->viewData('page')['props'];
        $types = collect($props['types'])->keyBy('value');

        expect($types['credit_deposit']['needsCounterparty'])->toBeTrue()
            ->and($types['credit_deposit']['bucket'])->toBe('credit_trust')
            ->and($types['credit_deposit']['increases'])->toBeTrue()
            ->and($types['transfer']['needsDestinationAccount'])->toBeTrue()
            ->and($types['transfer']['needsCounterparty'])->toBeFalse()
            ->and($types['opening_balance']['needsBucket'])->toBeTrue();
    });
});

describe('recording', function (): void {
    it('records credit left with us', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload())
            ->assertRedirect('/movements')
            ->assertSessionHas('success');

        expect(bucketOf(BalanceBucket::CreditTrust))->toBe('500000.00');
    });

    it('records money lent as something they owe', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload([
            'type' => 'loan_given', 'amount' => '400000',
        ]));

        expect(bucketOf(BalanceBucket::Receivable))->toBe('400000.00');
    });

    it('records money borrowed as something we owe', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload([
            'type' => 'loan_received', 'amount' => '300000',
        ]));

        expect(bucketOf(BalanceBucket::Payable))->toBe('300000.00');
    });

    it('reduces what they owe when they settle', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['type' => 'loan_given', 'amount' => '400000']));
        $this->actingAs($this->operator)->post('/movements', movementPayload(['type' => 'receivable_settlement', 'amount' => '150000']));

        expect(bucketOf(BalanceBucket::Receivable))->toBe('250000.00');
    });

    it('moves money between our own locations', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload([
            'type' => 'transfer',
            'counterparty_id' => null,
            'destination_account_id' => $this->bank->id,
            'amount' => '1000',
        ]))->assertSessionHasNoErrors();

        expect(Transaction::query()->sole()->type)->toBe(TransactionType::Transfer);
    });

    it('leaves the ledger verifiable', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload());

        $this->artisan('ledger:verify --transactions')->assertExitCode(0);
    });
});

describe('what it refuses', function (): void {
    it('refuses a type with its own screen', function (string $type): void {
        $this->actingAs($this->operator)
            ->post('/movements', movementPayload(['type' => $type]))
            ->assertSessionHasErrors('type');
    })->with(['currency_exchange', 'reversal']);

    it('requires a counterparty for a movement between us and somebody', function (): void {
        $this->actingAs($this->operator)
            ->post('/movements', movementPayload(['counterparty_id' => null]))
            ->assertSessionHasErrors('counterparty_id');
    });

    it('requires a destination for a transfer', function (): void {
        $this->actingAs($this->operator)
            ->post('/movements', movementPayload(['type' => 'transfer', 'counterparty_id' => null]))
            ->assertSessionHasErrors('destination_account_id');
    });

    it('refuses a transfer to the same place it came from', function (): void {
        $this->actingAs($this->operator)
            ->post('/movements', movementPayload([
                'type' => 'transfer', 'counterparty_id' => null, 'destination_account_id' => $this->safe->id,
            ]))
            ->assertSessionHasErrors('destination_account_id');
    });

    // The other direction is a different type, not a negative number.
    it('refuses a zero or negative amount', function (string $amount): void {
        $this->actingAs($this->operator)
            ->post('/movements', movementPayload(['amount' => $amount]))
            ->assertSessionHasErrors('amount');
    })->with(['0', '-500']);

    it('refuses an amount that is not a plain decimal', function (string $amount): void {
        $this->actingAs($this->operator)
            ->post('/movements', movementPayload(['amount' => $amount]))
            ->assertSessionHasErrors('amount');
    })->with(['1e5', '1,000', 'abc']);
});

describe('the positions panel', function (): void {
    it('shows all four positions, zeroes included', function (): void {
        $response = $this->actingAs($this->operator)->postJson('/movements/positions', [
            'counterparty_id' => $this->party->id,
            'currency_id' => $this->egp->id,
        ]);

        $response->assertOk();

        expect(array_keys($response->json('positions')))
            ->toEqualCanonicalizing(['custody', 'receivable', 'payable', 'credit_trust']);
    });

    it('shows what the movement would leave behind', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['amount' => '500000']));

        $response = $this->actingAs($this->operator)->postJson('/movements/positions', [
            'counterparty_id' => $this->party->id,
            'currency_id' => $this->egp->id,
            'type' => 'credit_deposit',
            'amount' => '200000',
        ]);

        $response->assertJsonPath('after.bucket', 'credit_trust')
            ->assertJsonPath('after.amount.amount', '700000.00');
    });

    it('subtracts for a movement that reduces a position', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['amount' => '500000']));

        $this->actingAs($this->operator)->postJson('/movements/positions', [
            'counterparty_id' => $this->party->id,
            'currency_id' => $this->egp->id,
            'type' => 'credit_settlement',
            'amount' => '200000',
        ])->assertJsonPath('after.amount.amount', '300000.00');
    });

    it('sends every amount as a string', function (): void {
        $json = $this->actingAs($this->operator)->postJson('/movements/positions', [
            'counterparty_id' => $this->party->id,
            'currency_id' => $this->egp->id,
        ])->content();

        expect($json)->toContain('"amount":"0.00"');
    });
});

/*
 * The owner's decision, recorded in posting-rules §9.4: a credit balance may go
 * negative, always allowed. A warning, never a block.
 */
describe('the negative credit warning', function (): void {
    it('warns when paying out more than they left with us', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['amount' => '100000']));

        $this->actingAs($this->operator)->postJson('/movements/positions', [
            'counterparty_id' => $this->party->id,
            'currency_id' => $this->egp->id,
            'type' => 'credit_settlement',
            'amount' => '150000',
        ])->assertJsonPath('negative_warning', 'credit_trust')
            ->assertJsonPath('after.amount.amount', '-50000.00');
    });

    it('stays quiet when the balance covers it', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['amount' => '500000']));

        $this->actingAs($this->operator)->postJson('/movements/positions', [
            'counterparty_id' => $this->party->id,
            'currency_id' => $this->egp->id,
            'type' => 'credit_settlement',
            'amount' => '200000',
        ])->assertJsonPath('negative_warning', null);
    });

    // Warned about, and then allowed. Blocking it would override a decision the owner
    // made deliberately.
    it('records the movement anyway', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['amount' => '100000']));

        $this->actingAs($this->operator)
            ->post('/movements', movementPayload(['type' => 'credit_settlement', 'amount' => '150000']))
            ->assertSessionHasNoErrors();

        expect(bucketOf(BalanceBucket::CreditTrust))->toBe('-50000.00');
    });
});

/*
 * The form promises what each type will do. This checks the ledger agrees, for every
 * type that declares an effect — so the declaration on the enum and the posting rules
 * cannot drift apart without a test failing.
 */
describe('the declared effect matches the ledger', function (): void {
    it('moves exactly the bucket it says, in the direction it says', function (): void {
        $rules = app(PostingRules::class);
        $posting = app(PostingService::class);
        $resolver = app(LedgerAccountResolver::class);

        foreach (TransactionType::cases() as $type) {
            $effect = $type->bucketEffect();

            if ($effect === null) {
                continue;
            }

            $party = Counterparty::factory()->create();
            $account = $resolver->forBucket($effect->bucket, $party, $this->egp);

            $posting->post($rules->build(new TransactionInput(
                type: $type,
                currency: $this->egp,
                amount: $this->egp->money('1000'),
                occurredAt: now(),
                account: $this->safe,
                counterparty: $party,
            )));

            $balance = LedgerBalance::query()->where('ledger_account_id', $account->id)->sole()->confirmed();

            expect($balance->toDisplayString())
                ->toBe($effect->increases ? '1000.00' : '-1000.00', "{$type->value} moved the wrong way");
        }
    });
});
