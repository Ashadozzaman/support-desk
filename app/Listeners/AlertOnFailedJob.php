<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\Permission;
use App\Models\User;
use App\Notifications\JobFailedAlert;
use App\Support\Logging\LogRedactor;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The one place a permanently failed job is logged with its exception and
 * alerted (ENHANCEMENTS.md §2.2). Each job's own failed() only cleans up its
 * state (e.g. marks an import failed) and logs its ids — never the message.
 *
 * Runs synchronously inside the worker: if the queue is what's broken, a
 * queued listener would never run and the failure would go unlogged.
 */
class AlertOnFailedJob
{
    public function handle(JobFailed $event): void
    {
        $jobName = $event->job->resolveName();

        Log::channel('queue')->error('job.failed', [
            'job' => $jobName,
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'exception' => $event->exception::class,
            'message' => LogRedactor::exceptionMessage($event->exception),
        ]);

        // The alert is itself a queued notification: alerting on its own
        // failure would loop for as long as mail/broadcast is down.
        if ($jobName === SendQueuedNotifications::class) {
            return;
        }

        // Cache::add is atomic (only the first caller in the window wins), so
        // a job class failing 500 times in a burst sends one alert, not 500.
        $minutes = (int) config('support.monitoring.failed_job_alert_minutes');
        if (! Cache::add("monitoring:failed-job-alert:{$jobName}", true, now()->addMinutes($minutes))) {
            return;
        }

        $recipients = User::permission(Permission::MonitoringView->value)->where('is_active', true)->get();

        Notification::send($recipients, new JobFailedAlert(
            jobName: $jobName,
            failedQueue: $event->job->getQueue(),
            exception: $event->exception::class,
        ));
    }
}
