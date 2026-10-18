<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\ReportAggregationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Business timezone Asia/Dhaka (UTC+6). 2026-01-04 is a Sunday.
 */
beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
});

function dhakaTime(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Asia/Dhaka')->utc();
}

function dhakaDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Asia/Dhaka')->startOfDay();
}

/**
 * Factory create, then timestamps set directly — created_at can't be passed
 * through create() (Eloquent sets it on insert).
 *
 * @param  array<string, mixed>  $timestamps
 * @param  array<string, mixed>  $attributes
 */
function reportTicket(array $timestamps, array $attributes = []): Ticket
{
    $ticket = Ticket::factory()->create($attributes);
    Ticket::query()->whereKey($ticket->id)->update($timestamps);

    return $ticket->refresh();
}

/**
 * @return list<array<string, mixed>>
 */
function rollupRows(): array
{
    return DB::table('ticket_daily_stats')
        ->orderBy('stat_date')->orderBy('category_id')->orderBy('priority')->orderBy('agent_id')
        ->get()->map(fn ($row) => (array) $row)->all();
}

test('events are bucketed by their own business-timezone day with every measure', function () {
    $category = Category::factory()->create();
    $agent = User::factory()->create();
    $dims = ['category_id' => $category->id, 'priority' => TicketPriority::High->value, 'assigned_agent_id' => $agent->id];

    // Created 23:30 Dhaka on Sunday = 17:30 UTC — still Sunday's row.
    reportTicket(['created_at' => dhakaTime('2026-01-04 23:30')], $dims);

    // Created Sunday, responded + resolved late + escalated on Monday.
    $resolved = reportTicket([
        'created_at' => dhakaTime('2026-01-04 10:00'),
        'first_response_at' => dhakaTime('2026-01-05 09:30'),
        'first_response_minutes' => 30,
        'resolved_at' => dhakaTime('2026-01-05 12:00'),
        'resolution_minutes' => 600,
        'sla_met' => false,
        'escalated_at' => dhakaTime('2026-01-05 11:00'),
        'status' => TicketStatus::Resolved->value,
    ], $dims);

    // Reopen on Monday, logged by a staff status change.
    TicketActivity::query()->create([
        'ticket_id' => $resolved->id,
        'event' => ActivityEvent::StatusChanged->value,
        'old_values' => ['status' => 'resolved'],
        'new_values' => ['status' => 'open'],
        'created_at' => dhakaTime('2026-01-05 15:00'),
    ]);

    // Unassigned, no category: lands on the 0 sentinels.
    reportTicket(['created_at' => dhakaTime('2026-01-05 00:30')], ['category_id' => null, 'priority' => TicketPriority::Low->value]);

    app(ReportAggregationService::class)->recompute(dhakaDay('2026-01-04'), dhakaDay('2026-01-05'));

    $rows = collect(rollupRows())->keyBy(fn ($r) => "{$r['stat_date']}|{$r['category_id']}|{$r['priority']}|{$r['agent_id']}");

    expect($rows)->toHaveCount(3);

    expect($rows["2026-01-04|{$category->id}|high|{$agent->id}"])->toMatchArray([
        'created_count' => 2, 'resolved_count' => 0, 'responded_count' => 0,
    ]);

    expect($rows["2026-01-05|{$category->id}|high|{$agent->id}"])->toMatchArray([
        'created_count' => 0,
        'resolved_count' => 1,
        'sla_met_count' => 0,
        'sla_breached_count' => 1,
        'sum_resolution_minutes' => 600,
        'responded_count' => 1,
        'sum_first_response_minutes' => 30,
        'escalated_count' => 1,
        'reopened_count' => 1,
    ]);

    expect($rows['2026-01-05|0|low|0'])->toMatchArray(['created_count' => 1]);
});

