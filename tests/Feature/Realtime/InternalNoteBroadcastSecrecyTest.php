<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Events\InternalNoteAdded;
use App\Events\ReplyAdded;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

/**
 * End-to-end version of CLAUDE.md rule 6 for the broadcast layer: posting
 * an internal note through the real HTTP + service flow must dispatch
 * InternalNoteAdded (not ReplyAdded), and that event's channel list must
 * never include the customer-reachable `tickets.{n}` channel.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('posting an internal note dispatches InternalNoteAdded, never ReplyAdded, and never broadcasts to the public ticket channel', function () {
    Event::fake([InternalNoteAdded::class, ReplyAdded::class]);

    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket->ticket_number), [
            'body' => 'Internal: waiting on vendor.',
            'type' => 'internal_note',
        ])
        ->assertRedirect();

    Event::assertDispatched(InternalNoteAdded::class);
    Event::assertNotDispatched(ReplyAdded::class);

    Event::assertDispatched(InternalNoteAdded::class, function (InternalNoteAdded $event) use ($ticket) {
        $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());

        return $channels === ["private-tickets.{$ticket->ticket_number}.staff"];
    });
});

test('posting a public reply dispatches ReplyAdded, never InternalNoteAdded', function () {
    Event::fake([InternalNoteAdded::class, ReplyAdded::class]);

    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Any update?'])
        ->assertRedirect();

    Event::assertDispatched(ReplyAdded::class);
    Event::assertNotDispatched(InternalNoteAdded::class);
});
