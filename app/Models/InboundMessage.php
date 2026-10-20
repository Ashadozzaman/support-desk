<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InboundChannel;
use App\Enums\InboundMessageStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'channel', 'external_message_id', 'sender_address', 'subject', 'status', 'skip_reason',
    'ticket_id', 'ticket_reply_id', 'rejected_attachments', 'received_at',
])]
class InboundMessage extends Model
{
    protected function casts(): array
    {
        return [
            'channel' => InboundChannel::class,
            'status' => InboundMessageStatus::class,
            'rejected_attachments' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
