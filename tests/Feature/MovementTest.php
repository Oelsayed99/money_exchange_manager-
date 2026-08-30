<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerBalance;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;

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
        'type' => 'in',
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
            ->and($offered)->toContain('in', 'out', 'in', 'transfer');
    });

    // The form shows and requires the right fields from what the type declares, rather
    // than from a second copy of the rules living in React.
    it('says what each type needs', function (): void {
        $props = $this->actingAs($this->operator)->get('/movements')->viewData('page')['props'];
        $types = collect($props['types'])->keyBy('value');

        expect($types['in']['needsCounterparty'])->toBeTrue()
            ->and($types['in']['mayConvert'])->toBeTrue()
            // In lowers the balance, out raises it: paying somebody puts them in debt
            // to us, taking money from them does the reverse.
            ->and($types['in']['increases'])->toBeFalse()
            ->and($types['out']['increases'])->toBeTrue()
            ->and($types['transfer']['needsDestinationAccount'])->toBeTrue()
            ->and($types['transfer']['needsCounterparty'])->toBeFalse()
            ->and($types['transfer']['mayConvert'])->toBeFalse();
    });
});

describe('recording', function (): void {
    function clientBalance(): string
    {
        $test = test();
        $account = app(LedgerAccountResolver::class)->forCounterparty($test->party, $test->egp);

        return LedgerBalance::query()->where('ledger_account_id', $account->id)->sole()->confirmed()->toDisplayString();
    }

    it('lowers the balance when money comes in from them', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['amount' => '500000']))->assertRedirect();

        expect(clientBalance())->toBe('-500000.00');
    });

    it('raises it when money goes out to them', function (): void {
        $this->actingAs($this->operator)
            ->post('/movements', movementPayload(['type' => 'out', 'amount' => '250000']))
            ->assertRedirect();

        expect(clientBalance())->toBe('250000.00');
    });

    it('nets the two into one figure', function (): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['amount' => '899510']));
        $this->actingAs($this->operator)->post('/movements', movementPayload(['type' => 'out', 'amount' => '14890']));

        expect(clientBalance())->toBe('-884620.00');
    });

    /*
     * The change the owner asked for: take money in one currency, record it in another.
     *
     * The dollars really arrive and the client's account really moves in pounds. Both
     * facts are kept, each currency balances on its own, and the rate is stored as the
     * description of what was agreed.
     */
    it('records a movement in a currency other than the one that moved', function (): void {
        $usd = Currency::query()->where('code', 'USD')->sole();

        $this->actingAs($this->operator)->post('/movements', movementPayload([
            'amount' => '508500',
            'cash_currency_id' => $usd->id,
            'cash_amount' => '10000',
            'rate' => '50.85',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $cash = app(LedgerAccountResolver::class)->forAccount($this->safe, $usd);

        expect(clientBalance())->toBe('-508500.00')
            ->and(LedgerBalance::query()->where('ledger_account_id', $cash->id)->sole()->confirmed()->toDisplayString())
            ->toBe('10000.00');

        $this->artisan('ledger:verify --transactions')->assertExitCode(0);
    });

    it('keeps both amounts and the rate on the record', function (): void {
        $usd = Currency::query()->where('code', 'USD')->sole();

        $this->actingAs($this->operator)->post('/movements', movementPayload([
            'amount' => '508500',
            'cash_currency_id' => $usd->id,
            'cash_amount' => '10000',
            'rate' => '50.85',
        ]));

        $transaction = Transaction::query()->sole();

        expect($transaction->legs)->toHaveCount(2)
            ->and($transaction->legs->pluck('currency_id')->all())->toBe([$usd->id, $this->egp->id]);
    });

    it('refuses a conversion on a movement that is not a client movement', function (): void {
        $usd = Currency::query()->where('code', 'USD')->sole();

        $this->actingAs($this->operator)->post('/movements', movementPayload([
            'type' => 'fee',
            'counterparty_id' => null,
            'cash_currency_id' => $usd->id,
            'cash_amount' => '10',
            'rate' => '50',
        ]))->assertSessionHasErrors('cash_currency_id');
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

/**
 * What the operator sees before recording anything.
 *
 * One signed figure, and what it would become. Both, and said in words — "they owe us"
 * or "we owe them" — because a minus sign is the easiest thing on a screen to misread.
 */
describe('the balance panel', function (): void {
    function ask(array $payload = []): TestResponse
    {
        $test = test();

        return $test->actingAs($test->operator)->postJson('/movements/positions', [
            'counterparty_id' => $test->party->id,
            'currency_id' => $test->egp->id,
            ...$payload,
        ]);
    }

    it('reports zero for a party with no history', function (): void {
        ask()->assertJsonPath('balance.amount', '0.00')
            ->assertJsonPath('after', null);
    });

    it('shows where the movement would leave them', function (): void {
        test()->actingAs(test()->operator)->post('/movements', movementPayload(['amount' => '500000']));

        ask(['type' => 'in', 'amount' => '200000'])
            ->assertJsonPath('balance.amount', '-500000.00')
            ->assertJsonPath('after.amount', '-700000.00')
            ->assertJsonPath('they_owe_us', false);
    });

    it('adds for a movement that goes out', function (): void {
        test()->actingAs(test()->operator)->post('/movements', movementPayload(['amount' => '500000']));

        ask(['type' => 'out', 'amount' => '200000'])
            ->assertJsonPath('after.amount', '-300000.00');
    });

    // The moment worth flagging: they were holding our money and now they owe us, or
    // the other way about. One movement, and the relationship reads the other way.
    it('says when the relationship turns over', function (): void {
        test()->actingAs(test()->operator)->post('/movements', movementPayload(['amount' => '100000']));

        ask(['type' => 'out', 'amount' => '150000'])
            ->assertJsonPath('after.amount', '50000.00')
            ->assertJsonPath('they_owe_us', true)
            ->assertJsonPath('turns_over', true);
    });

    it('stays quiet when it does not', function (): void {
        test()->actingAs(test()->operator)->post('/movements', movementPayload(['amount' => '100000']));

        ask(['type' => 'out', 'amount' => '20000'])->assertJsonPath('turns_over', false);
    });

    it('refuses the panel to somebody who cannot record', function (): void {
        $this->actingAs($this->viewer)->postJson('/movements/positions', [
            'counterparty_id' => $this->party->id,
            'currency_id' => $this->egp->id,
        ])->assertForbidden();
    });
});

/**
 * What the form promises and what the ledger does cannot disagree.
 *
 * The form is told which way each type moves the balance; the posting rules move it.
 * Two statements of one rule, so this asserts they agree.
 */
describe('the declared effect matches the ledger', function (): void {
    it('moves the balance the way the form said it would', function (string $type, string $expected): void {
        $this->actingAs($this->operator)->post('/movements', movementPayload(['type' => $type, 'amount' => '1000']));

        $account = app(LedgerAccountResolver::class)->forCounterparty($this->party, $this->egp);
        $balance = LedgerBalance::query()->where('ledger_account_id', $account->id)->sole()->confirmed();

        expect($balance->toDisplayString())->toBe($expected);
    })->with([
        ['in', '-1000.00'],
        ['out', '1000.00'],
    ]);
});
