<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

interface RoleRepositoryInterface
{
    /**
     * Every role with `users_count` and its permission names loaded.
     *
     * @return Collection<int, Role>
     */
    public function allWithCounts(): Collection;

    public function findForEdit(int $id): Role;

    public function create(string $name): Role;

    public function rename(Role $role, string $name): void;

    /**
     * @param  list<string>  $permissions
     */
    public function syncPermissions(Role $role, array $permissions): void;

    public function delete(Role $role): void;

    public function userCount(Role $role): int;

    /**
     * @return list<int>
     */
    public function userIdsWithRole(Role $role): array;

    /** Spatie caches roles/permissions; call after commit. */
    public function bustCache(): void;
}
