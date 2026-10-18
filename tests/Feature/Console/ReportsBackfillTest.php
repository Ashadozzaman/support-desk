<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketActivity;
use Carbon\CarbonImmutable;
use Database\Seeders\BusinessHourSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Business hours Sun–Thu 09:00–18:00 Asia/Dhaka; 2026-01-04 is a Sunday.
 */
beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $this->seed(BusinessHourSeeder::class);
});

function dhakaUtc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Asia/Dhaka')->utc();
}

/**
 * Bulk-inserted like PerformanceSeeder: timestamps set, metrics NULL.
 */
function legacyTicket(array $attributes): Ticket
{
    test()->travelTo(dhakaUtc('2026-01-04 09:00'));
    $ticket = Ticket::factory()->create($attributes);
    test()->travelBack();

    return $ticket;
}

test('backfill fills missing metrics from the stored timestamps', function () {
    $resolved = legacyTicket([
        'status' => TicketStatus::Resolved->value,
        'first_response_at' => dhakaUtc('2026-01-04 09:30'),
        'resolved_at' => dhakaUtc('2026-01-04 12:00'),
        'sla_due_at' => dhakaUtc('2026-01-04 11:00'),
    ]);
    $active = legacyTicket(['status' => TicketStatus::InProgress->value, 'first_response_at' => dhakaUtc('2026-01-04 10:00')]);
    $untouched = legacyTicket(['status' => TicketStatus::Open->value]);

    $this->artisan('reports:backfill')->assertSuccessful();

    $resolved->refresh();
    expect($resolved->first_response_minutes)->toBe(30)
        ->and($resolved->resolution_minutes)->toBe(180)
        ->and($resolved->sla_met)->toBeFalse();

    $active->refresh();
    expect($active->first_response_minutes)->toBe(60)
        ->and($active->resolution_minutes)->toBeNull();

    expect($untouched->refresh()->first_response_minutes)->toBeNull();
});

test('backfill sets reopen counts from the activity trail', function () {
    $ticket = legacyTicket(['status' => TicketStatus::Open->value]);

    TicketActivity::query()->create([
        'ticket_id' => $ticket->id,
        'event' => ActivityEvent::TicketReopened->value,
        'old_values' => ['status' => 'resolved'],
        'new_values' => ['status' => 'open'],
        'created_at' => now(),
    ]);
    TicketActivity::query()->create([
        'ticket_id' => $ticket->id,
        'event' => ActivityEvent::StatusChanged->value,
        'old_values' => ['status' => 'resolved'],
        'new_values' => ['status' => 'open'],
        'created_at' => now(),
    ]);
    // Not a reopen: a different transition.
    TicketActivity::query()->create([
        'ticket_id' => $ticket->id,
        'event' => ActivityEvent::StatusChanged->value,
        'old_values' => ['status' => 'open'],
        'new_values' => ['status' => 'in_progress'],
        'created_at' => now(),
    ]);

    $this->artisan('reports:backfill')->assertSuccessful();

    expect($ticket->refresh()->reopened_count)->toBe(2);
});

test('running backfill twice gives the same result', function () {
    $ticket = legacyTicket([
        'status' => TicketStatus::Resolved->value,
        'resolved_at' => dhakaUtc('2026-01-04 10:00'),
        'sla_due_at' => dhakaUtc('2026-01-04 11:00'),
    ]);
    TicketActivity::query()->create([
        'ticket_id' => $ticket->id,
        'event' => ActivityEvent::TicketReopened->value,
        'created_at' => now(),
    ]);

    $this->artisan('reports:backfill')->assertSuccessful();
    $first = $ticket->refresh()->only(['resolution_minutes', 'sla_met', 'reopened_count']);

    $this->artisan('reports:backfill')
        ->expectsOutputToContain('Filled metrics on 0 tickets; updated reopen counts on 0.')
        ->assertSuccessful();

    expect($ticket->refresh()->only(['resolution_minutes', 'sla_met', 'reopened_count']))->toBe($first)
        ->and($first)->toBe(['resolution_minutes' => 60, 'sla_met' => true, 'reopened_count' => 1]);
});
