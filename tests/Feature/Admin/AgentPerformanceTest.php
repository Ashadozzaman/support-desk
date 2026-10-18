<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeAgentPerformanceAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    return $admin;
}

function makeAgentPerformanceAgent(array $profileAttributes = []): User
{
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    AgentProfile::factory()->create([...$profileAttributes, 'user_id' => $agent->id]);

    return $agent;
}

function makeAgentPerformanceCustomer(): User
{
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    return $customer;
}

test('a non-admin cannot view the agents list or an agent detail page', function () {
    $agent = makeAgentPerformanceAgent();
    $other = makeAgentPerformanceAgent();

    $this->actingAs($agent)->get(route('admin.agents.index'))->assertForbidden();
    $this->actingAs($agent)->get(route('admin.agents.show', $other))->assertForbidden();
});

test('an admin sees every agent in the list, even one with zero tickets ever', function () {
    $admin = makeAgentPerformanceAdmin();
    $withTickets = makeAgentPerformanceAgent(['max_active_tickets' => 5, 'is_available' => true]);
    $withoutTickets = makeAgentPerformanceAgent(['max_active_tickets' => 5, 'is_available' => true]);

    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $withTickets->id]);

    $response = $this->actingAs($admin)->get(route('admin.agents.index'));

    $response->assertOk();
    $response->assertInertia(function ($page) use ($withTickets, $withoutTickets) {
        $agents = collect($page->toArray()['props']['agents']['data']);

        expect($agents->pluck('agent.id'))->toContain($withTickets->id, $withoutTickets->id);

        $zeroRow = $agents->firstWhere('agent.id', $withoutTickets->id);
        expect($zeroRow['active_count'])->toBe(0);
        expect($zeroRow['completed_count'])->toBe(0);
    });
});

test('completed counts only tickets resolved inside the picked dates, while workload counts stay live', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', config('support.sla.timezone')));
    $admin = makeAgentPerformanceAdmin();
    $agent = makeAgentPerformanceAgent();

    Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id, 'assigned_at' => now()]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id, 'assigned_at' => now()->subDays(10)]);
    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $agent->id]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'assigned_agent_id' => $agent->id, 'resolved_at' => now()->subHour()]);
    Ticket::factory()->create(['status' => TicketStatus::Closed->value, 'assigned_agent_id' => $agent->id, 'resolved_at' => now()->subDays(3)]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'assigned_agent_id' => $agent->id, 'resolved_at' => now()->subDays(20)]);

    $rowFor = fn (array $query) => collect(
        $this->actingAs($admin)->get(route('admin.agents.index', $query))->assertOk()
            ->viewData('page')['props']['agents']['data']
    )->firstWhere('agent.id', $agent->id);

    // Default window = today (business timezone).
    $today = $rowFor([]);
    expect($today['completed_count'])->toBe(1);
    expect($today['in_progress_count'])->toBe(2);
    expect($today['open_count'])->toBe(1);
    expect($today['active_count'])->toBe(3);
    expect($today['stuck_count'])->toBe(1);

    expect($rowFor(['from' => '2026-10-12', 'to' => '2026-10-18'])['completed_count'])->toBe(2);
    expect($rowFor(['from' => '2026-09-18', 'to' => '2026-10-18'])['completed_count'])->toBe(3);
    // Workload is a live snapshot — the date range never changes it.
    expect($rowFor(['from' => '2026-09-18', 'to' => '2026-10-18'])['in_progress_count'])->toBe(2);
});

