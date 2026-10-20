<?php

declare(strict_types=1);

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives the Step 22 permissions to the built-in roles on a database that
 * already has them. RolesAndPermissionsSeeder only grants agent/customer
 * defaults when it first creates those roles (so a re-run can't undo the
 * roles editor), which means it would never reach existing roles. A
 * migration runs exactly once — later edits in the roles editor stand.
 *
 * On a fresh database the roles don't exist yet when this runs; the seeder
 * creates them with these permissions included.
 */
return new class extends Migration
{
    /** @var array<string, list<PermissionEnum>> */
    private const GRANTS = [
        RoleEnum::Admin->value => [
            PermissionEnum::KnowledgeBaseManage,
            PermissionEnum::AiUseAssistant,
            PermissionEnum::AiChat,
            PermissionEnum::AiManage,
        ],
        RoleEnum::Agent->value => [PermissionEnum::AiUseAssistant],
        RoleEnum::Customer->value => [PermissionEnum::AiChat],
    ];

    public function up(): void
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', array_keys(self::GRANTS))
            ->with('permissions')
            ->get();

        foreach ($roles as $role) {
            $names = array_map(fn (PermissionEnum $permission) => $permission->value, self::GRANTS[$role->name]);

            foreach ($names as $name) {
                Permission::findOrCreate($name, 'web');
            }

            // One call per role: spatie reloads `permissions` after each grant.
            $role->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::GRANTS as $permissions) {
            foreach ($permissions as $permission) {
                Permission::query()->where('name', $permission->value)->where('guard_name', 'web')->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
