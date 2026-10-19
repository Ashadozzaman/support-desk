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
| **S**ingle responsibility | One service per use-case area: `TicketService`, `TicketAssignmentService`, `ReplyService`, `AttachmentService`, `SlaService`, `ActivityLogService`, `DashboardService`, `TicketImportService`. Escalation is two small queued jobs (`ProcessSlaWarningJob` / `ProcessSlaBreachJob`), not a service. Controllers only translate HTTP. |
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

Permissions: `tickets.view-any | view-queue | view-own | create | update | update-own | change-status | claim | assign`, `replies.create`, `notes.create`, `notes.view`, `attachments.upload`, `dashboard.view-global | view-own`, `imports.manage`, `sla.manage`, `business-hours.manage`, `categories.manage`, `users.manage`, plus later steps' `reports.*`, `monitoring.view`, `audit.view`, `roles.manage` (Step 19e). The full list is the `App\Enums\Permission` enum.

Seeded by an idempotent `RolesAndPermissionsSeeder` (reset the permission cache after seeding). It always gives `admin` every permission, but seeds `agent`/`customer` only when those roles are first created — after that they're owned by the roles editor (§3.2) and a re-run must not undo edits. Route middleware uses spatie's `permission:` alias only (no `role:` anywhere — §3.2); the UI reads permissions from Inertia shared props (cosmetic only).

The table below is the **seeded default**; since Step 19e an admin can change it.

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
1. **Route middleware** (`auth`, `verified`, and `permission:<x>` on every admin route group — `AdminRouteGuardTest` fails if an `admin.*` route lacks one or any route uses `role:`).
2. **Policy** (`TicketPolicy`, `ReplyPolicy`, `AttachmentPolicy`, `ImportPolicy`) via `$this->authorize()` / `authorizeResource`.
3. **Repository scoping** — `TicketRepository::paginateFor(User $viewer, TicketFilterData $f)` applies a *visibility scope* (customer → `created_by`, agent → assigned-or-unassigned, admin → all) so lists can never leak rows even if a policy call is forgotten.
4. **Reply visibility** — `ReplyRepository::forTicket($ticket, $viewer)` excludes `internal_note` unless viewer is staff. Internal-note **attachments and notifications** follow the same rule. Covered by tests.
5. **Route model binding by `ticket_number`** (not auto-increment id) to avoid enumeration; still policy-checked.

### 3.1 Users & agents admin (Step 19d)

`GET/POST /admin/users`, `GET /admin/users/{user}/edit`, `PUT /admin/users/{user}` (`permission:users.manage` +
`UserPolicy` `viewAny`/`create`/`update`) → `Admin\UserController` → `UserManagementService` →
`UserRepositoryInterface` + `AgentRepositoryInterface`.

* One role per user (`syncRoles([$role])`). **"Is an agent" = the role has `tickets.claim`**, not the role's name:
  for such a role the form shows availability / `max_active_tickets` / category skills, saved to `agent_profiles`
  + `category_agent` in the same transaction. Moving a user to a role without `tickets.claim` keeps their profile
  (history, performance page) but sets `is_available = false`.
* New accounts: random password the admin never sees, `email_verified_at` set (the admin vouches for the address),
  and a password-reset link emailed **after commit** (only if created active).
* Lockout rules (`UserLockoutException` → mapped to a 422 field error in `bootstrap/app.php`): no deactivating or
  re-roling your own account; the last active `users.manage` holder can't lose it. The holders' rows are locked
  `FOR UPDATE` (by primary key) inside the update transaction, so two admins demoting each other at once are
  serialised and the second sees what the first left.
* **`users.is_active` is enforced** (it was only stored before): `RejectInactiveUsers` (a step added to Fortify's
  login pipeline) refuses the login with the generic "credentials do not match" error before the password check;
  `EnsureUserIsActive` (web middleware) ends any session already open, incl. remember-me and passkey sign-ins;
  `AgentRepository` candidate queries skip inactive users, so auto-assign never picks them.
* Every create/update writes `admin.user_created` / `admin.user_updated` to the admin audit log with field-level
  old/new (account: name/email/role/is_active; agent: is_available/max_active_tickets/categories).


### 3.2 Roles & permissions editor (Step 19e)

`/admin/roles` (`permission:roles.manage` + `RolePolicy`, registered with `Gate::policy` because spatie's `Role` is
not an `App\Models` class) → `Admin\RoleController` → `RoleService` → `RoleRepositoryInterface` (+ `UserRepository`
for the lockout check).

* **Permissions are fixed in code, roles are data.** Policies check permission names, so a permission invented in a UI
  would do nothing; admins only combine the `Permission` enum's values (each has a `label()` and a `group()` for the
  checkbox grid) into roles.
* Built-in roles (`Role` enum): `admin` is locked to every permission; `admin`/`agent`/`customer` can't be renamed or
  deleted (seeding and sign-up refer to them by name). Custom roles: lowercase slug names, deletable only when no user
  has them (checked inside the delete transaction). Violations → `ProtectedRoleException` → 422 field error.
* Lockout: taking `users.manage` or `roles.manage` off a role is refused unless an active user **outside** that role
  still holds it — same lock-then-recount as §3.1.
