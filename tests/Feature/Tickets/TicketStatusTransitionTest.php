<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
