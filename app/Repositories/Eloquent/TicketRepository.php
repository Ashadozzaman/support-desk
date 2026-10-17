<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\TicketRepositoryInterface;
use App\DTOs\CreateTicketData;
use App\DTOs\UpdateTicketData;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class TicketRepository implements TicketRepositoryInterface
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
            'sla_due_at' => $slaDueAt,
            'sla_warning_at' => $slaWarningAt,
        ]);
    }

    public function findByNumberOrFail(string $ticketNumber): Ticket
    {
        return Ticket::where('ticket_number', $ticketNumber)->firstOrFail();
    }

    public function paginateFor(User $viewer, string $tab): LengthAwarePaginator
    {
        return Ticket::query()
            ->visibleTo($viewer)
            ->when($tab === 'resolved', fn ($query) => $query->resolved(), fn ($query) => $query->active())
            ->with([
                'category:id,name',
                'assignedAgent:id,name',
                'creator:id,name',
            ])
            ->select([
                'id', 'ticket_number', 'subject', 'status', 'priority', 'category_id',
                'assigned_agent_id', 'created_by', 'sla_due_at', 'sla_warning_at', 'created_at',
            ])
            ->latest()
            ->paginate(15)
            ->withQueryString();
    }

    public function countActiveForAgent(int $agentId): int
    {
        return Ticket::query()->where('assigned_agent_id', $agentId)->active()->count();
    }

    public function update(Ticket $ticket, UpdateTicketData $data): Ticket
    {
        $ticket->update([
            'subject' => $data->subject,
            'description' => $data->description,
            'category_id' => $data->categoryId,
            'priority' => $data->priority,
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

        $ticket->update([
            'status' => $status,
            'resolved_at' => $resolvedAt,
        ]);

        return $ticket;
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
