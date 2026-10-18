<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * One calendar month in the business timezone, for the agent detail page's
 * day-by-day table. `start` is midnight on the 1st (business tz); `end` is
 * the month's last moment, or now if the month is still in progress — so
 * the table never lists days that haven't happened yet.
 */
readonly class AgentMonthFilterData
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public function label(): string
    {
        return $this->start->format('Y-m');
    }
}
