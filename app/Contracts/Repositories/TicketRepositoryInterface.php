<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTOs\CreateTicketData;
use App\DTOs\UpdateTicketData;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

interface TicketRepositoryInterface
{
    public function create(CreateTicketData $data, ?CarbonImmutable $slaDueAt, ?CarbonImmutable $slaWarningAt): Ticket;

    public function findByNumberOrFail(string $ticketNumber): Ticket;

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginateFor(User $viewer, string $tab): LengthAwarePaginator;

    public function countActiveForAgent(int $agentId): int;

    public function update(Ticket $ticket, UpdateTicketData $data): Ticket;

    public function updateStatus(Ticket $ticket, TicketStatus $status): Ticket;

    /**
     * Updates last_reply_at (every reply) and first_response_at (the first
     * staff public reply only, left untouched once set).
     */
    public function recordReply(Ticket $ticket, bool $isStaffReply): Ticket;

    /**
     * Atomic conditional UPDATE (ARCHITECTURE.md §7): claims the ticket only
     * if it is still open and unassigned. Returns true iff exactly one row
     * was affected — false means another agent won the race first.
     */
    public function claimIfUnassigned(Ticket $ticket, int $agentId): bool;

    /**
     * Manual/auto assignment (admin assign, reassign, auto-assign). Moves
     * an Open ticket to InProgress; leaves the status alone otherwise (e.g.
     * reassigning a ticket that is already InProgress).
     */
    public function assign(Ticket $ticket, int $agentId): Ticket;
}
