<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

$makeAgent = function (): User {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    AgentProfile::factory()->create(['user_id' => $agent->id]);

    return $agent;
};

test('an admin can assign an unassigned ticket to an agent', function () use ($makeAgent) {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $agent = $makeAgent();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $this->actingAs($admin)
        ->post(route('tickets.assign', $ticket->ticket_number), ['agent_id' => $agent->id])
        ->assertRedirect(route('tickets.show', $ticket->ticket_number));

    $ticket->refresh();
    expect($ticket->assigned_agent_id)->toBe($agent->id);
    expect($ticket->status)->toBe(TicketStatus::InProgress);
    expect($ticket->activities()->where('event', ActivityEvent::TicketAssigned->value)->exists())->toBeTrue();
});

test('an admin can reassign an already-assigned ticket and the activity records a reassignment', function () use ($makeAgent) {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $original = $makeAgent();
    $next = $makeAgent();
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress->value,
        'assigned_agent_id' => $original->id,
    ]);

    $this->actingAs($admin)
        ->post(route('tickets.assign', $ticket->ticket_number), ['agent_id' => $next->id])
        ->assertRedirect(route('tickets.show', $ticket->ticket_number));

    expect($ticket->refresh()->assigned_agent_id)->toBe($next->id);
    expect($ticket->activities()->where('event', ActivityEvent::TicketReassigned->value)->exists())->toBeTrue();
});

test('an agent cannot manually assign a ticket', function () use ($makeAgent) {
    $agent = $makeAgent();
    $target = $makeAgent();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $this->actingAs($agent)
        ->post(route('tickets.assign', $ticket->ticket_number), ['agent_id' => $target->id])
        ->assertForbidden();

    expect($ticket->refresh()->assigned_agent_id)->toBeNull();
});

test('assigning to a user without an agent profile is rejected', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $this->actingAs($admin)
        ->post(route('tickets.assign', $ticket->ticket_number), ['agent_id' => $customer->id])
        ->assertInvalid(['agent_id']);
});

test('assigning to a deactivated agent is rejected', function () use ($makeAgent) {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $agent = $makeAgent();
    $agent->update(['is_active' => false]);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $this->actingAs($admin)
        ->post(route('tickets.assign', $ticket->ticket_number), ['agent_id' => $agent->id])
        ->assertInvalid(['agent_id']);
});
