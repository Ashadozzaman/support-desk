<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;

interface AttachmentRepositoryInterface
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
    ): Attachment;
}
