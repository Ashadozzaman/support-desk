<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Ticket;
use Illuminate\Support\Collection;

interface DashboardRepositoryInterface
{
    /**
     * One conditional-SUM aggregate (ARCHITECTURE.md §14). Pass an agent or
     * customer id to scope to that user's own tickets; pass neither for the
     * global (admin) view. `critical` is meaningless for a customer's own
     * tickets and comes back null rather than a misleading zero.
     *
     * @return array{open_total: int, critical: int|null, sla_breached: int, sla_approaching: int, resolved: int}
     */
    public function summary(?int $agentId = null, ?int $customerId = null): array;

    public function unassignedOpenCount(): int;

    /**
     * @return Collection<int, array{priority: string, count: int}>
     */
    public function byPriority(): Collection;

    /**
     * One pass over the whole `tickets` table (not just active tickets),
     * grouped by `assigned_agent_id` — current load (`active_count` and its
     * open/in_progress/waiting breakdown), a `stuck_count` of active tickets
     * assigned longer ago than `support.dashboard.stuck_after_days`, and
     * recent throughput (`resolved_7d_count`/`resolved_30d_count`) in one
     * query, no per-agent round trip.
     *
     * @return Collection<int, array{
     *     agent: array{id: int, name: string}|null,
     *     active_count: int,
     *     open_count: int,
     *     in_progress_count: int,
     *     waiting_count: int,
     *     stuck_count: int,
     *     resolved_7d_count: int,
     *     resolved_30d_count: int,
     *     max_active_tickets: int|null,
     *     is_available: bool|null,
     * }>
     */
    public function byAgent(): Collection;

    /**
     * Active tickets at or past their SLA warning threshold, soonest due
     * first — scoped the same way as `summary()`.
     *
     * @return Collection<int, Ticket>
     */
    public function approachingSla(?int $agentId = null, ?int $customerId = null, int $limit = 10): Collection;

    /**
     * Ticket count per creation day over the trailing `$days` days, scoped
     * the same way as `summary()`. Days with no tickets are simply absent
     * — the caller fills the gaps.
     *
     * @return Collection<int, array{day: string, count: int}>
     */
    public function ticketsCreatedPerDay(int $days, ?int $agentId = null, ?int $customerId = null): Collection;
}
