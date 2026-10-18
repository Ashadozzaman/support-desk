<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Import;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after each chunk so the admin's import/show page can update its
 * progress bar live (QUEUE_SCHEDULER_REALTIME.md §4). Only counts/status
 * are sent — never row data.
 */
class ImportProgressUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(public readonly Import $import) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("imports.{$this->import->id}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'import_id' => $this->import->id,
            'status' => $this->import->status->value,
            'total_rows' => $this->import->total_rows,
            'processed_rows' => $this->import->processed_rows,
            'success_rows' => $this->import->success_rows,
            'failed_rows' => $this->import->failed_rows,
        ];
    }
}
