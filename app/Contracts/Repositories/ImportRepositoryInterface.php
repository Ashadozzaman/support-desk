<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface ImportRepositoryInterface
{
    public function create(User $admin, string $originalFilename, string $diskPath): Import;

    public function findOrFail(int $id): Import;

    /**
     * @return LengthAwarePaginator<int, Import>
     */
    public function paginateFor(User $admin, int $perPage = 15): LengthAwarePaginator;

    /**
     * Set once the job has streamed the file to count data rows (a cheap,
     * memory-bounded pass done before the chunked processing pass), so the
     * UI has a real denominator for a progress percentage.
     */
    public function setTotalRows(Import $import, int $totalRows): Import;

    /**
     * Atomic counter bump after a chunk finishes (ARCHITECTURE.md §13):
     * adds to processed/success/failed rather than overwriting, so retried
     * or concurrent chunk handling can never double-count incorrectly.
     */
    public function incrementCounters(Import $import, int $processed, int $success, int $failed): Import;

    public function markStatus(Import $import, ImportStatus $status): Import;

    /**
     * @param  list<array{row_number: int, errors: array<int, string>, raw_row: array<string, mixed>}>  $rows
     */
    public function recordErrors(Import $import, array $rows): void;
}
