<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Jobs\ProcessSlaBreachJob;
use App\Jobs\ProcessSlaWarningJob;
use App\Models\Category;
use App\Models\Ticket;
use App\Services\ActivityLogService;
use App\Services\TicketAssignmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Event::fake();
});

test('an SLA breach is logged to the sla channel exactly once, however often the job runs', function () {
    $log = captureLog('sla');
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Open->value,
        'sla_due_at' => now()->subHour(),
        'sla_breach_notified_at' => null,
    ]);

    (new ProcessSlaBreachJob($ticket->id))->handle(app(ActivityLogService::class));
    (new ProcessSlaBreachJob($ticket->id))->handle(app(ActivityLogService::class));

    expect($log->getRecords())->toHaveCount(1);
    expect($log->getRecords()[0]->message)->toBe('sla.breached')
        ->and($log->getRecords()[0]->context)->toMatchArray(['ticket_id' => $ticket->id, 'escalation_level' => 1]);
});

test('an SLA warning is logged to the sla channel once', function () {
    $log = captureLog('sla');
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Open->value,
        'sla_warning_at' => now()->subMinute(),
        'sla_warning_notified_at' => null,
    ]);

    (new ProcessSlaWarningJob($ticket->id))->handle(app(ActivityLogService::class));
    (new ProcessSlaWarningJob($ticket->id))->handle(app(ActivityLogService::class));

    expect($log->getRecords())->toHaveCount(1);
    expect($log->getRecords()[0]->message)->toBe('sla.warning');
});

test('auto-assign with no candidate logs once per category per window, not once per ticket', function () {
    $log = captureLog(config('logging.default'));
    $category = Category::factory()->create();
    $tickets = Ticket::factory()->count(3)->create([
        'status' => TicketStatus::Open->value,
        'assigned_agent_id' => null,
        'category_id' => $category->id,
    ]);

    foreach ($tickets as $ticket) {
        app(TicketAssignmentService::class)->autoAssign($ticket);
    }

    $lines = collect($log->getRecords())->where('message', 'assignment.no_candidate');
    expect($lines)->toHaveCount(1);
    expect($lines->first()->context['category_id'])->toBe($category->id);
});
