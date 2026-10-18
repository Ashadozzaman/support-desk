<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardRepository implements DashboardRepositoryInterface
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

    public function summary(?int $agentId = null, ?int $customerId = null): array
    {
        $active = $this->quotedList(self::ACTIVE_STATUSES);
        $resolved = $this->quotedList(self::RESOLVED_STATUSES);

        // `critical` only means something against the whole active queue,
        // not a single customer's own tickets.
        $critical = $customerId === null
            ? "SUM(priority = '".TicketPriority::Critical->value."' AND status IN ({$active})) AS critical"
            : 'NULL AS critical';

        // Bind PHP's (UTC) now() rather than SQL NOW(), which runs in the
        // DB connection's own session timezone — using NOW() here would
        // silently disagree with every other SLA comparison in the app
        // (e.g. Ticket::scopeSlaState), which all compare against now().
        $now = now();

        $row = Ticket::query()
            ->when($agentId !== null, fn (Builder $q) => $q->where('assigned_agent_id', $agentId))
            ->when($customerId !== null, fn (Builder $q) => $q->where('created_by', $customerId))
            ->toBase()
            ->selectRaw(<<<SQL
                SUM(status IN ({$active})) AS open_total,
                {$critical},
                SUM(status IN ({$active}) AND sla_due_at IS NOT NULL AND sla_due_at <= ?) AS sla_breached,
                SUM(status IN ({$active}) AND sla_warning_at IS NOT NULL AND sla_warning_at <= ? AND sla_due_at > ?) AS sla_approaching,
                SUM(status IN ({$resolved})) AS resolved
            SQL, [$now, $now, $now])
            ->first();

        return [
            'open_total' => (int) $row->open_total,
            'critical' => $row->critical === null ? null : (int) $row->critical,
            'sla_breached' => (int) $row->sla_breached,
            'sla_approaching' => (int) $row->sla_approaching,
            'resolved' => (int) $row->resolved,
        ];
    }

    public function unassignedOpenCount(): int
    {
        return Ticket::query()
            ->whereNull('assigned_agent_id')
            ->where('status', TicketStatus::Open->value)
            ->count();
    }

    public function byPriority(): Collection
    {
        return Ticket::query()
            ->active()
            ->toBase()
            ->selectRaw('priority, COUNT(*) AS count')
            ->groupBy('priority')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['priority' => $row->priority, 'count' => (int) $row->count]);
    }

    public function byAgent(): Collection
    {
        return Ticket::query()
            ->active()
            ->toBase()
            ->leftJoin('users', 'users.id', '=', 'tickets.assigned_agent_id')
            ->leftJoin('agent_profiles', 'agent_profiles.user_id', '=', 'tickets.assigned_agent_id')
            ->selectRaw(<<<'SQL'
                tickets.assigned_agent_id AS agent_id,
                users.name AS agent_name,
                agent_profiles.max_active_tickets AS max_active_tickets,
                agent_profiles.is_available AS is_available,
                COUNT(*) AS count
            SQL)
            ->groupBy('tickets.assigned_agent_id', 'users.name', 'agent_profiles.max_active_tickets', 'agent_profiles.is_available')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'agent' => $row->agent_id === null ? null : ['id' => (int) $row->agent_id, 'name' => $row->agent_name],
                'count' => (int) $row->count,
                'max_active_tickets' => $row->max_active_tickets === null ? null : (int) $row->max_active_tickets,
                'is_available' => $row->is_available === null ? null : (bool) $row->is_available,
            ]);
    }

    public function approachingSla(?int $agentId = null, ?int $customerId = null, int $limit = 10): Collection
    {
        return Ticket::query()
            // Reuses the same predicate as Ticket::scopeSlaState(Approaching)
            // (ARCHITECTURE.md §8.3) so this widget can never drift from the
            // documented SLA-state definition — a prior hand-rolled version
            // of this filter omitted the sla_due_at > now() half and ended
            // up including already-breached tickets, which also defeated
            // the (status, sla_due_at) index (full table scan + filesort).
            ->slaState(SlaState::Approaching)
            ->when($agentId !== null, fn (Builder $q) => $q->where('assigned_agent_id', $agentId))
            ->when($customerId !== null, fn (Builder $q) => $q->where('created_by', $customerId))
            ->with(['category:id,name', 'assignedAgent:id,name', 'creator:id,name'])
            ->select([
                'id', 'ticket_number', 'subject', 'status', 'priority', 'category_id',
                'assigned_agent_id', 'created_by', 'sla_due_at', 'sla_warning_at', 'sla_met', 'created_at',
            ])
            ->orderBy('sla_due_at')
            ->limit($limit)
            ->get();
    }

    public function ticketsCreatedPerDay(int $days, ?int $agentId = null, ?int $customerId = null): Collection
    {
        $from = now()->subDays($days - 1)->startOfDay();

        return Ticket::query()
            ->when($agentId !== null, fn (Builder $q) => $q->where('assigned_agent_id', $agentId))
            ->when($customerId !== null, fn (Builder $q) => $q->where('created_by', $customerId))
            ->where('created_at', '>=', $from)
            ->toBase()
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['day' => (string) $row->day, 'count' => (int) $row->count]);
    }

    /**
     * Values are our own backed-enum strings, never request input, so
     * inlining them into the raw SQL below is safe.
     *
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(',', array_map(fn (string $v) => "'{$v}'", $values));
    }
}
