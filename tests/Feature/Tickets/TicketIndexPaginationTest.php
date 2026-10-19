<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * TicketRepository::paginateFor() queries each status in the tab
 * separately (top-N-per-status, then merge) instead of one
 * `WHERE status IN (...) ORDER BY created_at DESC` — a single IN()-based
 * query can't use an index for both the filter and the sort once the
 * matching set is a large share of the table (verified via EXPLAIN: full
 * table scan + filesort at ~1M rows). These tests exist to prove the
 * merge-then-slice rewrite returns exactly the same rows, in exactly the
 * same order, as the single-query version would — across status
 * boundaries, across pages, and under the existing visibility/filter
 * rules — not just that it's faster.
 *
 * DatabaseTruncation (not RefreshDatabase): these assertions are exact
 * counts/order (`has('tickets.data', N)`, exact ticket_number per row),
 * same reasoning as TicketFilterTest. A plain transaction-wrapped
 * RefreshDatabase test only rolls back its own writes — it does not clear
 * rows a *different*, truncation-based test file already committed
 * earlier in the same suite run, so a leaked row would silently corrupt
 * these exact-count assertions.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeAdminUser(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    return $admin;
}

/**
 * Creates a ticket and overrides its created_at after the fact — Eloquent
 * timestamps are auto-managed on create(), so an explicit ordering key
 * needs forceFill + saveQuietly (same pattern as TicketFilterTest).
 */
function ticketAt(array $attributes, string $createdAt): Ticket
{
    $ticket = Ticket::factory()->create($attributes);
    $ticket->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $ticket;
}

