# Support Desk — Architecture

Support Ticket & SLA Management System (Laravel assessment).
Stack: **Laravel (latest stable) · PHP 8.3+ · MySQL 8 (InnoDB) · Laravel React Starter Kit (Inertia + React + TypeScript + shadcn/ui + Tailwind) · spatie/laravel-permission (RBAC) · Laravel Reverb (real-time) · Pest · Queue + Scheduler**.

Queue / scheduler / Reverb usage, tied to assessment sections: see [`QUEUE_SCHEDULER_REALTIME.md`](QUEUE_SCHEDULER_REALTIME.md).

> Principle from the assessment: *quality of what is built beats ticking every box.* Build the core (tickets, roles, assignment, SLA, activity) correctly first, then the rest.

---

## 1. Layered architecture

```
HTTP Request
   │
   ▼
Controller          thin: FormRequest validation → Policy authorize → call Service → return Inertia/redirect
   │
   ▼
Service             business rules, transactions, events, orchestration  (the ONLY layer that opens DB::transaction)
   │
   ▼
Repository          Eloquent/query-builder access only; no business rules; returns models/collections/paginators
   │
   ▼
Model / MySQL
```

| Layer | Allowed | Forbidden |
|---|---|---|
| Controller | `FormRequest`, `$this->authorize()`, call one service method, return `Inertia::render` / `redirect` | Queries, business rules, `DB::transaction`, `Model::create` |
| Service | Business logic, transactions, dispatch events/jobs, call repositories & other services, throw domain exceptions | `Request` objects, `Inertia`, view concerns, raw HTTP |
| Repository | Eloquent queries, eager loading, locks (`lockForUpdate`), atomic updates, bulk inserts | Business decisions, events, notifications, authorization |
| Policy | Who may do what (server-side) | Queries beyond relationship checks |
| DTO | Typed, immutable input to services (`readonly` classes) | Behaviour |
| Enum | Status/priority/role/event types + small pure helpers (e.g. `canTransitionTo`) | I/O |

### SOLID mapping (be ready to explain this in the interview)

| Principle | Where applied |
|---|---|
| **S**ingle responsibility | One service per use-case area: `TicketService`, `TicketAssignmentService`, `ReplyService`, `AttachmentService`, `SlaService`, `EscalationService`, `ActivityLogService`, `DashboardService`, `TicketImportService`. Controllers only translate HTTP. |
| **O**pen/closed | `AssignmentStrategy` interface — add a new strategy class without editing `TicketAssignmentService`. SLA rules live in DB/config, not in `if` chains. |
| **L**iskov | Any `AssignmentStrategy` / repository implementation is substitutable behind its interface. |
| **I**nterface segregation | Small repository contracts (`TicketRepositoryInterface`, `ReplyRepositoryInterface`, …) instead of one god repository. |
| **D**ependency inversion | Services depend on `Contracts\Repositories\*` interfaces; bound to `Repositories\Eloquent\*` in `RepositoryServiceProvider`. Tests can swap fakes. |

---

## 2. Domain model (overview)

```
users ─┬─< tickets >─── categories
       │      │  └──── (priority → sla_policies, by enum value)
       │      ├─< ticket_replies (type: reply | internal_note) ─< attachments
       │      ├─< attachments
       │      └─< ticket_activities   (append-only audit trail)
       ├── agent_profiles (availability, capacity, last_assigned_at) ─ category_agent (skills)
       └── notifications (Laravel database channel)

sla_policies · business_hours · holidays · ticket_sequences · imports · import_errors
```

Full schema, columns and indexes: see [`DATABASE.md`](DATABASE.md).

---

## 3. Roles & authorization (server-side)

RBAC uses **spatie/laravel-permission**: roles `admin`, `agent`, `customer` (no `users.role` column) with granular permissions, enforced through **Policies** that combine *permission* checks (`$user->can('tickets.claim')`) with *ownership/assignment* checks (`created_by`, `assigned_agent_id`).

Permissions: `tickets.view-any | view-queue | view-own | create | update | update-own | change-status | claim | assign`, `replies.create`, `notes.create`, `notes.view`, `attachments.upload`, `dashboard.view-global | view-own`, `imports.manage`, `sla.manage`, `business-hours.manage`, `categories.manage`, `users.manage`.

