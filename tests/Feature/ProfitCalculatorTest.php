<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeInput;
use App\Domain\Exchange\ProfitCalculator;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Enums\ProfitMethod;
use App\Enums\SpreadType;
use App\Models\Account;
use App\Models\Currency;
use Database\Seeders\CurrencySeeder;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();

    $this->aed = Currency::query()->where('code', 'AED')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();
    $this->egp = Currency::query()->where('code', 'EGP')->sole();

    $this->into = Account::factory()->create(['name' => 'AED safe']);
    $this->from = Account::factory()->create(['name' => 'USD safe']);

    $this->calculator = app(ProfitCalculator::class);
});

/** Receive AED, deliver USD — the shape of Section 2's example. */
function exchange(array $overrides = []): ExchangeInput
{
    $test = test();

    return new ExchangeInput(
        receivedCurrency: $overrides['receivedCurrency'] ?? $test->aed,
        receivedAmount: $overrides['receivedAmount'] ?? $test->aed->money('3670'),
        receivedInto: $test->into,
        deliveredCurrency: $test->usd,
        deliveredAmount: $overrides['deliveredAmount'] ?? $test->usd->money('1000'),
        deliveredFrom: $test->from,
        occurredAt: now(),
        profitMethod: $overrides['profitMethod'] ?? ProfitMethod::RateDifference,
        costRate: array_key_exists('costRate', $overrides) ? $overrides['costRate'] : '3.65',
        spreadType: $overrides['spreadType'] ?? null,
        spreadValue: $overrides['spreadValue'] ?? null,
        feesCharged: $overrides['feesCharged'] ?? null,
        expenses: $overrides['expenses'] ?? null,
        commissions: $overrides['commissions'] ?? null,
    );
}

// Section 2, verbatim: receive AED, give USD, customer rate 3.67, cost 3.65,
// deliver 1,000 USD, receive 3,670 AED, gross profit 20 AED.
describe('the specification example', function (): void {
    it('produces exactly the figures the specification states', function (): void {
        $breakdown = $this->calculator->calculate(exchange());

        expect($breakdown->customerRate)->toBe('3.670000000000')
            ->and($breakdown->costRate)->toBe('3.65')
            ->and($breakdown->customerValue->toDisplayString())->toBe('3670.00')
            ->and($breakdown->costValue->toDisplayString())->toBe('3650.00')
            ->and($breakdown->grossProfit->toDisplayString())->toBe('20.00')
            ->and($breakdown->netProfit->toDisplayString())->toBe('20.00')
            ->and($breakdown->profitCurrency())->toBe('AED');
    });

    // The rate describes the money that moved rather than being entered beside it,
    // so a recorded rate can never disagree with the amounts.
    it('derives the customer rate from the amounts', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'receivedAmount' => $this->aed->money('3680'),
        ]));

        expect($breakdown->customerRate)->toBe('3.680000000000');
    });
});

// The real statement: 50,000 USD delivered at 51.48 against EGP.
describe('the real statement', function (): void {
    it('reproduces the 51.48 deal', function (): void {
        $breakdown = $this->calculator->calculate(new ExchangeInput(
            receivedCurrency: $this->egp,
            receivedAmount: $this->egp->money('2574000'),
            receivedInto: $this->into,
            deliveredCurrency: $this->usd,
            deliveredAmount: $this->usd->money('50000'),
            deliveredFrom: $this->from,
            occurredAt: now(),
            costRate: '51.20',
        ));

        expect($breakdown->customerRate)->toBe('51.480000000000')
            ->and($breakdown->customerValue->toDisplayString())->toBe('2574000.00')
            ->and($breakdown->costValue->toDisplayString())->toBe('2560000.00')
            ->and($breakdown->grossProfit->toDisplayString())->toBe('14000.00');
    });

    it('reproduces the 50.8 deal', function (): void {
        $breakdown = $this->calculator->calculate(new ExchangeInput(
            receivedCurrency: $this->egp,
            receivedAmount: $this->egp->money('914400'),
            receivedInto: $this->into,
            deliveredCurrency: $this->usd,
            deliveredAmount: $this->usd->money('18000'),
            deliveredFrom: $this->from,
            occurredAt: now(),
            costRate: '50.50',
        ));

        expect($breakdown->customerRate)->toBe('50.800000000000')
            ->and($breakdown->grossProfit->toDisplayString())->toBe('5400.00');
    });
});

// Section 3: "Do not assume that 0.02 always means 2%."
describe('the 0.02 ambiguity', function (): void {
    it('reads 0.02 per unit as two hundredths of a unit', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'spreadType' => SpreadType::PerUnit,
            'spreadValue' => '0.02',
        ]));

        // Cost rate 3.67 − 0.02 = 3.65, so cost is 3,650 and profit is 20.
        expect($breakdown->costRate)->toBe('3.650000000000')
            ->and($breakdown->grossProfit->toDisplayString())->toBe('20.00');
    });

    it('reads 0.02 per cent as two hundredths of a per cent', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'spreadType' => SpreadType::Percentage,
            'spreadValue' => '0.02',
        ]));

        // 0.02% of 3,670 is 0.734.
        expect($breakdown->grossProfit->toDisplayString())->toBe('0.734');
    });

    // The whole reason the enum exists: the same number, two readings, wildly
    // different answers. On a large deal this is the difference between a real margin
    // and a rounding error.
    it('gives materially different answers for the same number', function (): void {
        $perUnit = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'spreadType' => SpreadType::PerUnit,
            'spreadValue' => '0.02',
        ]));

        $percentage = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'spreadType' => SpreadType::Percentage,
            'spreadValue' => '0.02',
        ]));

        expect($perUnit->grossProfit->equals($percentage->grossProfit))->toBeFalse();
    });

    it('refuses a spread with no stated meaning', function (): void {
        expect(fn () => $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'spreadValue' => '0.02',
        ])))->toThrow(DomainException::class, 'what that value means');
    });

    it('reads 2 per cent as two per cent', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'spreadType' => SpreadType::Percentage,
            'spreadValue' => '2',
        ]));

        expect($breakdown->grossProfit->toDisplayString())->toBe('73.40');
    });
});