test('the active tab merges and sorts tickets across all three statuses by newest first', function () {
    $admin = makeAdminUser();

    // Deliberately interleaved: status does not correlate with recency, so
    // a correct merge must still produce a pure created_at-desc ordering.
    $t1 = ticketAt(['status' => TicketStatus::Open->value], '2026-01-01 00:00:00');
    $t2 = ticketAt(['status' => TicketStatus::InProgress->value], '2026-01-03 00:00:00');
    $t3 = ticketAt(['status' => TicketStatus::WaitingForCustomer->value], '2026-01-02 00:00:00');
    $t4 = ticketAt(['status' => TicketStatus::Open->value], '2026-01-04 00:00:00');
    // Resolved is out of the active tab entirely — must never appear.
    ticketAt(['status' => TicketStatus::Resolved->value, 'resolved_at' => now()], '2026-01-05 00:00:00');

    $response = $this->actingAs($admin)->get(route('tickets.index', ['tab' => 'active']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('tickets.data.0.ticket_number', $t4->ticket_number)
        ->where('tickets.data.1.ticket_number', $t2->ticket_number)
        ->where('tickets.data.2.ticket_number', $t3->ticket_number)
        ->where('tickets.data.3.ticket_number', $t1->ticket_number)
        ->has('tickets.data', 4)
    );
});

test('pagination across a page boundary is exact even when it falls between two different statuses', function () {
    $admin = makeAdminUser();

    // Global order (newest first): open, in_progress, open, in_progress,
    // open, in_progress — rank 3/4 straddles the status boundary that a
    // naive per-status top-N(=perPage) merge would get wrong.
    $tickets = [];
    $statuses = [
        TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Open,
        TicketStatus::InProgress, TicketStatus::Open, TicketStatus::InProgress,
    ];
    foreach ($statuses as $i => $status) {
        // Newest first: ticket 0 is newest (day 6), ticket 5 is oldest (day 1).
        $tickets[] = ticketAt(['status' => $status->value], sprintf('2026-02-%02d 00:00:00', 6 - $i));
    }

    $allNumbers = array_merge(
        ticketNumbersOnPage($this, $admin, 1, 2),
        ticketNumbersOnPage($this, $admin, 2, 2),
        ticketNumbersOnPage($this, $admin, 3, 2),
    );

    expect($allNumbers)->toBe(array_map(fn (Ticket $t) => $t->ticket_number, $tickets));
});

/**
 * Decodes the raw Inertia XHR response (same technique as
 * TicketControllerTest's "tab defaults to active tickets only" test) so
 * the exact row order across pages can be compared directly, rather than
 * asserting one page's shape at a time.
 *
 * @return list<string>
 */
function ticketNumbersOnPage(TestCase $test, User $user, int $page, int $perPage): array
{
    $version = inertiaVersion();

    $response = $test->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->get(route('tickets.index', ['tab' => 'active', 'per_page' => $perPage, 'page' => $page]))
        ->assertOk();

    $decoded = json_decode($response->getContent(), true);

    return collect($decoded['props']['tickets']['data'])->pluck('ticket_number')->all();
}

test('total count reflects all matching active tickets summed across statuses, with a filter applied', function () {
    $admin = makeAdminUser();

    ticketAt(['status' => TicketStatus::Open->value, 'priority' => TicketPriority::Critical->value], '2026-03-01 00:00:00');
    ticketAt(['status' => TicketStatus::InProgress->value, 'priority' => TicketPriority::Critical->value], '2026-03-02 00:00:00');
    ticketAt(['status' => TicketStatus::WaitingForCustomer->value, 'priority' => TicketPriority::Critical->value], '2026-03-03 00:00:00');
    // Different priority — must not be counted.
    ticketAt(['status' => TicketStatus::Open->value, 'priority' => TicketPriority::Low->value], '2026-03-04 00:00:00');
    // Resolved — wrong tab, must not be counted even though priority matches.
    ticketAt(['status' => TicketStatus::Resolved->value, 'priority' => TicketPriority::Critical->value, 'resolved_at' => now()], '2026-03-05 00:00:00');

    $response = $this->actingAs($admin)->get(route('tickets.index', [
        'tab' => 'active',
        'priority' => TicketPriority::Critical->value,
        'per_page' => 1,
    ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('tickets.meta.total', 3));
});

test('the resolved tab merges across its two statuses the same way the active tab does', function () {
    $admin = makeAdminUser();

    $closed = ticketAt(['status' => TicketStatus::Closed->value], '2026-04-02 00:00:00');
    $resolved = ticketAt(['status' => TicketStatus::Resolved->value, 'resolved_at' => now()], '2026-04-01 00:00:00');
    ticketAt(['status' => TicketStatus::Open->value], '2026-04-03 00:00:00');

    $response = $this->actingAs($admin)->get(route('tickets.index', ['tab' => 'resolved']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('tickets.data', 2)
        ->where('tickets.data.0.ticket_number', $closed->ticket_number)
        ->where('tickets.data.1.ticket_number', $resolved->ticket_number)
    );
});

test('visibility scoping is still applied per status branch, not just globally', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $otherAgent = User::factory()->create();
    $otherAgent->assignRole(RoleEnum::Agent->value);

    $mine = ticketAt(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $agent->id], '2026-05-03 00:00:00');
    $unassigned = ticketAt(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => null], '2026-05-02 00:00:00');
    ticketAt(['status' => TicketStatus::WaitingForCustomer->value, 'assigned_agent_id' => $otherAgent->id], '2026-05-01 00:00:00');

    $response = $this->actingAs($agent)->get(route('tickets.index', ['tab' => 'active']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('tickets.data', 2)
        ->where('tickets.data.0.ticket_number', $mine->ticket_number)
        ->where('tickets.data.1.ticket_number', $unassigned->ticket_number)
    );
});

test('an agent queue page boundary is exact across both the status split and the mine/unassigned split', function () {
    // An agent's queue is branched two ways (status x mine-or-unassigned,
    // see TicketRepository::visibilityBranches) — this interleaves both
    // dimensions so a correct merge must still reconstruct one clean
    // created_at-desc ordering across all of them.
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $otherAgent = User::factory()->create();
    $otherAgent->assignRole(RoleEnum::Agent->value);

    $visible = [];
    // Newest first: mine/open, unassigned/in_progress, mine/waiting,
    // unassigned/open — each a different (status, ownership) branch.
    $visible[] = ticketAt(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $agent->id], '2026-06-04 00:00:00');
    $visible[] = ticketAt(['status' => TicketStatus::InProgress->value, 'assigned_agent_id' => null], '2026-06-03 00:00:00');
    $visible[] = ticketAt(['status' => TicketStatus::WaitingForCustomer->value, 'assigned_agent_id' => $agent->id], '2026-06-02 00:00:00');
    $visible[] = ticketAt(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null], '2026-06-01 00:00:00');
    // Must never appear: someone else's ticket, interleaved in time.
    ticketAt(['status' => TicketStatus::Open->value, 'assigned_agent_id' => $otherAgent->id], '2026-06-05 00:00:00');

    $allNumbers = array_merge(
        ticketNumbersOnPage($this, $agent, 1, 2),
        ticketNumbersOnPage($this, $agent, 2, 2),
    );

    expect($allNumbers)->toBe(array_map(fn (Ticket $t) => $t->ticket_number, $visible));
});
