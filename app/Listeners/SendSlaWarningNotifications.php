<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\Permission;
use App\Events\SlaWarningTriggered;
use App\Notifications\SlaWarning;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * ARCHITECTURE.md §10: notify the assignee and all admins.
 */
class SendSlaWarningNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function handle(SlaWarningTriggered $event): void
    {
        $recipients = collect([$event->ticket->assignedAgent])
            ->merge($this->users->activeWithPermissions(Permission::DashboardViewGlobal->value))
            ->filter()
            ->unique('id');

        Notification::send($recipients, new SlaWarning($event->ticket));
    }
}
