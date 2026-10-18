<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ImportRepositoryInterface;
use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ImportRepository implements ImportRepositoryInterface
{
    public function create(User $admin, string $originalFilename, string $diskPath): Import
    {
        return Import::create([
            'user_id' => $admin->id,
            'status' => ImportStatus::Pending,
            'original_filename' => $originalFilename,
            'disk_path' => $diskPath,
        ]);
    }

    public function findOrFail(int $id): Import
    {
        return Import::with('user')->findOrFail($id);
    }

    public function paginateFor(User $admin, int $perPage = 15): LengthAwarePaginator
    {
        return Import::query()
            ->where('user_id', $admin->id)
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function setTotalRows(Import $import, int $totalRows): Import
    {
        $import->update(['total_rows' => $totalRows]);

        return $import;
    }

    public function incrementCounters(Import $import, int $processed, int $success, int $failed): Import
    {
        $import->increment('processed_rows', $processed);
        $import->increment('success_rows', $success);
        $import->increment('failed_rows', $failed);

        return $import;
    }

    public function markStatus(Import $import, ImportStatus $status): Import
    {
        $import->update(['status' => $status]);

        return $import;
    }

    public function recordErrors(Import $import, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $import->importErrors()->insert(array_map(fn (array $row) => [
            'import_id' => $import->id,
            'row_number' => $row['row_number'],
            'errors' => json_encode($row['errors']),
            'raw_row' => json_encode($row['raw_row']),
        ], $rows));
    }
}
