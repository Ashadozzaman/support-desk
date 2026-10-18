<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\Enums\TicketPriority;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cached (ARCHITECTURE.md §8.1): policies/business hours/holidays change
 * rarely but are read on every SLA calculation, so each set is cached
 * forever and busted explicitly by the admin update methods below.
 */
class SlaConfigRepository implements SlaConfigRepositoryInterface
{
    private const POLICIES_KEY = 'support.sla.policies';

    private const BUSINESS_HOURS_KEY = 'support.sla.business_hours';

    private const HOLIDAYS_KEY = 'support.sla.holidays';

    public function policyFor(TicketPriority $priority): ?SlaPolicy
    {
        return $this->policies()->get($priority->value);
    }

    /**
     * Cached payloads are plain arrays, never Eloquent models: this app's
     * database cache store sets serializable_classes = false (config/cache.php),
     * so any object survives only the request that wrote it — a second read
     * within the same request degrades to __PHP_Incomplete_Class. Rehydrate
     * fresh (unsaved) models from the cached attribute arrays on every call.
     */
    public function policies(): Collection
    {
        $rows = Cache::rememberForever(
            self::POLICIES_KEY,
            fn () => SlaPolicy::query()->get()->map(fn (SlaPolicy $policy) => $policy->getAttributes())->all(),
        );

        return collect($rows)
            ->map(fn (array $attributes) => (new SlaPolicy)->forceFill($attributes))
            ->keyBy(fn (SlaPolicy $policy) => $policy->priority->value);
    }

    public function businessHours(): Collection
    {
        $rows = Cache::rememberForever(
            self::BUSINESS_HOURS_KEY,
            fn () => BusinessHour::query()->get()->map(fn (BusinessHour $hour) => $hour->getAttributes())->all(),
        );

        return collect($rows)
            ->map(fn (array $attributes) => (new BusinessHour)->forceFill($attributes))
            ->keyBy('day_of_week');
    }

    public function holidayDates(): Collection
    {
        $dates = Cache::rememberForever(
            self::HOLIDAYS_KEY,
            fn () => Holiday::query()->pluck('date')->map(fn ($date) => $date->toDateString())->all(),
        );

        return collect($dates);
    }

    public function holidays(): Collection
    {
        return Holiday::query()->orderBy('date')->get();
    }

    public function updatePolicies(array $rows): void
    {
        foreach ($rows as $priority => $attributes) {
            SlaPolicy::query()->where('priority', $priority)->update($attributes);
        }
    }

    public function updateBusinessHours(array $rows): void
    {
        foreach ($rows as $dayOfWeek => $attributes) {
            BusinessHour::query()->where('day_of_week', $dayOfWeek)->update($attributes);
        }
    }

    public function addHoliday(string $date, string $name): void
    {
        Holiday::query()->create(['date' => $date, 'name' => $name]);
    }

    public function removeHoliday(int $id): void
    {
        Holiday::query()->whereKey($id)->delete();
    }

    public function bustCache(): void
    {
        Cache::forget(self::POLICIES_KEY);
        Cache::forget(self::BUSINESS_HOURS_KEY);
        Cache::forget(self::HOLIDAYS_KEY);
    }
}
