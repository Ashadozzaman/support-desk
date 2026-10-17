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
| §14 Dashboard | fast aggregates | **Cache** (not queue) | `Cache::remember` 30–60 s | — |
| §15 Activity history | traceable, consistent | **Synchronous** in transaction | `ActivityLogService` | — |
| §7 Attachments | validation + storage | **Synchronous** (user needs result) | `AttachmentService` | — |
| Housekeeping | keep tables small | **Scheduler** | `queue:prune-batches`, `queue:prune-failed`, `model:prune` | daily |
| Bonus | auto-close resolved tickets | **Scheduler** (P2) | `tickets:auto-close` | daily |

> In the README write a "Background processing" section copying this table — the assessment evaluates *"how appropriately long-running or asynchronous tasks are handled."*

---

## 2. Queues

Driver: `database` (zero setup for reviewers); Redis is a drop-in upgrade (`QUEUE_CONNECTION=redis`).

| Queue | Jobs | `tries` | `backoff` | `timeout` | Notes |
|---|---|---|---|---|---|
| `sla` | `ProcessSlaWarningJob`, `ProcessSlaBreachJob` | 3 | 30, 120 | 60 s | Highest priority; idempotent flag update |
| `default` | `AutoAssignTicketJob` | 3 | 10, 60 | 30 s | `ShouldBeUnique` per ticket id |
| `notifications` | queued mail/database notifications, event listeners | 5 | 30, 120, 600 | 60 s | Never retried to duplicate: notification idempotency via job uniqueness |
| `broadcasts` | Reverb broadcast events | 3 | 5, 30 | 15 s | Lossy is OK (UI also loads fresh data) |
| `imports` | `ProcessTicketImportJob` | 1 | — | 3600 s | Chunk-idempotent via `external_ref`; `failed()` marks import failed |

Workers:
```bash
# main worker (priority order)
php artisan queue:work --queue=sla,default,notifications,broadcasts --tries=3 --max-time=3600
# dedicated import worker so big files never starve SLA/notifications
php artisan queue:work --queue=imports --timeout=3600 --tries=1
```
Production: run both under Supervisor (sample in README); `php artisan queue:restart` on deploy.

Job rules (apply to every job):
1. Dispatch **after commit** (`->afterCommit()` or `DB::afterCommit`) — a job must never run for rolled-back data.
2. Pass **ids**, not models, for long-delayed jobs; re-load inside `handle()`.
3. Idempotent: running twice must not double-notify (atomic flag `UPDATE … WHERE flag IS NULL`).
4. Define `tries`, `backoff`, `timeout`, and `failed()` (log + mark state).
5. `ShouldBeUnique` where duplicates are wasteful (auto-assign).

---

## 3. Task scheduler (`routes/console.php`)

```php
Schedule::command('sla:scan')->everyMinute()->withoutOverlapping(5)->onOneServer()->runInBackground();
Schedule::command('tickets:auto-assign')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily();                 // old imports / import_errors
// P2: Schedule::command('tickets:auto-close --days=3')->dailyAt('02:00');
```

| Command | What it does | Why scheduled (not event-driven) |
|---|---|---|
| `sla:scan` | `chunkById(200)` over indexed active tickets where `sla_warning_at <= now` (not yet notified) or `sla_due_at <= now` (not yet notified); dispatches jobs to `sla` | Nothing "happens" when a deadline passes — time itself is the trigger |
| `tickets:auto-assign` | tickets unassigned > 1 min → `AutoAssignTicketJob` | Agent may become available later; no event fires |
| prune commands | housekeeping | keep `jobs`/`failed_jobs`/import tables small |

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
composer dev                         # serve + vite (+ queue listener if configured in composer script)
php artisan queue:work --queue=sla,default,notifications,broadcasts
php artisan queue:work --queue=imports --timeout=3600
php artisan schedule:work
php artisan reverb:start --debug
```
Add a `composer dev:all` script (concurrently) that runs all of the above — document it in README.

## 7. Tests for this layer
* `Queue::fake()` — ticket create dispatches `AutoAssignTicketJob` **after commit** (not when transaction rolls back).
* `sla:scan` dispatches warning/breach jobs once; running twice doesn't duplicate (idempotent).
* `Notification::fake()` — correct recipients/channels; internal note never notifies customer.
* `Event::fake()` / channel auth tests — customer **cannot** authorise `tickets.{n}.staff` or other customers' ticket channels.
* Import job: chunking, failure handler, progress broadcast.
* Scheduler registration test: `Schedule` contains `sla:scan` at every minute.
