<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\Decimal;
use App\Domain\Money\Exceptions\PrecisionLoss;
use App\Domain\Money\Money;
use App\Enums\ProfitMethod;
use App\Enums\SpreadType;
use DomainException;

/**
 * Works out the profit on an exchange, and shows how.
 *
 * Section 3's formulas, applied literally:
 *
 *     Customer Value = Delivered × Customer Rate
 *     Cost Value     = Delivered × Cost Rate
 *     Gross Profit   = Customer Value − Cost Value
 *     Net Profit     = Gross + Fees Charged − Expenses − External Commissions
 *
 * The customer rate is *derived* from the two amounts rather than entered, because the
 * amounts are what actually happened and the rate is a description of them. Entering
 * both and hoping they agree invites a deal whose recorded rate does not match the
 * money that moved.
 *
 * Every figure is an exact decimal. Nothing here is a float and nothing rounds.
 */
final class ProfitCalculator
{
    public function calculate(ExchangeInput $input): ProfitBreakdown
    {
        $profitSpec = $input->profitCurrency()->spec();

        // What the customer effectively paid per unit of what they got.
        $customerRate = $this->rateBetween($input->receivedAmount, $input->deliveredAmount);

        $customerValue = $input->receivedAmount;

        [$costRate, $costValue] = $this->cost($input, $customerRate, $customerValue);

        $gross = $customerValue->minus($costValue);

        $fees = $input->feesCharged ?? Money::zero($profitSpec);
        $expenses = $input->expenses ?? Money::zero($profitSpec);
        $commissions = $input->commissions ?? Money::zero($profitSpec);

        $net = $gross->plus($fees)->minus($expenses)->minus($commissions);

        return new ProfitBreakdown(
            method: $input->profitMethod,
            delivered: $input->deliveredAmount,
            received: $input->receivedAmount,
            customerRate: $customerRate,
            costRate: $costRate,
            customerValue: $customerValue,
            costValue: $costValue,
            grossProfit: $gross,
            feesCharged: $fees,
            expenses: $expenses,
            commissions: $commissions,
            netProfit: $net,
        );
    }

    /**
     * The cost side, which is what the profit method actually decides.
     *
     * @param  numeric-string  $customerRate
     * @return array{string|null, Money}
     */
    private function cost(ExchangeInput $input, string $customerRate, Money $customerValue): array
    {
        $profitSpec = $input->profitCurrency()->spec();

        return match ($input->profitMethod) {
            // An explicit cost rate: the most direct statement of what the delivered
            // currency actually cost.
            ProfitMethod::RateDifference => $this->fromCostRate($input, $customerRate),

            // A margin expressed against the rate. The spread type is what stops 0.02
            // being read as 2% when it means two hundredths of a unit.
            ProfitMethod::Percentage => $this->fromSpread($input, $customerRate, $customerValue),

            // The operator states the profit; the cost is whatever is left.
            ProfitMethod::FixedAmount, ProfitMethod::Manual => [
                null,
                $customerValue->minus($this->statedProfit($input)),
            ],

            // Moving our own money. There is no margin, so cost equals value.
            ProfitMethod::None => [null, $customerValue],
        };
    }

    /**
     * @param  numeric-string  $customerRate
     * @return array{string, Money}
     */
    private function fromCostRate(ExchangeInput $input, string $customerRate): array
    {
        $costRate = $input->costRate;

        if ($costRate === null) {
            throw new DomainException(
                'A rate-difference deal needs a cost rate: the profit is the gap between what the '
                .'customer was charged and what the currency cost. Supply one, or choose another method.'
            );
        }

        Decimal::assertValid($costRate);

        return [
            $costRate,
            $this->convert($input->deliveredAmount, $costRate, $input),
        ];
    }

    /**
     * A margin expressed as a spread against the customer rate.
     *
     * @param  numeric-string  $customerRate
     * @return array{string|null, Money}
     */
    private function fromSpread(ExchangeInput $input, string $customerRate, Money $customerValue): array
    {
        $type = $input->spreadType;
        $value = $input->spreadValue;

        if ($type === null || $value === null) {
            throw new DomainException(
                'A spread deal needs both a value and what that value means. Section 3: 0.02 may be '
                .'two hundredths of a unit or it may be two per cent, and the difference on a large '
                .'deal is enormous.'
            );
        }

        // Narrows the value for the arithmetic below, and rejects anything that is not
        // a plain decimal before it can reach bcmath.
        Decimal::assertValid($value);

        return match ($type) {
            // Margin per unit exchanged: cost rate is the customer rate less the spread.
            SpreadType::PerUnit => [
                $derived = Decimal::truncateTo(bcsub($customerRate, $value, Decimal::WORKING_SCALE), self::RATE_SCALE),
                $this->convert($input->deliveredAmount, $derived, $input),
            ],

            // A percentage of what the customer paid.
            SpreadType::Percentage => [
                null,
                $customerValue->minus($this->percentageOf($customerValue, $value)),
            ],
        };
    }

    private function statedProfit(ExchangeInput $input): Money
    {
        $value = $input->spreadValue;

        if ($value === null) {
            throw new DomainException(
                "A {$input->profitMethod->value} deal needs the profit amount to be stated."
            );
        }

        Decimal::assertValid($value);

        return Money::of($value, $input->profitCurrency()->spec());
    }

    /** @param  numeric-string  $percentage */
    private function percentageOf(Money $value, string $percentage): Money
    {
        $product = bcdiv(
            bcmul($value->toStorageString(), $percentage, Decimal::WORKING_SCALE),
            '100',
            Decimal::WORKING_SCALE,
        );

        return Money::of(Decimal::truncateTo($product, Money::SCALE), $value->currency);
    }

    /**
     * How many units of the received currency one unit of the delivered currency fetched.
     *
     * Division, so it truncates rather than rounds — the one place precision is
     * unavoidably lost, and it is lost at the twelfth decimal place of a rate.
     *
     * @return numeric-string
     */
    private function rateBetween(Money $received, Money $delivered): string
    {
        if ($delivered->isZero()) {
            throw new DomainException('An exchange cannot deliver nothing; there would be no rate.');
        }

        return Decimal::truncateTo(
            bcdiv($received->toStorageString(), $delivered->toStorageString(), Decimal::WORKING_SCALE),
            self::RATE_SCALE,
        );
    }

    /**
     * Convert an amount into the profit currency at a stated rate.
     *
     * Refuses rather than rounds when the product will not fit, exactly as
     * Money::multipliedBy does — a rate too precise for the amount is a loud failure,
     * not a quiet adjustment of somebody's margin.
     */
    private function convert(Money $amount, string $rate, ExchangeInput $input): Money
    {
        Decimal::assertValid($rate);

        $product = bcmul($amount->toStorageString(), $rate, Decimal::WORKING_SCALE);

        if (Decimal::losesPrecisionAt($product, Money::SCALE)) {
            throw PrecisionLoss::inMultiplication($amount->toStorageString(), $rate, $product, Money::SCALE);
        }

        return Money::of(Decimal::truncateTo($product, Money::SCALE), $input->profitCurrency()->spec());
    }

    /**
     * Rates are held to twelve decimal places, well beyond any quoted market rate.
     *
     * Defined by {@see RateQuote::SCALE}: a rate entered in the form and a rate derived
     * from the amounts must mean the same precision, or the two would disagree in the
     * last places for no reason a reader could discover.
     */
    public const int RATE_SCALE = RateQuote::SCALE;
}
