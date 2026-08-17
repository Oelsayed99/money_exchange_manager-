<?php

declare(strict_types=1);

use App\Domain\Exchange\RateQuote;
use App\Domain\Money\CurrencySpec;
use App\Domain\Money\Money;

$usd = new CurrencySpec('USD');
$aed = new CurrencySpec('AED');
$egp = new CurrencySpec('EGP');
$eur = new CurrencySpec('EUR');

describe('what a quote is', function () use ($usd, $aed): void {
    it('holds both currencies, so it cannot be read backwards later', function () use ($usd, $aed): void {
        $quote = RateQuote::of($usd, $aed, '3.67');

        expect($quote->base->code)->toBe('USD')
            ->and($quote->quote->code)->toBe('AED')
            ->and($quote->rate)->toBe('3.67');
    });

    it('refuses a rate between a currency and itself', function () use ($usd): void {
        expect(fn () => RateQuote::of($usd, $usd, '1'))
            ->toThrow(InvalidArgumentException::class, 'two different currencies');
    });

    it('refuses a rate of zero or less', function (string $rate) use ($usd, $aed): void {
        expect(fn () => RateQuote::of($usd, $aed, $rate))
            ->toThrow(InvalidArgumentException::class, 'greater than zero');
    })->with(['0', '0.000000000000', '-3.67']);

    it('refuses a rate carrying more precision than a rate can hold', function () use ($usd, $aed): void {
        expect(fn () => RateQuote::of($usd, $aed, '3.6712345678901'))
            ->toThrow(InvalidArgumentException::class, 'at most 12 decimal places');
    });

    it('refuses anything that is not a plain decimal', function () use ($usd, $aed): void {
        expect(fn () => RateQuote::of($usd, $aed, '3,67'))->toThrow(InvalidArgumentException::class);
    });
});

// The owner's own example: buying 100,000 USD, paying in dirhams at 3.67.
describe('converting from the base', function () use ($usd, $aed): void {
    it('multiplies, and says so by coming out exact', function () use ($usd, $aed): void {
        $result = (RateQuote::of($usd, $aed, '3.67'))->convert(Money::of('100000', $usd));

        expect($result->amount->toDisplayString())->toBe('367000.00')
            ->and($result->amount->currency->code)->toBe('AED')
            ->and($result->exact)->toBeTrue();
    });

    it('stays exact on a rate with real precision behind it', function () use ($usd, $aed): void {
        $result = (RateQuote::of($usd, $aed, '3.6725'))->convert(Money::of('1234', $usd));

        expect($result->amount->toStorageString())->toBe('4531.8650000000')
            ->and($result->exact)->toBeTrue();
    });

    // A rate precise enough to push the product past ten places truncates rather than
    // throwing, unlike Money::multipliedBy. The operator is told, and corrects it.
    it('truncates rather than throwing when the product will not fit', function () use ($usd, $aed): void {
        $result = (RateQuote::of($usd, $aed, '3.670000000001'))->convert(Money::of('0.001', $usd));

        expect($result->exact)->toBeFalse()
            ->and($result->amount->toStorageString())->toBe('0.0036700000');
    });
});

// The other example: selling EGP, paid in euros at 54.20 to the euro.
describe('converting from the quote', function () use ($egp, $eur, $usd): void {
    it('divides, and admits when the result had to be cut', function () use ($eur, $egp): void {
        $result = (RateQuote::of($eur, $egp, '54.20'))->convert(Money::of('1000000', $egp));

        expect($result->amount->toStorageString())->toBe('18450.1845018450')
            ->and($result->amount->currency->code)->toBe('EUR')
            ->and($result->exact)->toBeFalse();
    });

    it('comes out exact when the division happens to land', function () use ($eur, $egp): void {
        $result = (RateQuote::of($eur, $egp, '54.20'))->convert(Money::of('54200', $egp));

        expect($result->amount->toDisplayString())->toBe('1000.00')
            ->and($result->exact)->toBeTrue();
    });

    // Truncation, never rounding: the result can never exceed the true value.
    it('cuts towards zero rather than rounding up', function () use ($eur, $egp): void {
        $result = (RateQuote::of($eur, $egp, '3'))->convert(Money::of('100', $egp));

        expect($result->amount->toStorageString())->toBe('33.3333333333');
    });

    it('refuses an amount in a currency the quote does not mention', function () use ($eur, $egp, $usd): void {
        expect(fn () => (RateQuote::of($eur, $egp, '54.20'))->convert(Money::of('1', $usd)))
            ->toThrow(DomainException::class, 'belongs to neither side');
    });
});

describe('deriving the rate the amounts imply', function () use ($usd, $egp): void {
    // The real deal from the owner's statement: 2,574,000 EGP for 50,000 USD.
    it('recovers the rate from the two amounts', function () use ($usd, $egp): void {
        $quote = RateQuote::between(Money::of('50000', $usd), Money::of('2574000', $egp));

        expect($quote->rate)->toBe('51.480000000000')
            ->and($quote->base->code)->toBe('USD')
            ->and($quote->quote->code)->toBe('EGP');
    });

    it('reports whether that rate is the whole of it', function () use ($usd, $egp): void {
        expect(RateQuote::betweenIsExact(Money::of('50000', $usd), Money::of('2574000', $egp)))->toBeTrue()
            ->and(RateQuote::betweenIsExact(Money::of('3', $usd), Money::of('10', $egp)))->toBeFalse();
    });

    it('refuses to derive a rate from nothing', function () use ($usd, $egp): void {
        expect(fn () => RateQuote::between(Money::of('0', $usd), Money::of('2574000', $egp)))
            ->toThrow(DomainException::class, 'every rate would satisfy it');
    });

    // The property that makes the form trustworthy: type a rate, get an amount, and the
    // amount implies the rate you typed. Anything else would move money silently.
    it('round-trips a rate through a conversion and back', function () use ($usd, $egp): void {
        $typed = RateQuote::of($usd, $egp, '51.48');
        $delivered = Money::of('50000', $usd);

        $received = $typed->convert($delivered);

        expect($received->exact)->toBeTrue()
            ->and(RateQuote::between($delivered, $received->amount)->rate)
            ->toBe('51.480000000000');
    });
});

describe('stating the same deal the other way round', function () use ($usd, $aed): void {
    it('swaps the currencies and inverts the rate', function () use ($usd, $aed): void {
        $inverted = (RateQuote::of($usd, $aed, '4'))->inverted();

        expect($inverted->base->code)->toBe('AED')
            ->and($inverted->quote->code)->toBe('USD')
            ->and($inverted->rate)->toBe('0.250000000000');
    });

    // Inverting is a division and 1/3.67 does not terminate, so a round trip does not
    // return the original. This is why the form inverts the *label* and re-solves from
    // the amounts, rather than inverting the number the operator typed.
    it('does not survive a round trip when the inverse does not terminate', function () use ($usd, $aed): void {
        $original = RateQuote::of($usd, $aed, '3.67');

        expect($original->inverted()->inverted()->rate)->not->toBe('3.670000000000');
    });
});
