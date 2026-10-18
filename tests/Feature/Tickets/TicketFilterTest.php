<?php

declare(strict_types=1);

use App\DTOs\TicketFilterData;
use App\Enums\Role as RoleEnum;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * InnoDB FULLTEXT changes are only visible to a search once the inserting
 * transaction commits (MySQL's documented behaviour) — RefreshDatabase
 * wraps each test in a transaction that is rolled back, so the keyword
 * tests below would never see their own fixture rows. DatabaseTruncation
 * truncates between tests instead, so every write here really commits.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Runs Ticket::scopeFilter directly (ARCHITECTURE.md §12) — these are
 * unit-level checks of the WHERE clauses, independent of visibility
 * scoping or HTTP, which are covered separately below.
 */
function filterTicketIds(array $overrides = []): Collection
{
    $filters = new TicketFilterData(...$overrides);

    return Ticket::query()->filter($filters)->pluck('id')->sort()->values();
}

test('filters by ticket number prefix', function () {
    $match = Ticket::factory()->create(['ticket_number' => 'TKT-2026-000001']);
    Ticket::factory()->create(['ticket_number' => 'TKT-2026-000002']);

    expect(filterTicketIds(['ticketNumber' => 'TKT-2026-000001']))->toEqual(collect([$match->id]));
});

test('filters by keyword using the fulltext index', function () {
    $match = Ticket::factory()->create([
        'subject' => 'Cannot login to the dashboard',
        'description' => 'Password reset link never arrives.',
    ]);
    Ticket::factory()->create([
        'subject' => 'Invoice amount is wrong',
        'description' => 'The billing total does not match the quote.',
    ]);

    expect(filterTicketIds(['keyword' => 'login']))->toEqual(collect([$match->id]));
});

test('keyword search falls back to LIKE when fulltext is disabled', function () {
    config(['support.search.fulltext' => false]);

    $match = Ticket::factory()->create(['subject' => 'Cannot login to the dashboard']);
    Ticket::factory()->create(['subject' => 'Invoice amount is wrong']);

    expect(filterTicketIds(['keyword' => 'login']))->toEqual(collect([$match->id]));
});

test('filters by status', function () {
    $match = Ticket::factory()->create(['status' => TicketStatus::InProgress->value]);
    Ticket::factory()->create(['status' => TicketStatus::Open->value]);

    expect(filterTicketIds(['status' => TicketStatus::InProgress]))->toEqual(collect([$match->id]));
});

test('filters by priority', function () {
    $match = Ticket::factory()->create(['priority' => TicketPriority::Critical->value]);
    Ticket::factory()->create(['priority' => TicketPriority::Low->value]);

    expect(filterTicketIds(['priority' => TicketPriority::Critical]))->toEqual(collect([$match->id]));
});

test('filters by category', function () {
    $category = Category::factory()->create();
    $match = Ticket::factory()->create(['category_id' => $category->id]);
    Ticket::factory()->create(['category_id' => Category::factory()->create()->id]);

    expect(filterTicketIds(['categoryId' => $category->id]))->toEqual(collect([$match->id]));
});

test('filters by assigned agent', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $other = User::factory()->create();
    $other->assignRole(RoleEnum::Agent->value);

    $match = Ticket::factory()->create(['assigned_agent_id' => $agent->id]);
    Ticket::factory()->create(['assigned_agent_id' => $other->id]);

    expect(filterTicketIds(['assignedAgentId' => $agent->id]))->toEqual(collect([$match->id]));
});

test('filters by customer', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $other = User::factory()->create();
    $other->assignRole(RoleEnum::Customer->value);

    $match = Ticket::factory()->create(['created_by' => $customer->id]);
    Ticket::factory()->create(['created_by' => $other->id]);

    expect(filterTicketIds(['createdBy' => $customer->id]))->toEqual(collect([$match->id]));
});

