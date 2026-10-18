<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    return $admin;
}

function makeAgentUser(): User
{
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    return $agent;
}

function makeCustomer(): User
{
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    return $customer;
}

test('admin dashboard counts match seeded fixtures across all tickets', function () {
    $admin = makeAdmin();
    $agent = makeAgentUser();

    Ticket::factory()->create([
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Low->value,
        'assigned_agent_id' => null,
    ]);
    Ticket::factory()->create([
        'status' => TicketStatus::InProgress->value,
        'priority' => TicketPriority::Critical->value,
        'assigned_agent_id' => $agent->id,
    ]);
    Ticket::factory()->create([
        'status' => TicketStatus::InProgress->value,
        'priority' => TicketPriority::Medium->value,
        'assigned_agent_id' => $agent->id,
        'sla_due_at' => now()->subHour(),
        'sla_warning_at' => now()->subHours(2),
    ]);
    Ticket::factory()->create([
        'status' => TicketStatus::Resolved->value,
        'priority' => TicketPriority::Medium->value,
        'assigned_agent_id' => $agent->id,
        'resolved_at' => now(),
        'sla_met' => true,
    ]);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('scope', 'global')
        ->where('stats.open_total', 3)
        ->where('stats.unassigned', 1)
        ->where('stats.critical', 1)
        ->where('stats.sla_breached', 1)
        ->where('stats.resolved', 1)
    );
});

test('agent dashboard counts are scoped to their own tickets plus the global unassigned count', function () {
    $agent = makeAgentUser();
    $other = makeAgentUser();

    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $agent->id]);
    Ticket::factory()->create(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => $other->id]);

    $response = $this->actingAs($agent)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('scope', 'agent')
        ->where('stats.open_total', 1)
        ->where('stats.unassigned', 1)
        ->where('byPriority', [])
        ->where('byAgent', [])
    );
});

test('customer dashboard shows only their own tickets with no queue-wide figures', function () {
    $customer = makeCustomer();
    $other = makeCustomer();

    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'created_by' => $customer->id]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'created_by' => $customer->id, 'resolved_at' => now(), 'sla_met' => true]);
    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'created_by' => $other->id]);

    $response = $this->actingAs($customer)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('scope', 'customer')
        ->where('stats.open_total', 1)
        ->where('stats.resolved', 1)
        ->where('stats.unassigned', null)
        ->where('stats.critical', null)
    );
});

test('dashboard stats are cached rather than recomputed on every request', function () {
    $admin = makeAdmin();

    Ticket::factory()->count(2)->create(['status' => TicketStatus::Open->value]);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    // Created after the first request primed the cache — a fresh
    // computation would see 5, a cache hit must still report 2.
    Ticket::factory()->count(3)->create(['status' => TicketStatus::Open->value]);

    $response = $this->actingAs($admin)->get(route('dashboard'));
    $response->assertInertia(fn ($page) => $page->where('stats.open_total', 2));
});

test('dashboard stats refresh once the cache ttl has elapsed', function () {
    config(['support.dashboard.cache_ttl' => 60]);
    $admin = makeAdmin();

    Ticket::factory()->count(2)->create(['status' => TicketStatus::Open->value]);
    $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    Ticket::factory()->count(3)->create(['status' => TicketStatus::Open->value]);
    $this->travel(61)->seconds();

    $response = $this->actingAs($admin)->get(route('dashboard'));
    $response->assertInertia(fn ($page) => $page->where('stats.open_total', 5));
});

test('the dashboard query count does not grow with the number of tickets', function () {
    $admin = makeAdmin();

    Ticket::factory()->count(3)->create();

    // Warm permission/model/session caches first so both counted requests
    // are apples-to-apples (a cold cache would otherwise inflate the first).
    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    Cache::flush(); // ...but not the dashboard stats cache itself, which this test measures fresh each time.

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    $smallResultCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    Cache::flush();
    Ticket::factory()->count(20)->create();

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    $largeResultCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeResultCount)->toBe($smallResultCount);
});

test('the tickets-created trend is a continuous 14-day series with gap days zero-filled', function () {
    $admin = makeAdmin();

    Ticket::factory()->count(2)->create(['created_at' => now()]);
    Ticket::factory()->create(['created_at' => now()->subDays(5)]);
    // Outside the 14-day window entirely — must not appear in any bucket.
    Ticket::factory()->create(['created_at' => now()->subDays(20)]);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(function ($page) {
        $page->has('trend', 14);
        $trend = collect($page->toArray()['props']['trend'])->keyBy('day');

        expect($trend->get(now()->toDateString())['count'])->toBe(2);
        expect($trend->get(now()->subDays(5)->toDateString())['count'])->toBe(1);
        expect($trend->get(now()->subDays(1)->toDateString())['count'])->toBe(0);
        expect($trend->sum('count'))->toBe(3);
    });
});

test('the tickets-created trend is scoped the same way as the other stats', function () {
    $agent = makeAgentUser();
    $other = makeAgentUser();

    Ticket::factory()->create(['assigned_agent_id' => $agent->id, 'created_at' => now()]);
    Ticket::factory()->create(['assigned_agent_id' => $other->id, 'created_at' => now()]);

    $response = $this->actingAs($agent)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(function ($page) {
        $trend = collect($page->toArray()['props']['trend']);

        expect($trend->sum('count'))->toBe(1);
    });
});