test('recomputing the same range twice gives identical rows', function () {
    $agent = User::factory()->create();
    foreach (range(1, 5) as $i) {
        reportTicket([
            'created_at' => dhakaTime("2026-01-04 0{$i}:00"),
            'resolved_at' => dhakaTime("2026-01-05 1{$i}:00"),
            'resolution_minutes' => 60 * $i,
            'sla_met' => $i % 2 === 0,
            'status' => TicketStatus::Resolved->value,
        ], ['assigned_agent_id' => $agent->id]);
    }

    $aggregation = app(ReportAggregationService::class);
    $aggregation->recompute(dhakaDay('2026-01-04'), dhakaDay('2026-01-05'));
    $first = rollupRows();

    $aggregation->recompute(dhakaDay('2026-01-04'), dhakaDay('2026-01-05'));

    expect(rollupRows())->toBe($first)
        ->and(array_sum(array_column($first, 'created_count')))->toBe(5)
        ->and(array_sum(array_column($first, 'resolved_count')))->toBe(5);
});

test('a ticket that changed category leaves no stale row for its old group', function () {
    $old = Category::factory()->create();
    $new = Category::factory()->create();
    $ticket = reportTicket(['created_at' => dhakaTime('2026-01-04 10:00')], ['category_id' => $old->id]);

    $aggregation = app(ReportAggregationService::class);
    $aggregation->recompute(dhakaDay('2026-01-04'), dhakaDay('2026-01-04'));

    Ticket::query()->whereKey($ticket->id)->update(['category_id' => $new->id]);
    $aggregation->recompute(dhakaDay('2026-01-04'), dhakaDay('2026-01-04'));

    $rows = rollupRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['category_id'])->toBe($new->id)
        ->and($rows[0]['created_count'])->toBe(1);
});

test('recomputing one range leaves other days untouched', function () {
    reportTicket(['created_at' => dhakaTime('2026-01-04 10:00')]);
    reportTicket(['created_at' => dhakaTime('2026-01-06 10:00')]);

    $aggregation = app(ReportAggregationService::class);
    $aggregation->recompute(dhakaDay('2026-01-04'), dhakaDay('2026-01-06'));

    // Only the 4th is recomputed; its DELETE must not reach the 6th's row.
    $aggregation->recompute(dhakaDay('2026-01-04'), dhakaDay('2026-01-04'));

    expect(collect(rollupRows())->pluck('stat_date')->all())->toBe(['2026-01-04', '2026-01-06']);
});

test('reports:aggregate defaults to today and validates its options', function () {
    $this->travelTo(dhakaTime('2026-01-04 12:00'));
    reportTicket(['created_at' => dhakaTime('2026-01-04 09:00')]);
    reportTicket(['created_at' => dhakaTime('2026-01-03 09:00')]);

    $this->artisan('reports:aggregate')->assertSuccessful();
    expect(collect(rollupRows())->pluck('stat_date')->all())->toBe(['2026-01-04']);

    $this->artisan('reports:aggregate --yesterday')->assertSuccessful();
    expect(collect(rollupRows())->pluck('stat_date')->all())->toBe(['2026-01-03', '2026-01-04']);

    $this->artisan('reports:aggregate --from=2026-01-05 --to=2026-01-04')->assertExitCode(2);
    $this->artisan('reports:aggregate --from=04-01-2026')->assertExitCode(2);
});

test('reports:snapshot records the live backlog and overwrites the same day', function () {
    $this->travelTo(dhakaTime('2026-01-04 23:55'));
    $agent = User::factory()->create();

    Ticket::factory()->create(['status' => TicketStatus::Open->value]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id, 'sla_due_at' => now()->subHour()]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'assigned_agent_id' => $agent->id]);

    $this->artisan('reports:snapshot')->assertSuccessful();
    Ticket::factory()->create(['status' => TicketStatus::Open->value]);
    $this->artisan('reports:snapshot')->assertSuccessful();

    $snapshots = DB::table('ticket_daily_snapshots')->get();
    expect($snapshots)->toHaveCount(1)
        ->and((array) $snapshots[0])->toMatchArray([
            'stat_date' => '2026-01-04',
            'open_count' => 3,
            'unassigned_count' => 2,
            'breached_open_count' => 1,
        ]);
});