Seeded by an idempotent `RolesAndPermissionsSeeder` (reset the permission cache after seeding). Route middleware uses spatie's `role:` / `permission:` aliases; the UI reads permissions from Inertia shared props (cosmetic only).

| Action | Customer | Agent | Admin |
|---|---|---|---|
| Create ticket | ✅ | ✅ (on behalf of customer) | ✅ |
| View ticket | own only | assigned to them **or** unassigned (queue) | all |
| Update ticket fields (priority, category, subject) | ❌ (own subject/description only while `open`) | assigned only | all |
| Change status | close own *resolved* ticket / reopen by replying | assigned only (valid transitions) | all |
| Claim (accept) unassigned ticket | ❌ | ✅ | ✅ |
| Manual assign / reassign | ❌ | ❌ | ✅ |
| Public reply | own tickets | assigned only | ✅ |
| Internal note (create/view) | ❌ **never sees** | ✅ | ✅ |
| Download attachment | own non-internal | if can view ticket | ✅ |
| Dashboard | ❌ (own summary optional) | own workload + queue | global |
| CSV import, SLA / business-hours / category / user admin | ❌ | ❌ | ✅ |

Enforcement points (defence in depth):
1. **Route middleware** (`auth`, `verified`, `role:admin` for admin groups).
2. **Policy** (`TicketPolicy`, `ReplyPolicy`, `AttachmentPolicy`, `ImportPolicy`) via `$this->authorize()` / `authorizeResource`.
3. **Repository scoping** — `TicketRepository::paginateFor(User $viewer, TicketFilterData $f)` applies a *visibility scope* (customer → `created_by`, agent → assigned-or-unassigned, admin → all) so lists can never leak rows even if a policy call is forgotten.
4. **Reply visibility** — `ReplyRepository::forTicket($ticket, $viewer)` excludes `internal_note` unless viewer is staff. Internal-note **attachments and notifications** follow the same rule. Covered by tests.
5. **Route model binding by `ticket_number`** (not auto-increment id) to avoid enumeration; still policy-checked.

---

## 4. Ticket numbering — `TKT-2026-000001`

* Table `ticket_sequences(year PK, last_number)`.
* `TicketNumberGenerator::next()` runs **inside the ticket-creation transaction**:
  1. `INSERT IGNORE` the year row (`last_number = 0`)
  2. `SELECT … FOR UPDATE` that row
  3. increment + update
  4. return `sprintf('TKT-%d-%06d', $year, $n)`
* `tickets.ticket_number` has a **UNIQUE** index → DB is the final guard.
* Growth: `%06d` is a *minimum* width — past 999,999 it simply becomes 7 digits (column is `VARCHAR(32)`). Yearly reset is natural because the key is the year.
* Contention note: the sequence row is locked until the creating transaction commits, so call the generator **as late as possible** in the transaction (after validation and SLA calculation). For CSV import use `reserve(int $count)` to take a whole block with one lock.
* Alternative worth mentioning: `UPDATE … SET last_number = LAST_INSERT_ID(last_number + 1)` is a lock-free atomic variant.

---

## 5. Ticket lifecycle

Statuses: `open → in_progress → waiting_for_customer → resolved → closed`

```
open ──────────► in_progress ◄──────► waiting_for_customer
  │                  │                        │
  └──────────────────┴──────► resolved ◄──────┘
                                │   ▲
                                ▼   │ (customer reply reopens → open/in_progress)
                              closed
```

* Transitions are validated in `TicketStatus::canTransitionTo()` and enforced by `TicketService::changeStatus()` → throws `InvalidStatusTransitionException`.
* `escalated` is **not a status**; it is a flag/level on the ticket (`escalated_at`, `escalation_level`) so a ticket can be escalated *and* in any status.
* "Upcoming/active" = `open | in_progress | waiting_for_customer`. "Resolved/closed" = `resolved | closed`. Two index-backed list views.
* Priority change → recompute SLA (see §7) and write an activity row.

---

## 6. Assignment

### 6.1 Manual
`TicketAssignmentService::assign(Ticket, User $agent, User $actor)` — admin only. Validates target is an active agent, writes activity (`ticket.assigned` or `ticket.reassigned`), fires `TicketAssigned`.

### 6.2 Automatic — **least-workload with round-robin tie-break**
Strategy chosen via `config('support.assignment.strategy')` → bound to an `AssignmentStrategy` implementation.

