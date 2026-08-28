<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeInput;
use App\Domain\Exchange\ProfitCalculator;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Enums\MarginBasis;
use App\Enums\ProfitMethod;
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
        marginBasis: $overrides['marginBasis'] ?? MarginBasis::Received,
        costRate: array_key_exists('costRate', $overrides) ? $overrides['costRate'] : '3.65',
        profitValue: $overrides['profitValue'] ?? null,
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
//
// The two readings used to be one profit method and a second question about what its
// number meant. They are two methods now, which is what the ambiguity always was: not
// two spellings of one thing, but two different calculations.
describe('the 0.02 ambiguity', function (): void {
    it('reads 0.02 per unit as two hundredths of a unit', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::PerUnit,
            'profitValue' => '0.02',
        ]));

        // Cost rate 3.67 − 0.02 = 3.65, so cost is 3,650 and profit is 20.
        expect($breakdown->costRate)->toBe('3.650000000000')
            ->and($breakdown->grossProfit->toDisplayString())->toBe('20.00');
    });

    it('reads 0.02 per cent as two hundredths of a per cent', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'profitValue' => '0.02',
        ]));

        // 0.02% of 3,670 is 0.734.
        expect($breakdown->grossProfit->toDisplayString())->toBe('0.734');
    });

    // The whole reason the two are separate: the same number, two readings, wildly
    // different answers. On a large deal this is the difference between a real margin
    // and a rounding error.
    it('gives materially different answers for the same number', function (): void {
        $perUnit = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::PerUnit,
            'profitValue' => '0.02',
        ]));

        $percentage = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'profitValue' => '0.02',
        ]));

        expect($perUnit->grossProfit->equals($percentage->grossProfit))->toBeFalse();
    });

    // There is no longer a way to pick a reading and leave the figure out, or a figure
    // with no reading: choosing the method is choosing both.
    it('refuses a margin with no figure', function (): void {
        expect(fn () => $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::PerUnit,
        ])))->toThrow(DomainException::class, 'needs its figure stated');
    });

    it('reads 2 per cent as two per cent', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Percentage,
            'profitValue' => '2',
        ]));

        expect($breakdown->grossProfit->toDisplayString())->toBe('73.40');
    });
});

