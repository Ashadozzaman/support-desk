<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Holidays share business-hours.manage — they're part of the same business
 * calendar admins edit together (ARCHITECTURE.md §8.1).
 */
class HolidayPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permission::BusinessHoursManage->value);
    }
}
