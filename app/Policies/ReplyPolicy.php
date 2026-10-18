<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ReplyType;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;

/**
 * Subject of `create`/`createNote` is the Ticket being replied to (the reply
 * doesn't exist yet); `view` checks an existing reply. Bound explicitly in
 * AppServiceProvider since the class name doesn't match TicketReply's
 * auto-discovery convention.
 */
class ReplyPolicy
{
    public function create(User $user, Ticket $ticket): bool
    {
        if (! $user->can(Permission::RepliesCreate->value)) {
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
     * Internal notes: staff only, never visible to customers (CLAUDE.md rule 6).
     */
    public function createNote(User $user, Ticket $ticket): bool
    {
        if (! $user->can(Permission::NotesCreate->value)) {
            return false;
        }

        return $user->can(Permission::TicketsViewAny->value) || $ticket->assigned_agent_id === $user->id;
    }

    public function view(User $user, TicketReply $reply): bool
    {
        if ($reply->type === ReplyType::InternalNote && ! $user->can(Permission::NotesView->value)) {
            return false;
        }

        return app(TicketPolicy::class)->view($user, $reply->ticket);
    }
}
