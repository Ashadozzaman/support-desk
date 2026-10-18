<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ReportExportRepositoryInterface;
use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;

class ReportExportRepository implements ReportExportRepositoryInterface
{
    public function create(int $userId, array $filters, CarbonImmutable $expiresAt): ReportExport
    {
        return ReportExport::query()->create([
            'user_id' => $userId,
            'status' => ReportExportStatus::Pending,
            'filters' => $filters,
            'expires_at' => $expiresAt,
        ]);
    }

    public function find(int $id): ?ReportExport
    {
        return ReportExport::query()->find($id);
    }

    public function markStatus(ReportExport $export, ReportExportStatus $status): void
    {
        $export->update(['status' => $status]);
    }

    public function markCompleted(ReportExport $export, string $diskPath, int $rowCount): void
    {
        $export->update([
            'status' => ReportExportStatus::Completed,
            'disk_path' => $diskPath,
            'row_count' => $rowCount,
        ]);
    }
}
