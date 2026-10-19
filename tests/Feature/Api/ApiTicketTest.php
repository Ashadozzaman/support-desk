<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function apiActor(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    if ($role === RoleEnum::Agent) {
        $user->agentProfile()->create(['is_available' => true, 'max_active_tickets' => 10]);
    }

    return $user;
}

test('the list only contains tickets the caller may see', function () {
    $customer = apiActor(RoleEnum::Customer);
    $mine = Ticket::factory()->create(['created_by' => $customer->id]);
    Ticket::factory()->create();

    Sanctum::actingAs($customer);

    $this->getJson(route('api.v1.tickets.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ticket_number', $mine->ticket_number)
        ->assertJsonPath('meta.total', 1);
});

test('list filters are validated', function () {
    Sanctum::actingAs(apiActor(RoleEnum::Admin));

    $this->getJson(route('api.v1.tickets.index', ['status' => 'nonsense', 'per_page' => 500]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'per_page']);
});

test('a customer can create a ticket and gets 201 with its number', function () {
    $customer = apiActor(RoleEnum::Customer);
    $category = Category::factory()->create();
    Sanctum::actingAs($customer);

    $response = $this->postJson(route('api.v1.tickets.store'), [
        'subject' => 'Printer on fire',
        'description' => 'Smoke everywhere.',
        'priority' => 'high',
        'category_id' => $category->id,
    ])->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.creator.id', $customer->id);

    expect($response->json('data.ticket_number'))->toMatch('/^TKT-\d{4}-\d{6}$/');
});

test('creating a ticket validates the payload', function () {
    Sanctum::actingAs(apiActor(RoleEnum::Customer));

    $this->postJson(route('api.v1.tickets.store'), ['priority' => 'urgent-ish'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['subject', 'description', 'priority']);
});

test('another customer\'s ticket is forbidden', function () {
    $ticket = Ticket::factory()->create();
    Sanctum::actingAs(apiActor(RoleEnum::Customer));

    $this->getJson(route('api.v1.tickets.show', $ticket->ticket_number))->assertForbidden();
    $this->getJson(route('api.v1.tickets.replies.index', $ticket->ticket_number))->assertForbidden();
});

test('an assigned agent can update a ticket and change its status', function () {
    $agent = apiActor(RoleEnum::Agent);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id, 'status' => TicketStatus::InProgress->value]);
    Sanctum::actingAs($agent);

    $this->putJson(route('api.v1.tickets.update', $ticket->ticket_number), [
        'subject' => 'New subject',
        'description' => $ticket->description,
        'priority' => 'critical',
        'category_id' => $ticket->category_id,
    ])->assertOk()->assertJsonPath('data.subject', 'New subject')->assertJsonPath('data.priority', 'critical');

    $this->patchJson(route('api.v1.tickets.change-status', $ticket->ticket_number), ['status' => 'resolved'])
        ->assertOk()->assertJsonPath('data.status', 'resolved');
});

test('an invalid status transition returns 422', function () {
    $admin = apiActor(RoleEnum::Admin);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed->value]);
    Sanctum::actingAs($admin);

    $this->patchJson(route('api.v1.tickets.change-status', $ticket->ticket_number), ['status' => 'open'])
        ->assertUnprocessable();

    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed);
});

test('an agent can claim an unassigned ticket; another agent then gets 403', function () {
    $first = apiActor(RoleEnum::Agent);
    $second = apiActor(RoleEnum::Agent);
    $ticket = Ticket::factory()->create();

    Sanctum::actingAs($first);
    $this->postJson(route('api.v1.tickets.claim', $ticket->ticket_number))
        ->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $first->id)
        ->assertJsonPath('data.status', 'in_progress');

    // The ticket is now assigned to someone else, so the policy refuses
    // before the atomic UPDATE runs — 403. The 409 path (lost race, both
    // passed the policy) is exercised directly against the service:
    Sanctum::actingAs($second);
    $this->postJson(route('api.v1.tickets.claim', $ticket->ticket_number))->assertForbidden();

    expect($ticket->fresh()->assigned_agent_id)->toBe($first->id);
});

test('a claim that loses the race returns 409 JSON', function () {
    $agent = apiActor(RoleEnum::Agent);
    $rival = apiActor(RoleEnum::Agent);
    $ticket = Ticket::factory()->create();

    // Route model binding loads the still-unassigned row (so the policy
    // allows the claim); the rival's claim lands just before the UPDATE.
    Ticket::retrieved(function (Ticket $loaded) use ($ticket, $rival) {
        if ($loaded->id === $ticket->id && $loaded->assigned_agent_id === null) {
            Ticket::flushEventListeners();
            Ticket::query()->whereKey($ticket->id)->update([
                'assigned_agent_id' => $rival->id,
                'status' => TicketStatus::InProgress->value,
            ]);
        }
    });

    Sanctum::actingAs($agent);
    $this->postJson(route('api.v1.tickets.claim', $ticket->ticket_number))
        ->assertConflict()
        ->assertJsonStructure(['message']);

    expect($ticket->fresh()->assigned_agent_id)->toBe($rival->id);
});

test('only a user with tickets.assign can assign', function () {
    $agent = apiActor(RoleEnum::Agent);
    $ticket = Ticket::factory()->create();

    Sanctum::actingAs($agent);
    $this->postJson(route('api.v1.tickets.assign', $ticket->ticket_number), ['agent_id' => $agent->id])
        ->assertForbidden();

    Sanctum::actingAs(apiActor(RoleEnum::Admin));
    $this->postJson(route('api.v1.tickets.assign', $ticket->ticket_number), ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $agent->id);
});

test('an unknown ticket number is 404 JSON', function () {
    Sanctum::actingAs(apiActor(RoleEnum::Admin));

    $this->getJson(route('api.v1.tickets.show', 'TKT-1999-000001'))->assertNotFound();
});
