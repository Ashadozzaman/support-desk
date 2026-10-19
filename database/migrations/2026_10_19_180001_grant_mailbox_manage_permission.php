<?php

declare(strict_types=1);

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives `mailbox.manage` to the existing admin role, as
 * 2026_10_19_140002 did for the Step 22 permissions (the seeder never
 * touches roles that already exist). On a fresh database the role doesn't
 * exist yet and the seeder grants it every permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        $admin = Role::query()->where('guard_name', 'web')->where('name', RoleEnum::Admin->value)->first();

        if ($admin !== null) {
            Permission::findOrCreate(PermissionEnum::MailboxManage->value, 'web');
            $admin->givePermissionTo(PermissionEnum::MailboxManage->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', PermissionEnum::MailboxManage->value)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
