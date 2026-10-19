<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleRepository implements RoleRepositoryInterface
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function allWithCounts(): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->withCount('users')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();
    }

    public function findForEdit(int $id): Role
    {
        return Role::query()->with('permissions:id,name')->withCount('users')->findOrFail($id);
    }

    public function create(string $name): Role
    {
        return Role::create(['name' => $name, 'guard_name' => 'web']);
    }

    public function rename(Role $role, string $name): void
    {
        $role->update(['name' => $name]);
    }

    public function syncPermissions(Role $role, array $permissions): void
    {
        $role->syncPermissions($permissions);
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }

    public function userCount(Role $role): int
    {
        return $role->users()->count();
    }

    public function userIdsWithRole(Role $role): array
    {
        return User::role($role->name)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    public function bustCache(): void
    {
        $this->registrar->forgetCachedPermissions();
    }
}
