<?php

declare(strict_types=1);

use App\DTOs\ReportFilterData;
use App\Enums\ReportGranularity;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reportDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Asia/Dhaka')->startOfDay();
}

function filters(string $from = '2026-01-04', string $to = '2026-01-10', array $extra = []): ReportFilterData
{
    return new ReportFilterData(reportDay($from), reportDay($to), ...$extra);
}

/**
 * Inserts a rollup row directly — the service reads the rollup, so these
 * tests don't depend on the aggregation (covered in ReportAggregationTest).
 *
 * @param  array<string, int|string>  $values
 */
function rollup(string $date, array $values): void
{
    DB::table('ticket_daily_stats')->insert([
        'stat_date' => $date,
        'category_id' => 0,
        'priority' => TicketPriority::Medium->value,
        'agent_id' => 0,
        ...$values,
    ]);
}

function resolvedWith(int $minutes, TicketPriority $priority): void
{
    $ticket = Ticket::factory()->create(['priority' => $priority->value, 'status' => TicketStatus::Resolved->value]);
    Ticket::query()->whereKey($ticket->id)->update([
        'resolved_at' => CarbonImmutable::parse('2026-01-05 12:00', 'Asia/Dhaka')->utc(),
        'resolution_minutes' => $minutes,
    ]);
}