Candidate agents:
* `agent_profiles.is_available = 1`
* handles the ticket's category (`category_agent`), or has no category restriction
* `open_count < max_active_tickets`

Order: **fewest active tickets first**, then **oldest `last_assigned_at`** (acts as round-robin when workloads tie).

Concurrency-safe: the pick runs in a transaction with `SELECT … FOR UPDATE SKIP LOCKED` (MySQL 8) on the chosen `agent_profiles` row, so two simultaneous auto-assignments can't both overload one agent. `last_assigned_at` is updated in the same transaction.

Flow: `TicketService::create()` commits → `AutoAssignTicketJob` (queued, `ShouldBeUnique` by ticket id, `afterCommit`) → `TicketAssignmentService::autoAssign()`. If no candidate is available the ticket stays unassigned and a **scheduled sweep** (`tickets:auto-assign`, every minute) retries.

Strategies shipped: `LeastWorkloadStrategy` (default), `RoundRobinStrategy` (second implementation to demonstrate Open/Closed).

---

## 7. Concurrent "accept ticket" (two agents, same ticket)

**Requirement:** never assign one ticket to two agents.

**Solution: atomic conditional UPDATE (compare-and-set) inside a transaction.**

```sql
UPDATE tickets
SET assigned_agent_id = :agent, assigned_at = NOW(), status = 'in_progress', version = version + 1
WHERE id = :ticket AND assigned_agent_id IS NULL AND status = 'open';
-- affected rows = 1 → this agent won. affected rows = 0 → someone else got it first.
```

* InnoDB takes a row lock for the UPDATE; the second statement waits, re-evaluates `assigned_agent_id IS NULL`, finds it false, affects 0 rows.
* Service throws `TicketAlreadyAssignedException` → rendered as **HTTP 409** / friendly flash message ("Already taken by another agent").
* Activity row + event are created only for the winner, in the same transaction.
* No application-level check-then-act (`if ($ticket->assigned_agent_id === null) …`) — that is the classic race.
* A `version` column also gives **optimistic locking** for ordinary edits (lost-update protection) — optional extra.
* Test: unit-level (second claim returns false/throws) + real concurrency test with two DB connections or `Concurrency::run` (see testing plan).

Why not `lockForUpdate()` + read-then-write? It also works (and is shown as an alternative), but the single atomic UPDATE is shorter, holds the lock for less time and is easier to reason about.

---

## 8. SLA engine

### 8.1 Configuration lives in the database (change rules without code changes)

| Table | Purpose |
|---|---|
| `sla_policies` | `priority` (unique), `resolution_minutes`, `warning_threshold_percent` (default 80), `is_active` |
| `business_hours` | one row per weekday: `day_of_week`, `is_working_day`, `start_time`, `end_time` (default Sun–Thu 09:00–18:00) |
| `holidays` | `date`, `name` (non-working days) |
| `config/support.php` | business timezone, default thresholds, strategy, upload limits, import chunk size |

Seeded defaults: Low 48 h, Medium 24 h, High 8 h, Critical 2 h.
Admin UI edits these; `SlaConfigRepository` caches them (`Cache::rememberForever`, busted on update).

> **Interpretation to document in README:** the table's "48 Hours / 24 Hours / 8 Hours / 2 Hours" are treated as **business hours** (consumed only inside business windows) because §9 of the assessment requires business-hours calculation. Stored as minutes (`2880/1440/480/120`) for exactness.

### 8.2 Deadline calculation — `BusinessHoursCalculator`

`addBusinessMinutes(CarbonImmutable $start, int $minutes): CarbonImmutable`

