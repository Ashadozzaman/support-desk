<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAssignmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('an agent can claim an unassigned open ticket', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $this->actingAs($agent)
        ->post(route('tickets.claim', $ticket->ticket_number))
        ->assertRedirect(route('tickets.show', $ticket->ticket_number));

    $ticket->refresh();
    expect($ticket->assigned_agent_id)->toBe($agent->id);
    expect($ticket->status)->toBe(TicketStatus::InProgress);
    expect($ticket->activities()->where('event', ActivityEvent::TicketAssigned->value)->exists())->toBeTrue();
});

test('a customer cannot claim a ticket', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $this->actingAs($customer)
        ->post(route('tickets.claim', $ticket->ticket_number))
        ->assertForbidden();

    expect($ticket->refresh()->assigned_agent_id)->toBeNull();
});

test('an agent cannot claim a ticket that is already assigned', function () {
    $owner = User::factory()->create();
    $owner->assignRole(RoleEnum::Agent->value);
    $other = User::factory()->create();
    $other->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress->value,
        'assigned_agent_id' => $owner->id,
    ]);

    $this->actingAs($other)
        ->post(route('tickets.claim', $ticket->ticket_number))
        ->assertForbidden();
});

test('a second claim attempt throws once the first has committed, even against a stale in-memory copy', function () {
    // Sequential double-claim at the SERVICE layer (ARCHITECTURE.md §7): two
    // model instances both loaded while the ticket was still unassigned --
    // exactly what two near-simultaneous HTTP requests would each see before
    // either one writes. The real two-connection race is covered separately
    // in tests/Concurrency; this proves the atomic UPDATE's WHERE clause
    // re-checks the database, not the PHP object, on the second call.
    $first = User::factory()->create();
    $first->assignRole(RoleEnum::Agent->value);
    $second = User::factory()->create();
    $second->assignRole(RoleEnum::Agent->value);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $ticketSeenByFirst = Ticket::find($ticket->id);
    $ticketSeenBySecond = Ticket::find($ticket->id);

    app(TicketAssignmentService::class)->claim($ticketSeenByFirst, $first);

    expect(fn () => app(TicketAssignmentService::class)->claim($ticketSeenBySecond, $second))
        ->toThrow(TicketAlreadyAssignedException::class);

    expect($ticket->refresh()->assigned_agent_id)->toBe($first->id);
    expect($ticket->activities()->where('event', ActivityEvent::TicketAssigned->value)->count())->toBe(1);
});
