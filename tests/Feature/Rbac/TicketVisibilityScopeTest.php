<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Ticket::scopeVisibleTo is the repository-level defence-in-depth list
 * scope (ARCHITECTURE.md §3 enforcement point 3) — a separate code path
 * from TicketPolicy, so it needs its own coverage.
 */
test('customer list scope only returns their own tickets', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $otherCustomer = User::factory()->create();
    $otherCustomer->assignRole(RoleEnum::Customer->value);

    $own = Ticket::factory()->create(['created_by' => $customer->id]);
    Ticket::factory()->create(['created_by' => $otherCustomer->id]);

    $visible = Ticket::query()->visibleTo($customer)->pluck('id');

    expect($visible)->toEqual(collect([$own->id]));
});

test('agent list scope returns assigned-to-them or unassigned tickets, never another agent\'s', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    $otherAgent = User::factory()->create();
    $otherAgent->assignRole(RoleEnum::Agent->value);

    $assignedToMe = Ticket::factory()->create(['assigned_agent_id' => $agent->id]);
    $unassigned = Ticket::factory()->create(['assigned_agent_id' => null]);
    $assignedToOther = Ticket::factory()->create(['assigned_agent_id' => $otherAgent->id]);

    $visible = Ticket::query()->visibleTo($agent)->pluck('id')->sort()->values();

    expect($visible)->toEqual(collect([$assignedToMe->id, $unassigned->id])->sort()->values());
    expect($visible)->not->toContain($assignedToOther->id);
});

test('admin list scope returns every ticket', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    Ticket::factory()->count(3)->create();

    expect(Ticket::query()->visibleTo($admin)->count())->toBe(3);
});
