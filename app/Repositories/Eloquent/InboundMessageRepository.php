<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\InboundMessageRepositoryInterface;
use App\DTOs\InboundMessageData;
use App\Enums\InboundChannel;
use App\Enums\InboundMessageStatus;
use App\Models\InboundMessage;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class InboundMessageRepository implements InboundMessageRepositoryInterface
{
    public function createIfNew(InboundMessageData $data, string $externalId, string $subject): ?InboundMessage
    {
        // INSERT IGNORE on UNIQUE(channel, external_message_id): a second
        // copy of the same message (redelivery, retried job, two workers)
        // waits on the first one's key lock, then inserts nothing — no
        // read-then-write race.
        $inserted = InboundMessage::query()->insertOrIgnore([
            'channel' => $data->channel->value,
            'external_message_id' => $externalId,
            'sender_address' => mb_substr($data->senderAddress, 0, 255),
            'subject' => $subject,
            'status' => InboundMessageStatus::Skipped->value,
            'received_at' => $data->receivedAt->utc(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        return InboundMessage::query()
            ->where('channel', $data->channel->value)
            ->where('external_message_id', $externalId)
            ->firstOrFail();
    }

    public function recordOutcome(InboundMessage $message, array $outcome): void
    {
        $message->update($outcome);
    }

    public function ticketForThreadKeys(InboundChannel $channel, array $threadKeys): ?Ticket
    {
        if ($threadKeys === []) {
            return null;
        }

        $ticketIdsByKey = DB::table('ticket_channel_threads')
            ->where('channel', $channel->value)
            ->whereIn('thread_key', $threadKeys)
            ->pluck('ticket_id', 'thread_key');

        foreach ($threadKeys as $key) {
            if (isset($ticketIdsByKey[$key])) {
                return Ticket::query()->whereKey($ticketIdsByKey[$key])->first();
            }
        }

        return null;
    }

    public function rememberThreadKey(InboundChannel $channel, string $threadKey, Ticket $ticket): void
    {
        // Ignore: a key already belongs to a ticket and never moves.
        DB::table('ticket_channel_threads')->insertOrIgnore([
            'channel' => $channel->value,
            'thread_key' => $threadKey,
            'ticket_id' => $ticket->id,
            'created_at' => now(),
        ]);
    }
}