* Audit: `admin.role_created/updated/deleted`; renames as `role: name old → new`, permissions one by one as
  `permissions: notes.view yes → no`. Spatie's permission cache is busted after commit.
* **No role-name checks left in code** — the behaviour that used to hang off a role name now hangs off a permission:

  | Was | Now |
  |---|---|
  | `role:admin` on the admin route group | `permission:` per route group (users.manage, roles.manage, sla.manage, business-hours.manage, imports.manage, monitoring.view, audit.view) |
  | Admin notification recipients (`User::role('admin')`) | active users with `dashboard.view-global`; for internal notes **also** `notes.view` (and the assignee only if they have `notes.view`) |
  | Assignable agents / auto-assign candidates (`role('agent')`) | active + agent profile + `tickets.claim` (`AgentRepository`, `UserRepository::assignableAgents`, `AssignTicketRequest`) |
  | Customer picker / CSV-import customer (`role('customer')`) | `tickets.view-own` |
  | Agents performance detail (`hasRole('agent')`) | has an agent profile (the list's own source, so demoted agents' history stays reachable) |

  Registration still assigns the built-in `customer` role (`CreateNewUser`) — assigning a role, not authorizing by one.
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
`TicketAssignmentService::assign(Ticket, User $agent, User $actor)` — admin only. Validates target is an active agent, re-reads the ticket `FOR UPDATE` (`TicketRepository::findForUpdate()`) so a claim that landed since the page loaded is seen, writes activity (`ticket.assigned` or `ticket.reassigned`), fires `TicketAssigned`. See [`CONCURRENT_TICKET_ASSIGNMENT.md`](CONCURRENT_TICKET_ASSIGNMENT.md).

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
* Validation (`StoreReplyRequest`, `attachments.*`): max files 5, max 5 MB each, allow-list `jpg,jpeg,png,pdf,txt,csv,docx,xlsx` (configurable); server-side MIME sniffing (Laravel's `mimes` rule checks the detected content type, not the client's extension), so executables/archives are refused by the allow-list; store checksum (sha256).
* Access: **never public URLs.** `AttachmentController@download` → `AttachmentPolicy@download` → `Storage::download()` with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.
* Internal-note attachments inherit the note's visibility.
* Upload + DB row inside one transaction; if the DB insert fails, the stored file is deleted (compensating action).

---

## 12. Search & filtering

`TicketFilterData` (DTO) ← `TicketFilterRequest`.

* `ticket_number` — prefix `LIKE 'TKT-2026-%'` (uses the unique index) or exact match
* keyword — `MATCH(subject, description) AGAINST (? IN BOOLEAN MODE)` via FULLTEXT index (fallback `LIKE` for tiny/test DBs)
* status, priority, category, assigned agent, customer (`created_by`), created date range, SLA state (§8.3)
* Eager load `with(['category:id,name','assignedAgent:id,name','creator:id,name'])` → **no N+1**; `select` only list columns.
* Filters persist in the URL (Inertia `router.get` with `preserveState`).