describe('the other profit methods', function (): void {
    it('takes a fixed amount as stated', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::FixedAmount,
            'profitValue' => '25',
        ]));

        expect($breakdown->grossProfit->toDisplayString())->toBe('25.00')
            ->and($breakdown->costValue->toDisplayString())->toBe('3645.00')
            ->and($breakdown->costRate)->toBeNull();
    });

    it('takes a manually entered profit as stated', function (): void {
        $breakdown = $this->calculator->calculate(exchange([
            'profitMethod' => ProfitMethod::Manual,
            'profitValue' => '17.5',
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
        ])))->toThrow(DomainException::class, 'needs its figure stated');
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

/**
 * The margin on the other leg.
 *
 * An exchange can be entered as a sale of what leaves or a purchase of what arrives,
 * and until now only the first was really supported: the cost rate was always per unit
 * *delivered*, so buying 50,000 USD with pounds asked the operator for 0.019531 where
 * they were thinking 51.20, and reported the margin in dollars.
 *
 * The fix is not to turn the rate over — that is division, and division into a figure
 * the margin is derived from is where precision goes. It is to measure the margin on
 * the other leg, where the same rate is applied by multiplication. ADR 0027.
 */
describe('which leg carries the margin', function (): void {
    // Buy 50,000 USD for 2,560,000 EGP — 51.20 a dollar — when a dollar is worth 51.48.
    function purchase(array $overrides = []): ExchangeInput
    {
        $test = test();

        return new ExchangeInput(
            receivedCurrency: $test->usd,
            receivedAmount: $test->usd->money('50000'),
            receivedInto: $test->into,
            deliveredCurrency: $test->egp,
            deliveredAmount: $overrides['deliveredAmount'] ?? $test->egp->money('2560000'),
            deliveredFrom: $test->from,
            occurredAt: now(),
            profitMethod: $overrides['profitMethod'] ?? ProfitMethod::RateDifference,
            marginBasis: MarginBasis::Delivered,
            costRate: array_key_exists('costRate', $overrides) ? $overrides['costRate'] : '51.48',
            profitValue: $overrides['profitValue'] ?? null,
            feesCharged: $overrides['feesCharged'] ?? null,
            expenses: $overrides['expenses'] ?? null,
            commissions: $overrides['commissions'] ?? null,
        );
    }

    it('states the rate the way the operator does, and earns the margin in pounds', function (): void {
        $breakdown = $this->calculator->calculate(purchase());

        // 51.20 a dollar paid, 51.48 a dollar worth: 0.28 on each of 50,000.
        expect($breakdown->customerRate)->toBe('51.200000000000')
            ->and($breakdown->costRate)->toBe('51.48')
            ->and($breakdown->grossProfit->toDisplayString())->toBe('14000.00')
            ->and($breakdown->grossProfit->currency->code)->toBe('EGP');
    });

    /*
     * The same money, described from either side, has to come to the same thing.
     *
     * Give 2,574,000 EGP for 50,000 USD when a dollar cost 51.20. On the delivered leg
     * that is a 14,000 EGP loss. On the received leg it is the identical loss expressed
     * in dollars — 14,000 / 51.20 — and the two must agree, or the basis is not a way
     * of stating the deal but a way of changing it.
     */
    it('gives the same answer from either side, in that side is currency', function (): void {
        $onDelivered = $this->calculator->calculate(purchase([
            'deliveredAmount' => $this->egp->money('2574000'),
            'costRate' => '51.20',
        ]));

        $onReceived = $this->calculator->calculate(new ExchangeInput(
            receivedCurrency: $this->usd,
            receivedAmount: $this->usd->money('50000'),
            receivedInto: $this->into,
            deliveredCurrency: $this->egp,
            deliveredAmount: $this->egp->money('2574000'),
            deliveredFrom: $this->from,
            occurredAt: now(),
            marginBasis: MarginBasis::Received,
            // The same cost, turned over by hand: 1 / 51.20.
            costRate: '0.01953125',
        ));

        expect($onDelivered->grossProfit->toDisplayString())->toBe('-14000.00')
            ->and($onDelivered->grossProfit->currency->code)->toBe('EGP')
            ->and($onReceived->grossProfit->toDisplayString())->toBe('-273.4375')
            ->and($onReceived->grossProfit->currency->code)->toBe('USD');

        // -14,000 EGP at 51.20 to the dollar is -273.4375 USD. The same loss.
        expect(bcdiv('-14000', '51.20', 4))->toBe('-273.4375');
    });

    it('takes a per-unit margin against the leg the rate is quoted per', function (): void {
        $breakdown = $this->calculator->calculate(purchase([
            'profitMethod' => ProfitMethod::PerUnit,
            'costRate' => null,
            'profitValue' => '0.28',
        ]));

        // 0.28 EGP on each of 50,000 dollars, and the cost rate works out above the
        // customer rate rather than below it — the currency was bought, not sold.
        expect($breakdown->costRate)->toBe('51.480000000000')
            ->and($breakdown->grossProfit->toDisplayString())->toBe('14000.00');
    });

    it('takes a stated amount in the currency the margin is measured in', function (): void {
        $breakdown = $this->calculator->calculate(purchase([
            'profitMethod' => ProfitMethod::FixedAmount,
            'costRate' => null,
            'profitValue' => '9000',
        ]));

        expect($breakdown->grossProfit->toDisplayString())->toBe('9000.00')
            ->and($breakdown->grossProfit->currency->code)->toBe('EGP')
            ->and($breakdown->costValue->toDisplayString())->toBe('2569000.00');
    });

    it('takes a percentage of what was paid out', function (): void {
        $breakdown = $this->calculator->calculate(purchase([
            'profitMethod' => ProfitMethod::Percentage,
            'costRate' => null,
            'profitValue' => '1',
        ]));

        // 1% of 2,560,000.
        expect($breakdown->grossProfit->toDisplayString())->toBe('25600.00');
    });

    it('nets fees and costs in the margin currency', function (): void {
        $breakdown = $this->calculator->calculate(purchase([
            'feesCharged' => $this->egp->money('500'),
            'expenses' => $this->egp->money('200'),
            'commissions' => $this->egp->money('300'),
        ]));

        expect($breakdown->netProfit->toDisplayString())->toBe('14000.00')
            ->and($breakdown->netProfit->currency->code)->toBe('EGP');
    });
});
