<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\Role;
use App\Events\SlaWarningTriggered;
use App\Models\User;
use App\Notifications\SlaWarning;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * ARCHITECTURE.md §10: notify the assignee and all admins.
 */
class SendSlaWarningNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(SlaWarningTriggered $event): void
    {
        $recipients = collect([$event->ticket->assignedAgent])
            ->merge(User::role(Role::Admin->value)->get())
            ->filter()
            ->unique('id');

        Notification::send($recipients, new SlaWarning($event->ticket));
    }
}
