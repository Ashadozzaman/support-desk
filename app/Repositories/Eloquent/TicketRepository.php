<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\TicketRepositoryInterface;
use App\DTOs\CreateTicketData;
use App\DTOs\TicketFilterData;
use App\DTOs\UpdateTicketData;
use App\Enums\ActivityEvent;
use App\Enums\Permission;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

class TicketRepository implements TicketRepositoryInterface
{
    public function __construct(private readonly TicketNumberGenerator $numbers) {}

    public function create(CreateTicketData $data, ?CarbonImmutable $slaDueAt, ?CarbonImmutable $slaWarningAt): Ticket
    {
        return Ticket::create([
            'ticket_number' => $this->numbers->next(),
            'subject' => $data->subject,
            'description' => $data->description,
            'status' => TicketStatus::Open,
            'priority' => $data->priority,
            'category_id' => $data->categoryId,
            'created_by' => $data->createdBy,
            'external_ref' => $data->externalRef,
            'sla_due_at' => $slaDueAt,
            'sla_warning_at' => $slaWarningAt,
        ]);
    }

    public function findByNumberOrFail(string $ticketNumber): Ticket
    {
        return Ticket::where('ticket_number', $ticketNumber)->firstOrFail();
    }

    /**
     * Top-N-per-branch-then-merge, branched on (status x visibility rule).
     * A single `WHERE status IN (...) ORDER BY created_at DESC LIMIT` (the
     * obvious version of this query) can't use one index for both the
     * multi-value tab filter and the sort once the matching set is a large
     * fraction of the table — verified via EXPLAIN: MySQL falls back to a
     * full table scan + filesort (~2.6-4.9s at ~1M tickets / ~350K in the
     * "active" bucket). The same problem reappears for an agent's queue
     * even after splitting by status alone: `scopeVisibleTo`'s
     * `assigned_agent_id = me OR assigned_agent_id IS NULL` is itself a
     * 2-value condition, and MySQL picks an index that satisfies it but not
     * the `ORDER BY` (~1.5s at ~114K matching rows). Splitting visibility
     * into its own single-equality branches too — mine / unassigned,
     * instead of one OR — lets each (status, visibility) combination use
     * the (status, created_at) index natively (confirmed via EXPLAIN: 2-3ms
     * per branch). Admin (no visibility filter) and customer (a single
     * `created_by = me` equality) already only ever produce one visibility
     * branch, so neither is affected.
     *
     * Merging and re-slicing is exact, not an approximation, for the same
     * reason it was for status alone: if a row were outside its own
     * branch's top-$branchLimit, at least $branchLimit rows in that same
     * branch already outrank it by created_at, so it can't be in the
     * *global* top-$branchLimit either — the global top-N is always
     * contained in the union of every branch's own top-N.
     */
    public function paginateFor(User $viewer, string $tab, TicketFilterData $filters, int $perPage = 15): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $offset = ($page - 1) * $perPage;
        $branchLimit = $offset + $perPage;

        $statuses = $this->tabStatuses($tab);
        $visibilityBranches = $this->visibilityBranches($viewer);
        $branch = fn (string $status, Closure $applyVisibility) => $this->statusBranch($applyVisibility, $status, $filters);

        $tickets = collect($statuses)
            ->flatMap(fn (string $status) => collect($visibilityBranches)->flatMap(
                fn (Closure $applyVisibility) => $branch($status, $applyVisibility)->with([
                    'category:id,name',
                    'assignedAgent:id,name',
                    'creator:id,name',
                ])->latest()->limit($branchLimit)->get()
            ))
            ->sortByDesc(fn (Ticket $ticket) => $ticket->created_at)
            ->values()
            ->slice($offset, $perPage)
            ->values();

        $total = collect($statuses)->sum(fn (string $status) => collect($visibilityBranches)->sum(
            fn (Closure $applyVisibility) => $branch($status, $applyVisibility)->count()
        ));

