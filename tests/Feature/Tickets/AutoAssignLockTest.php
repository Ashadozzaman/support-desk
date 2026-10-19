<?php

declare(strict_types=1);

use App\Contracts\Repositories\TicketRepositoryInterface;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Repositories\Eloquent\TicketRepository;
use App\Services\TicketAssignmentService;
use App\Support\Tickets\TicketNumberGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('auto-assign takes its ticket row lock through the repository, not a query in the service', function () {
    // Records every findForUpdate() call, then defers to the real repository.
    $repository = new class(app(TicketNumberGenerator::class)) extends TicketRepository
    {
        /** @var array<int, int> */
        public array $lockedIds = [];

        public function findForUpdate(int $ticketId): ?Ticket
        {
            $this->lockedIds[] = $ticketId;

            return parent::findForUpdate($ticketId);
        }
    };
    app()->instance(TicketRepositoryInterface::class, $repository);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    app(TicketAssignmentService::class)->autoAssign($ticket);

    expect($repository->lockedIds)->toBe([$ticket->id]);
});
