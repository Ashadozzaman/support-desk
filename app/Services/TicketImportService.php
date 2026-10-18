<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ImportRepositoryInterface;
use App\Jobs\ProcessTicketImportJob;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketImportService
{
    public function __construct(private readonly ImportRepositoryInterface $imports) {}

    /**
     * Stores the file on the private disk and creates the `imports` row
     * synchronously (fast — no parsing happens here); the actual streaming
     * and row processing is queued so a large file never blocks the
     * request (QUEUE_SCHEDULER_REALTIME.md §1).
     */
    public function start(UploadedFile $file, User $admin): Import
    {
        return DB::transaction(function () use ($file, $admin) {
            $diskPath = $file->storeAs('imports', Str::uuid()->toString().'.csv', 'local');

            $import = $this->imports->create($admin, $file->getClientOriginalName(), $diskPath);

            ProcessTicketImportJob::dispatch($import->id)->afterCommit();

            return $import;
        });
    }

    public function find(int $id): Import
    {
        return $this->imports->findOrFail($id);
    }

    /**
     * @return LengthAwarePaginator<int, Import>
     */
    public function paginateFor(User $admin, int $perPage = 15): LengthAwarePaginator
    {
        return $this->imports->paginateFor($admin, $perPage);
    }
}
