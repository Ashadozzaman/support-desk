<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ReplyReceived;
use App\Notifications\SlaBreached;
use App\Notifications\SlaWarning;
use App\Notifications\TicketAssigned;
use App\Notifications\TicketCreated;
use App\Notifications\TicketResolved;
use App\Services\TicketAssignmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

// QUEUE_CONNECTION=sync in testing, so queued listeners run inline as part
// of the event dispatch — no queue faking/replay needed, only
// Notification::fake() to intercept the actual send.
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeAgent(): User
{
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    AgentProfile::factory()->create(['user_id' => $agent->id]);

    return $agent;
}

test('creating a ticket notifies admins and the customer', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $category = Category::factory()->create();

    $this->actingAs($customer)->post(route('tickets.store'), [
        'subject' => 'Cannot log in',
        'description' => 'Password reset link never arrives.',
        'priority' => 'medium',
        'category_id' => $category->id,
    ])->assertRedirect();

    Notification::assertSentTo($admin, TicketCreated::class);
    Notification::assertSentTo($customer, TicketCreated::class);
});

test('assigning a ticket notifies the agent and the customer', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $agent = makeAgent();
    $customer = User::factory()->create();
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Open->value,
        'assigned_agent_id' => null,
        'created_by' => $customer->id,
    ]);

    app(TicketAssignmentService::class)->assign($ticket, $agent->id, $admin);

    Notification::assertSentTo($agent, TicketAssigned::class);
    Notification::assertSentTo($customer, TicketAssigned::class);
});

test('a staff reply notifies the customer only', function () {
    Notification::fake();

    $agent = makeAgent();
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id, 'created_by' => $customer->id]);

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Looking into it.'])
        ->assertRedirect();

    Notification::assertSentTo($customer, ReplyReceived::class);
    Notification::assertNotSentTo($agent, ReplyReceived::class);
});

test('a customer reply notifies the assigned agent', function () {
    Notification::fake();

    $agent = makeAgent();
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id, 'created_by' => $customer->id]);

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Still broken.'])
        ->assertRedirect();

    Notification::assertSentTo($agent, ReplyReceived::class);
});

test('a customer reply on an unassigned ticket notifies admins', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => null, 'created_by' => $customer->id]);

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Anyone there?'])
        ->assertRedirect();

    Notification::assertSentTo($admin, ReplyReceived::class);
});

test('an internal note notifies other staff but never the customer', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $agent = makeAgent();
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id, 'created_by' => $customer->id]);

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket->ticket_number), [
            'body' => 'Escalating internally.',
            'type' => 'internal_note',
        ])
        ->assertRedirect();

    Notification::assertSentTo($admin, ReplyReceived::class);
    Notification::assertNotSentTo($agent, ReplyReceived::class);
    Notification::assertNotSentTo($customer, ReplyReceived::class);
});

test('resolving a ticket notifies the customer', function () {
    Notification::fake();

    $agent = makeAgent();
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress->value,
        'assigned_agent_id' => $agent->id,
        'created_by' => $customer->id,
    ]);

    $this->actingAs($agent)
        ->patch(route('tickets.change-status', $ticket->ticket_number), ['status' => 'resolved'])
        ->assertRedirect();

    Notification::assertSentTo($customer, TicketResolved::class);
});

test('an SLA warning notifies the assignee and admins', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $agent = makeAgent();
    Ticket::factory()->create([
        'assigned_agent_id' => $agent->id,
        'sla_warning_at' => now()->subMinute(),
        'sla_due_at' => now()->addHour(),
    ]);

    $this->artisan('sla:scan');

    Notification::assertSentTo($agent, SlaWarning::class);
    Notification::assertSentTo($admin, SlaWarning::class);
});

test('an SLA breach notifies the assignee and admins', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $agent = makeAgent();
    Ticket::factory()->create([
        'assigned_agent_id' => $agent->id,
        'sla_warning_at' => now()->subHour(),
        'sla_due_at' => now()->subMinute(),
    ]);

    $this->artisan('sla:scan');

    Notification::assertSentTo($agent, SlaBreached::class);
    Notification::assertSentTo($admin, SlaBreached::class);
});
