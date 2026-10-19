<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\Permission;
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

    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function handle(InternalNoteAdded $event): void
    {
        $author = $event->note->user;
        $ticket = $event->ticket;

        // Roles are editable (Step 19e), so "admin" or "assignee" no longer
        // implies notes.view — every recipient must hold it themselves.
        $recipients = collect([$ticket->assignedAgent])
            ->filter(fn (?User $agent) => $agent?->can(Permission::NotesView->value) === true)
            ->merge($this->users->activeWithPermissions(Permission::DashboardViewGlobal->value, Permission::NotesView->value))
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $author->id);

        Notification::send($recipients, new ReplyReceived($ticket, $event->note));
    }
}