describe('the other profit methods', function (): void {
    it('takes a fixed amount as stated', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::FixedAmount,
            'spreadValue' => '25',
        ]));

        expect($breakdown->grossProfit->toDisplayString())->toBe('25.00')
            ->and($breakdown->costValue->toDisplayString())->toBe('3645.00')
            ->and($breakdown->costRate)->toBeNull();
    });

    it('takes a manually entered profit as stated', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Manual,
            'spreadValue' => '17.5',
        ]));

        expect($breakdown->grossProfit->toDisplayString())->toBe('17.50');
    });

    // Moving our own money between currencies earns nothing.
    it('records no profit on an internal transfer', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::None,
        ]));

        expect($breakdown->grossProfit->isZero())->toBeTrue()
            ->and($breakdown->netProfit->isZero())->toBeTrue();
    });

    it('refuses a rate-difference deal with no cost rate', function (): void {
        expect(fn () => $this->calculator->calculate(exchange(['costRate' => null])))
            ->toThrow(DomainException::class, 'needs a cost rate');
    });

    it('refuses a fixed-amount deal with no amount', function (): void {
        expect(fn () => $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::FixedAmount,
        ])))->toThrow(DomainException::class, 'profit amount to be stated');
    });
});

describe('fees, expenses and commissions', function (): void {
    // Net Profit = Gross + Fees Charged − Expenses − External Commissions
    it('applies the specification formula', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'feesCharged' => $this->aed->money('10'),
            'expenses' => $this->aed->money('4'),
            'commissions' => $this->aed->money('1'),
        ]));

        expect($breakdown->grossProfit->toDisplayString())->toBe('20.00')
            ->and($breakdown->netProfit->toDisplayString())->toBe('25.00');
    });

    it('measures them in the profit currency', function (): void {
        expect(fn () => exchange(['feesCharged' => $this->usd->money('10')]))
            ->toThrow(CurrencyMismatch::class, 'measured in the profit currency');
    });
});

describe('losses', function (): void {
    // Section 3 requires negative profit to be supported and warned about.
    it('reports a loss when the cost exceeded what was charged', function (): void {
        $breakdown = $this->calculator->calculate(exchange(['costRate' => '3.70']));

        expect($breakdown->grossProfit->toDisplayString())->toBe('-30.00')
            ->and($breakdown->isLoss())->toBeTrue();
    });

    it('reports a loss caused by costs rather than by the rate', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'expenses' => $this->aed->money('50'),
        ]));

        expect($breakdown->grossProfit->isPositive())->toBeTrue()
            ->and($breakdown->netProfit->toDisplayString())->toBe('-30.00')
            ->and($breakdown->isLoss())->toBeTrue();
    });

    it('does not call a break-even deal a loss', function (): void {
        expect($this->calculator->calculate(exchange(['costRate' => '3.67']))->isLoss())->toBeFalse();
    });
});

describe('validation', function (): void {
    it('refuses an exchange between one currency and itself', function (): void {
        expect(fn () => new ExchangeInput(
            receivedCurrency: $this->aed,
            receivedAmount: $this->aed->money('100'),
            receivedInto: $this->into,
            deliveredCurrency: $this->aed,
            deliveredAmount: $this->aed->money('100'),
            deliveredFrom: $this->from,
            occurredAt: now(),
        ))->toThrow(InvalidArgumentException::class, 'is a transfer');
    });

    it('refuses a zero or negative leg', function (): void {
        expect(fn () => exchange(['deliveredAmount' => $this->usd->money('0')]))
            ->toThrow(InvalidArgumentException::class, 'must be positive');
    });
});

describe('the breakdown is transportable', function (): void {
    // Section 3 wants a clear calculation breakdown, and risk R1 says every amount
    // crosses as a string.
    it('serialises every amount as a string', function (): void {
        $payload = $this->calculator->calculate(exchange())->jsonSerialize();
        $encoded = json_encode($payload);

        expect($payload['gross_profit']['amount'])->toBeString()
            ->and($encoded)->toContain('"amount":"20.00"')
            ->and($encoded)->toContain('"customer_rate":"3.670000000000"')
            ->and($payload['is_loss'])->toBeFalse();
    });

    it('carries the inputs alongside the outputs', function (): void {
        $payload = $this->calculator->calculate(exchange())->jsonSerialize();

        expect($payload['delivered']['amount'])->toBe('1000.00')
            ->and($payload['received']['amount'])->toBe('3670.00')
            ->and($payload['cost_rate'])->toBe('3.65');
    });
});