function userWithRole(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

test('median and p90 use nearest rank, matching a hand-computed fixture', function () {
    // high: 10, 20, … 100 (n=10) → median = 5th = 50, p90 = 9th = 90.
    foreach (range(10, 100, 10) as $minutes) {
        resolvedWith($minutes, TicketPriority::High);
    }
    // critical: 5, 7, 100 (n=3) → median = CEIL(1.5) = 2nd = 7, p90 = CEIL(2.7) = 3rd = 100.
    foreach ([100, 5, 7] as $minutes) {
        resolvedWith($minutes, TicketPriority::Critical);
    }
    // all (n=13): 5, 7, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 100
    //   median = CEIL(6.5) = 7th = 50, p90 = CEIL(11.7) = 12th = 100.

    $result = app(ReportService::class)->responseResolution(filters())['resolution'];

    expect($result['all'])->toMatchArray(['median' => 50, 'p90' => 100, 'count' => 13])
        ->and($result['high'])->toMatchArray(['median' => 50, 'p90' => 90, 'count' => 10])
        ->and($result['critical'])->toMatchArray(['median' => 7, 'p90' => 100, 'count' => 3])
        ->and($result['low'])->toMatchArray(['median' => null, 'p90' => null, 'count' => 0]);
});

test('averages come from the rollup and percentiles are capped at 30 days', function () {
    rollup('2026-01-05', ['resolved_count' => 4, 'sum_resolution_minutes' => 410, 'responded_count' => 2, 'sum_first_response_minutes' => 61]);

    $result = app(ReportService::class)->responseResolution(filters('2025-06-01', '2026-01-10'));

    expect($result['resolution']['all']['avg'])->toBe(103)
        ->and($result['firstResponse']['all']['avg'])->toBe(31)
        ->and($result['percentileWindow'])->toBe(['from' => '2025-12-12', 'to' => '2026-01-10']);
});

test('an agent only ever sees their own performance row', function () {
    $agentA = userWithRole(RoleEnum::Agent);
    $agentB = userWithRole(RoleEnum::Agent);
    rollup('2026-01-05', ['agent_id' => $agentA->id, 'resolved_count' => 3, 'sla_met_count' => 3]);
    rollup('2026-01-05', ['agent_id' => $agentB->id, 'resolved_count' => 5, 'sla_met_count' => 1, 'sla_breached_count' => 4]);
    rollup('2026-01-05', ['agent_id' => 0, 'created_count' => 9]);

    $service = app(ReportService::class);

    $own = $service->agentPerformance($agentA, filters());
    expect($own)->toHaveCount(1)
        ->and($own[0])->toMatchArray(['agent_id' => $agentA->id, 'resolved' => 3, 'sla_met_pct' => 100.0]);

    // Asking for another agent's row explicitly still returns only their own.
    $asked = $service->agentPerformance($agentA, filters(extra: ['agentId' => $agentB->id]));
    expect(array_column($asked, 'agent_id'))->toBe([$agentA->id]);

    $admin = userWithRole(RoleEnum::Admin);
    expect(array_column($service->agentPerformance($admin, filters()), 'agent_id'))
        ->toEqualCanonicalizing([$agentA->id, $agentB->id]);

    expect($service->agentPerformance(userWithRole(RoleEnum::Customer), filters()))->toBe([]);
});

test('sla compliance counts met and breached, ignoring tickets without an SLA', function () {
    rollup('2026-01-05', ['priority' => 'high', 'resolved_count' => 5, 'sla_met_count' => 3, 'sla_breached_count' => 1, 'escalated_count' => 2]);
    rollup('2026-01-06', ['priority' => 'low', 'resolved_count' => 2, 'sla_met_count' => 2]);

    $result = app(ReportService::class)->slaCompliance(filters());

    expect($result['totals'])->toBe(['met' => 5, 'breached' => 1, 'compliance_pct' => 83.3, 'escalated' => 2])
        ->and(collect($result['byPriority'])->firstWhere('priority', 'high'))
        ->toBe(['priority' => 'high', 'met' => 3, 'breached' => 1, 'compliance_pct' => 75.0])
        ->and(collect($result['byPriority'])->firstWhere('priority', 'critical')['compliance_pct'])->toBeNull();
});

test('overview series is zero-filled per bucket, weeks starting on Sunday', function () {
    rollup('2026-01-06', ['created_count' => 2, 'resolved_count' => 1]);
    rollup('2026-01-13', ['created_count' => 3]);

    $result = app(ReportService::class)->overview(
        filters('2026-01-06', '2026-01-20', ['granularity' => ReportGranularity::Week]),
    );

    expect($result['series'])->toBe([
        ['bucket' => '2026-01-04', 'created' => 2, 'resolved' => 1],
        ['bucket' => '2026-01-11', 'created' => 3, 'resolved' => 0],
        ['bucket' => '2026-01-18', 'created' => 0, 'resolved' => 0],
    ])->and($result['totals'])->toMatchArray(['created' => 5, 'resolved' => 1]);
});

test('categories report volume share and breach rate', function () {
    $billing = Category::factory()->create(['name' => 'Billing']);
    rollup('2026-01-05', ['category_id' => $billing->id, 'created_count' => 3, 'resolved_count' => 2, 'sum_resolution_minutes' => 100, 'sla_met_count' => 1, 'sla_breached_count' => 1]);
    rollup('2026-01-05', ['created_count' => 1]);

    $result = app(ReportService::class)->categories(filters());

    expect($result[0])->toMatchArray(['name' => 'Billing', 'created' => 3, 'share_pct' => 75.0, 'avg_resolution_minutes' => 50, 'breach_rate_pct' => 50.0])
        ->and($result[1])->toMatchArray(['category_id' => 0, 'name' => null, 'share_pct' => 25.0]);
});

test('aging buckets active tickets by calendar age', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20 12:00', 'UTC'));
    $agent = User::factory()->create();

    foreach (['-2 hours', '-2 days', '-5 days', '-10 days'] as $age) {
        $ticket = Ticket::factory()->create(['priority' => 'high', 'status' => TicketStatus::Open->value]);
        Ticket::query()->whereKey($ticket->id)->update(['created_at' => now()->modify($age)]);
    }
    Ticket::factory()->create(['priority' => 'high', 'status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id]);
    Ticket::factory()->create(['priority' => 'high', 'status' => TicketStatus::Resolved->value]);

    $result = app(ReportService::class)->aging(filters());
    $high = collect($result['byPriority'])->firstWhere('priority', 'high');

    expect($high)->toMatchArray(['age_0_1d' => 2, 'age_1_3d' => 1, 'age_3_7d' => 1, 'age_7d_plus' => 1, 'unassigned_count' => 4])
        ->and($high['oldest_unassigned_at'])->toStartWith('2026-01-10')
        ->and($result['totals']['age_7d_plus'])->toBe(1);
});
