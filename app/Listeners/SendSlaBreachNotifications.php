<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\Role;
use App\Events\SlaBreached;
use App\Models\User;
use App\Notifications\SlaBreached as SlaBreachedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * ARCHITECTURE.md §10: notify the assignee and all admins.
 */
class SendSlaBreachNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(SlaBreached $event): void
    {
        $recipients = collect([$event->ticket->assignedAgent])
            ->merge(User::role(Role::Admin->value)->get())
            ->filter()
            ->unique('id');

        Notification::send($recipients, new SlaBreachedNotification($event->ticket));
    }
}
