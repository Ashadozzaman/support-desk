<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\DTOs\RoleData;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Exceptions\ProtectedRoleException;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Roles editor (Step 19e). Permissions are fixed in code (the Permission
 * enum — policies check them by name); admins only combine them into roles.
 */
class RoleService
{
    /**
     * Removing these from a role must leave at least one active user who
     * still holds them, or nobody could manage users/roles any more.
     *
     * @var list<Permission>
     */
    private const LOCKOUT_PERMISSIONS = [Permission::UsersManage, Permission::RolesManage];

    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return Collection<int, Role>
     */
    public function all(): Collection
    {
        return $this->roles->allWithCounts();
    }

    public function findForEdit(Role $role): Role
    {
        return $this->roles->findForEdit($role->id);
    }

    /**
     * Checkbox grid for the form, grouped by the part before the dot.
     *
     * @return list<array{group: string, label: string, permissions: list<array{value: string, label: string}>}>
     */
    public function permissionGroups(): array
    {
        $groups = [];

        foreach (Permission::cases() as $permission) {
            $groups[$permission->group()][] = ['value' => $permission->value, 'label' => $permission->label()];
        }

        return array_map(
            fn (string $group, array $permissions) => ['group' => $group, 'label' => Str::headline($group), 'permissions' => $permissions],
            array_keys($groups),
            array_values($groups),
        );
    }

    public function create(RoleData $data, User $actor): Role
    {
        $role = DB::transaction(function () use ($data, $actor) {
            $role = $this->roles->create($data->name);
            $this->roles->syncPermissions($role, $data->permissions);

            $this->audit->record(AuditEvent::RoleCreated, $actor, properties: [
                'changes' => $this->diff(null, [], $data->name, $data->permissions),
            ]);

            return $role;
        });

        $this->roles->bustCache();

        return $role;
    }

    public function update(Role $role, RoleData $data, User $actor): void
    {
        if ($role->name === RoleEnum::Admin->value) {
            throw new ProtectedRoleException('The admin role always has every permission and can\'t be changed.', 'permissions');
        }

        if (RoleEnum::isSystem($role->name) && $data->name !== $role->name) {
            throw new ProtectedRoleException('Built-in roles can\'t be renamed.', 'name');
        }

        DB::transaction(function () use ($role, $data, $actor) {
            $oldName = $role->name;
            $oldPermissions = $this->roles->findForEdit($role->id)->permissions->pluck('name')->all();

            $this->guardAgainstLockout($role, array_diff($oldPermissions, $data->permissions));

            if ($data->name !== $oldName) {
                $this->roles->rename($role, $data->name);
            }
            $this->roles->syncPermissions($role, $data->permissions);

            $changes = $this->diff($oldName, $oldPermissions, $data->name, $data->permissions);

            if ($changes !== []) {
                $this->audit->record(AuditEvent::RoleUpdated, $actor, properties: ['changes' => $changes]);
            }
        });

        $this->roles->bustCache();
    }

    public function delete(Role $role, User $actor): void
    {
        if (RoleEnum::isSystem($role->name)) {
            throw new ProtectedRoleException('Built-in roles can\'t be deleted.', 'role');
        }

        DB::transaction(function () use ($role, $actor) {
            // Checked inside the transaction so a user given this role a moment
            // ago isn't left with no role: the count reads committed data and
            // the delete then cascades only what was counted.
            if ($this->roles->userCount($role) > 0) {
                throw new ProtectedRoleException('Move this role\'s users to another role before deleting it.', 'role');
            }

            $permissions = $this->roles->findForEdit($role->id)->permissions->pluck('name')->all();
            $this->roles->delete($role);

            $this->audit->record(AuditEvent::RoleDeleted, $actor, properties: [
                'changes' => $this->diff($role->name, $permissions, null, []),
            ]);
        });

        $this->roles->bustCache();
    }

    /**
     * Same locking idea as UserManagementService: the current holders' rows
     * stay locked until commit, so two concurrent edits can't each remove
     * the "other" last holder.
     *
     * @param  array<int, string>  $removed  permission names being taken off the role
     */
    private function guardAgainstLockout(Role $role, array $removed): void
    {
        $roleUserIds = null;

        foreach (self::LOCKOUT_PERMISSIONS as $permission) {
            if (! in_array($permission->value, $removed, true)) {
                continue;
            }

            $roleUserIds ??= $this->roles->userIdsWithRole($role);
            $holders = $this->users->lockActiveIdsWithPermission($permission->value);

            if (array_diff($holders, $roleUserIds) === []) {
                throw new ProtectedRoleException(
                    "Nobody else would be left with “{$permission->label()}” — give it to another active user first.",
                    'permissions',
                );
            }
        }
    }

    /**
     * Audit shape shared with SlaService/UserManagementService: per group,
     * only what changed. Permissions are listed one by one as granted
     * yes/no, so the audit page prints "permissions: notes.view yes → no".
     *
     * @param  array<int, string>  $oldPermissions
     * @param  array<int, string>  $newPermissions
     * @return array<string, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    private function diff(?string $oldName, array $oldPermissions, ?string $newName, array $newPermissions): array
    {
        $changes = [];

        if ($oldName !== $newName) {
            $changes['role'] = ['old' => ['name' => $oldName], 'new' => ['name' => $newName]];
        }

        $granted = array_values(array_diff($newPermissions, $oldPermissions));
        $revoked = array_values(array_diff($oldPermissions, $newPermissions));

        foreach ($granted as $permission) {
            $changes['permissions']['old'][$permission] = false;
            $changes['permissions']['new'][$permission] = true;
        }

        foreach ($revoked as $permission) {
            $changes['permissions']['old'][$permission] = true;
            $changes['permissions']['new'][$permission] = false;
        }

        return $changes;
    }
}
