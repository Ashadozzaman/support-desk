<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\Permission;
use App\Events\ReplyAdded;
use App\Models\User;
use App\Notifications\ReplyReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * ARCHITECTURE.md §10 recipient rule: agent/admin reply -> customer;
 * customer reply -> assigned agent, or all admins if still unassigned.
 */
class SendReplyAddedNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function handle(ReplyAdded $event): void
    {
        $author = $event->reply->user;
        $ticket = $event->ticket;
        $isStaffReply = $author->can(Permission::TicketsViewAny->value) || $author->can(Permission::TicketsViewQueue->value);

        $recipients = $isStaffReply
            ? collect([$ticket->creator])
            : ($ticket->assigned_agent_id !== null
                ? collect([$ticket->assignedAgent])
                : $this->users->activeWithPermissions(Permission::DashboardViewGlobal->value));

        $recipients = $recipients->filter()->unique('id')->reject(fn (User $user) => $user->id === $author->id);

        Notification::send($recipients, new ReplyReceived($ticket, $event->reply));
    }
}