1. Convert `$start` to the **business timezone** (all DB timestamps stay UTC).
2. Move cursor to the next working moment (before opening → opening time; after closing / non-working day / holiday → next working day's opening).
3. Loop: `available = closeOfDay − cursor`. If `remaining ≤ available` → `cursor + remaining`. Else consume the day, `remaining −= available`, jump to the next working day's opening.
4. Guard the loop (max ~366 iterations) to avoid infinite loops on bad config.

**Worked example (from the assessment):** Critical = 120 min, created **Thursday 17:00**.
Thursday 17:00→18:00 consumes 60 min; Fri/Sat are not business days; Sunday 09:00 + remaining 60 min ⇒ **due Sunday 10:00**.

Stored on the ticket at create/priority-change time:
`sla_due_at` and `sla_warning_at` (= start + `threshold%` of SLA, also in business time). Both UTC, both indexed.

On priority change: recompute from `created_at` with the new policy (documented assumption; alternative = from change time).
Optional extension: pause the clock while `waiting_for_customer` (`sla_paused_at`, `sla_paused_minutes`) — design hook only; not in v1.

### 8.3 SLA state (no extra denormalised status column)

```php
enum SlaState { case WithinSla; case Approaching; case Breached; case Met; case MetLate; }
```
Computed from `status`, `now()`, `sla_warning_at`, `sla_due_at`, `resolved_at`.
Because the boundaries are *columns*, the list filter is index-friendly:

| Filter | SQL predicate (active tickets) |
|---|---|
| Within | `sla_warning_at > NOW()` |
| Approaching | `sla_warning_at <= NOW() AND sla_due_at > NOW()` |
| Breached | `sla_due_at <= NOW()` |

---

## 9. Escalation (scheduler → queue)

```
routes/console.php
  Schedule::command('sla:scan')->everyMinute()->withoutOverlapping()->onOneServer();
  Schedule::command('tickets:auto-assign')->everyMinute()->withoutOverlapping();
```

`sla:scan` (uses indexes `(status, sla_warning_at)` / `(status, sla_due_at)`):
1. **Approaching:** active tickets where `sla_warning_at <= now AND sla_warning_notified_at IS NULL` → `chunkById(200)` → dispatch `ProcessSlaWarningJob`.
2. **Breached:** active tickets where `sla_due_at <= now AND sla_breach_notified_at IS NULL` → dispatch `ProcessSlaBreachJob`.

Each job (idempotent):
* Inside a transaction, **atomic flag update** `UPDATE … SET sla_breach_notified_at = NOW(), escalated_at = NOW(), escalation_level = escalation_level + 1 WHERE id = ? AND sla_breach_notified_at IS NULL` — if 0 rows, another worker already did it → stop (no duplicate emails).
* Write `ticket_activities` row (`sla.warning` / `sla.breached` / `ticket.escalated`).
* After commit: notify assigned agent, all admins (and optionally the agent's supervisor); channels `mail` + `database`; queued.
* Optional second level: breach + N hours still open → escalate to admins (level 2).

---

## 10. Events, listeners, notifications

Domain events are dispatched **after commit** (`DB::afterCommit` / `ShouldDispatchAfterCommit`) so listeners never see rolled-back data.

| Event | Listeners |
|---|---|
| `TicketCreated` | notify admins/agents (queue), customer confirmation |
| `TicketAssigned` | notify assigned agent (+ customer) |
| `ReplyAdded` (public) | notify the *other* party (agent→customer / customer→agent or admins if unassigned) |
| `InternalNoteAdded` | notify other agents only — **never** customer |
| `TicketStatusChanged` / `TicketResolved` | notify customer |
| `SlaWarningTriggered` / `SlaBreached` | notify agent + admins |

* Notifications implement `ShouldQueue`, channels `['mail', 'database', 'broadcast']`; queue `notifications`. The `broadcast` channel pushes to the user's private Reverb channel for an instant bell/toast; domain events (`ShouldBroadcast`, after commit, queue `broadcasts`) update open ticket pages and the staff queue. Internal-note events go only to `tickets.{n}.staff` channels. Full channel map: `QUEUE_SCHEDULER_REALTIME.md`.
* Activity logging is **synchronous inside the service transaction** (it's part of the audit truth); notifications are **asynchronous after commit**.

---

## 11. Replies & attachments

* `ticket_replies.type`: `reply | internal_note`. Plain text (escaped on render — no `dangerouslySetInnerHTML`).
* Attachments: stored on a **private disk** (`storage/app/private/tickets/{ticket_id}/{uuid}.{ext}`), original name kept in DB only.
* Validation (`StoreAttachmentRequest`): max files 5, max 5 MB each, allow-list `jpg,jpeg,png,pdf,txt,csv,docx,xlsx` (configurable); server-side MIME sniffing (`mimetypes` rule), block executables/archives; store checksum (sha256).
* Access: **never public URLs.** `AttachmentController@download` → `AttachmentPolicy@download` → `Storage::download()` with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.
* Internal-note attachments inherit the note's visibility.
* Upload + DB row inside one transaction; if the DB insert fails, the stored file is deleted (compensating action).

---

## 12. Search & filtering

`TicketFilterData` (DTO) ← `TicketFilterRequest`. `TicketRepository::paginateFor($viewer, $filters)` builds one query:

* `ticket_number` — prefix `LIKE 'TKT-2026-%'` (uses the unique index) or exact match
* keyword — `MATCH(subject, description) AGAINST (? IN BOOLEAN MODE)` via FULLTEXT index (fallback `LIKE` for tiny/test DBs)
* status, priority, category, assigned agent, customer (`created_by`), created date range, SLA state (§8.3)
* Eager load `with(['category:id,name','assignedAgent:id,name','creator:id,name'])` → **no N+1**; `select` only list columns; `paginate(15)->withQueryString()` (use `cursorPaginate` for huge admin lists).
* Filters persist in the URL (Inertia `router.get` with `preserveState`).

---

## 13. CSV import (large files)

```
Upload (validate type/size) → store on private disk → imports row (status=pending)
   → dispatch ProcessTicketImportJob (queue "imports")
        stream file with league/csv (cursor, never load whole file)
        chunks of N rows (config, default 500)
          per row: validate (Validator), dedupe (external_ref), resolve category/customer/priority
          valid rows → one transaction per chunk: reserve ticket numbers, bulk insert tickets (+ one activity "imported")
          invalid rows → import_errors (row_number, errors json, raw json)
          update imports counters (processed/success/failed) after each chunk
   → status=completed | completed_with_errors | failed
```

* **Memory:** streaming reader + chunking → constant memory.
* **Duplicates:** `tickets.external_ref` UNIQUE; use `insertOrIgnore`/`upsert` + count skipped; also dedupe inside the file.
* **Errors:** downloadable CSV of failed rows with reason; one bad row never fails the file.
* **Long-running:** job `timeout`, `tries = 1` on the file (chunks are idempotent by `external_ref`), `failed()` handler marks import failed.
* **Progress:** UI polls `GET /imports/{id}` (Inertia partial reload every 2 s) — bonus: broadcast.
* Imported tickets get SLA computed per the same `SlaService` (bulk-safe, config cached in memory for the run).
* Imported tickets don't trigger customer emails unless explicitly enabled.

---

## 14. Dashboard

`DashboardService` → `DashboardRepository` using **few aggregate queries**, not per-widget queries:

```sql
SELECT
  SUM(status IN ('open','in_progress','waiting_for_customer'))                         AS open_total,
  SUM(assigned_agent_id IS NULL AND status = 'open')                                   AS unassigned,
  SUM(priority = 'critical' AND status IN (…active…))                                  AS critical,
  SUM(status IN (…active…) AND sla_due_at <= NOW())                                    AS sla_breached,
  SUM(status IN (…active…) AND sla_warning_at <= NOW() AND sla_due_at > NOW())         AS sla_approaching,
  SUM(status IN ('resolved','closed'))                                                 AS resolved
FROM tickets;
-- + GROUP BY priority ; + GROUP BY assigned_agent_id (JOIN users)
```
* Admin = global; Agent = own queue + unassigned count; results cached 30–60 s (`Cache::remember`, key per role/user).
* UI: `StatCard` grid, simple bar lists for by-priority / by-agent, table of "approaching SLA" tickets.

---

## 15. Activity / audit history

Append-only `ticket_activities`: `ticket_id, actor_id (null = system), event, old_values json, new_values json, metadata json, created_at`.

`ActivityLogService::log(Ticket, ActivityEvent, ?User actor, array old, array new, array meta)` is called by services **in the same transaction** as the change (atomic with it). Events (enum `ActivityEvent`):
`ticket.created, ticket.updated, ticket.assigned, ticket.reassigned, priority.changed, status.changed, category.changed, reply.added, note.added, attachment.added, sla.warning, sla.breached, ticket.escalated, ticket.imported, ticket.reopened`.

Rendered as a timeline (`ActivityTimeline` component): "10:05 AM – Assigned to Agent B", "Priority changed from Medium to High". Customers see a filtered timeline (no internal-note, escalation, or SLA internals).

---

## 16. ACID, transactions and locking

| Operation | Transaction? | Why / mechanism |
|---|---|---|
| Create ticket | ✅ | sequence lock + ticket row + activity + attachments atomic; events `afterCommit` |
| Claim ticket | ✅ | atomic conditional UPDATE + activity |
| Manual assign / auto assign | ✅ | agent row `FOR UPDATE SKIP LOCKED`, ticket update, `last_assigned_at`, activity |
| Change status / priority | ✅ | ticket update + SLA recompute + activity (all-or-nothing) |
| Add reply / internal note | ✅ | reply + attachments + ticket `last_reply_at` / status side-effect + activity |
| Resolve / close | ✅ | status + `resolved_at` + SLA met/late flag + activity |
| Escalation job | ✅ | atomic flag update + activity; notifications after commit |
| CSV import chunk | ✅ per chunk | number reservation + bulk insert; failure rolls back that chunk only |
| Admin: SLA/business-hour updates | ✅ | multi-row update + cache bust after commit |
| Read-only lists, dashboard | ❌ | no transaction needed |

* **Atomicity:** services own transactions; repositories never begin/commit.
* **Consistency:** FK constraints, UNIQUE keys, enum-backed `VARCHAR`/`ENUM` columns, `NOT NULL`, `CHECK` where useful, `utf8mb4`, strict SQL mode.
* **Isolation:** InnoDB `REPEATABLE READ` default; explicit row locks / atomic updates exactly where races exist (number sequence, claim, agent pick, escalation flag).
* **Durability:** InnoDB with `innodb_flush_log_at_trx_commit=1` (default).
* Deadlock handling: `DB::transaction($cb, attempts: 3)` on lock-heavy paths; lock rows in a consistent order (ticket → agent profile).

---

## 17. Performance checklist

* Index every filter/sort path (see `DATABASE.md`); `EXPLAIN` the 5 hottest queries and paste the output in the README.
* No N+1: eager loading everywhere; enable `Model::preventLazyLoading(! app()->isProduction())` and `Model::shouldBeStrict()` in dev.
* Select only needed columns for lists; paginate everything; cap `per_page`.
* Cache SLA policies / business hours / dashboard aggregates.
* Queues for mail, notifications, escalation, import, auto-assign.
* Activity/notification tables are append-heavy → narrow rows, indexes limited to read paths; mention partitioning / archiving as scale path.

---

## 18. Security checklist

* AuthZ on server for every route (middleware + policy + repository scope).
* `FormRequest` validation for all input; DTOs prevent mass-assignment.
* CSRF (Inertia default), session security, login rate limiting (`RateLimiter`), throttle reply/upload/import endpoints.
* Private file storage + authorized streaming downloads; strict upload allow-list; random stored names.
* XSS: plain-text rendering; no raw HTML from users.
* Ticket URLs by `ticket_number`; 404 (not 403) for tickets the viewer may not know exist (optional hardening).
* No secrets in repo: `.env.example` only.
* Audit trail for sensitive changes (assignment, priority, status, escalation).

---

## 19. Key decisions log (for README / interview)

| # | Decision | Alternative considered | Reason |
|---|---|---|---|
| 1 | spatie/laravel-permission + Policies | role enum column | Standard RBAC, granular permissions, cached, easy to extend (policies still add ownership rules) |
| 11 | Reverb for real-time | polling only | First-party WebSockets; private channels authorised by policies; polling kept as fallback |
| 2 | Sequence table + row lock for ticket number | `id` padded / UUID / `MAX()+1` | Safe under concurrency, yearly reset, DB-unique as backstop |
| 3 | Atomic conditional UPDATE to claim | `lockForUpdate` read-then-write | Single statement, minimal lock time, race-free |
| 4 | SLA rules in DB tables | constants/config only | Admin can change without deploy; one place to change |
| 5 | Store `sla_due_at` / `sla_warning_at` columns | compute on every read | Index-friendly filters, cheap scheduler scan |
| 6 | `escalated` as flag, not status | status value | Escalation is orthogonal to workflow state |
| 7 | Activity inside tx, notifications after commit | all async | Audit must be atomic; emails must not fire for rolled-back work |
| 8 | Streaming chunked CSV import | `Excel::import` whole file | Constant memory, resumable, per-chunk transactions |
| 9 | Service owns transactions | Repository owns | Use-case boundary spans several repositories |
| 10 | Inertia + React (starter kit) | separate SPA + API | Less boilerplate, auth/session/CSRF handled; API can be added as bonus |
