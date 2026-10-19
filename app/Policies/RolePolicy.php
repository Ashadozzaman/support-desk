<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Roles editor (Step 19e). Which roles may be changed or deleted is a
 * business rule, enforced in RoleService (ProtectedRoleException).
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesManage->value);
    }
}
