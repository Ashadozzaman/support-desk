<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\TicketStatus;
use App\Jobs\ProcessSlaBreachJob;
use App\Jobs\ProcessSlaWarningJob;
use App\Models\Ticket;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('sla:scan dispatches a warning job for a ticket past its warning threshold', function () {
    Queue::fake();

    $approaching = Ticket::factory()->create([
        'sla_warning_at' => now()->subMinute(),
        'sla_due_at' => now()->addHour(),
    ]);
    $notYetApproaching = Ticket::factory()->create([
        'sla_warning_at' => now()->addHour(),
        'sla_due_at' => now()->addHours(2),
    ]);

    Artisan::call('sla:scan');

    Queue::assertPushed(ProcessSlaWarningJob::class, fn (ProcessSlaWarningJob $job) => $job->ticketId === $approaching->id);
    Queue::assertNotPushed(ProcessSlaWarningJob::class, fn (ProcessSlaWarningJob $job) => $job->ticketId === $notYetApproaching->id);
});

test('sla:scan dispatches a breach job for a ticket past its due date', function () {
    Queue::fake();

    $breached = Ticket::factory()->create([
        'sla_warning_at' => now()->subHour(),
        'sla_due_at' => now()->subMinute(),
    ]);

    Artisan::call('sla:scan');

    Queue::assertPushed(ProcessSlaBreachJob::class, fn (ProcessSlaBreachJob $job) => $job->ticketId === $breached->id);
});

test('resolved tickets are never escalated even when past their SLA deadline', function () {
    Queue::fake();

    $resolved = Ticket::factory()->create([
        'status' => TicketStatus::Resolved->value,
        'resolved_at' => now(),
        'sla_warning_at' => now()->subHour(),
        'sla_due_at' => now()->subMinute(),
    ]);

    Artisan::call('sla:scan');

    Queue::assertNotPushed(ProcessSlaWarningJob::class);
    Queue::assertNotPushed(ProcessSlaBreachJob::class);

    expect($resolved->refresh()->escalation_level)->toBe(0);
});

test('running sla:scan twice only escalates a breached ticket once', function () {
    // No Queue::fake() here — QUEUE_CONNECTION=sync in testing, so the
    // dispatched job runs for real and sets sla_breach_notified_at, which
    // is exactly the idempotency flag the second scan's WHERE NULL clause
    // depends on (ARCHITECTURE.md §9).
    $ticket = Ticket::factory()->create([
        'sla_warning_at' => now()->subHour(),
        'sla_due_at' => now()->subMinute(),
    ]);

    Artisan::call('sla:scan');
    Artisan::call('sla:scan');

    $ticket->refresh();
    expect($ticket->sla_breach_notified_at)->not->toBeNull();
    expect($ticket->escalation_level)->toBe(1);
    expect($ticket->activities()->where('event', ActivityEvent::SlaBreached->value)->count())->toBe(1);
    expect($ticket->activities()->where('event', ActivityEvent::TicketEscalated->value)->count())->toBe(1);
});
