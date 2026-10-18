<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BusinessHourSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Report metrics on tickets (ENHANCEMENTS.md §1.2): sla_met,
 * resolution_minutes, first_response_minutes, reopened_count are written at
 * the moment of the event. Business hours: Sun–Thu 09:00–18:00 Asia/Dhaka.
 * 2026-01-04 is a Sunday.
 */
beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $this->seed([RolesAndPermissionsSeeder::class, BusinessHourSeeder::class]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleEnum::Admin->value);

    $this->customer = User::factory()->create();
    $this->customer->assignRole(RoleEnum::Customer->value);
});

/**
 * Dhaka wall-clock time as a UTC instant — Eloquent stores a datetime in
 * its own timezone, and the app keeps every column in UTC.
 */
function at(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Asia/Dhaka')->utc();
}

function changeStatus(User $actor, Ticket $ticket, TicketStatus $status): void
{
    test()->actingAs($actor)
        ->patch(route('tickets.change-status', $ticket->ticket_number), ['status' => $status->value])
        ->assertRedirect();
}

function ticketCreatedAt(string $time, array $attributes = []): Ticket
{
    test()->travelTo(at($time));

    return Ticket::factory()->create([
        'status' => TicketStatus::InProgress->value,
        'created_by' => test()->customer->id,
        ...$attributes,
    ]);
}

test('resolving within the SLA sets sla_met true and the business-minute resolution time', function () {
    $ticket = ticketCreatedAt('2026-01-04 09:00', ['sla_due_at' => at('2026-01-04 12:00')]);

    $this->travelTo(at('2026-01-04 11:00'));
    changeStatus($this->admin, $ticket, TicketStatus::Resolved);

    $ticket->refresh();
    expect($ticket->sla_met)->toBeTrue()
        ->and($ticket->resolution_minutes)->toBe(120);
});

test('resolving after the SLA deadline sets sla_met false', function () {
    $ticket = ticketCreatedAt('2026-01-04 09:00', ['sla_due_at' => at('2026-01-04 10:00')]);

    $this->travelTo(at('2026-01-04 11:00'));
    changeStatus($this->admin, $ticket, TicketStatus::Resolved);

    expect($ticket->refresh()->sla_met)->toBeFalse();
});

test('resolution time only counts business hours across the weekend', function () {
    // Thu 17:00 → Sun 10:00 = 60 min Thursday + 60 min Sunday.
    $ticket = ticketCreatedAt('2026-01-01 17:00');

    $this->travelTo(at('2026-01-04 10:00'));
    changeStatus($this->admin, $ticket, TicketStatus::Resolved);

    expect($ticket->refresh()->resolution_minutes)->toBe(120);
});

test('a ticket without an SLA gets a resolution time but sla_met stays null', function () {
    $ticket = ticketCreatedAt('2026-01-04 09:00', ['sla_due_at' => null]);

    $this->travelTo(at('2026-01-04 09:30'));
    changeStatus($this->admin, $ticket, TicketStatus::Resolved);

    $ticket->refresh();
    expect($ticket->sla_met)->toBeNull()
        ->and($ticket->resolution_minutes)->toBe(30);
});

test('closing a resolved ticket keeps its resolution metrics', function () {
    $ticket = ticketCreatedAt('2026-01-04 09:00', ['sla_due_at' => at('2026-01-04 12:00')]);

    $this->travelTo(at('2026-01-04 10:00'));
    changeStatus($this->admin, $ticket, TicketStatus::Resolved);

    $this->travelTo(at('2026-01-05 15:00'));
    changeStatus($this->admin, $ticket, TicketStatus::Closed);

    $ticket->refresh();
    expect($ticket->sla_met)->toBeTrue()
        ->and($ticket->resolution_minutes)->toBe(60);
});

test('only the first staff public reply sets first_response_minutes', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = ticketCreatedAt('2026-01-04 09:00', ['assigned_agent_id' => $agent->id]);

    // Neither a customer reply nor an internal note is a first response.
    $this->travelTo(at('2026-01-04 09:10'));
    $this->actingAs($this->customer)->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Hello?']);
    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Note', 'type' => 'internal_note']);
    expect($ticket->refresh()->first_response_minutes)->toBeNull();

    $this->travelTo(at('2026-01-04 09:45'));
    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Looking into it.']);
    expect($ticket->refresh()->first_response_minutes)->toBe(45);

    $this->travelTo(at('2026-01-04 11:00'));
    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Fixed.']);
    expect($ticket->refresh()->first_response_minutes)->toBe(45);
});

test('a customer reply reopening a resolved ticket clears resolution metrics and counts the reopen', function () {
    $ticket = ticketCreatedAt('2026-01-04 09:00', ['sla_due_at' => at('2026-01-04 12:00')]);

    $this->travelTo(at('2026-01-04 10:00'));
    changeStatus($this->admin, $ticket, TicketStatus::Resolved);

    $this->travelTo(at('2026-01-04 10:30'));
    $this->actingAs($this->customer)->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Still broken.']);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->reopened_count)->toBe(1)
        ->and($ticket->sla_met)->toBeNull()
        ->and($ticket->resolution_minutes)->toBeNull();

    // Resolving again measures created_at → the final resolution.
    $this->travelTo(at('2026-01-04 13:00'));
    changeStatus($this->admin, $ticket, TicketStatus::Resolved);

    $ticket->refresh();
    expect($ticket->sla_met)->toBeFalse()
        ->and($ticket->resolution_minutes)->toBe(240);
});

test('staff moving a resolved ticket back to open also counts as a reopen', function () {
    $ticket = ticketCreatedAt('2026-01-04 09:00');

    $this->travelTo(at('2026-01-04 10:00'));
    changeStatus($this->admin, $ticket, TicketStatus::Resolved);
    changeStatus($this->admin, $ticket, TicketStatus::Open);

    $ticket->refresh();
    expect($ticket->reopened_count)->toBe(1)
        ->and($ticket->resolution_minutes)->toBeNull();
});
