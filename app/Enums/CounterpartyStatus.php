<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a counterparty stands with the business.
 *
 * The owner's three cases, in their words: "they own me money, they have credit with
 * me, or closed status no one owns the other". Plus a fourth they did not name and
 * which the four-bucket model makes possible — a party can be on both sides at once,
 * and calling that either one would be a lie.
 *
 * Derived, never stored. A status is a reading of the four positions, and storing it
 * would create a field that could contradict them.
 */
enum CounterpartyStatus: string
{
    /** They hold our money or owe it to us. */
    case OwesUs = 'owes_us';

    /** We hold their money or owe it to them. */
    case HasCredit = 'has_credit';

    /** Both at once. Not a middle ground: two live positions that must not be netted. */
    case Mixed = 'mixed';

    /** Every bucket at zero. Nobody owes anybody anything. */
    case Settled = 'settled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    /**
     * Read a status from the two sides of the relationship.
     *
     * Takes the sides rather than a net figure, because a net figure is exactly what
     * this application refuses to compute. See ADR 0007.
     */
    public static function forSides(bool $theyOweUs, bool $weOweThem): self
    {
        return match (true) {
            $theyOweUs && $weOweThem => self::Mixed,
            $theyOweUs => self::OwesUs,
            $weOweThem => self::HasCredit,
            default => self::Settled,
        };
    }

    /**
     * One status for a party trading in several currencies.
     *
     * Currencies that disagree resolve to Mixed rather than to whichever appears
     * first: a client owing dollars while holding pounds on deposit is genuinely both,
     * and the currency filter is how somebody looks at one of them at a time.
     *
     * @param  list<self>  $perCurrency
     */
    public static function across(array $perCurrency): self
    {
        $live = array_values(array_filter($perCurrency, fn (self $status): bool => $status !== self::Settled));

        return match (count(array_unique(array_map(fn (self $s): string => $s->value, $live)))) {
            0 => self::Settled,
            1 => $live[0],
            default => self::Mixed,
        };
    }

    public function label(): string
    {
        return __('dashboard.statuses.'.$this->value);
    }
}