test('"today" follows the business timezone, not UTC', function () {
    // 2026-10-18 01:00 in Dhaka (UTC+6) is still 2026-10-17 in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 01:00', 'Asia/Dhaka'));
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $admin = makeAgentPerformanceAdmin();
    $agent = makeAgentPerformanceAgent();

    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'assigned_agent_id' => $agent->id, 'resolved_at' => CarbonImmutable::parse('2026-10-18 00:30', 'Asia/Dhaka')->utc()]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'assigned_agent_id' => $agent->id, 'resolved_at' => CarbonImmutable::parse('2026-10-17 23:30', 'Asia/Dhaka')->utc()]);

    $props = $this->actingAs($admin)->get(route('admin.agents.index'))->viewData('page')['props'];

    expect($props['filters'])->toBe(['from' => '2026-10-18', 'to' => '2026-10-18']);
    expect($props['today'])->toBe('2026-10-18');
    expect(collect($props['agents']['data'])->firstWhere('agent.id', $agent->id)['completed_count'])->toBe(1);
});

test('the agents list is paginated and keeps the date filter in page links', function () {
    $admin = makeAgentPerformanceAdmin();
    foreach (range(1, 4) as $i) {
        makeAgentPerformanceAgent();
    }

    $agents = $this->actingAs($admin)
        ->get(route('admin.agents.index', ['per_page' => 3, 'from' => '2026-10-01', 'to' => '2026-10-18']))
        ->assertOk()
        ->viewData('page')['props']['agents'];

    expect($agents['data'])->toHaveCount(3);
    expect($agents['meta']['total'])->toBe(4);
    expect($agents['meta']['last_page'])->toBe(2);
    expect($agents['links']['next'])->toContain('from=2026-10-01')->toContain('to=2026-10-18');
});

test('an end date before the start date is rejected', function () {
    $admin = makeAgentPerformanceAdmin();

    $this->actingAs($admin)
        ->get(route('admin.agents.index', ['from' => '2026-10-18', 'to' => '2026-10-01']))
        ->assertSessionHasErrors('to');
});

test('the agents list query count does not grow with the number of agents on the page', function () {
    $admin = makeAgentPerformanceAdmin();
    $countQueries = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.agents.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $agent = makeAgentPerformanceAgent();
    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $agent->id]);
    $countQueries(); // warm-up: first request also loads the permission cache
    $few = $countQueries();

    foreach (range(1, 5) as $i) {
        $more = makeAgentPerformanceAgent();
        Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $more->id]);
    }

    expect($countQueries())->toBe($few);
});

test('an admin can open one agent detail page and sees the full all-time status breakdown plus a zero-filled completed trend', function () {
    $admin = makeAgentPerformanceAdmin();
    $agent = makeAgentPerformanceAgent();

    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $agent->id]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id]);
    Ticket::factory()->create([
        'status' => TicketStatus::Resolved->value,
        'assigned_agent_id' => $agent->id,
        'resolved_at' => now()->subDays(2),
    ]);
    Ticket::factory()->create([
        'status' => TicketStatus::Closed->value,
        'assigned_agent_id' => $agent->id,
        'resolved_at' => now()->subDays(40), // outside the 30-day trend window
    ]);

    $response = $this->actingAs($admin)->get(route('admin.agents.show', $agent));

    $response->assertOk();
    $response->assertInertia(function ($page) {
        $props = $page->toArray()['props'];

        expect($props['statusBreakdown'])->toBe([
            'open' => 1,
            'in_progress' => 1,
            'waiting_for_customer' => 0,
            'resolved' => 1,
            'closed' => 1,
        ]);

        $trend = collect($props['completedTrend']);
        expect($trend)->toHaveCount(30);
        expect($trend->sum('count'))->toBe(1); // only the 2-days-ago resolution falls inside the window
        expect($trend->firstWhere('day', now()->subDays(2)->toDateString())['count'])->toBe(1);
    });
});

test('opening the detail page for a user who is not an agent is denied', function () {
    $admin = makeAgentPerformanceAdmin();
    $customer = makeAgentPerformanceCustomer();

    $this->actingAs($admin)->get(route('admin.agents.show', $customer))->assertForbidden();
});

