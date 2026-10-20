<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tickets:auto-assign')->everyMinute()->withoutOverlapping(5)->onOneServer();
// Queues a sync only when the admin's fetch interval has passed (docs/IMAP.md).
Schedule::command('mailbox:sync')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('sla:scan')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('dashboard:warm-cache')->everyThirtySeconds()->withoutOverlapping(5)->onOneServer();
// Report rollups (ENHANCEMENTS.md §1.2): today stays ≤ 15 min stale;
// yesterday is recomputed once more after midnight so late edits settle.
// Times are business-timezone wall clock.
Schedule::command('reports:aggregate')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('reports:aggregate --yesterday')->dailyAt('00:30')->timezone(config('support.sla.timezone'))->withoutOverlapping(30)->onOneServer();
Schedule::command('reports:snapshot')->dailyAt('23:55')->timezone(config('support.sla.timezone'))->withoutOverlapping(10)->onOneServer();
Schedule::command('reports:weekly-digest')->weeklyOn(1, '08:00')->timezone(config('support.sla.timezone'))->withoutOverlapping(10)->onOneServer();
// Heartbeats read by ScheduleCheck / QueueCheck (GET /health). The queue
// one dispatches a tiny job to every watched queue; no withoutOverlapping —
// each run is a cache write / a few dispatches.
Schedule::command('health:schedule-check-heartbeat')->everyMinute();
Schedule::command('health:queue-check-heartbeat')->everyMinute()->onOneServer();
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily();
// Expired API tokens are already rejected; this just keeps the table small.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
// Audit log retention per log (support.audit.*). Daily, so each run deletes
// about one day's rows — a small range DELETE on INDEX(log_name, created_at).
Schedule::command('activitylog:clean auth --days='.config('support.audit.auth_retention_days').' --force')->daily()->onOneServer();
Schedule::command('activitylog:clean admin --days='.config('support.audit.admin_retention_days').' --force')->daily()->onOneServer();
