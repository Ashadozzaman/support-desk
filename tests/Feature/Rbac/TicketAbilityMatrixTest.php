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
 * role × ability × ticket relationship → expected allow/deny
 * (ARCHITECTURE.md §3). "other" = a different customer's ticket;
 * "assignedToSelf"/"assignedToOther" relate to the acting agent.
 */
dataset('ticket_ability_matrix', [
    'admin can view another customer\'s ticket' => [RoleEnum::Admin, 'view', 'other', true],
    'admin can view an unassigned ticket' => [RoleEnum::Admin, 'view', 'unassigned', true],
    'admin can update any ticket' => [RoleEnum::Admin, 'update', 'other', true],
    'admin can change status of any ticket' => [RoleEnum::Admin, 'changeStatus', 'other', true],
    'admin can claim an unassigned ticket' => [RoleEnum::Admin, 'claim', 'unassigned', true],
    'admin cannot claim an already-assigned ticket' => [RoleEnum::Admin, 'claim', 'assignedToOther', false],
    'admin can assign any ticket' => [RoleEnum::Admin, 'assign', 'other', true],

    'agent can view their own assigned ticket' => [RoleEnum::Agent, 'view', 'assignedToSelf', true],
    'agent can view an unassigned ticket (queue)' => [RoleEnum::Agent, 'view', 'unassigned', true],
    'agent cannot view a ticket assigned to another agent' => [RoleEnum::Agent, 'view', 'assignedToOther', false],
    'agent can update their own assigned ticket' => [RoleEnum::Agent, 'update', 'assignedToSelf', true],
    'agent cannot update a ticket assigned to another agent' => [RoleEnum::Agent, 'update', 'assignedToOther', false],
    'agent cannot update an unassigned ticket' => [RoleEnum::Agent, 'update', 'unassigned', false],
    'agent can change status of their own assigned ticket' => [RoleEnum::Agent, 'changeStatus', 'assignedToSelf', true],
    'agent cannot change status of another agent\'s ticket' => [RoleEnum::Agent, 'changeStatus', 'assignedToOther', false],
    'agent can claim an unassigned ticket' => [RoleEnum::Agent, 'claim', 'unassigned', true],
    'agent cannot claim an already-assigned ticket' => [RoleEnum::Agent, 'claim', 'assignedToOther', false],
    'agent cannot assign tickets' => [RoleEnum::Agent, 'assign', 'unassigned', false],

    'customer can view their own ticket' => [RoleEnum::Customer, 'view', 'own', true],
    'customer cannot view another customer\'s ticket' => [RoleEnum::Customer, 'view', 'other', false],
    'customer cannot view an unassigned ticket they do not own' => [RoleEnum::Customer, 'view', 'unassigned', false],
    'customer can update their own (open) ticket' => [RoleEnum::Customer, 'update', 'own', true],
    'customer cannot update another customer\'s ticket' => [RoleEnum::Customer, 'update', 'other', false],
    'customer cannot change ticket status' => [RoleEnum::Customer, 'changeStatus', 'own', false],
    'customer cannot claim tickets' => [RoleEnum::Customer, 'claim', 'unassigned', false],
    'customer cannot assign tickets' => [RoleEnum::Customer, 'assign', 'own', false],
]);

test('ticket ability matrix', function (RoleEnum $role, string $ability, string $ticketKind, bool $expected) {
    $actor = User::factory()->create();
    $actor->assignRole($role->value);

    $otherAgent = User::factory()->create();
    $otherAgent->assignRole(RoleEnum::Agent->value);

    $otherCustomer = User::factory()->create();
    $otherCustomer->assignRole(RoleEnum::Customer->value);

    $ticket = match ($ticketKind) {
        'own' => Ticket::factory()->create(['created_by' => $actor->id]),
        'other' => Ticket::factory()->create(['created_by' => $otherCustomer->id]),
        'unassigned' => Ticket::factory()->create(['created_by' => $otherCustomer->id, 'assigned_agent_id' => null]),
        'assignedToSelf' => Ticket::factory()->create(['created_by' => $otherCustomer->id, 'assigned_agent_id' => $actor->id]),
        'assignedToOther' => Ticket::factory()->create(['created_by' => $otherCustomer->id, 'assigned_agent_id' => $otherAgent->id]),
    };

    expect($actor->can($ability, $ticket))->toBe($expected);
})->with('ticket_ability_matrix');

test('any authenticated role with tickets.create can create a ticket', function (RoleEnum $role, bool $expected) {
    $actor = User::factory()->create();
    $actor->assignRole($role->value);

    expect($actor->can('create', Ticket::class))->toBe($expected);
})->with([
    'admin' => [RoleEnum::Admin, true],
    'agent' => [RoleEnum::Agent, true],
    'customer' => [RoleEnum::Customer, true],
]);