**`TicketRepository::paginateFor($viewer, $tab, $filters)` does NOT build one query.** The obvious version —
`visibleTo($viewer)->whereIn('status', $tabStatuses)->filter($filters)->latest()->paginate()` — degrades badly
once the matching set is a large share of `tickets`: MySQL can't use one index for both a multi-value `status
IN (...)` (or an agent's `assigned_agent_id = me OR assigned_agent_id IS NULL`) and `ORDER BY created_at`
together, and falls back to a full table scan + filesort (measured: ~2.6-4.9s for the admin "active" tab,
~1.5s for an agent's queue, at ~1M tickets). Instead it does **top-N-per-branch, then merge**:

1. Split the query into one branch per (status × visibility-rule) combination — e.g. an agent's "active" tab is
   `{open, in_progress, waiting_for_customer} × {mine, unassigned}` = 6 branches; admin is 3 (no visibility
   split); customer is 3 (its `created_by = me` is already a single equality, not an OR, so it never needed
   splitting).
2. Each branch is a *single*-equality filter on every column involved, so it can use the `(status, created_at)`
   index (`DATABASE.md` §3/§5) natively — index-only, no filesort — and only has to read
   `offset + perPage` rows, not the whole matching set (confirmed via `EXPLAIN`: 2-3ms per branch at ~1M rows).
3. Merge all branches' rows, sort by `created_at` desc, slice `[offset, offset+perPage)` for the exact page.
   This is exact, not an approximation: if a row were outside its own branch's top-`(offset+perPage)`, that
   many rows in its own branch already outrank it, so it can't be in the *global* top-N either — the global
   top-N is always contained in the union of every branch's own top-N. `total` is the sum of each branch's own
   `count()` (also index-only per branch).
4. `withQueryString()` is called on the manually-built `LengthAwarePaginator`, so filters still persist in the
   URL exactly as before — only the query strategy behind it changed, not its public contract.

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
-- + GROUP BY priority ; + GROUP BY assigned_agent_id (JOIN users, JOIN agent_profiles for capacity/availability)
```
* Admin = global; Agent = own queue + unassigned count; results cached 30–60 s (`Cache::remember`, key per role/user).
* The global aggregate (`summary`/`byPriority`/`byAgent`/`trend` with no filters) runs a full index scan over every ticket — at six-figure-plus ticket volumes this is several seconds, too slow to pay on a cache miss mid-request. `dashboard:warm-cache` (scheduled every 30s, see `QUEUE_SCHEDULER_REALTIME.md` §3) dispatches `WarmDashboardCacheJob` to rebuild `dashboard.global` before `cache_ttl` expires, so the heavy query runs on the queue, never on the request path. Per-agent/per-customer caches aren't warmed this way — those are already scoped (`WHERE assigned_agent_id = ?` / `WHERE created_by = ?`), index-selective, and cheap.
* `approachingSla()` reuses `Ticket::scopeSlaState(SlaState::Approaching)` (§8.3's own `sla_warning_at <= now() AND sla_due_at > now()`) rather than a hand-rolled predicate — a prior version checked only `sla_warning_at`, which also matched already-breached tickets and forced a full table scan + filesort because MySQL couldn't satisfy both the filter and the `ORDER BY sla_due_at` from one index.
* `byAgent()` is a **performance** breakdown, not just a workload count: one conditional-SUM query (the fully-covering `(assigned_agent_id, status, resolved_at, assigned_at)` index — see §14.1 for why — no per-agent round trip) over the *whole* `tickets` table — not just active ones — returning per agent: `active_count` split into `open_count`/`in_progress_count`/`waiting_count`, `stuck_count` (active tickets whose `assigned_at` is older than `config('support.dashboard.stuck_after_days')`, default 3 — a configurable business threshold, not hard-coded), and `resolved_7d_count`/`resolved_30d_count` (by `resolved_at`). A bare "N active tickets" number told an admin how full an agent's plate was, not whether they were actually moving tickets — this is the fix.
* UI: `StatCard` grid, `PriorityBarChart`/`TicketsTrendChart` for by-priority/trend, `AgentWorkloadCard` for by-agent (status breakdown + a "stuck" badge + 7d/30d resolved counts, not a bare progress bar), table of "approaching SLA" tickets.

### 14.1 Agents page (admin-only performance view)

The dashboard's by-agent widget is deliberately a glance, not a report — it only lists agents who
have at least one ticket, and only ever shows current load. `GET /admin/agents` (list) and
`GET /admin/agents/{agent}` (detail) go further for an admin who wants to assess one agent:

* `Admin\AgentController` → `AgentPerformanceService` → `AgentRepository` (new methods:
  `paginatePerformance()`, `statusBreakdown()`, `ticketsCompletedPerDay()`). Gated by a new
  `UserPolicy` (`viewAnyPerformance`/`viewPerformance`), both checking `users.manage` — a
  permission that already existed in the matrix but had no page behind it until now.
* **List**: paginated (15/page, ordered by name, `per_page` ≤ 50) **from `agent_profiles`** (not
  `tickets`, unlike `DashboardRepository::byAgent()`), so an agent with zero tickets ever still
  appears. A **date range** (`from`/`to`, `AgentPerformanceFilterRequest` → `AgentPerformanceFilterData`,
  default **today**, picked as business-timezone calendar days and converted to UTC) drives
  `completed_count` = tickets resolved/closed with `resolved_at` in the range. The workload columns
  (active/open/in_progress/waiting/stuck) are always a **live snapshot** — ticket status is not
  stored historically per day, so "in progress on 1 Oct" can't be answered from `tickets` alone
  (it would need replaying `status.changed` rows from `ticket_activities`).
* **Detail**: `statusBreakdown()` is the agent's **all-time** status counts (including
  resolved/closed, which the dashboard's active-only aggregate never shows), plus
  `ticketsCompletedPerDay()` — a 30-day, zero-filled, day-by-day count of tickets the agent
  resolved or closed, rendered with the same `TicketsTrendChart` component the dashboard trend
  uses (generalised with a `title`/`valueLabel` prop rather than a second chart component). The
  zero-fill logic itself is shared via `App\Support\TrendSeries::zeroFilled()` so
  `DashboardService::trend()` and `AgentPerformanceService::detail()` can't drift apart.
* **Detail — day by day**: a month picker (`?month=YYYY-MM`, `AgentMonthFilterRequest` →
  `AgentMonthFilterData`, default the current business-timezone month, stopping at today) drives a
  table with one zero-filled row per day: **Assigned** (by `assigned_at`) and **Completed**
  (resolved/closed, by `resolved_at`), plus a total. Days are business-timezone calendar days
  (`DATE(CONVERT_TZ(col, '+00:00', <offset>))` — a fixed offset so MySQL's tz tables aren't needed;
  exact for a no-DST zone like Asia/Dhaka). `AgentPerformanceService::daily()` builds the rows for
  both the page and `GET /admin/agents/{agent}/daily-export` (CSV, same `viewPerformance` gate), so
  the export can never disagree with the screen. Both queries range-scan only that agent's slice of
  the covering index: ~0.02–0.07s for the busiest seeded agent (~31k tickets).
* `viewPerformance` denies (403, not an empty page) if `{agent}` isn't actually an agent — e.g. a
  customer's user id in the URL.
* **Performance at scale**: the first version grouped every agent's tickets in one conditional-SUM
  over the whole table. Against the then-existing `(assigned_agent_id, status)` index `EXPLAIN`
  showed no `Using index` (full-row lookups for ~1M tickets, never finished inside 120s); the
  4-column covering index `(assigned_agent_id, status, resolved_at, assigned_at)` (migration
  `2026_10_18_190000_add_agent_performance_indexes_to_tickets_table`) brought it to ~20s — still a
  full index scan, because the `SUM`s had to read every resolved/closed row just to count the
  recent ones. The current `paginatePerformance()` instead **pages agents first**, then runs two
  aggregates for only that page's agent ids, each with its conditions in `WHERE` so MySQL
  range-scans just its slice of the same covering index (`type: range`, `Using index`):
  1. active rows only — `status IN (active) GROUP BY assigned_agent_id, status`, with
     `SUM(assigned_at <= ?)` for stuck;
  2. completed in range — `status IN (resolved, closed) AND resolved_at BETWEEN ? AND ?`.
  Measured at ~1M tickets: 0.14–0.39s per page (today → last 365 days), vs ~20s. Query count is
  constant (3 per page) regardless of agents per page. No cache: the result varies by page and
  date range and is fast enough uncached, so the former `WarmAgentPerformanceCacheJob` was removed.

---

### 14.2 Reports data layer (Step 17)

* **Per-ticket metrics (17a)** — `first_response_minutes`, `resolution_minutes` (business minutes from
  `created_at`), `sla_met`, `reopened_count`, written by `TicketMetricsService` inside the same transaction as
  the reply / status change. `reports:backfill` fills older rows (idempotent).
* **Rollup (17b)** — `ticket_daily_stats`: one row per business day × category × priority × agent, each event
  dated by its own timestamp. `ReportAggregationService::recompute()` reads with plain non-locking `SELECT`s,
  then `DELETE` + `INSERT` for the range in one transaction. Not `INSERT … SELECT … ON DUPLICATE KEY UPDATE`:
  under REPEATABLE READ that locks every scanned `tickets` row, and upserting leaves stale rows for groups that
  disappeared (e.g. a ticket moved category). Scheduled every 15 min for today, 00:30 for yesterday.
* **`ReportService`** — overview, slaCompliance, responseResolution, agentPerformance, aging, categories.
  Counts/averages from the rollup; median/p90 from a live frequency table (≤ 30 days, cached 5 min); live
  backlog/aging from `tickets` (aging cached 60s). `agentPerformance` *scopes* rather than hides:
  `reports.view` → all agents, `reports.view-own` (agent) → forced to the viewer's own row, otherwise nothing.
* Permissions `reports.view`, `reports.export` (admin) and `reports.view-own` (agent). The Agents pages (§14.1)
  stay on `users.manage` for now; Step 18 decides whether they move under `reports.view`.
* **Page (18a)** — `GET /reports` (`permission:reports.view|reports.view-own` middleware) →
  `ReportController` → `ReportService::forTab()`. Tabs are links (`?tab=`), so a request computes only the
  tab being viewed. `ReportPolicy` has no model, so its `viewAny`/`viewTab` are registered as named abilities
  (`Gate::define('viewReports' / 'viewReportTab', [ReportPolicy::class, …])`); an agent may open only the
  Agents tab, and the service still scopes that tab to their own row (defence in depth).
* **Export (18b)** — `reports.export` (admin). Summary CSV of the open tab is always streamed. Ticket CSV
  (one row per ticket created in the period, with its metrics) streams up to
  `support.reports.export_sync_limit` rows via `lazyById`; above that a `report_exports` row + `GenerateReportExportJob`
  (queue `exports`, after commit) writes a private file and notifies the owner with a signed link that expires
  with the file (`export_ttl_days`); `model:prune` (`ReportExport` is `Prunable`) deletes both. Every cell goes
  through `App\Support\Csv::safeCell()` (CSV injection). Download = signed URL **and** owner check
  (`ReportExportPolicy`).
* **Weekly digest (18b)** — `reports:weekly-digest`, Mondays 08:00 business tz: last 7 days vs the 7 before,
  top breaching categories, busiest agents, CSV attached; mailed to everyone with `reports.view`, at most once
  per week (atomic `Cache::add` marker, `--force` to resend).

### 14.3 Knowledge base + AI foundation (Step 22)

**Knowledge base.** `kb_articles` (plain text, FULLTEXT(title, body)). Admin CRUD at `/admin/kb-articles`
(`permission:knowledge-base.manage` + `KbArticlePolicy`) → `Admin\KbArticleController` → `KnowledgeBaseService` →
`KnowledgeBaseRepositoryInterface`. Help centre at `/help` and `/help/{slug}` for every signed-in user: published
articles only; a draft is a **404** (not 403) for anyone without `knowledge-base.manage`, and editors see it as a preview.
Search uses FULLTEXT **natural-language mode**, not boolean mode as on tickets, because a customer's question can contain `+ - * " ( )`, which are operators in
boolean mode. Queries under 3 characters (InnoDB's minimum token size) return nothing without hitting the DB.

**Retrieval for the chatbot (the "R" of RAG).** `KnowledgeBaseService::relevantArticles($question, $limit)` →
`KnowledgeBaseRepository::search()`: the top N published articles with their FULLTEXT `relevance` score, so Step 24
can treat a weak best match as "low confidence → hand off to a human". No vector database: MySQL 8.0 has no vector
type, and Anthropic has no embeddings endpoint, so semantic search would add a second provider and a new
service. For a help centre of a few hundred articles, keyword relevance is enough. The repository interface is the swap point if
embeddings are ever needed.

**AI layer**, **off by default** (`AI_ENABLED=false`, no key needed):

```
feature service (Steps 23–24) → AiGuard::complete(AiRequest, ?User, ?ticketId) → AiClient (interface)
                                                                                  ├─ AnthropicClient (Laravel Http → POST /v1/messages)
                                                                                  ├─ OpenAiClient (Laravel Http → POST /v1/chat/completions)
                                                                                  └─ FakeAiClient (deterministic; bound unless AI is enabled with a real provider)
```

* **Providers** (`AI_PROVIDER`): `anthropic`, `openai`, anything else → fake. `OpenAiClient` translates the app's
  provider-neutral messages (Anthropic-style `tool_use` / `tool_result` blocks) to Chat Completions (`tool_calls` on
  the assistant message, one `role: tool` message per result, the system prompt as the first message) and back, so
  no feature knows which provider answered. `finish_reason` maps to the app's stop reasons
  (`content_filter`/`refusal` → refusal); a 429 `insufficient_quota` is not retried. Added when the developer's Claude
  Pro subscription turned out not to include API access (they're billed separately).
* `AiGuard` is the **only** caller of `AiClient`, in this order: feature flag → circuit breaker (5 provider failures in a
  row → paused 5 min; a success, refusals included, resets the count) → per-user rate limit (per minute + per day,
  across features) → daily token budget (business-timezone day, cache counter, soft: checked before each call) →
  PII redaction of the messages (`PiiRedactor`: emails, phones, long digit runs; ticket numbers and ISO dates kept) →
  call → `AiInteractionLogger`. Refusals by checks 1–4 throw `AiUnavailableException` **before any HTTP request**.
  Provider failures throw `AiProviderException` (no request/response body in the message).
* `AiGuard::wrapUntrusted($tag, $text)` puts user text in `<tag>…</tag>` and strips any copy of the tag inside it, so
  the text can't close the wrapper (prompt-injection rule §3.2.3). Our own system prompt is never redacted or wrapped.
* `AnthropicClient`: headers `x-api-key` + `anthropic-version: 2023-06-01`; body `model`, `max_tokens`, `system`,
  `messages`, `output_config.effort`; only `text` blocks are returned (current models also return `thinking` blocks).
  Retries 408/429/5xx/529 and connection errors (`ai.retries`), never 4xx. `stop_reason: "refusal"` is a normal
  response (`AiResponse::refused()`), not an error. Server-side refusal fallback (`fallbacks: "default"`, beta header)
  is sent except for Claude Haiku models, which reject it. Model per feature: `AiFeature::model()` (`AI_MODEL`, optional
  per-feature overrides).
* `ai_interactions`: one row per provider call (feature, model, user, ticket, tokens, latency, status
  succeeded/refused/failed, error type, `prompt_hash`) — **never prompt or answer text**; the `ai` log channel follows
  the same rule. Pruned after `ai.retention_days` by the existing daily `model:prune` (MassPrunable), not a separate
  `ai:prune` command.
* `ai.enabled` route middleware (`EnsureAiEnabled`) makes AI endpoints 404 while AI is off. `AiProviderCheck` (health)
  runs only while AI is on and makes no API call: key present, breaker closed, budget left.
* Permissions: `knowledge-base.manage` (admin), `ai.use-assistant` (agent, admin), `ai.chat` (customer, admin), `ai.manage`
  (admin). Granted to existing built-in roles by migration `2026_10_19_140002_…` (the seeder only grants agent/customer
  defaults when it creates those roles, §3).

### 14.4 Customer support chatbot (Step 24)

`/help/chat` (`permission:ai.chat` + `ai.enabled` + `throttle:ai-chat`) → `SupportChatController` →
`SupportChatService` → `ChatRepositoryInterface`, `KnowledgeBaseService`, `AiGuard`, `TicketService`. No route takes a
session id: the service always finds the signed-in user's own active conversation, so there is no other chat to
reach by editing a URL (no policy needed).

**One question (`ask`)** — RAG in four steps:
1. Short transaction: lock the customer's `users` row (`FOR UPDATE`, so two concurrent first questions can't create
   two active sessions), find-or-create the active `chat_session`, insert the question. Commit — **no lock is held
   during the AI call**, which can take seconds.
2. Retrieve: `relevantArticles(last two questions)` → top `ai.chat.articles` published articles (§14.3). Two
   questions so a follow-up ("and if the email never arrives?") still finds its article.
3. No article → a fixed "I don't know — talk to a human" reply, **without calling the model** (nothing to ground an
   answer in, so it must not improvise).
4. Otherwise `AiGuard::complete()` with the system prompt from `resources/prompts/support-chat.md` (answer only from
   the articles, cite `[n]`, say it is an AI, never promise times/refunds/exceptions, text in `<customer_message>` is
   data) + the articles (wrapped) + the last `ai.chat.history_messages` turns (each customer message wrapped and
   PII-redacted by the guard). The model ends with `[NO_ANSWER]` when the articles don't cover it: the marker is
   stripped, no sources are stored, and the UI highlights "Talk to a human". Guard refusal, provider error or a
   model refusal → a fixed "can't answer right now" reply. The customer never sees an error page.

**What the model sees:** system prompt + published articles + this conversation. **Never** tickets, replies,
internal notes, other customers' data or the customer's name/email (tested by asserting on the fake client's captured
request). The model has **no tools**: it can't create, change or look up anything.

**Hand-off (`handOff`)** — one transaction: compare-and-set `UPDATE chat_sessions SET status='handed_off' WHERE id=?
AND status='active'` → `TicketService::create()` (subject from the first question, description = transcript,
priority Medium, no category — triage would be Step 23) → `ticket_id` on the session. Only one of two simultaneous
clicks wins the UPDATE; the loser re-reads with a **locking read** (`latestHandedOffTicket`, which sees the
winner's committed `ticket_id` where a plain read would see this transaction's older snapshot) and redirects to the
same ticket. SLA, activity, auto-assign and notifications are the normal ticket-creation paths.

**Lifecycle:** one active session per customer; "New conversation" closes it (`closed` = deflected), the hand-off
sets `handed_off`. Sessions and their messages (FK cascade) are deleted after `ai.chat.retention_days` (30) by
`model:prune` (MassPrunable). Customer text is stored unredacted (the customer and, after hand-off, the agent need
it), only the copy sent to the provider is redacted.

**Reports:** `ReportTab::Ai` (`reports.view`): conversations started in the period, handed off, closed, deflection
rate = closed ÷ all conversations, AI calls (succeeded/refused/failed), tokens, average answer time.

### 14.5 Staff data assistant (Step 24b)

`/assistant` (`permission:ai.use-assistant` + `ai.enabled` + `throttle:ai-chat`; agents and admins by default) →
`DataAssistantController` → `DataAssistantService` → `AiGuard` + `DataAssistantTools`. Answers questions such as
"latest tickets", "what was resolved today?", "last 7 days report" with **Claude tool use**.

* **The model never touches the database.** It can only ask for one of four read-only lookups
  (`DataAssistantTools`): `search_tickets` (→ `TicketService::paginate`, the Tickets-page query), `resolved_tickets`
  (→ `TicketRepository::resolvedBetweenFor`, a range over `INDEX(resolved_at, …)`), `current_summary`
  (→ `DashboardService::forUser`), `ticket_report` (→ `ReportService::forTab`, gated by `viewReportTab`).
* **Each lookup runs as the person asking**: `visibleTo()` limits an agent to their queue + unassigned; an agent
  asking for a global report gets an error result, not data. Rows are list fields only (number, subject, status,
  priority, category, assignee, dates): never descriptions, replies or internal notes.
* **Input is untrusted**: validated like a request; invalid input, an unknown lookup or a forbidden report becomes an
  `is_error` tool result the model can correct (`DataToolException`), never an exception page.
* **Loop**: each round is one `AiGuard::complete()` (all safeguards and an audit row per round), at most
  `ai.data_assistant.max_rounds` (4). The assistant turn is sent back **unchanged** (signed thinking blocks
  included). `AiGuard` redacts only user turns (questions, tool results), never assistant blocks. `tool_choice`
  stays `auto` (current models reject forced tool use).
* The system prompt (`resources/prompts/data-assistant.md`) carries today's date in the business timezone: the
  model has no clock, and "today"/"last 7 days" must mean business days like the rest of the app.
* **Failures tell staff what to fix** (developer request): `AiProviderException::problem()` classifies a failure as
  missing key / invalid key (401, `authentication_error`, `invalid_api_key`) / no credit (402, `billing_error`,
  `insufficient_quota`, Anthropic's 400 "credit balance" message) / other, and the Data assistant replies with the
  fix ("add OPENAI_API_KEY to .env", "add API credit to the OpenAI account"); our own refusals (budget, rate limit,
  paused) get their own replies too. The **customer** help chat keeps one neutral reply — setup details are not for
  customers. A missing key doesn't count towards the circuit breaker: no request left the app, and pausing would
  hide the reason behind "calls paused".
* Only the question and the final answer are stored, in `chat_sessions` with `assistant = 'data'` (separate from the
  help chat, not counted in its deflection rate). Ticket numbers in answers render as links (access still checked
  when opened).

### 14.6 Inbound channels — email-to-ticket (Step 25)

Full spec: [`IMAP.md`](IMAP.md). A message from any channel is parsed by its adapter into `InboundMessageData` and
handed to `InboundMessageService::receive()`, which **reuses `TicketService::create()` / `ReplyService::addReply()`**
— not a second ticket system, so SLA, numbering, activity, auto-assign, notifications and broadcasts are the same
as for a web ticket. One transaction: `INSERT IGNORE` into `inbound_messages` (UNIQUE `(channel,
external_message_id)` → a duplicate inserts nothing and returns null) → sender by email (unknown → new customer,
no email sent) → thread by `ticket_channel_threads` keys, then a `[TKT-…]` hint, **only on the sender's own,
not-closed ticket** → create or public reply → attachments via `AttachmentService::storeContents()` → remember
this message's id as a thread key. Inbound text never becomes an internal note. 25a = this core; 25b = the IMAP
adapter + admin page; 25c = outbound thread headers + loop prevention.

**25b — IMAP adapter.** `mailbox:sync` (every minute, `withoutOverlapping()->onOneServer()`) queues `SyncMailboxJob`
(unique, queue `mail`) only when the admin's fetch interval has passed; "Sync now" queues the same job.
`MailboxSyncService::sync()` calls `MailboxClient::fetch()` **outside any transaction** (network), spools each raw
message to the private disk, then in one transaction moves the UID cursor and dispatches `ProcessInboundEmailJob`
per file (after commit). The first sync of a mailbox only records the starting UID (old mail is never imported).
The job parses with `EmailMessageParser` and calls `InboundMessageService::receive()`. Admin → Mailbox
(`permission:mailbox.manage` + `MailboxSettingPolicy`) → `Admin\MailboxController` → `MailboxSettingsService`:
the password is write-only (encrypted cast, `#[Hidden]`, never in props or audit — the audit row says `changed`),
a blank field keeps it, `IMAP_PASSWORD` in `.env` is the fallback; Test connection tries the typed values without
saving; failures are a fixed safe message (`MailboxConnectionException`).

**25c — threading + loop prevention.** `TicketEmailThreadService::thread()` (called from the customer-facing
notifications' `toMail()`) gives mail to the ticket's customer a remembered `Message-ID`, `In-Reply-To`/`References`,
a `[TKT-…]` subject, `Reply-To` the mailbox and `Auto-Submitted: auto-generated`; the reply then threads by header.
The parser marks auto-replies, bounces, bulk/list mail and our own mail (`skipReason` on the DTO); the core adds a
per-sender hourly limit and skips staff senders. Full table: IMAP.md §4.

## 15. Activity / audit history

Append-only `ticket_activities`: `ticket_id, actor_id (null = system), event, old_values json, new_values json, metadata json, created_at`.

`ActivityLogService::log(Ticket, ActivityEvent, ?User actor, array old, array new, array meta)` is called by services **in the same transaction** as the change (atomic with it). Events (enum `ActivityEvent`):
`ticket.created, ticket.updated, ticket.assigned, ticket.reassigned, priority.changed, status.changed, category.changed, reply.added, note.added, attachment.added, sla.warning, sla.breached, ticket.escalated, ticket.imported, ticket.reopened`.

Rendered as a timeline (`ActivityTimeline` component): "10:05 AM – Assigned to Agent B", "Priority changed from Medium to High". Customers see a filtered timeline (no internal-note, escalation, or SLA internals).

### 15.1 Security & admin audit log (Step 19c)

Events that belong to no ticket go to spatie/laravel-activitylog's `activity_log` table (v4.12 — v5 needs PHP 8.4):
one audit trail per kind of thing, never the same event in both.

* **`auth` log** — login (incl. remember-me), logout, failed login, lockout, registration, password reset, password
  change, two-factor confirmed/disabled. `RecordAuthAudit` (sync, discovered listener) on Laravel/Fortify events;
  password change is recorded in `Settings\SecurityController` (the starter-kit action fires no event). Failed
  login/lockout: no causer, email only as `LogRedactor::email()` HMAC. The `security` file log stays for log shipping.
* **`admin` log** — SLA policy, business-hours and holiday changes (`SlaService`) and user create/update
  (`UserManagementService`, Step 19d), each written **inside the same transaction** as the change, with field-level old/new (only fields that actually changed; nothing if nothing did).
* **No `LogsActivity` trait**: model events would miss the query-builder writes (atomic claim, escalation flags,
  bulk import) and copy ticket/note bodies. Explicit calls only: `AuditLogService::record(AuditEvent, …)` (properties
  allow-listed: ip, user_agent, guard, remember, email_hash, changes, holiday) → `AuditLogRepository`, the only class
  that calls `activity()`. Its insert is plain Eloquent on the default connection, so it joins the caller's transaction.
* **Page** — `GET /admin/audit-log` (`permission:audit.view` + `viewAuditLog` gate → `AuditLogPolicy`), 25/page,
  filters log / event / business-tz date range. `email_hash` is never sent to the browser.
* **Tickets log** (developer request, 2026-10-19) — a third log on the same page that **reads**
  `ticket_activities` (`ActivityRepository::paginateAll()` → `TicketAuditResource`, which reuses ActivityResource's
  wording and links each row to its ticket). Nothing is copied into `activity_log`. **"All logs"** (the default)
  merges both tables with §12's top-N-then-merge in `AuditLogService::paginate()`: each side returns only its newest
  `page × 25` rows (backward index-only scans), the union is sorted by `created_at` and sliced; total = sum of both
  counts. A SQL `UNION … ORDER BY created_at` would read and sort both tables in full. Admin-only, so staff-only
  events (note added, SLA, escalation) are included; ticket_activities holds no reply/note bodies.
* **Page window** — always a bounded date range: default the last `support.audit.page_default_days` (30) days,
  presets 7/30/90; older entries by picking earlier dates (nothing is moved). Bounding it also keeps the page's
  `COUNT` to that range instead of both whole tables.
* **Retention, per log** (`support.audit.*`, developer decision 2026-10-19): `activitylog:clean auth --days=180`
  and `activitylog:clean admin --days=1095`, daily (≈ one day's rows per run, range `DELETE` on
  `INDEX(log_name, created_at)`). Sign-ins hold IPs/user agents and grow fastest; admin changes are few.
  `ticket_activities` is **never** deleted (ticket timeline + reports). Considered and not done: monthly archive
  files before deleting (add only if old login history is needed), MySQL partitioning (not allowed on tables with
  FKs, so not for `ticket_activities`; overkill for `activity_log` at this volume).
* Role/permission changes are recorded too (Step 19e, §3.2).

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

## 17a. Observability (Steps 19a/19b)

* **Logs** — JSON, daily files: `app` (default), `security` (90 days), `sla`, `performance`, `queue` (14 days).
  `AssignRequestContext` (web group) puts `request_id` (incoming `X-Request-Id` if well-formed, else a UUID;
  echoed on the response) and `user_id` into Laravel `Context`, which Laravel appends to every log line and
  carries into jobs queued during the request. Never logged: passwords, tokens, ticket bodies, AI prompts/answers. Personal data rule:
  `App\Support\Logging\LogRedactor` (email → HMAC with the app key; `QueryException` → placeholder SQL).
  Technical logs ≠ audit trail (`ticket_activities`).
* **Where each event is logged** — 403/429: exception handler in `bootstrap/app.php` (not `Gate::after`:
  resource `can` flags evaluate policies on every list page). Failed login: `LogFailedLogin` (sync — the event
  holds the password). Slow query: `DB::listen` in `AppServiceProvider`. Claim conflict / no candidate:
  `TicketAssignmentService`. SLA warning/breach: the jobs, inside `DB::afterCommit` (no log line for
  rolled-back work). Job failures: `AlertOnFailedJob`.
* **Health** — `SystemHealthService` runs the spatie/laravel-health checks registered in
  `AppServiceProvider::configureHealthChecks()` on demand for `GET /health` (machine; outside the `web`
  group; `throttle:30,1` then `RequireHealthSecret`) and `/admin/system-health` (`monitoring.view`). Only
  failed/crashed checks make the result unhealthy (503).
* **Pulse** — `/pulse`, `viewPulse` = `MonitoringPolicy::view` = permission `monitoring.view` (admin).
* **Audit log (19c)** — logs are technical and rotate; who-signed-in / who-changed-SLA lives in the database
  (`activity_log`, §15.1) so an admin can search it in the app.

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

## 18a. REST API v1 (assessment bonus)

Full guide: [`API.md`](API.md). `routes/api.php` → `/api/v1/*`, `auth:sanctum` (bearer tokens only — no
`statefulApi()`, so no session/CSRF surface) + `verified` + throttle. `Api\V1\TicketController` /
`ReplyController` are twins of the web controllers: **same FormRequests, same `$this->authorize()` calls, same
services, same `paginateFor` visibility scope**; only the response differs (API Resources as JSON instead of
Inertia). No service changed to add the API — the layering doing its job.

* Tokens: `TokenController` → `ApiTokenService` → `UserRepository`. Same rules as the login form: generic error for
  unknown/wrong/inactive (fires `Failed` → security + audit log), unverified refused, two-factor `code` required
  when enabled (else the API would bypass 2FA). Issue/revoke + audit row in one transaction
  (`auth.api_token_created/revoked`). Expiry `SANCTUM_TOKEN_EXPIRATION` (30 days), pruned daily.
* Deactivated users: `Sanctum::authenticateAccessTokensUsing()` rejects their tokens immediately (API twin of
  `EnsureUserIsActive`).
* `ForceJsonResponse` (api group) — every `/api` response is JSON, incl. validation errors and `verified`.
* Domain exceptions already render as JSON for `api/*` (`shouldRenderJsonWhen`): 409 claim race, 422 transition,
  `InvalidApiCredentialsException` → 422 field error.
* Internal notes: `GET /replies` uses `ReplyRepository::forTicket()`; `AttachmentResource::download_url` points
  at the API download route for API requests. Tested in `tests/Feature/Api/ApiReplySecrecyTest.php`.
* Docs: dedoc/scramble generates OpenAPI 3.1 from routes, FormRequests, Resources and PHPDoc at `/docs/api`
  (`web` + `auth` middleware — any signed-in user; it describes shapes, not data).

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
| 12 | REST API reuses web FormRequests/policies/services; Sanctum bearer tokens; Scramble docs | separate API services; Passport/OAuth; hand-written OpenAPI | One set of rules for both surfaces; first-party, simple tokens; docs generated from code can't drift |