        return (new LengthAwarePaginator($tickets, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]))->withQueryString();
    }

    /**
     * @return list<string>
     */
    private function tabStatuses(string $tab): array
    {
        return $tab === 'resolved'
            ? [TicketStatus::Resolved->value, TicketStatus::Closed->value]
            : [TicketStatus::Open->value, TicketStatus::InProgress->value, TicketStatus::WaitingForCustomer->value];
    }

    /**
     * Mirrors Ticket::scopeVisibleTo's permission matrix (ARCHITECTURE.md
     * §3) as separate single-condition closures instead of one OR'd scope,
     * so paginateFor() can query (and merge) each combination independently
     * — see that method's docblock for why. Keep in sync with
     * scopeVisibleTo if the permission matrix ever changes.
     *
     * @return list<Closure(Builder<Ticket>): Builder<Ticket>>
     */
    private function visibilityBranches(User $viewer): array
    {
        if ($viewer->can(Permission::TicketsViewAny->value)) {
            return [fn (Builder $query) => $query];
        }

        if ($viewer->can(Permission::TicketsViewQueue->value)) {
            return [
                fn (Builder $query) => $query->where('assigned_agent_id', $viewer->id),
                fn (Builder $query) => $query->whereNull('assigned_agent_id'),
            ];
        }

        return [fn (Builder $query) => $query->where('created_by', $viewer->id)];
    }

    /**
     * @return Builder<Ticket>
     */
    private function statusBranch(Closure $applyVisibility, string $status, TicketFilterData $filters): Builder
    {
        return $applyVisibility(Ticket::query())
            ->where('status', $status)
            ->filter($filters)
            ->select([
                'id', 'ticket_number', 'subject', 'status', 'priority', 'category_id',
                'assigned_agent_id', 'created_by', 'sla_due_at', 'sla_warning_at', 'sla_met', 'created_at',
            ]);
    }

    public function countActiveForAgent(int $agentId): int
    {
        return Ticket::query()->where('assigned_agent_id', $agentId)->active()->count();
    }

    public function update(Ticket $ticket, UpdateTicketData $data, ?CarbonImmutable $slaDueAt, ?CarbonImmutable $slaWarningAt): Ticket
    {
        $ticket->update([
            'subject' => $data->subject,
            'description' => $data->description,
            'category_id' => $data->categoryId,
            'priority' => $data->priority,
            'sla_due_at' => $slaDueAt,
            'sla_warning_at' => $slaWarningAt,
        ]);

        return $ticket;
    }

    public function updateStatus(Ticket $ticket, TicketStatus $status): Ticket
    {
        // Closed always comes from Resolved, so resolved_at is preserved;
        // any other target (e.g. a customer reply reopening the ticket)
        // clears it since the ticket is no longer considered resolved.
        $resolvedAt = match ($status) {
            TicketStatus::Resolved => now(),
            TicketStatus::Closed => $ticket->resolved_at,
            default => null,
        };

        $attributes = ['status' => $status, 'resolved_at' => $resolvedAt];

        if ($resolvedAt === null) {
            $attributes['sla_met'] = null;
            $attributes['resolution_minutes'] = null;
        }

        $ticket->update($attributes);

        return $ticket;
    }

    public function recordResolutionMetrics(Ticket $ticket, ?bool $slaMet, int $resolutionMinutes): Ticket
    {
        $ticket->update(['sla_met' => $slaMet, 'resolution_minutes' => $resolutionMinutes]);

        return $ticket;
    }

    public function recordFirstResponseMinutes(Ticket $ticket, int $minutes): Ticket
    {
        $ticket->update(['first_response_minutes' => $minutes]);

        return $ticket;
    }

    public function incrementReopenedCount(Ticket $ticket): Ticket
    {
        $ticket->increment('reopened_count');

        return $ticket;
    }

    public function chunkMissingMetrics(int $size, callable $callback): void
    {
        Ticket::query()
            ->select(['id', 'created_at', 'first_response_at', 'first_response_minutes', 'resolved_at', 'resolution_minutes', 'sla_due_at', 'sla_met'])
            ->where(function (Builder $query) {
                $query->where(fn (Builder $q) => $q->whereNotNull('first_response_at')->whereNull('first_response_minutes'))
                    ->orWhere(fn (Builder $q) => $q->whereNotNull('resolved_at')->whereNull('resolution_minutes'))
                    ->orWhere(fn (Builder $q) => $q->whereNotNull('resolved_at')->whereNotNull('sla_due_at')->whereNull('sla_met'));
            })
            ->chunkById($size, $callback);
    }

    public function updateMetricsById(int $ticketId, ?int $firstResponseMinutes, ?int $resolutionMinutes, ?bool $slaMet): void
    {
        Ticket::query()->whereKey($ticketId)->update([
            'first_response_minutes' => $firstResponseMinutes,
            'resolution_minutes' => $resolutionMinutes,
            'sla_met' => $slaMet,
        ]);
    }

    public function recountReopensFromActivity(): int
    {
        // One UPDATE … JOIN over a grouped count, so the result is the same
        // however many times it runs (SET =, never +=).
        return DB::update(
            "UPDATE tickets t
             JOIN (
                 SELECT ticket_id, COUNT(*) AS reopens
                 FROM ticket_activities
                 WHERE event = ?
                    OR (event = ? AND old_values->>'$.status' = ? AND new_values->>'$.status' = ?)
                 GROUP BY ticket_id
             ) a ON a.ticket_id = t.id
             SET t.reopened_count = a.reopens
             WHERE t.reopened_count <> a.reopens",
            [
                ActivityEvent::TicketReopened->value,
                ActivityEvent::StatusChanged->value,
                TicketStatus::Resolved->value,
                TicketStatus::Open->value,
            ],
        );
    }

    public function recordReply(Ticket $ticket, bool $isStaffReply): Ticket
    {
        $attributes = ['last_reply_at' => now()];

        if ($isStaffReply && $ticket->first_response_at === null) {
            $attributes['first_response_at'] = now();
        }

        $ticket->update($attributes);

        return $ticket;
    }

    public function claimIfUnassigned(Ticket $ticket, int $agentId): bool
    {
        $affected = Ticket::query()
            ->whereKey($ticket->id)
            ->where('status', TicketStatus::Open->value)
            ->whereNull('assigned_agent_id')
            ->update([
                'assigned_agent_id' => $agentId,
                'assigned_at' => now(),
                'status' => TicketStatus::InProgress->value,
                'version' => DB::raw('version + 1'),
            ]);

        return $affected === 1;
    }

    public function assign(Ticket $ticket, int $agentId): Ticket
    {
        $ticket->update([
            'assigned_agent_id' => $agentId,
            'assigned_at' => now(),
            'status' => $ticket->status === TicketStatus::Open
                ? TicketStatus::InProgress->value
                : $ticket->status->value,
            'version' => $ticket->version + 1,
        ]);

        return $ticket;
    }
}
