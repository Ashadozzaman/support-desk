<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\InboundChannel;
use Carbon\CarbonImmutable;

/**
 * One message from any channel, already parsed by its adapter (docs/IMAP.md §1).
 */
readonly class InboundMessageData
{
    /**
     * @param  list<string>  $threadKeys  ids this message answers, most specific first
     *                                    (email: In-Reply-To, then References newest → oldest)
     * @param  list<InboundAttachmentData>  $attachments
     */
    public function __construct(
        public InboundChannel $channel,
        public string $externalMessageId,
        public string $senderAddress,
        public ?string $senderName,
        public string $subject,
        public string $body,
        public CarbonImmutable $receivedAt,
        public array $threadKeys = [],
        public ?string $ticketNumberHint = null,
        public array $attachments = [],
        /** Set by the adapter when this isn't a customer's message (auto-reply, bounce, our own mail). */
        public ?string $skipReason = null,
    ) {}
}
