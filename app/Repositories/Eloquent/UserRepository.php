<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\DTOs\UserFilterData;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

class UserRepository implements UserRepositoryInterface
{
    public function paginateForAdmin(UserFilterData $filters, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->select(['id', 'name', 'email', 'is_active', 'created_at'])
            ->with(['roles:id,name', 'agentProfile:id,user_id,is_available,max_active_tickets', 'categories:id'])
            ->when($filters->role !== null, fn (Builder $query) => $query->role($filters->role))
            // ~5k users: a leading-wildcard LIKE scans the table, which is
            // fine at this size and lets "smith" match a name or an email.
            ->when($filters->search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($filters) {
                $inner->where('name', 'like', '%'.$filters->search.'%')
                    ->orWhere('email', 'like', '%'.$filters->search.'%');
            }))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForAdmin(int $id): User
    {
        return User::query()
            ->with(['roles:id,name', 'agentProfile', 'categories:id,name'])
            ->findOrFail($id);
    }

    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    public function update(User $user, array $attributes): void
    {
        $user->update($attributes);
    }

    public function syncRole(User $user, string $role): void
    {
        $user->syncRoles([$role]);
    }

    public function markEmailVerified(User $user): void
    {
        $user->forceFill(['email_verified_at' => now()])->save();
    }

    public function lockActiveIdsWithPermission(string $permission): array
    {
        // Two steps so the lock covers only the current holders' rows (by
        // primary key) instead of every user row the permission subqueries
        // scan. The second, locking read sees the latest committed roles, so
        // two admins demoting each other at once are serialised and the
        // second one counts what the first left behind.
        $ids = User::permission($permission)->where('is_active', true)->pluck('users.id');

        return User::permission($permission)
            ->where('is_active', true)
            ->whereIn('users.id', $ids)
            ->lockForUpdate()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function roleGrants(string $role, string $permission): bool
    {
        return Role::findByName($role, 'web')->hasPermissionTo($permission);
    }

    public function activeWithPermissions(string ...$permissions): Collection
    {
        $query = User::query()->where('is_active', true);

        foreach ($permissions as $permission) {
            $query->permission($permission);
        }

        return $query->get();
    }

    public function optionsWithPermission(string $permission): Collection
    {
        return User::permission($permission)->orderBy('name')->get(['id', 'name']);
    }

    public function assignableAgents(): Collection
    {
        return User::permission(Permission::TicketsClaim->value)
            ->where('is_active', true)
            ->has('agentProfile')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function roles(): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
