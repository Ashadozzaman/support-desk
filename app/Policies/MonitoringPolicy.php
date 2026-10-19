<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * No model behind monitoring, so this backs two named abilities registered
 * in AppServiceProvider: `viewSystemHealth` (admin System health page) and
 * `viewPulse` (the gate Laravel Pulse checks for /pulse).
 */
class MonitoringPolicy
{
    public function view(User $user): bool
    {
        return $user->can(Permission::MonitoringView->value);
    }
}
