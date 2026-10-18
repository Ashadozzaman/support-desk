<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ReportTab;
use App\Models\User;

/**
 * Reports have no model, so these are registered as named abilities in
 * AppServiceProvider (Gate::define). Permission checks only — the per-row
 * scoping of the Agents tab lives in ReportService::agentPerformance(), so a
 * forgotten authorize() still can't leak another agent's numbers.
 */
class ReportPolicy
{
    /**
     * Admins see every report; agents get the Reports page for their own
     * performance row only.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ReportsView->value) || $user->can(Permission::ReportsViewOwn->value);
    }

    public function viewTab(User $user, ReportTab $tab): bool
    {
        if ($user->can(Permission::ReportsView->value)) {
            return true;
        }

        return $tab === ReportTab::Agents && $user->can(Permission::ReportsViewOwn->value);
    }

    /**
     * CSV exports (summary and ticket-level) are admin-only.
     */
    public function export(User $user): bool
    {
        return $user->can(Permission::ReportsExport->value);
    }
}