test('filters by created date range', function () {
    $inRange = Ticket::factory()->create();
    $inRange->forceFill(['created_at' => '2026-02-10 12:00:00'])->saveQuietly();

    $outOfRange = Ticket::factory()->create();
    $outOfRange->forceFill(['created_at' => '2026-03-10 12:00:00'])->saveQuietly();

    $ids = filterTicketIds([
        'createdFrom' => CarbonImmutable::parse('2026-02-01'),
        'createdTo' => CarbonImmutable::parse('2026-02-28'),
    ]);

    expect($ids)->toEqual(collect([$inRange->id]));
});

/**
 * One ticket per SLA state (ARCHITECTURE.md §8.3), asserting both that the
 * matching key includes it and that it does not leak into any other state.
 */
dataset('ticket_sla_states', [
    'within' => ['within', ['status' => TicketStatus::Open->value, 'sla_due_at' => now()->addDays(2), 'sla_warning_at' => now()->addDay()]],
    'approaching' => ['approaching', ['status' => TicketStatus::Open->value, 'sla_due_at' => now()->addHour(), 'sla_warning_at' => now()->subMinute()]],
    'breached' => ['breached', ['status' => TicketStatus::InProgress->value, 'sla_due_at' => now()->subMinute(), 'sla_warning_at' => now()->subHour()]],
    'met' => ['met', ['status' => TicketStatus::Resolved->value, 'sla_due_at' => now()->subDay(), 'sla_met' => true]],
    'met_late' => ['met_late', ['status' => TicketStatus::Closed->value, 'sla_due_at' => now()->subDay(), 'sla_met' => false]],
]);

test('filters by SLA state', function (string $key, array $attributes) {
    $ticket = Ticket::factory()->create($attributes);

    foreach (SlaState::keys() as $candidate) {
        $ids = filterTicketIds(['slaState' => SlaState::fromKey($candidate)]);

        expect($ids->contains($ticket->id))->toBe($candidate === $key);
    }
})->with('ticket_sla_states');

test('combining multiple filters narrows the result to the intersection', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $category = Category::factory()->create();

    $match = Ticket::factory()->create([
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::High->value,
        'category_id' => $category->id,
    ]);
    Ticket::factory()->create([
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Low->value,
        'category_id' => $category->id,
    ]);
    Ticket::factory()->create([
        'status' => TicketStatus::Resolved->value,
        'priority' => TicketPriority::High->value,
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($admin)->get(route('tickets.index', [
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::High->value,
        'category_id' => $category->id,
    ]))->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->has('tickets.data', 1)
        ->where('tickets.data.0.ticket_number', $match->ticket_number)
    );
});

test('a customer cannot use the created_by filter to see another customer\'s tickets', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $other = User::factory()->create();
    $other->assignRole(RoleEnum::Customer->value);
    Ticket::factory()->create(['created_by' => $other->id]);

    $response = $this->actingAs($customer)
        ->get(route('tickets.index', ['created_by' => $other->id]))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->has('tickets.data', 0));
});

test('an agent cannot use the assigned_agent_id filter to see another agent\'s tickets', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    $otherAgent = User::factory()->create();
    $otherAgent->assignRole(RoleEnum::Agent->value);
    Ticket::factory()->create(['assigned_agent_id' => $otherAgent->id]);

    $response = $this->actingAs($agent)
        ->get(route('tickets.index', ['assigned_agent_id' => $otherAgent->id]))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->has('tickets.data', 0));
});

test('the ticket index query count does not grow with the number of results', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    Ticket::factory()->count(3)->create();

    // Warm permission/model caches first so both counted requests are
    // apples-to-apples (a cold cache would otherwise inflate the first).
    $this->actingAs($admin)->get(route('tickets.index'))->assertOk();

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('tickets.index'))->assertOk();
    $smallResultCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    Ticket::factory()->count(12)->create();

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('tickets.index'))->assertOk();
    $largeResultCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeResultCount)->toBe($smallResultCount);
});
