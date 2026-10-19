<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Jobs\AutoAssignTicketJob;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function sweepAgent(int $maxActive, array $categoryIds = [], bool $available = true): User
{
    $agent = User::factory()->create();
    AgentProfile::factory()->create([
        'user_id' => $agent->id,
        'max_active_tickets' => $maxActive,
        'is_available' => $available,
    ]);
    $agent->categories()->sync($categoryIds);

    return $agent;
}

function unassignedTickets(int $count, ?int $categoryId = null, string $age = '-5 minutes'): Collection
{
    return Ticket::factory()->count($count)->create([
        'status' => TicketStatus::Open->value,
        'assigned_agent_id' => null,
        'category_id' => $categoryId,
        'created_at' => now()->modify($age),
    ]);
}

test('dispatches nothing when every available agent is at capacity', function () {
    Queue::fake();
    $agent = sweepAgent(maxActive: 2);
    Ticket::factory()->count(2)->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id]);
    unassignedTickets(5);

    $this->artisan('tickets:auto-assign')->assertSuccessful();

    Queue::assertNotPushed(AutoAssignTicketJob::class);
});

test('dispatches nothing when no agent is available', function () {
    Queue::fake();
    sweepAgent(maxActive: 10, available: false);
    unassignedTickets(3);

    $this->artisan('tickets:auto-assign')->assertSuccessful();

    Queue::assertNotPushed(AutoAssignTicketJob::class);
});

test('dispatches at most the free slots, oldest tickets first', function () {
    Queue::fake();
    $agent = sweepAgent(maxActive: 3);
    Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id]);
    $oldest = unassignedTickets(2, age: '-2 hours');
    unassignedTickets(4, age: '-10 minutes');

    $this->artisan('tickets:auto-assign')->assertSuccessful();

    Queue::assertPushed(AutoAssignTicketJob::class, 2);
    $oldest->each(fn (Ticket $t) => Queue::assertPushed(AutoAssignTicketJob::class, fn ($job) => $job->ticketId === $t->id));
});

test('skips tickets created less than a minute ago (their own create-time job covers them)', function () {
    Queue::fake();
    sweepAgent(maxActive: 10);
    unassignedTickets(2, age: '-10 seconds');

    $this->artisan('tickets:auto-assign')->assertSuccessful();

    Queue::assertNotPushed(AutoAssignTicketJob::class);
});

test('only dispatches tickets whose category a free agent can handle', function () {
    // Without this, old tickets in a category nobody with free capacity
    // handles would use up the free slots every minute and starve the rest.
    Queue::fake();
    $billing = Category::factory()->create();
    $network = Category::factory()->create();
    sweepAgent(maxActive: 5, categoryIds: [$network->id]);
    unassignedTickets(3, $billing->id, age: '-2 hours');
    $handled = unassignedTickets(1, $network->id);
    $uncategorised = unassignedTickets(1);

    $this->artisan('tickets:auto-assign')->assertSuccessful();

    Queue::assertPushed(AutoAssignTicketJob::class, 2);
    Queue::assertPushed(AutoAssignTicketJob::class, fn ($job) => $job->ticketId === $handled->first()->id);
    Queue::assertPushed(AutoAssignTicketJob::class, fn ($job) => $job->ticketId === $uncategorised->first()->id);
});
