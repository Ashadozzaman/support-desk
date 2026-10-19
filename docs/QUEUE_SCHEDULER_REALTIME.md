# Queue, Scheduler & Real-time (Reverb) — where and why

This file answers: **which work goes to the queue, which runs on the scheduler, which is broadcast over Reverb, and which stays synchronous** — each tied to the assessment section that requires it.

Rule of thumb
* **Scheduler** = *time-driven* ("every minute, look for tickets near/over SLA").
* **Queue** = *event-driven or heavy* ("send this email", "process this 50k-row file") — keep HTTP requests fast.
* **Reverb** = *push to the browser* after something changed.
* **Synchronous (inside the DB transaction)** = anything that is part of the data's truth (ticket row, activity row, reply, attachment row).

---

## 1. Master map (assessment ➜ mechanism)

| Assessment § | Requirement | Mechanism | Class / command | Queue / schedule |
|---|---|---|---|---|
| §4 Automatic assignment | assign new tickets without blocking | **Queue** | `AutoAssignTicketJob` (`ShouldBeUnique`, afterCommit) | `default` |
| §4 Automatic assignment | retry tickets no agent could take | **Scheduler** | `tickets:auto-assign` | every minute, `withoutOverlapping` |
| §5 Concurrent assignment | one winner | **Synchronous**, DB atomic UPDATE | `claimIfUnassigned()` | — (never queue this) |
| §10 SLA escalation | find tickets approaching/over SLA | **Scheduler** | `sla:scan` | every minute, `withoutOverlapping`, `onOneServer` |
| §10 SLA escalation | escalate each ticket (flag, activity, notify) | **Queue** | `ProcessSlaWarningJob`, `ProcessSlaBreachJob` | `sla` |
| §11 Notifications (email) | "processed without blocking requests" | **Queue** | queued `Notification` classes (`mail`, `database`) | `notifications` |
| §11 / Bonus real-time | live updates + bell | **Reverb** (broadcast) | `ShouldBroadcast` events + `broadcast` notification channel | `broadcasts` (queued broadcasts) |
| §13 CSV import | large files, long-running | **Queue** | `ProcessTicketImportJob` | `imports` (own worker) |
| Email-to-ticket (Step 25b) | poll the support mailbox at the admin's interval | **Scheduler** → **Queue** | `mailbox:sync` → `SyncMailboxJob` (unique) → `ProcessInboundEmailJob` per message | every minute (skips until the interval passed) / `mail` |
| §14 Dashboard | fast aggregates | **Cache**, proactively kept warm | `Cache::remember` 30–60 s + `dashboard:warm-cache` → `WarmDashboardCacheJob` | `default`, every 30 s |
| Reports rollup (enhancement) | report counts/averages over up to a year | **Scheduler** → synchronous command (≈0.6s for today) | `reports:aggregate` (today), `reports:aggregate --yesterday`, `reports:snapshot` | every 15 min / 00:30 / 23:55 business tz, `withoutOverlapping`, `onOneServer` |
| §14.1 Agents performance list | fast aggregate at ~1M-ticket scale | **Synchronous**, uncached — paginated + index range scans (~0.2-0.4s/page) | `AgentRepository::paginatePerformance()` | — |
| §15 Activity history | traceable, consistent | **Synchronous** in transaction | `ActivityLogService` | — |
| §7 Attachments | validation + storage | **Synchronous** (user needs result) | `AttachmentService` | — |
| AI chatbot (Step 24) | answer a customer's question | **Synchronous** request (the customer is waiting), AI call outside any transaction, `ai.timeout` per attempt | `SupportChatService::ask` | — |
| §1.2 Report metrics (enhancement) | per-ticket response/resolution minutes, sla_met, reopen count | **Synchronous** in the status/reply transaction; one-off **command** for old rows | `TicketMetricsService`, `reports:backfill` (manual, not scheduled) | — |
| Monitoring (Step 19b) | prove the scheduler and each worker are alive | **Scheduler** → cache write / tiny queued job | `health:schedule-check-heartbeat`, `health:queue-check-heartbeat` (read by `GET /health`) | every minute |
| Monitoring (Step 19a) | log + alert permanently failed jobs | **Synchronous** listener in the worker (a queued one couldn't run if the queue is broken); the alert itself is a queued notification | `AlertOnFailedJob` → `JobFailedAlert` | `notifications` |
| Housekeeping | keep tables small | **Scheduler** | `queue:prune-batches`, `queue:prune-failed`, `model:prune`, `activitylog:clean`, `sanctum:prune-expired` | daily |
| Audit log (Step 19c) | who signed in / changed SLA settings | **Synchronous** (listener / inside the service transaction) | `RecordAuthAudit`, `AuditLogService` | — |
| Bonus | auto-close resolved tickets | **Scheduler** (P2) | `tickets:auto-close` | daily |

> In the README write a "Background processing" section copying this table — the assessment evaluates *"how appropriately long-running or asynchronous tasks are handled."*

---

## 2. Queues

Driver: `database` (zero setup for reviewers); Redis is a drop-in upgrade (`QUEUE_CONNECTION=redis`).

| Queue | Jobs | `tries` | `backoff` | `timeout` | Notes |
|---|---|---|---|---|---|
| `sla` | `ProcessSlaWarningJob`, `ProcessSlaBreachJob` | 3 | 30, 120 | 60 s | Highest priority; idempotent flag update |
| `default` | `AutoAssignTicketJob`, `WarmDashboardCacheJob` | 3 / 1 | 10, 60 / — | 30 s / 30 s | Both `ShouldBeUnique` (per ticket id / fixed key) |
| `mail` | `SyncMailboxJob`, `ProcessInboundEmailJob` | 1 / 3 | — / 30, 120 | 120 s | Inbound email (Step 25b): sync is unique (never two at once); per-message job is idempotent (UNIQUE Message-ID) |
| `notifications` | queued mail/database notifications, event listeners | 5 | 30, 120, 600 | 60 s | Never retried to duplicate: notification idempotency via job uniqueness |
| `broadcasts` | Reverb broadcast events | 3 | 5, 30 | 15 s | Lossy is OK (UI also loads fresh data) |
| `exports` | `GenerateReportExportJob` | 1 | — | 1800 s | Ticket exports over `support.reports.export_sync_limit` rows; own queue so a big export never delays SLA jobs; idempotent for a completed export |
| `ai` | AI jobs from Step 23 (triage, reply draft, summary) — none yet in Step 22 | — | — | — | Own queue so a slow provider never delays SLA jobs; monitored by QueueCheck |
| `imports` | `ProcessTicketImportJob` | 1 | — | 3600 s | Chunk-idempotent via `external_ref`; `failed()` marks import failed |

Workers:
```bash
# main worker (priority order)
php artisan queue:work --queue=sla,default,mail,notifications,broadcasts,exports,ai --tries=3 --max-time=3600
# dedicated import worker so big files never starve SLA/notifications
php artisan queue:work --queue=imports --timeout=3600 --tries=1
```
Production: run both under Supervisor (sample in README); `php artisan queue:restart` on deploy.

Job rules (apply to every job):
1. Dispatch **after commit** (`->afterCommit()` or `DB::afterCommit`) — a job must never run for rolled-back data.
2. Pass **ids**, not models, for long-delayed jobs; re-load inside `handle()`.
3. Idempotent: running twice must not double-notify (atomic flag `UPDATE … WHERE flag IS NULL`).
4. Define `tries`, `backoff`, `timeout`, and `failed()`. `failed()` marks the job's own state and logs only its ids; the exception itself is logged (redacted) and alerted once, centrally, by `AlertOnFailedJob` (Step 19a).
5. `ShouldBeUnique` where duplicates are wasteful (auto-assign).

---

## 3. Task scheduler (`routes/console.php`)

```php
Schedule::command('sla:scan')->everyMinute()->withoutOverlapping(5)->onOneServer()->runInBackground();
Schedule::command('tickets:auto-assign')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('mailbox:sync')->everyMinute()->withoutOverlapping(5)->onOneServer();   // queues a sync only when the fetch interval passed
Schedule::command('dashboard:warm-cache')->everyThirtySeconds()->withoutOverlapping(5)->onOneServer();
Schedule::command('health:schedule-check-heartbeat')->everyMinute();
Schedule::command('health:queue-check-heartbeat')->everyMinute()->onOneServer();
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily();                 // old imports / import_errors, report exports, ai_interactions, chat sessions
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();  // expired API tokens (docs/API.md)
Schedule::command('activitylog:clean auth --days=180 --force')->daily()->onOneServer();   // support.audit.auth_retention_days
Schedule::command('activitylog:clean admin --days=1095 --force')->daily()->onOneServer(); // support.audit.admin_retention_days
Schedule::command('reports:aggregate')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('reports:aggregate --yesterday')->dailyAt('00:30')->timezone(config('support.sla.timezone'))->withoutOverlapping(30)->onOneServer();
Schedule::command('reports:weekly-digest')->weeklyOn(1, '08:00')->timezone(config('support.sla.timezone'))->withoutOverlapping(10)->onOneServer();
Schedule::command('reports:snapshot')->dailyAt('23:55')->timezone(config('support.sla.timezone'))->withoutOverlapping(10)->onOneServer();
// P2: Schedule::command('tickets:auto-close --days=3')->dailyAt('02:00');
```

| Command | What it does | Why scheduled (not event-driven) |
|---|---|---|
| `sla:scan` | `chunkById(200)` over indexed active tickets where `sla_warning_at <= now` (not yet notified) or `sla_due_at <= now` (not yet notified); dispatches jobs to `sla` | Nothing "happens" when a deadline passes — time itself is the trigger |
| `tickets:auto-assign` | open tickets unassigned > 1 min → `AutoAssignTicketJob`, **at most as many as the free agent slots** (Σ `max_active_tickets − active` over available agents), oldest first, only in categories a free agent handles; queues nothing when every agent is full | Agent may become available later; no event fires. Bounded because queuing the whole backlog every minute (~60k jobs when all agents were full) never succeeded and starved every queue behind `default` |
| `dashboard:warm-cache` | dispatches `WarmDashboardCacheJob` (queue `default`) to rebuild the global dashboard aggregate before `support.dashboard.cache_ttl` (default 60s) expires | Keeps the ~5-10s full-table dashboard aggregate off the request path entirely — admins always read a warm cache instead of occasionally paying the cold cost |
| `reports:aggregate` | recomputes `ticket_daily_stats` for today (or `--yesterday`, or `--from/--to`) | Report numbers are aggregates over time; recomputing a small window on a clock keeps reads instant. Runs inline (not queued) — today takes ~0.6s |
| `reports:snapshot` | stores end-of-day backlog in `ticket_daily_snapshots` | Ticket status isn't stored per day; the only way to have a backlog trend is to record it daily |
| prune commands | housekeeping | keep `jobs`/`failed_jobs`/import tables small |

> Sub-minute schedules (`everyThirtySeconds()`) still fire correctly from a plain cron `* * * * * schedule:run` — Laravel's scheduler sleeps/re-checks internally within that minute; `schedule:work` (used locally) does the same in a long-running process.

Server cron (production / reviewer instructions):
```
* * * * * cd /path/to/support-desk && php artisan schedule:run >> /dev/null 2>&1
```
Local: `php artisan schedule:work`.

---

## 4. Reverb real-time (WebSockets)

Setup: `php artisan install:broadcasting` (choose **Reverb**), frontend via Laravel Echo (`@laravel/echo-react` / `laravel-echo` + `pusher-js` per the installed version).

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID= REVERB_APP_KEY= REVERB_APP_SECRET=
REVERB_HOST=localhost REVERB_PORT=8080 REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}" VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}" VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```
Run: `php artisan reverb:start` (plus the queue worker — broadcasts are queued on `broadcasts`).

### Channels (all **private**, authorised in `routes/channels.php`)

| Channel | Who may subscribe | Carries |
|---|---|---|
| `App.Models.User.{id}` | that user only | Laravel notifications (`broadcast` channel) → bell + toast |
| `tickets.{ticketNumber}` | users who pass `TicketPolicy@view` | public replies, status changes, assignment, attachments (public only), SLA badge changes |
| `tickets.{ticketNumber}.staff` | staff who can view the ticket (`notes.view`) | **internal notes**, escalations, internal activity |
| `staff.queue` | users with `tickets.claim` | new unassigned ticket, ticket claimed/assigned (remove from queue) |
| `staff.alerts` | admins (`dashboard.view-global`) | SLA warnings/breaches, import progress finished |
| `imports.{id}` | the admin who started it | import progress (processed/failed counts) |

> **Security rule:** internal-note events are broadcast **only** to `.staff` channels. A customer can never subscribe to them because channel authorisation checks the permission server-side. Test this.

### Events → channels

| Event (`ShouldBroadcast`, afterCommit, queue `broadcasts`) | Channel(s) |
|---|---|
| `TicketCreated` | `staff.queue` |
| `TicketAssigned` / `TicketClaimed` | `staff.queue`, `tickets.{n}`, assignee user channel |
| `ReplyAdded` (public) | `tickets.{n}` |
| `InternalNoteAdded` | `tickets.{n}.staff` |
| `TicketStatusChanged` | `tickets.{n}`, `staff.queue` |
| `SlaWarningTriggered`, `SlaBreached` | `staff.alerts`, `tickets.{n}.staff` (+ notification to assignee) |
| `ImportProgressUpdated` | `imports.{id}` |

Broadcast payloads are **small** (ids, status, counts, `reply_id`) — the client then does an Inertia partial reload (`router.reload({ only: [...] })`). Never put internal data in a public-channel payload.

### Notifications
`Notification::via()` → `['mail', 'database', 'broadcast']`, class `implements ShouldQueue`, `$queue = 'notifications'`.
* `mail` — email (queued)
* `database` — persistent list + unread count (`notifications` table)
* `broadcast` — instant push to `App.Models.User.{id}`

### Frontend (React)
* `useNotifications()` hook: loads unread list, subscribes to user channel, increments bell badge, shows toast.
* `useTicketChannel(ticketNumber)` on `tickets/show`: on `ReplyAdded` / `TicketStatusChanged` → `router.reload({ only: ['ticket','replies','activities'] })`; staff also subscribe to `.staff`.
* `useStaffQueue()` on queue/dashboard: on `TicketCreated` / `TicketAssigned` → `router.reload({ only: ['tickets','stats'] })` (debounced 500 ms).
* Graceful fallback: if the socket disconnects, show a small "offline" indicator and poll every 30 s (`usePolling`).

---

## 5. What stays synchronous (and why)

| Work | Reason |
|---|---|
| Ticket/reply/attachment rows, activity row | Must be atomic (ACID) and visible immediately |
| Claim ticket | Result needed instantly; race protection is the DB's job |
| Authorization checks | Must block the request |
| Reading lists/dashboard | Cached/indexed reads |
| Business-hours calculation on create | Cheap pure function; deadline needed in the response |

---

## 6. Local development (one command each)

```bash
composer dev                         # serve + queue listener + vite + schedule:work + reverb:start (all in one)
php artisan queue:work --queue=imports --timeout=3600   # dedicated import worker (Step 14), run separately
```
`composer dev` runs `php artisan dev`, which uses Laravel's built-in dev-process
registry (`Illuminate\Foundation\DevCommands`) rather than a bespoke
`concurrently` script. The starter kit registers `serve`/`queue:listen`/`vite`
by default; `AppServiceProvider::configureDevCommands()` overrides the
default `queue` process to listen on `sla,default,mail,notifications,broadcasts,exports,ai`
(the default only covers `default`) and adds `schedule:work` and
`reverb:start --debug` processes. `php artisan dev:list` shows every
registered process. The CSV-import worker is intentionally left out of this
list — Step 14's `timeout=3600` worker is meant to run in its own terminal so
a long import never starves the others.

## 7. Tests for this layer
* `Queue::fake()` — ticket create dispatches `AutoAssignTicketJob` **after commit** (not when transaction rolls back).
* `sla:scan` dispatches warning/breach jobs once; running twice doesn't duplicate (idempotent).
* `Notification::fake()` — correct recipients/channels; internal note never notifies customer.
* `Event::fake()` / channel auth tests — customer **cannot** authorise `tickets.{n}.staff` or other customers' ticket channels.
* Import job: chunking, failure handler, progress broadcast.
* Scheduler registration test: `Schedule` contains `sla:scan` at every minute.
