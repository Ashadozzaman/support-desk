<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Models\User;

class ReportExportPolicy
{
    /**
     * The signed URL proves the link wasn't tampered with or expired; this
     * proves the person holding it is the one who asked for the export
     * (a forwarded email link is useless to anyone else).
     */
    public function download(User $user, ReportExport $export): bool
    {
        return $user->can(Permission::ReportsExport->value)
            && $export->user_id === $user->id
            && $export->status === ReportExportStatus::Completed;
    }
}
