<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAnyPerformance(User $viewer): bool
    {
        return $viewer->can(Permission::UsersManage->value);
    }

    /**
     * Permission check (admin-only, same gate as the list) plus a role
     * check — the performance page only makes sense for an agent, so a
     * customer/admin id in the URL is denied rather than rendering an
     * empty page.
     */
    public function viewPerformance(User $viewer, User $agent): bool
    {
        return $viewer->can(Permission::UsersManage->value) && $agent->hasRole(Role::Agent->value);
    }
}
