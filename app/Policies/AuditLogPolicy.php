<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Backs the `viewAuditLog` ability (AppServiceProvider). Named ability
 * rather than a model policy: the rows are spatie's Activity model, and only
 * the audit-log page reads them.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::AuditView->value);
    }
}
