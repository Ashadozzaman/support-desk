<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\DTOs\SlaWindowData;
use App\Enums\TicketPriority;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use App\Support\Sla\BusinessHoursCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SlaService
{
    public function __construct(
        private readonly SlaConfigRepositoryInterface $config,
        private readonly BusinessHoursCalculator $calculator,
    ) {}

    /**
     * Resolution/warning windows are business minutes (ARCHITECTURE.md
     * §8.1); an inactive or missing policy leaves the ticket without an SLA
     * rather than guessing a default.
     */
    public function calculate(TicketPriority $priority, CarbonImmutable $start): SlaWindowData
    {
        $policy = $this->config->policyFor($priority);

        if ($policy === null || ! $policy->is_active) {
            return new SlaWindowData(null, null);
        }

        $dueAt = $this->calculator->addBusinessMinutes($start, $policy->resolution_minutes);

        $warningMinutes = (int) round($policy->resolution_minutes * $policy->warning_threshold_percent / 100);
        $warningAt = $this->calculator->addBusinessMinutes($start, $warningMinutes);

        return new SlaWindowData($dueAt, $warningAt);
    }

    /**
     * @return Collection<string, SlaPolicy>
     */
    public function policies(): Collection
    {
        return $this->config->policies();
    }

    /**
     * @return Collection<int, BusinessHour>
     */
    public function businessHours(): Collection
    {
        return $this->config->businessHours();
    }

    /**
     * @return Collection<int, Holiday>
     */
    public function holidays(): Collection
    {
        return $this->config->holidays();
    }

    /**
     * @param  array<string, array{resolution_minutes: int, warning_threshold_percent: int, is_active: bool}>  $rows
     */
    public function updatePolicies(array $rows): void
    {
        DB::transaction(fn () => $this->config->updatePolicies($rows));

        $this->config->bustCache();
    }

    /**
     * @param  array<int, array{is_working_day: bool, start_time: ?string, end_time: ?string}>  $rows
     */
    public function updateBusinessHours(array $rows): void
    {
        DB::transaction(fn () => $this->config->updateBusinessHours($rows));

        $this->config->bustCache();
    }

    public function addHoliday(string $date, string $name): void
    {
        DB::transaction(fn () => $this->config->addHoliday($date, $name));

        $this->config->bustCache();
    }

    public function removeHoliday(int $id): void
    {
        DB::transaction(fn () => $this->config->removeHoliday($id));

        $this->config->bustCache();
    }
}
