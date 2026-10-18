<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class BusinessHourPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permission::BusinessHoursManage->value);
    }
}
