<?php

declare(strict_types=1);

use App\Domain\Money\Decimal;
use App\Domain\Money\RoundingMode;

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
        expect(fn () => Decimal::round('1.5', -1, RoundingMode::HalfUp))
            ->toThrow(InvalidArgumentException::class);
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

describe('rounding', function (): void {
    // bcmath truncates rather than rounds, so each mode is verified explicitly at a
    // tie (exactly .5), above a tie and below a tie, in both signs.

    it('HalfUp takes ties away from zero', function (string $in, string $out): void {
        expect(Decimal::round($in, 0, RoundingMode::HalfUp))->toBe($out);
    })->with([
        ['2.5', '3'], ['3.5', '4'], ['2.4', '2'], ['2.6', '3'],
        ['-2.5', '-3'], ['-3.5', '-4'], ['-2.4', '-2'], ['-2.6', '-3'],
    ]);

    it('HalfDown takes ties toward zero', function (string $in, string $out): void {
        expect(Decimal::round($in, 0, RoundingMode::HalfDown))->toBe($out);
    })->with([
        ['2.5', '2'], ['3.5', '3'], ['2.6', '3'],
        ['-2.5', '-2'], ['-3.5', '-3'], ['-2.6', '-3'],
    ]);

    it('HalfEven takes ties to the nearest even digit', function (string $in, string $out): void {
        expect(Decimal::round($in, 0, RoundingMode::HalfEven))->toBe($out);
    })->with([
        ['2.5', '2'], ['3.5', '4'], ['4.5', '4'], ['5.5', '6'],
        ['-2.5', '-2'], ['-3.5', '-4'],
        ['2.51', '3'], ['2.49', '2'],
    ]);

    it('Up always moves away from zero', function (string $in, string $out): void {
        expect(Decimal::round($in, 0, RoundingMode::Up))->toBe($out);
    })->with([['2.1', '3'], ['2.9', '3'], ['-2.1', '-3'], ['-2.9', '-3']]);

    it('Down always moves toward zero', function (string $in, string $out): void {
        expect(Decimal::round($in, 0, RoundingMode::Down))->toBe($out);
    })->with([['2.1', '2'], ['2.9', '2'], ['-2.1', '-2'], ['-2.9', '-2']]);

    it('Ceiling always moves toward positive infinity', function (string $in, string $out): void {
        expect(Decimal::round($in, 0, RoundingMode::Ceiling))->toBe($out);
    })->with([['2.1', '3'], ['2.9', '3'], ['-2.1', '-2'], ['-2.9', '-2']]);

    it('Floor always moves toward negative infinity', function (string $in, string $out): void {
        expect(Decimal::round($in, 0, RoundingMode::Floor))->toBe($out);
    })->with([['2.1', '2'], ['2.9', '2'], ['-2.1', '-3'], ['-2.9', '-3']]);

    it('leaves exact values untouched in every mode', function (RoundingMode $mode): void {
        expect(Decimal::round('7.00', 2, $mode))->toBe('7.00');
        expect(Decimal::round('-7.00', 2, $mode))->toBe('-7.00');
    })->with(RoundingMode::cases());

    it('pads to the requested scale when the value is less precise', function (): void {
        expect(Decimal::round('1.5', 4, RoundingMode::HalfUp))->toBe('1.5000');
    });

    it('rounds a half-cent tie up', function (): void {
        expect(Decimal::round('1.005', 2, RoundingMode::HalfUp))->toBe('1.01');
    });

    // Documents why this class exists at all. PHP's round() applies a fuzz correction
    // that rescues some literals, but raw IEEE-754 arithmetic still does not hold,
    // and a ledger cannot depend on a correction that only sometimes applies.
    it('avoids the float arithmetic this class exists to replace', function (): void {
        expect(0.1 + 0.2 === 0.3)->toBeFalse()
            ->and(0.1 + 0.7 === 0.8)->toBeFalse();

        expect(Decimal::round(bcadd('0.1', '0.2', 20), 10, RoundingMode::HalfUp))->toBe('0.3000000000');
    });

    it('never produces a negative zero', function (): void {
        expect(Decimal::round('-0.004', 2, RoundingMode::HalfUp))->toBe('0.00')
            ->and(Decimal::round('-0.4', 0, RoundingMode::HalfUp))->toBe('0')
            ->and(Decimal::round('-0.9', 0, RoundingMode::Down))->toBe('0');
    });

    it('carries correctly across a digit boundary', function (): void {
        expect(Decimal::round('9.995', 2, RoundingMode::HalfUp))->toBe('10.00')
            ->and(Decimal::round('0.999999', 2, RoundingMode::HalfUp))->toBe('1.00')
            ->and(Decimal::round('-9.995', 2, RoundingMode::HalfUp))->toBe('-10.00');
    });

    it('keeps full precision on very large values', function (): void {
        expect(Decimal::round('123456789012345678.987654321', 2, RoundingMode::HalfUp))
            ->toBe('123456789012345678.99');
    });
});

describe('atScale', function (): void {
    it('truncates toward zero rather than rounding', function (): void {
        expect(Decimal::atScale('0.999', 2))->toBe('0.99')
            ->and(Decimal::atScale('-0.999', 2))->toBe('-0.99');
    });

    it('pads a less precise value', function (): void {
        expect(Decimal::atScale('1', 3))->toBe('1.000');
    });
});
