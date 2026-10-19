<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\DTOs\SlaWindowData;
use App\Enums\AuditEvent;
use App\Enums\TicketPriority;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Support\Sla\BusinessHoursCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SlaService
{
    public function __construct(
        private readonly SlaConfigRepositoryInterface $config,
        private readonly BusinessHoursCalculator $calculator,
        private readonly AuditLogService $audit,
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
     * The audit row is written inside the same transaction as the change,
     * so a rolled-back edit leaves no "updated" entry behind.
     *
     * @param  array<string, array{resolution_minutes: int, warning_threshold_percent: int, is_active: bool}>  $rows
     */
    public function updatePolicies(array $rows, User $actor): void
    {
        $changes = $this->diff($this->config->policies(), $rows);

        DB::transaction(function () use ($rows, $changes, $actor) {
            $this->config->updatePolicies($rows);

            if ($changes !== []) {
                $this->audit->record(AuditEvent::SlaPoliciesUpdated, $actor, properties: ['changes' => $changes]);
            }
        });

        $this->config->bustCache();
    }

    /**
     * @param  array<int, array{is_working_day: bool, start_time: ?string, end_time: ?string}>  $rows
     */
    public function updateBusinessHours(array $rows, User $actor): void
    {
        $changes = $this->diff($this->config->businessHours()->keyBy('day_of_week'), $rows);

        DB::transaction(function () use ($rows, $changes, $actor) {
            $this->config->updateBusinessHours($rows);

            if ($changes !== []) {
                $this->audit->record(AuditEvent::BusinessHoursUpdated, $actor, properties: ['changes' => $changes]);
            }
        });

        $this->config->bustCache();
    }

    public function addHoliday(string $date, string $name, User $actor): void
    {
        DB::transaction(function () use ($date, $name, $actor) {
            $this->config->addHoliday($date, $name);
            $this->audit->record(AuditEvent::HolidayAdded, $actor, properties: ['holiday' => ['date' => $date, 'name' => $name]]);
        });

        $this->config->bustCache();
    }

    public function removeHoliday(Holiday $holiday, User $actor): void
    {
        DB::transaction(function () use ($holiday, $actor) {
            $this->config->removeHoliday($holiday->id);
            $this->audit->record(AuditEvent::HolidayRemoved, $actor, properties: [
                'holiday' => ['date' => $holiday->date->toDateString(), 'name' => $holiday->name],
            ]);
        });

        $this->config->bustCache();
    }

    /**
     * Field-level old/new for the audit row, only for values that actually
     * change. "Before" comes from the config cache — safe because this
     * service is the only writer and busts the cache after every write.
     * Loose `!=` on purpose: the cache holds raw DB values (1, "09:00:00")
     * and the form sends typed ones (true, "09:00:00").
     *
     * @param  Collection<array-key, Model>  $current
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return array<array-key, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    private function diff(Collection $current, array $rows): array
    {
        $changes = [];

        foreach ($rows as $key => $attributes) {
            $before = $current->get($key);

            foreach ($attributes as $field => $value) {
                $old = $before?->getAttribute($field);

                if ($old != $value) {
                    $changes[$key]['old'][$field] = $old;
                    $changes[$key]['new'][$field] = $value;
                }
            }
        }

        return $changes;
    }
}
