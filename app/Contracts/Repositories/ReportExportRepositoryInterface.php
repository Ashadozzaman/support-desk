<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;

interface ReportExportRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function create(int $userId, array $filters, CarbonImmutable $expiresAt): ReportExport;

    public function find(int $id): ?ReportExport;

    public function markStatus(ReportExport $export, ReportExportStatus $status): void;

    public function markCompleted(ReportExport $export, string $diskPath, int $rowCount): void;
}
