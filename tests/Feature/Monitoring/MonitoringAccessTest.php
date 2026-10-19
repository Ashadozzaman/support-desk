<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function monitoringUser(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

test('only users with monitoring.view hold the permission — admins, not agents or customers', function () {
    expect(monitoringUser(RoleEnum::Admin)->can('monitoring.view'))->toBeTrue()
        ->and(monitoringUser(RoleEnum::Agent)->can('monitoring.view'))->toBeFalse()
        ->and(monitoringUser(RoleEnum::Customer)->can('monitoring.view'))->toBeFalse();
});

test('an admin sees the system health page with the check results', function () {
    $this->actingAs(monitoringUser(RoleEnum::Admin))
        ->get(route('admin.system-health'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/system-health/index')
            ->has('report.checks')
            ->where('report.checks', fn ($checks) => collect($checks)->pluck('name')->contains('SlaBacklog')));
});

test('agents and customers cannot open the system health page', function (RoleEnum $role) {
    $this->actingAs(monitoringUser($role))->get(route('admin.system-health'))->assertForbidden();
})->with([RoleEnum::Agent, RoleEnum::Customer]);

test('agents and customers cannot open Pulse', function (RoleEnum $role) {
    $this->actingAs(monitoringUser($role))->get('/pulse')->assertForbidden();
})->with([RoleEnum::Agent, RoleEnum::Customer]);

test('guests cannot open Pulse', function () {
    $this->get('/pulse')->assertForbidden();
});

test('the viewPulse gate follows the monitoring.view permission', function () {
    expect(monitoringUser(RoleEnum::Admin)->can('viewPulse'))->toBeTrue()
        ->and(monitoringUser(RoleEnum::Agent)->can('viewPulse'))->toBeFalse();
});
