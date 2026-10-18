<?php

declare(strict_types=1);

use App\Enums\ReportTab;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reportUser(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

/**
 * @param  array<string, int|string>  $values
 */
function reportRollup(array $values): void
{
    DB::table('ticket_daily_stats')->insert([
        'stat_date' => now('Asia/Dhaka')->toDateString(),
        'category_id' => 0,
        'priority' => TicketPriority::Medium->value,
        'agent_id' => 0,
        ...$values,
    ]);
}

test('an admin can open every report tab', function (string $tab) {
    $this->actingAs(reportUser(RoleEnum::Admin))
        ->get(route('reports.index', ['tab' => $tab]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('tab', $tab)
            ->has('report')
            ->has('filters', fn (Assert $filters) => $filters
                ->where('granularity', 'day')
                ->where('to', now('Asia/Dhaka')->toDateString())
                ->etc())
            ->has('options.categories')
            ->has('options.agents'));
})->with(array_map(fn (ReportTab $tab) => $tab->value, ReportTab::cases()));

test('an admin lands on the overview tab by default', function () {
    reportRollup(['created_count' => 4, 'resolved_count' => 3]);

    $this->actingAs(reportUser(RoleEnum::Admin))
        ->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'overview')
            ->where('report.totals.created', 4)
            ->where('report.totals.resolved', 3));
});

test('an agent lands on the agents tab and only ever receives their own row', function () {
    $agent = reportUser(RoleEnum::Agent);
    $other = reportUser(RoleEnum::Agent);
    reportRollup(['agent_id' => $agent->id, 'resolved_count' => 2]);
    reportRollup(['agent_id' => $other->id, 'resolved_count' => 7]);

    $this->actingAs($agent)
        ->get(route('reports.index', ['agent_id' => $other->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'agents')
            ->has('report', 1)
            ->where('report.0.agent_id', $agent->id)
            ->where('report.0.resolved', 2)
            // No cross-agent filter options for someone who can't use them.
            ->where('options.agents', [])
            ->where('options.categories', []));
});

test('an agent cannot open any other report tab', function (string $tab) {
    $this->actingAs(reportUser(RoleEnum::Agent))
        ->get(route('reports.index', ['tab' => $tab]))
        ->assertForbidden();
})->with(['overview', 'sla', 'response', 'aging', 'categories']);

test('a customer cannot open reports at all', function () {
    $this->actingAs(reportUser(RoleEnum::Customer))
        ->get(route('reports.index'))
        ->assertForbidden();
});

test('a guest is redirected to login', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

test('invalid filters come back as validation errors', function (array $query, string $field) {
    $this->actingAs(reportUser(RoleEnum::Admin))
        ->from(route('reports.index'))
        ->get(route('reports.index', $query))
        ->assertRedirect(route('reports.index'))
        ->assertSessionHasErrors($field);
})->with([
    'range over 366 days' => [['from' => '2025-01-01', 'to' => '2026-06-01'], 'to'],
    'unknown tab' => [['tab' => 'nope'], 'tab'],
]);

test('rollup-backed tabs run a fixed number of queries however much data there is', function (string $tab) {
    $admin = reportUser(RoleEnum::Admin);
    $count = function () use ($admin, $tab): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('reports.index', ['tab' => $tab]))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    reportRollup(['priority' => 'low', 'created_count' => 1]);
    $count(); // warm-up: the first request also loads the permission cache
    $small = $count();

    foreach (['medium', 'high', 'critical'] as $priority) {
        foreach (range(1, 5) as $category) {
            reportRollup(['priority' => $priority, 'category_id' => $category, 'created_count' => 9, 'resolved_count' => 4]);
        }
    }

    expect($count())->toBe($small);
})->with(['overview', 'sla', 'categories']);