test('the detail page lists every day of the picked month with assigned and completed counts by business-timezone day', function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', 'Asia/Dhaka'));
    $admin = makeAgentPerformanceAdmin();
    $agent = makeAgentPerformanceAgent();
    $dhaka = fn (string $at) => CarbonImmutable::parse($at, 'Asia/Dhaka')->utc();

    // 2026-09-05 00:30 Dhaka is still 2026-09-04 in UTC — must count on the 5th.
    Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id, 'assigned_at' => $dhaka('2026-09-05 00:30')]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'assigned_agent_id' => $agent->id, 'assigned_at' => $dhaka('2026-09-05 10:00'), 'resolved_at' => $dhaka('2026-09-07 15:00')]);
    Ticket::factory()->create(['status' => TicketStatus::Closed->value, 'assigned_agent_id' => $agent->id, 'assigned_at' => $dhaka('2026-08-20 10:00'), 'resolved_at' => $dhaka('2026-09-30 23:30')]);
    // Outside September, and another agent's ticket — neither may count.
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'assigned_agent_id' => $agent->id, 'assigned_at' => $dhaka('2026-10-01 00:10'), 'resolved_at' => $dhaka('2026-10-01 00:20')]);
    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => makeAgentPerformanceAgent()->id, 'assigned_at' => $dhaka('2026-09-05 10:00')]);

    $daily = $this->actingAs($admin)->get(route('admin.agents.show', [$agent, 'month' => '2026-09']))
        ->assertOk()->viewData('page')['props']['daily'];
    $days = collect($daily['days'])->keyBy('date');

    expect($daily['month'])->toBe('2026-09');
    expect($days)->toHaveCount(30);
    expect($days['2026-09-04'])->toBe(['date' => '2026-09-04', 'assigned' => 0, 'completed' => 0]);
    expect($days['2026-09-05']['assigned'])->toBe(2);
    expect($days['2026-09-07']['completed'])->toBe(1);
    expect($days['2026-09-30']['completed'])->toBe(1);
    expect($daily['totals'])->toBe(['assigned' => 2, 'completed' => 2]);
});

test('the detail page defaults to the current month and stops at today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', config('support.sla.timezone')));
    $admin = makeAgentPerformanceAdmin();
    $agent = makeAgentPerformanceAgent();

    $daily = $this->actingAs($admin)->get(route('admin.agents.show', $agent))->viewData('page')['props']['daily'];

    expect($daily['month'])->toBe('2026-10');
    expect($daily['days'])->toHaveCount(18);
    expect(end($daily['days'])['date'])->toBe('2026-10-18');
});

test('an invalid month is rejected', function () {
    $admin = makeAgentPerformanceAdmin();
    $agent = makeAgentPerformanceAgent();

    $this->actingAs($admin)->get(route('admin.agents.show', [$agent, 'month' => '2026-13']))->assertSessionHasErrors('month');
});

test('the daily CSV export has the same rows as the page plus a total, and is admin-only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', config('support.sla.timezone')));
    $admin = makeAgentPerformanceAdmin();
    $agent = makeAgentPerformanceAgent();
    Ticket::factory()->create([
        'status' => TicketStatus::Resolved->value,
        'assigned_agent_id' => $agent->id,
        'assigned_at' => CarbonImmutable::parse('2026-09-02 10:00', config('support.sla.timezone'))->utc(),
        'resolved_at' => CarbonImmutable::parse('2026-09-03 10:00', config('support.sla.timezone'))->utc(),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.agents.daily-export', [$agent, 'month' => '2026-09']));

    $response->assertOk();
    $response->assertDownload("agent-{$agent->id}-2026-09.csv");
    $lines = explode("\r\n", trim($response->streamedContent()));

    expect($lines)->toHaveCount(32); // header + 30 days + total
    expect($lines[0])->toBe('Date,Assigned,Completed');
    expect($lines[2])->toBe('2026-09-02,1,0');
    expect($lines[3])->toBe('2026-09-03,0,1');
    expect($lines[31])->toBe('Total,1,1');

    $this->actingAs(makeAgentPerformanceAgent())
        ->get(route('admin.agents.daily-export', $agent))
        ->assertForbidden();
});
