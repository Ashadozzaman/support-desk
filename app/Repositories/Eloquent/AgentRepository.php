<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AgentRepositoryInterface;
use App\DTOs\AgentPerformanceFilterData;
use App\Enums\TicketStatus;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class AgentRepository implements AgentRepositoryInterface
{
    private const ACTIVE_STATUSES = [
        TicketStatus::Open->value,
        TicketStatus::InProgress->value,
        TicketStatus::WaitingForCustomer->value,
    ];

    private const RESOLVED_STATUSES = [
        TicketStatus::Resolved->value,
        TicketStatus::Closed->value,
    ];

    public function candidatesFor(?int $categoryId): Collection
    {
        return $this->underCapacity(
            $this->availableWithActiveCount()
                ->with('user:id,name')
                ->when($categoryId !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($categoryId) {
                    $inner->whereHas('user.categories', fn (Builder $q) => $q->whereKey($categoryId))
                        ->orWhereDoesntHave('user.categories');
                }))
                ->get(),
        );
    }

    public function withFreeCapacity(): Collection
    {
        return $this->underCapacity(
            $this->availableWithActiveCount()->with('user.categories:id')->get(),
        );
    }

    /**
     * @return Builder<AgentProfile>
     */
    private function availableWithActiveCount(): Builder
    {
        return AgentProfile::query()
            ->select('agent_profiles.*')
            ->addSelect([
                // Correlated subquery: one query, no N+1 per candidate.
                'active_count' => Ticket::query()
                    ->selectRaw('count(*)')
                    ->active()
                    ->whereColumn('tickets.assigned_agent_id', 'agent_profiles.user_id'),
            ])
            ->where('is_available', true)
            // A deactivated user keeps their profile (history, performance
            // page) but must never be picked.
            ->whereHas('user', fn (Builder $query) => $query->where('is_active', true));
    }

    /**
     * @param  Collection<int, AgentProfile>  $profiles
     * @return Collection<int, AgentProfile>
     */
    private function underCapacity(Collection $profiles): Collection
    {
        return $profiles
            ->filter(fn (AgentProfile $profile) => (int) $profile->active_count < $profile->max_active_tickets)
            ->values();
    }

    public function lockForAssignment(int $agentProfileId): ?AgentProfile
    {
        // MySQL 8 SKIP LOCKED: Laravel's query builder has no skipLocked()
        // helper, so the raw lock clause is passed directly to lock().
        return AgentProfile::query()
            ->whereKey($agentProfileId)
            ->lock('for update skip locked')
            ->first();
    }

    public function touchLastAssigned(AgentProfile $profile): void
    {
        $profile->update(['last_assigned_at' => now()]);
    }

    public function saveProfile(int $userId, bool $isAvailable, int $maxActiveTickets): AgentProfile
    {
        return AgentProfile::updateOrCreate(
            ['user_id' => $userId],
            ['is_available' => $isAvailable, 'max_active_tickets' => $maxActiveTickets],
        );
    }

    public function markUnavailable(int $userId): void
    {
        AgentProfile::query()->where('user_id', $userId)->update(['is_available' => false]);
    }

    public function syncCategories(int $userId, array $categoryIds): void
    {
        User::query()->findOrFail($userId)->categories()->sync($categoryIds);
    }

    public function paginatePerformance(AgentPerformanceFilterData $filters, int $perPage): LengthAwarePaginator
    {
        // Page the agents first (cheap: ~tens of rows), then aggregate
        // tickets for just this page's agents. The old version left-joined
        // every agent to every ticket and conditionally SUMmed all ~1M rows
        // (~20s); filtering in WHERE instead lets both queries below seek
        // straight to their slice of the covering
        // (assigned_agent_id, status, resolved_at, assigned_at) index.
        $page = AgentProfile::query()
            ->toBase()
            ->join('users', 'users.id', '=', 'agent_profiles.user_id')
            ->select([
                'agent_profiles.user_id AS agent_id',
                'users.name AS agent_name',
                'agent_profiles.max_active_tickets',
                'agent_profiles.is_available',
            ])
            ->orderBy('users.name')
            ->orderBy('agent_profiles.user_id')
            ->paginate($perPage)
            ->withQueryString();

        $agentIds = collect($page->items())->pluck('agent_id')->all();

        if ($agentIds === []) {
            return $page;
        }

        // Live workload: only active rows are read, never the (much larger)
        // resolved/closed history.
        $active = Ticket::query()
            ->toBase()
            ->whereIn('assigned_agent_id', $agentIds)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->selectRaw('assigned_agent_id, status, COUNT(*) AS count, SUM(assigned_at <= ?) AS stuck', [
                now()->subDays((int) config('support.dashboard.stuck_after_days')),
            ])
            ->groupBy('assigned_agent_id', 'status')
            ->get()
            ->groupBy('assigned_agent_id');

        // Completed in the picked window: (agent, status) equality + a
        // resolved_at range — an index range scan, no row lookups.
        $completed = Ticket::query()
            ->toBase()
            ->whereIn('assigned_agent_id', $agentIds)
            ->whereIn('status', self::RESOLVED_STATUSES)
            ->whereBetween('resolved_at', [$filters->completedFrom, $filters->completedTo])
            ->selectRaw('assigned_agent_id, COUNT(*) AS count')
            ->groupBy('assigned_agent_id')
            ->pluck('count', 'assigned_agent_id');

        return $page->through(function ($row) use ($active, $completed) {
            $byStatus = $active->get($row->agent_id, collect())->keyBy('status');
            $count = fn (TicketStatus $status) => (int) ($byStatus->get($status->value)?->count ?? 0);

            return [
                'agent' => ['id' => (int) $row->agent_id, 'name' => $row->agent_name],
                'active_count' => (int) $byStatus->sum('count'),
                'open_count' => $count(TicketStatus::Open),
                'in_progress_count' => $count(TicketStatus::InProgress),
                'waiting_count' => $count(TicketStatus::WaitingForCustomer),
                'stuck_count' => (int) $byStatus->sum('stuck'),
                'completed_count' => (int) ($completed->get($row->agent_id) ?? 0),
                'max_active_tickets' => (int) $row->max_active_tickets,
                'is_available' => (bool) $row->is_available,
            ];
        });
    }

    public function statusBreakdown(int $agentId): array
    {
        $row = Ticket::query()
            ->where('assigned_agent_id', $agentId)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'open' => (int) ($row->get(TicketStatus::Open->value)?->count ?? 0),
            'in_progress' => (int) ($row->get(TicketStatus::InProgress->value)?->count ?? 0),
            'waiting_for_customer' => (int) ($row->get(TicketStatus::WaitingForCustomer->value)?->count ?? 0),
            'resolved' => (int) ($row->get(TicketStatus::Resolved->value)?->count ?? 0),
            'closed' => (int) ($row->get(TicketStatus::Closed->value)?->count ?? 0),
        ];
    }

    public function ticketsCompletedPerDay(int $agentId, int $days): BaseCollection
    {
        $from = now()->subDays($days - 1)->startOfDay();

        return Ticket::query()
            ->where('assigned_agent_id', $agentId)
            ->whereIn('status', self::RESOLVED_STATUSES)
            ->where('resolved_at', '>=', $from)
            ->toBase()
            ->selectRaw('DATE(resolved_at) AS day, COUNT(*) AS count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['day' => (string) $row->day, 'count' => (int) $row->count]);
    }

    public function assignedPerDay(int $agentId, CarbonImmutable $from, CarbonImmutable $to): BaseCollection
    {
        // Only agent equality is a seekable prefix here (assigned_at is the
        // index's 4th column, behind status/resolved_at), so this reads the
        // agent's whole index slice — still index-only, ~30k entries for the
        // busiest seeded agent, measured ~0.04s.
        return $this->countPerDay(
            Ticket::query()->where('assigned_agent_id', $agentId),
            'assigned_at',
            $from,
            $to,
        );
    }

    public function completedPerDay(int $agentId, CarbonImmutable $from, CarbonImmutable $to): BaseCollection
    {
        return $this->countPerDay(
            Ticket::query()->where('assigned_agent_id', $agentId)->whereIn('status', self::RESOLVED_STATUSES),
            'resolved_at',
            $from,
            $to,
        );
    }

    /**
     * Groups by calendar day in `$from`'s timezone (the business tz), not
     * UTC — a ticket resolved at 23:30 Dhaka time belongs to that Dhaka
     * day even though it's 17:30 UTC. Uses a fixed UTC offset rather than
     * a named zone so CONVERT_TZ works without MySQL's tz tables loaded;
     * correct for a no-DST zone like Asia/Dhaka (a DST zone would be off
     * by an hour across its switch-over day).
     *
     * @return BaseCollection<string, int> keyed by `Y-m-d`
     */
    private function countPerDay(Builder $query, string $column, CarbonImmutable $from, CarbonImmutable $to): BaseCollection
    {
        $offset = $from->format('P');

        return $query
            ->whereBetween($column, [$from->utc(), $to->utc()])
            ->toBase()
            ->selectRaw("DATE(CONVERT_TZ({$column}, '+00:00', ?)) AS day, COUNT(*) AS count", [$offset])
            ->groupBy('day')
            ->pluck('count', 'day')
            ->map(fn ($count) => (int) $count);
    }
}
