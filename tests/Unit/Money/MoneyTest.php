<?php

declare(strict_types=1);

use App\Domain\Money\CurrencySpec;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Domain\Money\RoundingMode;

function usd(): CurrencySpec
{
    return new CurrencySpec('USD', 2, RoundingMode::HalfUp);
}

function aed(): CurrencySpec
{
    return new CurrencySpec('AED', 2, RoundingMode::HalfUp);
}

describe('construction', function (): void {
    it('holds the amount at the storage scale', function (): void {
        expect(Money::of('10', usd())->toStorageString())->toBe('10.0000000000');
    });

    it('accepts integers', function (): void {
        expect(Money::of(1000, usd())->toStorageString())->toBe('1000.0000000000');
    });

    it('accepts negative amounts, because losses and payables are real', function (): void {
        expect(Money::of('-42.50', usd())->toCurrencyScale())->toBe('-42.50');
    });

    // strict_types + the string|int parameter mean a float is a TypeError rather than
    // a silent precision loss. This is the first line of defence for risk R1.
    it('rejects float input outright', function (): void {
        // @phpstan-ignore-next-line argument.type -- proving the type boundary rejects floats
        expect(fn () => Money::of(0.1, usd()))->toThrow(TypeError::class);
    });

    it('rejects scientific notation', function (): void {
        expect(fn () => Money::of('1e5', usd()))->toThrow(InvalidArgumentException::class);
    });

    it('rejects precision beyond the storage scale instead of silently rounding it', function (): void {
        expect(fn () => Money::of('1.12345678901', usd()))
            ->toThrow(InvalidArgumentException::class, 'carries more than 10 decimal places');
    });

    it('creates zero', function (): void {
        expect(Money::zero(usd())->isZero())->toBeTrue();
    });
});

describe('exact arithmetic', function (): void {
    // The headline case. In IEEE-754, 0.1 + 0.2 === 0.30000000000000004.
    it('adds 0.1 and 0.2 to exactly 0.3', function (): void {
        $sum = Money::of('0.1', usd())->plus(Money::of('0.2', usd()));

        expect($sum->toStorageString())->toBe('0.3000000000')
            ->and($sum->equals(Money::of('0.3', usd())))->toBeTrue();
    });

    it('stays exact over a long chain of additions', function (): void {
        $total = Money::zero(usd());

        for ($i = 0; $i < 1000; $i++) {
            $total = $total->plus(Money::of('0.01', usd()));
        }

        expect($total->toCurrencyScale())->toBe('10.00');
    });

    it('subtracts exactly', function (): void {
        expect(Money::of('0.3', usd())->minus(Money::of('0.1', usd()))->toStorageString())
            ->toBe('0.2000000000');
    });

    it('keeps precision on values far beyond float safety', function (): void {
        $big = Money::of('123456789012345678.1234567890', usd());

        expect($big->plus(Money::of('0.0000000001', usd()))->toStorageString())
            ->toBe('123456789012345678.1234567891');
    });

    it('is immutable', function (): void {
        $original = Money::of('10.00', usd());
        $original->plus(Money::of('5.00', usd()));

        expect($original->toCurrencyScale())->toBe('10.00');
    });
});

describe('currency safety', function (): void {
    it('refuses to add different currencies', function (): void {
        expect(fn () => Money::of('1', usd())->plus(Money::of('1', aed())))
            ->toThrow(CurrencyMismatch::class, 'Cannot add [USD] and [AED]');
    });

    it('refuses to subtract different currencies', function (): void {
        expect(fn () => Money::of('1', usd())->minus(Money::of('1', aed())))
            ->toThrow(CurrencyMismatch::class);
    });

    it('refuses to order different currencies', function (): void {
        expect(fn () => Money::of('1', usd())->compareTo(Money::of('1', aed())))
            ->toThrow(CurrencyMismatch::class, 'Cannot compare [USD] and [AED]');
    });

    it('answers false rather than throwing when equality spans currencies', function (): void {
        expect(Money::of('1', usd())->equals(Money::of('1', aed())))->toBeFalse();
    });
});

