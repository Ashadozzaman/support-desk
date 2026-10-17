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
}
