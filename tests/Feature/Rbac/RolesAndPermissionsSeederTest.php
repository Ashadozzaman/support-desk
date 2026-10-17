<?php

declare(strict_types=1);

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('seeder is idempotent: running it twice does not duplicate roles or permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::count())->toBe(3);
    expect(Permission::count())->toBe(count(PermissionEnum::cases()));
});

test('admin role has every permission', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = Role::findByName(RoleEnum::Admin->value);

    expect($admin->permissions()->count())->toBe(count(PermissionEnum::cases()));
});

test('customer role cannot claim, assign, or manage anything staff/admin-only', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $customer = Role::findByName(RoleEnum::Customer->value);
    $permissionNames = $customer->permissions()->pluck('name');

    expect($permissionNames)
        ->not->toContain(PermissionEnum::TicketsClaim->value)
        ->not->toContain(PermissionEnum::TicketsAssign->value)
        ->not->toContain(PermissionEnum::NotesCreate->value)
        ->not->toContain(PermissionEnum::NotesView->value)
        ->not->toContain(PermissionEnum::ImportsManage->value);
});
