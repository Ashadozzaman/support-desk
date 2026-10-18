<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * SLA policy editing is an admin-only, non-ownership ability (same shape
 * as ImportPolicy) — the controller authorizes every action against it.
 */
class SlaPolicyPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permission::SlaManage->value);
    }
}
