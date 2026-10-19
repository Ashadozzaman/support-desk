<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AgentRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\DTOs\UserData;
use App\DTOs\UserFilterData;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Exceptions\UserLockoutException;
use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Admin user & agent management (Step 19d). Whether someone is an agent is
 * decided by their role's permissions (`tickets.claim`), not by the role's
 * name — so a custom role can be an agent role too.
 */
class UserManagementService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AgentRepositoryInterface $agents,
        private readonly AuditLogService $audit,
        private readonly PasswordBrokerFactory $passwords,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(UserFilterData $filters, int $perPage): LengthAwarePaginator
    {
        return $this->users->paginateForAdmin($filters, $perPage);
    }

    public function findForEdit(User $user): User
    {
        return $this->users->findForAdmin($user->id);
    }

    /**
     * Each role flagged with whether it can work tickets — the form then
     * shows the agent fields.
     *
     * @return list<array{value: string, label: string, works_tickets: bool}>
     */
    public function roleOptions(): array
    {
        return $this->users->roles()->map(fn (Role $role) => [
            'value' => $role->name,
            'label' => Str::headline($role->name),
            'works_tickets' => $role->permissions->contains('name', Permission::TicketsClaim->value),
        ])->values()->all();
    }

    /**
     * Roles plus categories for agent skills.
     *
     * @return array{roles: list<array{value: string, label: string, works_tickets: bool}>, categories: list<array{id: int, name: string}>}
     */
    public function formOptions(): array
    {
        return [
            'roles' => $this->roleOptions(),
            // Same direct read-only query as TicketService::categoryOptions().
            'categories' => Category::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])
                ->all(),
        ];
    }

    /**
     * The account gets a random password it never sees; the invite is a
     * password-reset link, sent only after commit so a rolled-back create
     * never emails anyone.
     */
    public function create(UserData $data, User $actor): User
    {
        $user = DB::transaction(function () use ($data, $actor) {
            $user = $this->users->create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => Str::password(32),
                'is_active' => $data->isActive,
            ]);
            $this->users->markEmailVerified($user);
            $this->users->syncRole($user, $data->role);
            $this->applyAgentProfile($user, $data);

            $this->audit->record(AuditEvent::UserCreated, $actor, $user, [
                'changes' => $this->diff([], $this->snapshot($this->users->findForAdmin($user->id))),
            ]);

            return $user;
        });

        if ($user->is_active) {
            $this->passwords->broker()->sendResetLink(['email' => $user->email]);
        }

        return $user;
    }

    public function update(User $user, UserData $data, User $actor): void
    {
        DB::transaction(function () use ($user, $data, $actor) {
            $this->guardAgainstLockout($user, $data, $actor);

            $before = $this->snapshot($this->users->findForAdmin($user->id));

            $this->users->update($user, [
                'name' => $data->name,
                'email' => $data->email,
                'is_active' => $data->isActive,
            ]);
            $this->users->syncRole($user, $data->role);
            $this->applyAgentProfile($user, $data);

            $changes = $this->diff($before, $this->snapshot($this->users->findForAdmin($user->id)));

            if ($changes !== []) {
                $this->audit->record(AuditEvent::UserUpdated, $actor, $user, ['changes' => $changes]);
            }
        });
    }

    /**
     * Runs inside the update transaction: the current `users.manage`
     * holders stay locked until commit, so two admins removing each other's
     * access at the same moment can't both succeed.
     */
    private function guardAgainstLockout(User $user, UserData $data, User $actor): void
    {
        if ($user->is($actor)) {
            if (! $data->isActive) {
                throw new UserLockoutException('You cannot deactivate your own account.', 'is_active');
            }

            if (! $actor->hasRole($data->role)) {
                throw new UserLockoutException('You cannot change your own role.', 'role');
            }
        }

        $managers = $this->users->lockActiveIdsWithPermission(Permission::UsersManage->value);

        $losesAccess = in_array($user->id, $managers, true)
            && (! $data->isActive || ! $this->users->roleGrants($data->role, Permission::UsersManage->value));

        if ($losesAccess && count($managers) <= 1) {
            throw new UserLockoutException(
                'This is the last active user who can manage users — give someone else that access first.',
                $data->isActive ? 'role' : 'is_active',
            );
        }
    }

    /**
     * A role without `tickets.claim` keeps an existing profile (history,
     * performance page) but is taken out of auto-assign.
     */
    private function applyAgentProfile(User $user, UserData $data): void
    {
        if (! $this->users->roleGrants($data->role, Permission::TicketsClaim->value)) {
            $this->agents->markUnavailable($user->id);

            return;
        }

        $this->agents->saveProfile($user->id, $data->agent->isAvailable, $data->agent->maxActiveTickets);
        $this->agents->syncCategories($user->id, $data->agent->categoryIds);
    }

    /**
     * What the audit row compares, grouped the way the audit page prints
     * it ("account: role agent → admin").
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshot(User $user): array
    {
        $snapshot = [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->pluck('name')->sort()->implode(', '),
                'is_active' => $user->is_active,
            ],
        ];

        if ($user->agentProfile !== null) {
            $snapshot['agent'] = [
                'is_available' => $user->agentProfile->is_available,
                'max_active_tickets' => $user->agentProfile->max_active_tickets,
                'categories' => $user->categories->pluck('name')->sort()->implode(', ') ?: 'all',
            ];
        }

        return $snapshot;
    }

    /**
     * Same shape as SlaService's audit diff: per group, only the fields
     * that changed, old and new.
     *
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<string, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $group => $fields) {
            foreach ($fields as $field => $value) {
                $old = $before[$group][$field] ?? null;

                if ($old !== $value) {
                    $changes[$group]['old'][$field] = $old;
                    $changes[$group]['new'][$field] = $value;
                }
            }
        }

        return $changes;
    }
}
