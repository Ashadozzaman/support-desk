<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;

class AttachmentRepository implements AttachmentRepositoryInterface
{
    public function create(
        Ticket $ticket,
        ?TicketReply $reply,
        User $uploader,
        string $diskPath,
        string $originalName,
        string $mimeType,
        int $sizeBytes,
        string $checksum,
    ): Attachment {
        return $ticket->attachments()->create([
            'ticket_reply_id' => $reply?->id,
            'uploaded_by' => $uploader->id,
            'disk_path' => $diskPath,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'checksum_sha256' => $checksum,
        ]);
    }
}
