<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Events\InternalNoteAdded;
use App\Events\ReplyAdded;
use App\Events\SlaBreached;
use App\Events\SlaWarningTriggered;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketStatusChanged;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Unit-level: asserts each event's broadcastOn() channel list directly,
 * independent of any broadcaster driver. InternalNoteAdded and the SLA
 * events are the security-critical ones — CLAUDE.md rule 6 requires they
 * never reach a public/customer-reachable channel.
 */
test('TicketCreated broadcasts only to the staff queue', function () {
    $ticket = Ticket::factory()->make(['id' => 1]);

    $channels = array_map(fn ($c) => $c->name, (new TicketCreated($ticket))->broadcastOn());

    expect($channels)->toBe(['private-staff.queue']);
});

test('ReplyAdded broadcasts only to the ticket\'s public channel', function () {
    $ticket = Ticket::factory()->make(['id' => 1, 'ticket_number' => 'TKT-2026-000001']);
    $reply = TicketReply::factory()->make(['id' => 1]);

    $channels = array_map(fn ($c) => $c->name, (new ReplyAdded($ticket, $reply))->broadcastOn());

    expect($channels)->toBe(['private-tickets.TKT-2026-000001']);
});

test('InternalNoteAdded broadcasts only to the staff channel, never the public ticket channel', function () {
    $ticket = Ticket::factory()->make(['id' => 1, 'ticket_number' => 'TKT-2026-000001']);
    $note = TicketReply::factory()->make(['id' => 1]);

    $channels = array_map(fn ($c) => $c->name, (new InternalNoteAdded($ticket, $note))->broadcastOn());

    expect($channels)->toBe(['private-tickets.TKT-2026-000001.staff']);
    expect($channels)->not->toContain('private-tickets.TKT-2026-000001');
});

test('TicketAssigned broadcasts to the queue, the ticket channel, and the assignee\'s channel', function () {
    $ticket = Ticket::factory()->make([
        'id' => 1,
        'ticket_number' => 'TKT-2026-000001',
        'assigned_agent_id' => 42,
    ]);

    $channels = array_map(fn ($c) => $c->name, (new TicketAssigned($ticket))->broadcastOn());

    expect($channels)->toBe([
        'private-staff.queue',
        'private-tickets.TKT-2026-000001',
        'private-App.Models.User.42',
    ]);
});

test('TicketAssigned omits the assignee channel when there is no assignee yet', function () {
    $ticket = Ticket::factory()->make([
        'id' => 1,
        'ticket_number' => 'TKT-2026-000001',
        'assigned_agent_id' => null,
    ]);

    $channels = array_map(fn ($c) => $c->name, (new TicketAssigned($ticket))->broadcastOn());

    expect($channels)->toBe(['private-staff.queue', 'private-tickets.TKT-2026-000001']);
});

test('TicketStatusChanged broadcasts to the ticket channel and the staff queue', function () {
    $ticket = Ticket::factory()->make(['id' => 1, 'ticket_number' => 'TKT-2026-000001']);

    $event = new TicketStatusChanged($ticket, TicketStatus::Open, TicketStatus::InProgress);
    $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());

    expect($channels)->toBe(['private-tickets.TKT-2026-000001', 'private-staff.queue']);
});

test('SlaWarningTriggered and SlaBreached never reach the public ticket channel', function () {
    $ticket = Ticket::factory()->make(['id' => 1, 'ticket_number' => 'TKT-2026-000001']);

    $warningChannels = array_map(fn ($c) => $c->name, (new SlaWarningTriggered($ticket))->broadcastOn());
    $breachChannels = array_map(fn ($c) => $c->name, (new SlaBreached($ticket))->broadcastOn());

    expect($warningChannels)->toBe(['private-staff.alerts', 'private-tickets.TKT-2026-000001.staff']);
    expect($breachChannels)->toBe(['private-staff.alerts', 'private-tickets.TKT-2026-000001.staff']);
    expect($warningChannels)->not->toContain('private-tickets.TKT-2026-000001');
    expect($breachChannels)->not->toContain('private-tickets.TKT-2026-000001');
});
