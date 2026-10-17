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
        $ticket->update([
            'status' => $status,
            'resolved_at' => $status === TicketStatus::Resolved ? now() : $ticket->resolved_at,
        ]);

        return $ticket;
    }
}
