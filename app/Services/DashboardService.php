<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Enums\Permission;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    private const APPROACHING_SLA_LIMIT = 10;

    private const TREND_DAYS = 14;

    public function __construct(private readonly DashboardRepositoryInterface $dashboard) {}

    /**
     * Stats/breakdowns are cached (ARCHITECTURE.md §14); the approaching-SLA
     * list is not — it's a cheap, indexed, limit(10) query, and caching a
     * `Ticket` collection is unsafe anyway: the default `database` cache
     * store sets `serializable_classes = false` (config/cache.php), so
     * Eloquent model objects don't survive a cache hit in production. API
     * Resources stay the controller's job (CLAUDE.md rule 3) — this
     * returns a raw `Ticket` collection, not a resource.
     *
     * @return array{scope: string, stats: array, byPriority: array, byAgent: array, trend: array, approachingSla: Collection<int, Ticket>}
     */
    public function forUser(User $user): array
    {
        $scope = $this->resolveScope($user);

        $cached = Cache::remember(
            $this->cacheKey($user, $scope),
            config('support.dashboard.cache_ttl'),
            fn () => $this->buildStats($user, $scope),
        );

        return [
            ...$cached,
            'approachingSla' => $this->approachingSla($user, $scope),
        ];
    }

    private function resolveScope(User $user): string
    {
        return match (true) {
            $user->can(Permission::DashboardViewGlobal->value) => 'global',
            $user->can(Permission::DashboardViewOwn->value) => 'agent',
            default => 'customer',
        };
    }

    /**
     * @return array{scope: string, stats: array, byPriority: array, byAgent: array, trend: array}
     */
    private function buildStats(User $user, string $scope): array
    {
        return match ($scope) {
            'global' => [
                'scope' => 'global',
                'stats' => [
                    ...$this->dashboard->summary(),
                    'unassigned' => $this->dashboard->unassignedOpenCount(),
                ],
                'byPriority' => $this->dashboard->byPriority()->values()->all(),
                'byAgent' => $this->dashboard->byAgent()->values()->all(),
                'trend' => $this->trend(),
            ],
            'agent' => [
                'scope' => 'agent',
                'stats' => [
                    ...$this->dashboard->summary(agentId: $user->id),
                    'unassigned' => $this->dashboard->unassignedOpenCount(),
                ],
                'byPriority' => [],
                'byAgent' => [],
                'trend' => $this->trend(agentId: $user->id),
            ],
            default => [
                'scope' => 'customer',
                'stats' => [
                    ...$this->dashboard->summary(customerId: $user->id),
                    'unassigned' => null,
                ],
                'byPriority' => [],
                'byAgent' => [],
                'trend' => $this->trend(customerId: $user->id),
            ],
        };
    }

    /**
     * Fills in days with no tickets as zero so the trend chart draws a
     * continuous `TREND_DAYS`-point line rather than skipping gaps.
     *
     * @return list<array{day: string, count: int}>
     */
    private function trend(?int $agentId = null, ?int $customerId = null): array
    {
        $byDay = $this->dashboard
            ->ticketsCreatedPerDay(self::TREND_DAYS, $agentId, $customerId)
            ->keyBy('day');

        return collect(range(self::TREND_DAYS - 1, 0))
            ->map(fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString())
            ->map(fn (string $day) => [
                'day' => $day,
                'count' => $byDay->get($day)['count'] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Ticket>
     */
    private function approachingSla(User $user, string $scope): Collection
    {
        return match ($scope) {
            'global' => $this->dashboard->approachingSla(limit: self::APPROACHING_SLA_LIMIT),
            'agent' => $this->dashboard->approachingSla(agentId: $user->id, limit: self::APPROACHING_SLA_LIMIT),
            default => $this->dashboard->approachingSla(customerId: $user->id, limit: self::APPROACHING_SLA_LIMIT),
        };
    }

    /**
     * Global stats are identical for every admin, so they share one cache
     * entry; agent/customer views are scoped to that one user (ARCHITECTURE
     * §14's "cached per role/user").
     */
    private function cacheKey(User $user, string $scope): string
    {
        return match ($scope) {
            'global' => 'dashboard.global',
            'agent' => "dashboard.agent.{$user->id}",
            default => "dashboard.customer.{$user->id}",
        };
    }
}
