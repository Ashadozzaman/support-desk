<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MailboxSyncService;
use Illuminate\Console\Command;

/**
 * Scheduled every minute (QUEUE_SCHEDULER_REALTIME.md §3); queues a sync
 * only when the admin's fetch interval has passed. --now ignores the interval.
 */
class SyncMailbox extends Command
{
    protected $signature = 'mailbox:sync {--now : Sync now, ignoring the fetch interval}';

    protected $description = 'Queue a support-mailbox sync when one is due.';

    public function handle(MailboxSyncService $syncs): int
    {
        if (! $this->option('now') && ! $syncs->isDue()) {
            $this->info('Mailbox disabled or not due yet.');

            return self::SUCCESS;
        }

        $this->info($syncs->requestSync(null) ? 'Mailbox sync queued.' : 'Mailbox is disabled.');

        return self::SUCCESS;
    }
}
