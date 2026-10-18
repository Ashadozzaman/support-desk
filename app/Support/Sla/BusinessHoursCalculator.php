<?php

declare(strict_types=1);

namespace App\Support\Sla;

use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\Models\BusinessHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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

    /**
     * Config loaded once per calculator instance. On the `database` cache
     * store every repository call is a DB round trip, and the day loops below
     * ask for it per day — `reports:backfill` over ~1M tickets would make
     * hundreds of millions of them. Safe because the calculator is resolved
     * fresh per request/job/command (never a singleton), so an admin edit is
     * still picked up by the next one.
     *
     * @var Collection<int, BusinessHour>|null
     */
    private ?Collection $businessHours = null;

    /** @var Collection<int, string>|null */
    private ?Collection $holidayDates = null;

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
     * Inverse of addBusinessMinutes(): how many business minutes lie between
     * two instants (report metrics, ENHANCEMENTS.md §1.2). Walks the
     * calendar days of the range itself and adds each working day's overlap
     * with it, so the loop is bounded by the range — no iteration guard
     * needed, and a config with no working days correctly yields 0 instead
     * of throwing.
     */
    public function businessMinutesBetween(CarbonImmutable $startUtc, CarbonImmutable $endUtc): int
    {
        $timezone = config('support.sla.timezone');
        $start = $startUtc->setTimezone($timezone);
        $end = $endUtc->setTimezone($timezone);
        $minutes = 0;

        for ($day = $start->startOfDay(); $day->lt($end); $day = $day->addDay()) {
            $hours = $this->businessHoursFor($day);

            if ($hours === null) {
                continue;
            }

            $from = $start->max($this->atTime($day, $hours->start_time));
            $to = $end->min($this->atTime($day, $hours->end_time));

            if ($from->lt($to)) {
                $minutes += (int) $from->diffInMinutes($to);
            }
        }

        return $minutes;
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
        $this->businessHours ??= $this->config->businessHours();
        $hours = $this->businessHours->get($moment->dayOfWeek);

        return ($hours !== null && $hours->is_working_day && ! $this->isHoliday($moment)) ? $hours : null;
    }

    private function isHoliday(CarbonImmutable $moment): bool
    {
        $this->holidayDates ??= $this->config->holidayDates();

        return $this->holidayDates->contains($moment->toDateString());
    }

    private function atTime(CarbonImmutable $moment, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $moment->setTime($hour, $minute, 0);
    }
}
