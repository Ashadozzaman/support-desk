<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    /** Admin Users page (Step 19d). Lockout rules live in UserManagementService. */
    public function viewAny(User $viewer): bool
    {
        return $viewer->can(Permission::UsersManage->value);
    }

    public function create(User $viewer): bool
    {
        return $viewer->can(Permission::UsersManage->value);
    }

    public function update(User $viewer, User $user): bool
    {
        return $viewer->can(Permission::UsersManage->value);
    }

    public function viewAnyPerformance(User $viewer): bool
    {
        return $viewer->can(Permission::UsersManage->value);
    }

    /**
     * Permission check (same gate as the list) plus "is an agent" — has an
     * agent profile, the same source the list is built from, so a demoted
     * agent's history stays reachable. A customer/admin id in the URL is
     * denied rather than rendering an empty page.
     */
    public function viewPerformance(User $viewer, User $agent): bool
    {
        return $viewer->can(Permission::UsersManage->value) && $agent->agentProfile()->exists();
    }
}
