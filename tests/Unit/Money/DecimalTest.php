<?php

declare(strict_types=1);

use App\Domain\Money\Decimal;

describe('validation', function (): void {
    it('accepts plain decimal strings', function (string $value): void {
        expect(Decimal::isValid($value))->toBeTrue();
    })->with(['0', '-0', '1', '-1', '1.5', '-1.5', '0.0000000001', '123456789012345678.123456789']);

    it('rejects anything that is not a plain decimal', function (string $value): void {
        expect(Decimal::isValid($value))->toBeFalse();
    })->with([
        'scientific notation' => '1e5',
        'scientific notation uppercase' => '1E5',
        'thousands separator' => '1,000.00',
        'leading whitespace' => ' 1.00',
        'trailing whitespace' => "1.00\n",
        'empty' => '',
        'bare sign' => '-',
        'trailing dot' => '1.',
        'leading dot' => '.5',
        'double sign' => '--1',
        'currency symbol' => '$1.00',
        'hex' => '0x1A',
        'infinity' => 'INF',
        'not a number' => 'NAN',
    ]);

    it('throws with a useful message on invalid input', function (): void {
        expect(fn () => Decimal::assertValid('1e5'))
            ->toThrow(InvalidArgumentException::class, 'Not a valid decimal string: [1e5]');
    });

    it('rejects a negative scale', function (): void {
        expect(fn () => Decimal::padTo('1.5', -1))->toThrow(InvalidArgumentException::class);
    });
});

describe('scaleOf', function (): void {
    it('counts decimal places', function (string $value, int $expected): void {
        expect(Decimal::scaleOf($value))->toBe($expected);
    })->with([
        ['1', 0],
        ['1.0', 1],
        ['1.00', 2],
        ['-1.234', 3],
        ['0.0000000001', 10],
    ]);
});

describe('padTo', function (): void {
    it('pads a less precise value', function (): void {
        expect(Decimal::padTo('1', 3))->toBe('1.000')
            ->and(Decimal::padTo('1.5', 4))->toBe('1.5000');
    });

    // Padding must never be a disguised truncation: it only ever adds zeros.
    it('leaves a more precise value untouched', function (): void {
        expect(Decimal::padTo('0.999', 2))->toBe('0.999')
            ->and(Decimal::padTo('-0.123456789', 2))->toBe('-0.123456789');
    });

    it('never produces a negative zero', function (): void {
        expect(Decimal::padTo('-0', 2))->toBe('0.00');
    });
});

describe('truncateTo', function (): void {
    // Truncation, not rounding. Magnitude never increases, in either sign.
    it('drops digits toward zero', function (string $in, string $out): void {
        expect(Decimal::truncateTo($in, 2))->toBe($out);
    })->with([
        ['0.999', '0.99'],
        ['-0.999', '-0.99'],
        ['2.005', '2.00'],
        ['-2.005', '-2.00'],
        ['9.999', '9.99'],
    ]);

    it('never rounds a half up', function (): void {
        // Under any half-up rule these would be 3, 1.01 and -3. They are not.
        expect(Decimal::truncateTo('2.5', 0))->toBe('2')
            ->and(Decimal::truncateTo('1.005', 2))->toBe('1.00')
            ->and(Decimal::truncateTo('-2.5', 0))->toBe('-2');
    });

    it('never increases magnitude', function (string $value): void {
        $truncated = Decimal::truncateTo($value, 2);

        expect(bccomp(ltrim($truncated, '-'), ltrim($value, '-'), 24))->toBeLessThanOrEqual(0);
    })->with(['0.999', '-0.999', '12.345678', '-12.345678', '0.001', '-0.001']);

    it('pads when the value is less precise', function (): void {
        expect(Decimal::truncateTo('1.5', 4))->toBe('1.5000');
    });

    it('never produces a negative zero', function (): void {
        expect(Decimal::truncateTo('-0.004', 2))->toBe('0.00')
            ->and(Decimal::truncateTo('-0.9', 0))->toBe('0');
    });

    it('keeps full precision on very large values', function (): void {
        expect(Decimal::truncateTo('123456789012345678.987654321', 2))
            ->toBe('123456789012345678.98');
    });
});

describe('losesPrecisionAt', function (): void {
    it('detects a digit that would be discarded', function (): void {
        expect(Decimal::losesPrecisionAt('1.005', 2))->toBeTrue()
            ->and(Decimal::losesPrecisionAt('1.001', 2))->toBeTrue();
    });

    it('reports no loss when the value already fits', function (): void {
        expect(Decimal::losesPrecisionAt('1.00', 2))->toBeFalse()
            ->and(Decimal::losesPrecisionAt('1.10', 2))->toBeFalse()
            ->and(Decimal::losesPrecisionAt('1', 2))->toBeFalse();
    });

    it('ignores trailing zeros beyond the scale', function (): void {
        expect(Decimal::losesPrecisionAt('1.2300000', 2))->toBeFalse();
    });
});

describe('exactness', function (): void {
    // Documents why this class exists. IEEE-754 cannot represent these sums.
    it('avoids the float arithmetic it exists to replace', function (): void {
        expect(0.1 + 0.2 === 0.3)->toBeFalse()
            ->and(0.1 + 0.7 === 0.8)->toBeFalse();

        // Exact to twenty decimal places, then reduced to ten by dropping zeros only.
        expect(bcadd('0.1', '0.2', 20))->toBe('0.30000000000000000000')
            ->and(Decimal::truncateTo(bcadd('0.1', '0.2', 20), 10))->toBe('0.3000000000');
    });
});