describe('multiplication and division', function (): void {
    it('multiplies by a rate carrying more precision than storage', function (): void {
        expect(Money::of('1000', usd())->multipliedBy('3.671234567891')->toStorageString())
            ->toBe('3671.2345678910');
    });

    // Section 2's worked example, arithmetically: delivering 1,000 units at a customer
    // rate of 3.67 against a cost rate of 3.65 yields exactly 20 of gross profit.
    // The semantic wiring of legs and rates lands in Phase 4; this asserts the maths.
    it('reproduces the specification profit example exactly', function (): void {
        $delivered = Money::of('1000', aed());

        $customerValue = $delivered->multipliedBy('3.67');
        $costValue = $delivered->multipliedBy('3.65');

        expect($customerValue->minus($costValue)->toCurrencyScale())->toBe('20.00');
    });

    it('divides', function (): void {
        expect(Money::of('10', usd())->dividedBy('4')->toCurrencyScale())->toBe('2.50');
    });

    it('refuses division by zero', function (): void {
        expect(fn () => Money::of('10', usd())->dividedBy('0'))->toThrow(DivisionByZeroError::class);
    });

    it('uses the currency rounding mode by default', function (): void {
        $halfEven = new CurrencySpec('XTS', 0, RoundingMode::HalfEven);

        // 5 x 0.5 = 2.5, which HalfEven resolves to 2 rather than 3.
        expect(Money::of('5', $halfEven)->multipliedBy('0.5')->toCurrencyScale())->toBe('2');
    });

    it('accepts a display rounding override', function (): void {
        $money = Money::of('2.5', new CurrencySpec('XTS', 0, RoundingMode::HalfEven));

        expect($money->toCurrencyScale())->toBe('2')
            ->and($money->toCurrencyScale(RoundingMode::HalfUp))->toBe('3');
    });

    // The override on multipliedBy() governs the storage scale, not the display scale,
    // so it only bites when a product carries more than SCALE decimal places.
    it('applies a rounding override at the storage scale', function (): void {
        $money = Money::of('1', usd());

        // 1 x 0.00000000005 is a tie at the eleventh decimal place.
        expect($money->multipliedBy('0.00000000005', RoundingMode::HalfUp)->toStorageString())
            ->toBe('0.0000000001')
            ->and($money->multipliedBy('0.00000000005', RoundingMode::Down)->toStorageString())
            ->toBe('0.0000000000');
    });
});

describe('sign and comparison', function (): void {
    it('reports sign', function (): void {
        expect(Money::of('1', usd())->isPositive())->toBeTrue()
            ->and(Money::of('-1', usd())->isNegative())->toBeTrue()
            ->and(Money::zero(usd())->isZero())->toBeTrue();
    });

    it('negates and takes absolute value', function (): void {
        expect(Money::of('-5', usd())->negated()->toCurrencyScale())->toBe('5.00')
            ->and(Money::of('-5', usd())->absolute()->toCurrencyScale())->toBe('5.00')
            ->and(Money::of('5', usd())->absolute()->toCurrencyScale())->toBe('5.00');
    });

    it('never yields a negative zero when negating zero', function (): void {
        expect(Money::zero(usd())->negated()->toStorageString())->toBe('0.0000000000');
    });

    it('orders amounts', function (): void {
        expect(Money::of('2', usd())->isGreaterThan(Money::of('1', usd())))->toBeTrue()
            ->and(Money::of('1', usd())->isLessThan(Money::of('2', usd())))->toBeTrue();
    });
});

describe('presentation and transport', function (): void {
    it('rounds to the currency precision for display', function (): void {
        expect(Money::of('1.005', usd())->toCurrencyScale())->toBe('1.01');
    });

    it('respects a zero-decimal currency', function (): void {
        expect(Money::of('1234.56', new CurrencySpec('JPY', 0))->toCurrencyScale())->toBe('1235');
    });

    it('respects a three-decimal currency', function (): void {
        expect(Money::of('1.2345', new CurrencySpec('KWD', 3))->toCurrencyScale())->toBe('1.235');
    });

    // Risk R1: JavaScript's number is float64, so money must cross the boundary as a
    // string. This asserts the JSON payload contains no numeric amount.
    it('serialises the amount as a string, never a JSON number', function (): void {
        $payload = Money::of('3670.50', aed())->jsonSerialize();

        expect($payload)->toBe(['amount' => '3670.50', 'currency' => 'AED'])
            ->and($payload['amount'])->toBeString();

        expect(json_encode($payload))->toBe('{"amount":"3670.50","currency":"AED"}');
    });

    it('casts to string at currency precision', function (): void {
        expect((string) Money::of('42', usd()))->toBe('42.00');
    });
});

describe('CurrencySpec', function (): void {
    it('rejects an empty code', function (): void {
        expect(fn () => new CurrencySpec(''))->toThrow(InvalidArgumentException::class);
    });

    it('rejects a lowercase code, so USD and usd cannot diverge', function (): void {
        expect(fn () => new CurrencySpec('usd'))->toThrow(InvalidArgumentException::class, 'must be uppercase');
    });

    it('rejects precision beyond what Money can store', function (): void {
        expect(fn () => new CurrencySpec('USD', 11))->toThrow(InvalidArgumentException::class);
    });

    it('rejects negative precision', function (): void {
        expect(fn () => new CurrencySpec('USD', -1))->toThrow(InvalidArgumentException::class);
    });

    it('compares by code', function (): void {
        expect(usd()->is(new CurrencySpec('USD', 2)))->toBeTrue()
            ->and(usd()->is(aed()))->toBeFalse();
    });
});
