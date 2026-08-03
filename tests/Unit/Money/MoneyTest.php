<?php

declare(strict_types=1);

use App\Domain\Money\CurrencySpec;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Exceptions\PrecisionLoss;
use App\Domain\Money\Money;

function usd(): CurrencySpec
{
    return new CurrencySpec('USD', 2);
}

function aed(): CurrencySpec
{
    return new CurrencySpec('AED', 2);
}

describe('construction', function (): void {
    it('holds the amount at the storage scale', function (): void {
        expect(Money::of('10', usd())->toStorageString())->toBe('10.0000000000');
    });

    it('accepts integers', function (): void {
        expect(Money::of(1000, usd())->toStorageString())->toBe('1000.0000000000');
    });

    it('accepts negative amounts, because losses and payables are real', function (): void {
        expect(Money::of('-42.50', usd())->toDisplayString())->toBe('-42.50');
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

    it('rejects precision beyond the storage scale rather than discarding it', function (): void {
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

        expect($total->toDisplayString())->toBe('10.00');
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

        expect($original->toDisplayString())->toBe('10.00');
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

describe('multiplication is exact or it fails', function (): void {
    it('multiplies exactly', function (): void {
        expect(Money::of('1000', usd())->multipliedBy('3.6712345678')->toStorageString())
            ->toBe('3671.2345678000');
    });

    // Section 2's worked example: delivering 1,000 units at a customer rate of 3.67
    // against a cost rate of 3.65 yields exactly 20 of gross profit.
    it('reproduces the specification profit example exactly', function (): void {
        $delivered = Money::of('1000', aed());

        $customerValue = $delivered->multipliedBy('3.67');
        $costValue = $delivered->multipliedBy('3.65');

        expect($customerValue->minus($costValue)->toDisplayString())->toBe('20.00');
    });

    // Nothing rounds. A product that will not fit is a loud failure, not a quiet
    // adjustment of somebody's money.
    it('throws rather than discard a digit it cannot represent', function (): void {
        expect(fn () => Money::of('1', usd())->multipliedBy('0.00000000005'))
            ->toThrow(PrecisionLoss::class, 'needs more than 10 decimal places');
    });

    it('allows a product that fits exactly at the boundary', function (): void {
        expect(Money::of('1', usd())->multipliedBy('0.0000000001')->toStorageString())
            ->toBe('0.0000000001');
    });

    it('multiplies by zero and by one', function (): void {
        expect(Money::of('12.34', usd())->multipliedBy('0')->isZero())->toBeTrue()
            ->and(Money::of('12.34', usd())->multipliedBy('1')->toDisplayString())->toBe('12.34');
    });
});

describe('division truncates and never rounds', function (): void {
    it('divides exactly when it can', function (): void {
        expect(Money::of('10', usd())->dividedBy('4')->toDisplayString())->toBe('2.50');
    });

    // 10 / 3 does not terminate. The result is cut, never rounded up: the tenth
    // decimal stays 3 rather than becoming 4.
    it('truncates a non-terminating quotient rather than rounding it', function (): void {
        expect(Money::of('10', usd())->dividedBy('3')->toStorageString())->toBe('3.3333333333');
    });

    it('truncates toward zero for negatives too', function (): void {
        expect(Money::of('-10', usd())->dividedBy('3')->toStorageString())->toBe('-3.3333333333');
    });

    // Under any rounding rule this would end ...6667. It does not.
    it('never rounds a quotient up', function (): void {
        expect(Money::of('20', usd())->dividedBy('3')->toStorageString())->toBe('6.6666666666');
    });

    it('reports whether a division would be exact', function (): void {
        $ten = Money::of('10', usd());

        expect($ten->divisionIsExact('4'))->toBeTrue()
            ->and($ten->divisionIsExact('3'))->toBeFalse()
            ->and($ten->divisionIsExact('0'))->toBeFalse();
    });

    it('refuses division by zero', function (): void {
        expect(fn () => Money::of('10', usd())->dividedBy('0'))->toThrow(DivisionByZeroError::class);
    });
});

describe('sign and comparison', function (): void {
    it('reports sign', function (): void {
        expect(Money::of('1', usd())->isPositive())->toBeTrue()
            ->and(Money::of('-1', usd())->isNegative())->toBeTrue()
            ->and(Money::zero(usd())->isZero())->toBeTrue();
    });

    it('negates and takes absolute value', function (): void {
        expect(Money::of('-5', usd())->negated()->toDisplayString())->toBe('5.00')
            ->and(Money::of('-5', usd())->absolute()->toDisplayString())->toBe('5.00')
            ->and(Money::of('5', usd())->absolute()->toDisplayString())->toBe('5.00');
    });

    it('never yields a negative zero when negating zero', function (): void {
        expect(Money::zero(usd())->negated()->toStorageString())->toBe('0.0000000000');
    });

    it('orders amounts', function (): void {
        expect(Money::of('2', usd())->isGreaterThan(Money::of('1', usd())))->toBeTrue()
            ->and(Money::of('1', usd())->isLessThan(Money::of('2', usd())))->toBeTrue();
    });
});

describe('display shows exactly what is held', function (): void {
    it('pads out to the currency precision', function (): void {
        expect(Money::of('1000', usd())->toDisplayString())->toBe('1000.00')
            ->and(Money::of('1234.5', usd())->toDisplayString())->toBe('1234.50');
    });

    // The critical property: display never rounds an amount down to the currency's
    // precision. A sub-cent balance is shown, not hidden.
    it('shows every significant digit, beyond the currency precision', function (): void {
        expect(Money::of('1000.123456', usd())->toDisplayString())->toBe('1000.123456')
            ->and(Money::of('1.005', usd())->toDisplayString())->toBe('1.005')
            ->and(Money::of('0.0000000001', usd())->toDisplayString())->toBe('0.0000000001');
    });

    it('respects a zero-decimal currency', function (): void {
        expect(Money::of('1234', new CurrencySpec('JPY', 0))->toDisplayString())->toBe('1234')
            ->and(Money::of('1234.5', new CurrencySpec('JPY', 0))->toDisplayString())->toBe('1234.5');
    });

    it('respects a three-decimal currency', function (): void {
        expect(Money::of('1', new CurrencySpec('KWD', 3))->toDisplayString())->toBe('1.000')
            ->and(Money::of('1.2345', new CurrencySpec('KWD', 3))->toDisplayString())->toBe('1.2345');
    });

    it('displays zero cleanly', function (): void {
        expect(Money::zero(usd())->toDisplayString())->toBe('0.00');
    });

    // Risk R1: JavaScript's number is float64, so money must cross the boundary as a
    // string. This asserts the JSON payload contains no numeric amount.
    it('serialises the amount as a string, never a JSON number', function (): void {
        $payload = Money::of('3670.50', aed())->jsonSerialize();

        expect($payload)->toBe(['amount' => '3670.50', 'currency' => 'AED'])
            ->and($payload['amount'])->toBeString();

        expect(json_encode($payload))->toBe('{"amount":"3670.50","currency":"AED"}');
    });

    it('casts to string for display', function (): void {
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
