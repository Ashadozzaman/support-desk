<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to re-run (e.g. after adding a permission) without
 * duplicating roles/permissions or losing existing assignments elsewhere.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permissions granted to the agent role (admin gets every permission).
     *
     * @var list<PermissionEnum>
     */
    private const AGENT_PERMISSIONS = [
        PermissionEnum::TicketsViewQueue,
        PermissionEnum::TicketsCreate,
        PermissionEnum::TicketsUpdate,
        PermissionEnum::TicketsChangeStatus,
        PermissionEnum::TicketsClaim,
        PermissionEnum::RepliesCreate,
        PermissionEnum::NotesCreate,
        PermissionEnum::NotesView,
        PermissionEnum::AttachmentsUpload,
        PermissionEnum::DashboardViewOwn,
        PermissionEnum::ReportsViewOwn,
    ];

    /**
     * Permissions granted to the customer role.
     *
     * @var list<PermissionEnum>
     */
    private const CUSTOMER_PERMISSIONS = [
        PermissionEnum::TicketsViewOwn,
        PermissionEnum::TicketsCreate,
        PermissionEnum::TicketsUpdateOwn,
        PermissionEnum::RepliesCreate,
        PermissionEnum::AttachmentsUpload,
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::values() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => RoleEnum::Admin->value, 'guard_name' => 'web']);
        $agent = Role::firstOrCreate(['name' => RoleEnum::Agent->value, 'guard_name' => 'web']);
        $customer = Role::firstOrCreate(['name' => RoleEnum::Customer->value, 'guard_name' => 'web']);

        $admin->syncPermissions(PermissionEnum::values());
        $agent->syncPermissions(array_map(fn (PermissionEnum $p) => $p->value, self::AGENT_PERMISSIONS));
        $customer->syncPermissions(array_map(fn (PermissionEnum $p) => $p->value, self::CUSTOMER_PERMISSIONS));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
