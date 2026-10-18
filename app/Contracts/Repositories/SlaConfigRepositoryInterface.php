<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\TicketPriority;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use Illuminate\Support\Collection;

interface SlaConfigRepositoryInterface
{
    public function policyFor(TicketPriority $priority): ?SlaPolicy;

    /**
     * @return Collection<string, SlaPolicy> keyed by priority value
     */
    public function policies(): Collection;

    /**
     * @return Collection<int, BusinessHour> keyed by day_of_week (0 = Sunday)
     */
    public function businessHours(): Collection;

    /**
     * @return Collection<int, string> holiday dates as 'Y-m-d' strings
     */
    public function holidayDates(): Collection;

    /**
     * Uncached list of holidays for the admin listing (needs ids/names, not
     * just the date set the calculator uses).
     *
     * @return Collection<int, Holiday>
     */
    public function holidays(): Collection;

    /**
     * @param  array<string, array{resolution_minutes: int, warning_threshold_percent: int, is_active: bool}>  $rows  keyed by priority value
     */
    public function updatePolicies(array $rows): void;

    /**
     * @param  array<int, array{is_working_day: bool, start_time: ?string, end_time: ?string}>  $rows  keyed by day_of_week
     */
    public function updateBusinessHours(array $rows): void;

    public function addHoliday(string $date, string $name): void;

    public function removeHoliday(int $id): void;

    public function bustCache(): void;
}
