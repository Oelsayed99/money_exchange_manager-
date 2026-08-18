<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\CounterpartyStatus;
use App\Models\Counterparty;
use App\Models\Currency;
use Illuminate\Support\Carbon;

/**
 * What the dashboard is being asked to show.
 *
 * The owner's four: "filter by client, time, currency, status".
 */
final readonly class DashboardFilters
{
    public function __construct(
        public ?Counterparty $counterparty = null,
        public ?Currency $currency = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
        public ?CounterpartyStatus $status = null,
    ) {}

    public function hasPeriod(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /** Inclusive to the end of the closing day, so "to the 30th" includes the 30th. */
    public function until(): ?Carbon
    {
        return $this->to?->copy()->endOfDay();
    }

    public function since(): ?Carbon
    {
        return $this->from?->copy()->startOfDay();
    }
}
