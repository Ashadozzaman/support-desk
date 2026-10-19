<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\InboundMessageService;
use App\Services\MailboxSettingsService;
use App\Services\MailboxSyncService;
use App\Support\Inbound\EmailMessageParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One fetched email → ticket or reply. Safe to retry: InboundMessageService
 * dedupes on the Message-ID, so a second run after a partial failure
 * inserts nothing new. The spooled file is deleted only after success.
 */
class ProcessInboundEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public int $timeout = 120;

    public function __construct(
        public readonly string $path,
        public readonly int $syncId,
    ) {
        $this->queue = 'mail';
    }

    public function handle(
        EmailMessageParser $parser,
        InboundMessageService $inbound,
        MailboxSyncService $syncs,
        MailboxSettingsService $settings,
    ): void {
        $disk = Storage::disk('local');

        // Already processed by an earlier attempt.
        if (! $disk->exists($this->path)) {
            return;
        }

        $message = $inbound->receive($parser->parse((string) $disk->get($this->path), $settings->ownAddresses()));

        $syncs->recordOutcome($this->syncId, $message);
        $disk->delete($this->path);
    }

    /**
     * The file is kept, so `queue:retry` can process it after a fix. Only
     * ids are logged — never the message or its sender.
     */
    public function failed(Throwable $exception): void
    {
        app(MailboxSyncService::class)->recordFailure($this->syncId);
        Log::error('mailbox.message_failed', ['sync_id' => $this->syncId, 'path' => $this->path, 'exception' => $exception::class]);
    }
}
