<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTOs\AgentPerformanceFilterData;
use App\Models\AgentProfile;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

interface AgentRepositoryInterface
{
    /**
     * Available agent profiles that either handle the given category or
     * have no category restriction, annotated with `active_count` and
     * still under `max_active_tickets` (ARCHITECTURE.md §6.2). Not locked
     * and not ordered — callers (AssignmentStrategy implementations)
     * decide the ranking.
     *
     * @return Collection<int, AgentProfile>
     */
    public function candidatesFor(?int $categoryId): Collection;

    /**
     * Every available agent still under `max_active_tickets`, regardless of
     * category, annotated with `active_count` and with `user.categories`
     * loaded — what the `tickets:auto-assign` sweep needs to size itself.
     *
     * @return Collection<int, AgentProfile>
     */
    public function withFreeCapacity(): Collection;

    /**
     * SELECT ... FOR UPDATE SKIP LOCKED on a single agent_profiles row.
     * Returns null if another concurrent pick already holds the lock.
     */
    public function lockForAssignment(int $agentProfileId): ?AgentProfile;

    public function touchLastAssigned(AgentProfile $profile): void;

    /**
     * One page of agent profiles ordered by name (every agent, even one
     * with zero tickets ever — unlike DashboardRepository::byAgent()), each
     * with live workload counts (active/open/in_progress/waiting/stuck, as
     * of now) and `completed_count`: tickets resolved/closed with
     * `resolved_at` inside the filter window.
     *
     * @return LengthAwarePaginator<int, array{
     *     agent: array{id: int, name: string},
     *     active_count: int,
     *     open_count: int,
     *     in_progress_count: int,
     *     waiting_count: int,
     *     stuck_count: int,
     *     completed_count: int,
     *     max_active_tickets: int,
     *     is_available: bool,
     * }>
     */
    public function paginatePerformance(AgentPerformanceFilterData $filters, int $perPage): LengthAwarePaginator;

    /**
     * Full, all-time status breakdown for one agent — including
     * resolved/closed, unlike the dashboard's active-only counts.
     *
     * @return array{open: int, in_progress: int, waiting_for_customer: int, resolved: int, closed: int}
     */
    public function statusBreakdown(int $agentId): array;

    /**
     * Resolved+closed ticket count per day (by `resolved_at`) over the
     * trailing `$days` days for one agent. Days with no completions are
     * simply absent — the caller zero-fills (`TrendSeries::zeroFilled`).
     *
     * @return BaseCollection<int, array{day: string, count: int}>
     */
    public function ticketsCompletedPerDay(int $agentId, int $days): BaseCollection;

    /**
     * Tickets assigned to the agent per day (by `assigned_at`) between
     * `$from` and `$to`, grouped by calendar day in `$from`'s timezone.
     * Days with nothing are absent — the caller zero-fills.
     *
     * @return BaseCollection<string, int> keyed by `Y-m-d`
     */
    public function assignedPerDay(int $agentId, CarbonImmutable $from, CarbonImmutable $to): BaseCollection;

    /**
     * Same shape as assignedPerDay(), for tickets resolved/closed (by
     * `resolved_at`).
     *
     * @return BaseCollection<string, int> keyed by `Y-m-d`
     */
    public function completedPerDay(int $agentId, CarbonImmutable $from, CarbonImmutable $to): BaseCollection;
}
