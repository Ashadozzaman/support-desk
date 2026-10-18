<?php

declare(strict_types=1);

namespace App\Support\Sla;

use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\Models\BusinessHour;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Business-hours arithmetic (ARCHITECTURE.md §8.2). All DB timestamps are
 * UTC; the day/open/close math below must happen in the business timezone
 * (config('support.sla.timezone')) so "09:00-18:00" means local wall-clock
 * hours, not UTC ones.
 */
class BusinessHoursCalculator
{
    /**
     * Guards both loops below against spinning forever on bad config (e.g.
     * every day marked non-working) — ARCHITECTURE.md §8.2 point 4.
     */
    private const MAX_ITERATIONS = 366;

    public function __construct(private readonly SlaConfigRepositoryInterface $config) {}

    public function addBusinessMinutes(CarbonImmutable $startUtc, int $minutes): CarbonImmutable
    {
        $cursor = $this->nextWorkingMoment($startUtc->setTimezone(config('support.sla.timezone')));
        $remaining = $minutes;

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $hours = $this->businessHoursFor($cursor);
            $closeOfDay = $this->atTime($cursor, $hours->end_time);
            $availableMinutes = $cursor->diffInMinutes($closeOfDay);

            if ($remaining <= $availableMinutes) {
                return $cursor->addMinutes($remaining)->setTimezone('UTC');
            }

            $remaining -= $availableMinutes;
            $cursor = $this->nextWorkingMoment($closeOfDay->addSecond());
        }

        throw new RuntimeException('BusinessHoursCalculator exceeded the iteration guard — check business_hours configuration.');
    }

    /**
     * Moves $moment forward to the next instant that falls inside a working
     * day's hours: before opening -> that day's opening; after closing, a
     * non-working day, or a holiday -> the next working day's opening.
     */
    private function nextWorkingMoment(CarbonImmutable $moment): CarbonImmutable
    {
        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $hours = $this->businessHoursFor($moment);

            if ($hours === null) {
                $moment = $moment->addDay()->startOfDay();

                continue;
            }

            $open = $this->atTime($moment, $hours->start_time);
            $close = $this->atTime($moment, $hours->end_time);

            if ($moment->lt($open)) {
                return $open;
            }

            if ($moment->gte($close)) {
                $moment = $moment->addDay()->startOfDay();

                continue;
            }

            return $moment;
        }

        throw new RuntimeException('BusinessHoursCalculator exceeded the iteration guard — check business_hours configuration.');
    }

    private function businessHoursFor(CarbonImmutable $moment): ?BusinessHour
    {
        $hours = $this->config->businessHours()->get($moment->dayOfWeek);

        return ($hours !== null && $hours->is_working_day && ! $this->isHoliday($moment)) ? $hours : null;
    }

    private function isHoliday(CarbonImmutable $moment): bool
    {
        return $this->config->holidayDates()->contains($moment->toDateString());
    }

    private function atTime(CarbonImmutable $moment, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $moment->setTime($hour, $minute, 0);
    }
}
