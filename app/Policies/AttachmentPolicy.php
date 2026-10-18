<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ReplyType;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;

class AttachmentPolicy
{
    public function upload(User $user, Ticket $ticket): bool
    {
        if (! $user->can(Permission::AttachmentsUpload->value)) {
            return false;
        }

        if ($user->can(Permission::TicketsViewAny->value)) {
            return true;
        }

        if ($user->can(Permission::TicketsViewQueue->value)) {
            return $ticket->assigned_agent_id === $user->id;
        }

        return $ticket->created_by === $user->id;
    }

    /**
     * Attachments on an internal note inherit the note's visibility (CLAUDE.md rule 6).
     */
    public function download(User $user, Attachment $attachment): bool
    {
        $reply = $attachment->reply;

        if ($reply !== null && $reply->type === ReplyType::InternalNote && ! $user->can(Permission::NotesView->value)) {
            return false;
        }

        return app(TicketPolicy::class)->view($user, $attachment->ticket);
    }
}
