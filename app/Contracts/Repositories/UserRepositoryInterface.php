<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTOs\UserFilterData;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

interface UserRepositoryInterface
{
    /**
     * Admin user list, newest first, with roles and agent profile loaded.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateForAdmin(UserFilterData $filters, int $perPage): LengthAwarePaginator;

    /** The user with roles, agent profile and categories loaded (edit form, audit "before"). */
    public function findForAdmin(int $id): User;

    /**
     * @param  array{name: string, email: string, password: string, is_active: bool}  $attributes
     */
    public function create(array $attributes): User;

    /**
     * @param  array{name: string, email: string, is_active: bool}  $attributes
     */
    public function update(User $user, array $attributes): void;

    /** Replaces every role the user has with exactly this one. */
    public function syncRole(User $user, string $role): void;

    /** Marks an admin-created account verified — the admin vouches for the address. */
    public function markEmailVerified(User $user): void;

    /**
     * Active users holding `$permission` (via any role or directly), with
     * their rows locked FOR UPDATE until the caller's transaction ends.
     *
     * @return list<int>
     */
    public function lockActiveIdsWithPermission(string $permission): array;

    public function roleGrants(string $role, string $permission): bool;

    /**
     * Every role with its permission names loaded.
     *
     * @return Collection<int, Role>
     */
    public function roles(): Collection;
}
