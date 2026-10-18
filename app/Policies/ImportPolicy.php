<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * CSV import is an admin-only feature with a single ability; the controller
 * (Step 14) authorizes every action against it.
 */
class ImportPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permission::ImportsManage->value);
    }
}
