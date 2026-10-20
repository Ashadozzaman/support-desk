<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Inbound\MailboxClient;
use App\Contracts\Repositories\MailboxRepositoryInterface;
use App\Enums\InboundMessageStatus;
use App\Enums\MailboxSyncStatus;
use App\Exceptions\MailboxConnectionException;
use App\Jobs\ProcessInboundEmailJob;
use App\Jobs\SyncMailboxJob;
use App\Models\InboundMessage;
use App\Models\MailboxSync;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches new mail and queues one job per message (docs/IMAP.md §1). The
 * IMAP call runs outside any transaction (it can take seconds); only the
 * cursor move + job dispatch are one transaction.
 */
class MailboxSyncService
{
    /** Raw messages wait here (private disk) for their job; a job payload stays small. */
    private const SPOOL_DIRECTORY = 'inbound';

    public function __construct(
        private readonly MailboxRepositoryInterface $mailbox,
        private readonly MailboxSettingsService $settings,
        private readonly MailboxClient $client,
    ) {}

    /** Enabled, and the configured interval has passed since the last sync. */
    public function isDue(): bool
    {
        $settings = $this->mailbox->settings();

        if ($settings === null || ! $settings->is_enabled) {
            return false;
        }

        return $settings->last_synced_at === null
            || $settings->last_synced_at->lte(now()->subMinutes($settings->fetch_interval_minutes));
    }

    /**
     * Queues a sync. SyncMailboxJob is unique, so a sync that is already
     * queued or running is not started twice. False when the mailbox is off.
     */
    public function requestSync(?User $triggeredBy): bool
    {
        if (! $this->settings->current()->is_enabled) {
            return false;
        }

        SyncMailboxJob::dispatch($triggeredBy?->id);

        return true;
    }

    public function sync(?int $triggeredBy): ?MailboxSync
    {
        $settings = $this->mailbox->settings();

        if ($settings === null || ! $settings->is_enabled) {
            return null;
        }

        $sync = $this->mailbox->startSync($triggeredBy);
        // The first sync of a mailbox (or after its UIDs were reset) only records where to start.
        $afterUid = $settings->last_uid;

        try {
            $result = $this->client->fetch($this->settings->connectionFor($settings), $afterUid, $settings->batch_size);
        } catch (MailboxConnectionException $e) {
            // last_synced_at moves on failure too, so a broken mailbox is retried at the
            // configured interval, not every minute.
            $this->mailbox->saveSettings(['last_synced_at' => now()]);
            $this->mailbox->updateSync($sync, ['status' => MailboxSyncStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()]);
            Log::warning('mailbox.sync_failed', ['sync_id' => $sync->id, 'reason' => $e->getMessage(), 'causes' => $e->causes()]);

            return $sync;
        }

        if ($afterUid === null || $settings->uid_validity !== $result->uidValidity) {
            // UIDVALIDITY changed = the server renumbered the folder: old UIDs are meaningless.
            // Never import a mailbox's history — start from its newest message.
            DB::transaction(function () use ($sync, $result) {
                $this->mailbox->saveSettings([
                    'last_uid' => $result->highestUid,
                    'uid_validity' => $result->uidValidity,
                    'last_synced_at' => now(),
                ]);
                $this->mailbox->updateSync($sync, ['status' => MailboxSyncStatus::Baseline, 'finished_at' => now()]);
            });

            return $sync;
        }

        $paths = $this->spool($result->messages);

        try {
            DB::transaction(function () use ($sync, $paths, $afterUid) {
                $this->mailbox->saveSettings([
                    'last_uid' => $paths === [] ? $afterUid : max(array_keys($paths)),
                    'last_synced_at' => now(),
                ]);
                $this->mailbox->updateSync($sync, [
                    'status' => MailboxSyncStatus::Completed,
                    'fetched_count' => count($paths),
                    'finished_at' => now(),
                ]);

                foreach ($paths as $path) {
                    ProcessInboundEmailJob::dispatch($path, $sync->id)->afterCommit();
                }
            });
        } catch (Throwable $e) {
            // Cursor not moved → the same messages are fetched again next time; drop this copy.
            Storage::disk('local')->delete(array_values($paths));

            throw $e;
        }

        return $sync;
    }

    /**
     * Counts what one queued message became, on its sync's history row.
     */
    public function recordOutcome(int $syncId, ?InboundMessage $message): void
    {
        $column = match ($message?->status) {
            null => 'duplicate_count',
            InboundMessageStatus::TicketCreated => 'created_count',
            InboundMessageStatus::ReplyAdded => 'replied_count',
            InboundMessageStatus::Skipped => 'skipped_count',
        };

        $this->mailbox->incrementSyncCounter($syncId, $column);
    }

    public function recordFailure(int $syncId): void
    {
        $this->mailbox->incrementSyncCounter($syncId, 'failed_count');
    }

    /**
     * @return Collection<int, MailboxSync>
     */
    public function history(int $limit = 20): Collection
    {
        return $this->mailbox->recentSyncs($limit);
    }

    /**
     * @param  array<int, string>  $messages  UID => raw message
     * @return array<int, string> UID => path on the private disk
     */
    private function spool(array $messages): array
    {
        $paths = [];

        foreach ($messages as $uid => $raw) {
            $path = self::SPOOL_DIRECTORY.'/'.Str::uuid()->toString().'.eml';
            Storage::disk('local')->put($path, $raw);
            $paths[$uid] = $path;
        }

        return $paths;
    }
}
