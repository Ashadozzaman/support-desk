<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a valid status transition succeeds', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value]);

    $this->actingAs($admin)
        ->patch(route('tickets.change-status', $ticket->ticket_number), ['status' => TicketStatus::InProgress->value])
        ->assertRedirect(route('tickets.show', $ticket->ticket_number));

    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);
});

test('an invalid status transition is rejected with 422', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed->value]);

    $this->actingAs($admin)
        ->patch(route('tickets.change-status', $ticket->ticket_number), ['status' => TicketStatus::Open->value])
        ->assertStatus(422);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});

test('a customer cannot change ticket status', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $ticket = Ticket::factory()->create(['created_by' => $customer->id, 'status' => TicketStatus::Open->value]);

    $this->actingAs($customer)
        ->patch(route('tickets.change-status', $ticket->ticket_number), ['status' => TicketStatus::InProgress->value])
        ->assertForbidden();
});

test('the ticket page offers staff exactly the statuses the ticket can move to', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)
        ->get(route('tickets.show', $ticket->ticket_number))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ticket.data.can.change_status', true)
            ->where('ticket.data.allowed_statuses', [TicketStatus::WaitingForCustomer->value, TicketStatus::Resolved->value]));
});

test('someone who may not change status is offered no statuses', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id, 'status' => TicketStatus::Open->value]);

    $this->actingAs($customer)
        ->get(route('tickets.show', $ticket->ticket_number))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ticket.data.can.change_status', false)
            ->where('ticket.data.allowed_statuses', []));
});
