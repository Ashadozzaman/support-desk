<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTOs\InboundMessageData;
use App\Enums\InboundChannel;
use App\Enums\InboundMessageStatus;
use App\Models\InboundMessage;
use App\Models\Ticket;

interface InboundMessageRepositoryInterface
{
    /**
     * Inserts the message row unless (channel, external id) already exists.
     * Returns null for a duplicate.
     */
    public function createIfNew(InboundMessageData $data, string $externalId, string $subject): ?InboundMessage;

    /**
     * @param  array{status: InboundMessageStatus, skip_reason?: string|null, ticket_id?: int|null, ticket_reply_id?: int|null, rejected_attachments?: list<array{name: string, reason: string}>|null}  $outcome
     */
    public function recordOutcome(InboundMessage $message, array $outcome): void;

    /**
     * The ticket the first matching key belongs to, trying keys in the given order.
     *
     * @param  list<string>  $threadKeys
     */
    public function ticketForThreadKeys(InboundChannel $channel, array $threadKeys): ?Ticket;

    public function rememberThreadKey(InboundChannel $channel, string $threadKey, Ticket $ticket): void;
}
