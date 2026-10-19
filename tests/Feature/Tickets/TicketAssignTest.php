<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAssignmentService;
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

test('the assign dialog lists each agent with their active ticket count and capacity', function () use ($makeAgent) {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $busy = $makeAgent();
    $busy->agentProfile->update(['max_active_tickets' => 5]);
    $idle = $makeAgent();
    Ticket::factory()->count(2)->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $busy->id]);
    // Resolved tickets are not current load.
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'assigned_agent_id' => $busy->id]);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $agents = collect(
        $this->actingAs($admin)->get(route('tickets.show', $ticket->ticket_number))->assertOk()
            ->viewData('page')['props']['agents']['data']
    );

    expect($agents->firstWhere('id', $busy->id))->toMatchArray([
        'active_tickets_count' => 2,
        'max_active_tickets' => 5,
        'is_available' => true,
    ]);
    expect($agents->firstWhere('id', $idle->id)['active_tickets_count'])->toBe(0);
});

test('an agent without tickets.assign gets no assign options', function () use ($makeAgent) {
    $agent = $makeAgent();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $props = $this->actingAs($agent)->get(route('tickets.show', $ticket->ticket_number))->assertOk()
        ->viewData('page')['props'];

    expect($props['agents']['data'])->toBe([]);
});

test('the ticket list gives assign options with workload only to users who can assign', function () use ($makeAgent) {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $agent = $makeAgent();
    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $agent->id]);

    $adminOptions = $this->actingAs($admin)->get(route('tickets.index'))->assertOk()
        ->viewData('page')['props']['assignableAgents']['data'];
    expect(collect($adminOptions)->firstWhere('id', $agent->id)['active_tickets_count'])->toBe(1);

    $agentOptions = $this->actingAs($agent)->get(route('tickets.index'))->assertOk()
        ->viewData('page')['props']['assignableAgents']['data'];
    expect($agentOptions)->toBe([]);
});

test('a manual assign racing a claim records the claiming agent as the previous assignee', function () use ($makeAgent) {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $claimer = $makeAgent();
    $target = $makeAgent();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    // The admin's copy is loaded while the ticket is still unassigned...
    $staleCopy = Ticket::findOrFail($ticket->id);
    // ...then an agent claims it before the admin submits the assign dialog.
    app(TicketAssignmentService::class)->claim(Ticket::findOrFail($ticket->id), $claimer);

    app(TicketAssignmentService::class)->assign($staleCopy, $target->id, $admin);

    $ticket->refresh();
    expect($ticket->assigned_agent_id)->toBe($target->id);
    // claim bumped version to 1; the assign must build on it, not on the stale 0.
    expect($ticket->version)->toBe(2);

    $activity = $ticket->activities()->latest('id')->firstOrFail();
    expect($activity->event)->toBe(ActivityEvent::TicketReassigned);
    expect($activity->old_values)->toBe(['assigned_agent_id' => $claimer->id]);
});
