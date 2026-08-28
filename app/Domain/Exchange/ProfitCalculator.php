<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\Decimal;
use App\Domain\Money\Exceptions\PrecisionLoss;
use App\Domain\Money\Money;
use App\Enums\ProfitMethod;
use DomainException;

/**
 * Works out the profit on an exchange, and shows how.
 *
 * Section 3's formulas, stated against whichever leg carries the margin:
 *
 *     Customer Value = the margin leg, exactly as it happened
 *     Cost Value     = the other leg × Cost Rate
 *     Gross Profit   = Customer Value − Cost Value, or the reverse
 *     Net Profit     = Gross + Fees Charged − Expenses − External Commissions
 *
 * Section 3 writes the first three against the delivered leg, which is right for a sale
 * — the currency going out is the one that cost something — and wrong for a purchase,
 * where the margin is in the currency being paid out and the cost rate is per unit
 * *received*. {@see MarginBasis}. The received basis is what Section 3 describes and
 * remains the default.
 *
 * The direction of the subtraction follows the leg: money arriving in the margin
 * currency means more of it is better, money leaving means less of it is.
 *
 * The customer rate is *derived* from the two amounts rather than entered, because the
 * amounts are what actually happened and the rate is a description of them. Entering
 * both and hoping they agree invites a deal whose recorded rate does not match the
 * money that moved.
 *
 * Every figure is an exact decimal. Nothing here is a float, nothing rounds, and the
 * cost rate is only ever multiplied — which is the reason the basis exists rather than
 * a rule that turns the rate over.
 */
final class ProfitCalculator
{
    public function calculate(ExchangeInput $input): ProfitBreakdown
    {
        $profitSpec = $input->profitCurrency()->spec();

        // The deal's own rate, in the margin currency per unit of the other leg — the
        // same terms as the cost rate, so the two are comparable without conversion.
        $customerRate = $this->rateBetween($input->marginLeg(), $input->otherLeg());

        $customerValue = $input->marginLeg();

        [$costRate, $costValue] = $this->cost($input, $customerRate, $customerValue);

        $gross = $input->marginCameIn()
            ? $customerValue->minus($costValue)
            : $costValue->minus($customerValue);

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

            // Section 3's two readings of the same number, each now its own method.
            // 0.02 per unit on a rate of 3.67 is a cost of 3.65; 0.02 per cent of the
            // same deal is two hundredths of a per cent. A factor of about fifty.
            ProfitMethod::PerUnit => $this->fromPerUnitMargin($input, $customerRate),
            ProfitMethod::Percentage => [
                null,
                $this->costYielding($input, $customerValue, $this->percentageOf($customerValue, $this->statedValue($input))),
            ],

            // The operator states the profit; the cost is whatever is left.
            ProfitMethod::FixedAmount, ProfitMethod::Manual => [
                null,
                $this->costYielding($input, $customerValue, $this->statedProfit($input)),
            ],

            // Moving our own money. There is no margin, so cost equals value.
            ProfitMethod::None => [null, $customerValue],
        };
    }

    /**
     * The cost value that would produce a given gross margin.
     *
     * The inverse of the subtraction in calculate(), so the two cannot disagree about
     * which way round the margin leg sits.
     */
    private function costYielding(ExchangeInput $input, Money $customerValue, Money $gross): Money
    {
        return $input->marginCameIn()
            ? $customerValue->minus($gross)
            : $customerValue->plus($gross);
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
            $this->convert($input->otherLeg(), $costRate, $input),
        ];
    }

    /**
     * Margin stated as currency units per unit exchanged.
     *
     * The cost rate is the customer rate less the margin, so this is the one stated
     * method that still produces a rate — and the rate is what the ledger records.
     *
     * @param  numeric-string  $customerRate
     * @return array{string, Money}
     */
    private function fromPerUnitMargin(ExchangeInput $input, string $customerRate): array
    {
        $margin = $this->statedValue($input);

        // Subtract when the margin currency came in — we paid less for it than we got
        // — and add when it went out, where the gap runs the other way. Either way the
        // gross works out as the margin times the other leg.
        $derived = Decimal::truncateTo(
            $input->marginCameIn()
                ? bcsub($customerRate, $margin, Decimal::WORKING_SCALE)
                : bcadd($customerRate, $margin, Decimal::WORKING_SCALE),
            self::RATE_SCALE,
        );

        return [$derived, $this->convert($input->otherLeg(), $derived, $input)];
    }

    /**
     * The figure typed alongside the method.
     *
     * Narrowed for the arithmetic below, and rejected here rather than at bcmath if it
     * is not a plain decimal.
     *
     * @return numeric-string
     */
    private function statedValue(ExchangeInput $input): string
    {
        $value = $input->profitValue;

        if ($value === null) {
            throw new DomainException(
                "A {$input->profitMethod->value} deal needs its figure stated. "
                .'Supply one, or choose a method that does not need it.'
            );
        }

        Decimal::assertValid($value);

        return $value;
    }

    private function statedProfit(ExchangeInput $input): Money
    {
        return Money::of($this->statedValue($input), $input->profitCurrency()->spec());
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
     * How many units of the margin currency one unit of the other leg fetched.
     *
     * Division, so it truncates rather than rounds — the one place precision is
     * unavoidably lost, and it is lost at the twelfth decimal place of a rate. Note
     * that this is the *derived* rate, describing money that has already moved. The
     * cost rate is never divided; that is what the basis is for.
     *
     * @return numeric-string
     */
    private function rateBetween(Money $margin, Money $other): string
    {
        if ($other->isZero()) {
            throw new DomainException('An exchange cannot have a leg of nothing; there would be no rate.');
        }

        return Decimal::truncateTo(
            bcdiv($margin->toStorageString(), $other->toStorageString(), Decimal::WORKING_SCALE),
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
