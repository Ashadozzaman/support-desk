<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MailboxSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One mailbox sync (docs/IMAP.md §1), from `mailbox:sync` or "Sync now".
 * Unique: a second request while one is queued or running is dropped, so
 * two syncs never fetch the same messages at once.
 */
class SyncMailboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Not retried: the next scheduled sync is the retry, and the cursor only moves on success.
    public int $tries = 1;

    public int $timeout = 120;

    /** Longer than $timeout, so a lost worker can't block syncing for the cache's 24h default. */
    public int $uniqueFor = 300;

    public function __construct(public readonly ?int $triggeredBy = null)
    {
        $this->queue = 'mail';
    }

    public function uniqueId(): string
    {
        return 'mailbox-sync';
    }

    public function handle(MailboxSyncService $syncs): void
    {
        $syncs->sync($this->triggeredBy);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('mailbox.sync_job_failed', ['exception' => $exception::class]);
    }
}
