<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\Role;
use App\Events\InternalNoteAdded;
use App\Models\User;
use App\Notifications\ReplyReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * CLAUDE.md rule 6 / ARCHITECTURE.md §10: internal notes notify other staff
 * only — the ticket's assigned agent and admins — and must never reach the
 * customer. Reuses the ReplyReceived notification shape (it links to the
 * ticket; the ticket's own authorization already hides the note body from
 * anyone without notes.view).
 */
class SendInternalNoteAddedNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(InternalNoteAdded $event): void
    {
        $author = $event->note->user;
        $ticket = $event->ticket;

        $recipients = collect([$ticket->assignedAgent])
            ->merge(User::role(Role::Admin->value)->get())
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $author->id);

        Notification::send($recipients, new ReplyReceived($ticket, $event->note));
    }
}
