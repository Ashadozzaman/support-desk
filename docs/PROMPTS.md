# Step-by-step prompts for Claude Code

How to use
0. Copy `CLAUDE.md`, `docs/` (including `PROGRESS.md`) and the `.claude/` folder (settings + slash commands) into the project root. **Instructions live in `CLAUDE.md` → "Working protocol"; permissions in `.claude/settings.json`; shortcuts in `.claude/commands/`.**
   Instead of pasting a prompt you can type `/step-start 3` — it reads that step from this file and follows the protocol. After Claude's Step Report, reply `approved` (or `/step-approve 3`), `/step-rework <what to change>`, or ask a question. `/status` shows what is complete.
1. Put `CLAUDE.md` in the repo root and the `docs/` files in `docs/` (after Step 0 creates the project).
2. Paste **one prompt at a time**, in order. Review the diff, run the tests, commit (`git commit`), then go to the next.
3. If a step goes wrong, stay in the same step and ask Claude to fix it — don't skip ahead.
4. After each step ask yourself: *"Can I explain every file in this diff in an interview?"*

Stack decisions in this version: **spatie/laravel-permission** (RBAC) · **Laravel Reverb** (real-time notifications) · **Queue + Scheduler** exactly as mapped in `docs/QUEUE_SCHEDULER_REALTIME.md`.

---

## Step 0 — Create project & load context

Run in your terminal first:
```bash
laravel new support-desk      # choose: React starter kit → Laravel built-in auth → Pest → MySQL
cd support-desk
# copy CLAUDE.md, README.md and the docs/ folder into the project, then:
claude
```
Prompt:
```
Read CLAUDE.md and every file in docs/ (ARCHITECTURE, DATABASE, CODING_STRUCTURE, IMPLEMENTATION_PLAN, QUEUE_SCHEDULER_REALTIME).
Summarise in 15 lines: the layers, the roles/permissions approach (spatie), where queue / scheduler / Reverb are used, and the build order.
List any contradictions or unclear points between the docs and the original assessment, and propose a resolution for each.
Do not write code yet.
```

---

## Step 1 — Bootstrap & configuration

```
Step 1 of the plan: project bootstrap.
- Configure .env.example for MySQL, QUEUE_CONNECTION=database, MAIL_MAILER=log, BROADCAST_CONNECTION=log (Reverb comes in Step 11).
- Create config/support.php exactly as described in docs (sla.timezone, sla.warning_threshold_percent, assignment.strategy, uploads {max_files,max_kb,mimes}, import.chunk_size, dashboard.cache_ttl).
- Create app/Providers/RepositoryServiceProvider.php (empty bindings for now) and register it in bootstrap/providers.php.
- In AppServiceProvider: Model::shouldBeStrict() and preventLazyLoading when not production.
- Create the empty folder structure from docs/CODING_STRUCTURE.md (app/Contracts, DTOs, Enums, Services, Repositories/Eloquent, Support, etc.) with .gitkeep where needed.
- Add GitHub Actions workflow: pint --test, pest (MySQL service), tsc --noEmit, npm run build.
- Configure phpunit/pest to use a separate MySQL test database via .env.testing.example.
Acceptance: app boots, login page loads, `php artisan test` passes, pint passes.
Show me the file list changed and how to verify.
```

---

## Step 2 — Enums, migrations, models, factories, seeders

```
Step 2: database foundation. Follow docs/DATABASE.md exactly.
- Create backed enums: TicketStatus (with canTransitionTo()), TicketPriority, ReplyType, ActivityEvent, ImportStatus, SlaState. Role names are NOT a DB column — create a Role enum (Admin='admin', Agent='agent', Customer='customer') only as a source of role-name strings for spatie.
- Do NOT add a `role` column to users (RBAC comes from spatie in Step 3). Keep `is_active`.
- Migrations in the order of DATABASE.md §4 (categories, agent_profiles, category_agent, ticket_sequences, sla_policies, business_hours, holidays, tickets, ticket_replies, attachments, ticket_activities, imports, import_errors). Include every index/FK/unique and the FULLTEXT index on tickets(subject, description) with the names from the doc.
- Eloquent models with relationships, casts (enums/datetimes/json), fillable, and scopes: Ticket::scopeVisibleTo(User) (placeholder using permissions, finish in Step 3), scopeFilter, scopeActive, scopeResolved.
- Factories for every model, and seeders: CategorySeeder, SlaPolicySeeder (2880/1440/480/120 minutes, 80%), BusinessHourSeeder (Sun–Thu 09:00–18:00), DatabaseSeeder wiring.
Acceptance: `php artisan migrate:fresh --seed` works; add a Pest test that asserts the key unique/FK constraints exist (e.g. duplicate ticket_number fails).
```

---

## Step 3 — RBAC with spatie/laravel-permission

```
Step 3: RBAC using spatie/laravel-permission.
1. composer require spatie/laravel-permission; publish its migration + config; add HasRoles to User; register middleware aliases (role, permission, role_or_permission) in bootstrap/app.php.
2. Create RolesAndPermissionsSeeder (idempotent; call app(PermissionRegistrar::class)->forgetCachedPermissions()). Roles: admin, agent, customer. Permissions (guard web):
   tickets.view-any, tickets.view-queue, tickets.view-own, tickets.create, tickets.update, tickets.update-own,
   tickets.change-status, tickets.claim, tickets.assign, replies.create, notes.create, notes.view,
   attachments.upload, dashboard.view-global, dashboard.view-own, imports.manage, sla.manage,
   business-hours.manage, categories.manage, users.manage.
   Matrix: admin = all; agent = view-queue, create, update, change-status, claim, replies.create, notes.create, notes.view, attachments.upload, dashboard.view-own; customer = view-own, create, update-own, replies.create, attachments.upload.
3. Seed demo users: 1 admin, 3 agents (with agent_profiles + category skills), 5 customers — assign roles via assignRole(). Registration creates a customer (assignRole('customer')).
4. Policies (TicketPolicy, ReplyPolicy, AttachmentPolicy, ImportPolicy) — combine PERMISSION checks ($user->can('tickets.claim')) with OWNERSHIP/ASSIGNMENT checks (created_by, assigned_agent_id). Register in AuthServiceProvider/AppServiceProvider.
5. Implement Ticket::scopeVisibleTo: view-any → all; view-queue → assigned to me OR unassigned; view-own → created_by me; otherwise none().
6. Share `auth.user` {id,name,roles,permissions} through Inertia HandleInertiaRequests; add TS types + a `useCan(permission)` hook (cosmetic only).
7. Admin routes use middleware('role:admin') or permission middleware — never rely on the UI.
Tests (Pest): permission matrix dataset (role × action × own/other/unassigned) returning allow/deny; customer cannot view another customer's ticket; agent cannot view a ticket assigned to another agent; seeder is idempotent.
Update docs/ARCHITECTURE.md §3 if anything differs.
```

---

## Step 4 — Ticket numbering + ticket backend (Controller → Service → Repository)

```
Step 4: ticket backend. Follow docs/CODING_STRUCTURE.md templates.
- Support/Tickets/TicketNumberGenerator: sequence table + lockForUpdate, next() and reserve(int $count). Format TKT-{YYYY}-{%06d}.
- Contracts/Repositories/TicketRepositoryInterface + Eloquent implementation (create, findByNumberOrFail, paginateFor with eager loading + selected columns + withQueryString, countActiveForAgent). Bind in RepositoryServiceProvider.
- DTOs: CreateTicketData, UpdateTicketData. FormRequests: StoreTicketRequest, UpdateTicketRequest (with toData()).
- TicketService: create() (transaction: SLA placeholder null for now, generate number LATE in the transaction, insert), update(), changeStatus() using TicketStatus::canTransitionTo and InvalidStatusTransitionException (map to 422 in bootstrap/app.php).
- Ticket route key = ticket_number. TicketController (index with active|resolved tab, create, store, show, edit, update) — thin: authorize → service → Inertia.
- TicketResource / TicketListResource including `can` flags derived from policies.
Tests: 50 sequential creates produce unique, ordered numbers; year rollover; reserve(100) block; invalid transition → 422; validation rules; DB unique index backstop.
```

---

## Step 5 — Ticket UI with reusable components

```
Step 5: React UI for tickets using ONLY reusable components.
Create (TypeScript strict, shadcn primitives underneath):
- components/form: FormField, TextInput, TextAreaInput, SelectInput, PrioritySelect, CategorySelect, StatusSelect, SearchInput (debounced), SubmitButton.
- components/cards: FormCard, TicketCard, StatCard (for later), FilterCard.
- components/ticket: StatusBadge, PriorityBadge, TicketForm (shared create/edit), TicketTable (table on desktop, cards on mobile).
- components/data: Pagination, EmptyState; components/feedback: FlashMessage, ConfirmDialog.
Pages: tickets/index (Active / Resolved tabs), tickets/create, tickets/edit, tickets/show (basic details).
Rules: no raw input/select/textarea or ad-hoc card markup inside pages; server validation errors flow to each field's `error` prop; types in resources/js/types.
Acceptance: `npx tsc --noEmit` and `npm run build` pass; customer/agent/admin each see only permitted buttons (cosmetic) and the server still enforces.
```

---

## Step 6 — Activity / audit history

```
Step 6: activity trail.
- ActivityLogService::log(Ticket, ActivityEvent, ?User actor, array old, array new, array meta) writing ticket_activities inside the CALLER's transaction.
- Wire it into TicketService (created, updated with field-level old/new diff, status.changed, priority.changed, category.changed).
- ActivityResource: human-readable line ("Priority changed from Medium to High") + actor + time. Customer view filters out staff-only events.
- React: ActivityTimeline + ActivityItemCard on tickets/show.
Tests: each mutation writes exactly one expected activity; a failing transaction leaves no activity; customer timeline hides staff-only events.
```

---

## Step 7 — Replies, internal notes, attachments

```
Step 7: conversation + attachments.
- ReplyRepositoryInterface::forTicket(Ticket, User $viewer) — excludes internal_note unless viewer has permission notes.view.
- ReplyService::addReply / addInternalNote in a transaction: reply row + attachments + tickets.last_reply_at + first_response_at (first staff public reply) + customer reply on a resolved ticket reopens it + activity rows. Internal notes require permission notes.create.
- AttachmentService: validate against config('support.uploads'), store on PRIVATE disk as tickets/{ticket_id}/{uuid}.{ext}, save original_name/mime/size/sha256; delete the stored file if the DB insert fails (compensating action).
- AttachmentController@download behind AttachmentPolicy (internal-note attachments follow the note's visibility); headers: Content-Disposition attachment, X-Content-Type-Options nosniff.
- Throttle reply/upload endpoints.
- React: ReplyThread, ReplyCard, ReplyForm (Reply / Internal note toggle only for staff), FileUploadInput (type/size checks), AttachmentCard.
Tests: customer never receives internal notes (HTTP, Inertia props, activity, attachments, search); disallowed MIME/size rejected; stranger gets 403/404 on download; Storage::fake cleanup on failure; reopen behaviour.
```

---

## Step 8 — Assignment (manual, automatic, concurrent claim)

```
Step 8: assignment. This is a key evaluation area.
- TicketRepository::claimIfUnassigned(ticketId, agentId): single atomic UPDATE ... WHERE assigned_agent_id IS NULL AND status='open', sets assigned_at/status/version; returns true only if exactly 1 row changed.
- TicketAssignmentService: claim() (transaction + activity + TicketAssigned event after commit; throws TicketAlreadyAssignedException → 409/flash), assign() (permission tickets.assign, validates active agent, activity ticket.assigned/reassigned), autoAssign().
- Contracts/Assignment/AssignmentStrategy + LeastWorkloadStrategy (fewest active tickets, then oldest last_assigned_at; honour agent_profiles.is_available, category skill, max_active_tickets) + RoundRobinStrategy. Select the strategy from config('support.assignment.strategy'). Candidate pick uses lockForUpdate()->skipLocked() in a transaction and updates last_assigned_at.
- AutoAssignTicketJob (queue `default`, ShouldBeUnique, tries 3, backoff, afterCommit dispatch from TicketService::create) and command `tickets:auto-assign` (scheduled every minute, withoutOverlapping, onOneServer) for unassigned leftovers. Register the schedule in routes/console.php.
- React: ClaimTicketButton, AssignAgentDialog + AgentSelect, unassigned queue view; handle 409 gracefully.
Tests: sequential double claim; REAL concurrency test (tests/Concurrency) using two processes/connections against MySQL → exactly one winner; auto-assign selection (workload, availability, category, capacity, tie-break); job dispatched only after commit; manual assign permission.
Document in the final message why a conditional UPDATE is race-safe.
```

---

## Step 9 — SLA & business hours

```
Step 9: SLA engine. Follow ARCHITECTURE.md §8.
- SlaConfigRepository (policies, business hours, holidays) with Cache::rememberForever + cache bust on update.
- Support/Sla/BusinessHoursCalculator::addBusinessMinutes(CarbonImmutable $startUtc, int $minutes) per the algorithm in CODING_STRUCTURE.md: business timezone from config, skip non-working days and holidays, before-open → open, after-close → next day, loop guard.
- SlaService::calculate(priority, start) → value object {dueAt, warningAt} (warning at threshold% of SLA in business time). Use it in TicketService::create and when priority changes (recompute from created_at; write priority.changed activity).
- SlaState computation (WithinSla, Approaching, Breached, Met, MetLate) and Ticket scope for SLA filters (use sla_warning_at / sla_due_at columns).
- Admin pages (permission sla.manage / business-hours.manage): SLA policies, business hours, holidays — using reusable form components; wrap multi-row updates in a transaction; bust cache after commit.
- SlaBadge component.
Tests (unit, Pest dataset): Critical 120 min created Thu 17:00 → Sun 10:00; created Fri → from Sun 09:00; created Sun 07:30 → from 09:00; Thu 17:59 +120 → Sun 10:59; 480 min High from Sun 09:00 → Sun 17:00; 1440 min spanning days; holiday skip; boundary exactly 18:00; config change (Critical=180) picked up without code change; warning time at 80%.
```

---

## Step 10 — Scheduler escalation + queued notifications (mail + database)

```
Step 10: SLA escalation and notifications. Use docs/QUEUE_SCHEDULER_REALTIME.md as the spec.
- Command `sla:scan` (scheduled everyMinute, withoutOverlapping, onOneServer): chunkById(200) over active tickets using the indexes; dispatch ProcessSlaWarningJob / ProcessSlaBreachJob to queue `sla`.
- Jobs: in a transaction do the atomic flag update (UPDATE ... WHERE sla_*_notified_at IS NULL; stop if 0 rows), set escalated_at + escalation_level, write activity (sla.warning / sla.breached / ticket.escalated); dispatch events after commit. tries/backoff/timeout/failed() defined.
- Events + queued listeners: TicketCreated, TicketAssigned, ReplyAdded, InternalNoteAdded, TicketStatusChanged, TicketResolved, SlaWarningTriggered, SlaBreached.
- Notification classes (ShouldQueue, queue `notifications`, via ['mail','database'] — 'broadcast' is added in Step 11): TicketCreated, TicketAssigned, ReplyReceived, TicketResolved, SlaWarning, SlaBreached. Recipient rules: agent reply → customer; customer reply → assigned agent or admins if unassigned; internal note → other staff only; SLA → assignee + admins.
- Notification bell UI: unread count, list, mark read (NotificationController + component).
- Register all scheduled commands (including daily queue:prune-batches / queue:prune-failed) in routes/console.php.
Tests: Queue::fake / Notification::fake; scan run twice → ONE escalation; resolved tickets are never escalated; internal note never notifies customer; jobs dispatched after commit; schedule registration.
```

---

## Step 11 — Real-time with Laravel Reverb

```
Step 11: Reverb real-time. Follow the "Reverb" section of docs/QUEUE_SCHEDULER_REALTIME.md.
- Run install:broadcasting with Reverb; configure .env/.env.example (BROADCAST_CONNECTION=reverb, REVERB_*, VITE_REVERB_*); set up Laravel Echo on the frontend (use the package that matches the installed starter kit/Laravel version).
- routes/channels.php: private channels App.Models.User.{id}, tickets.{ticketNumber} (TicketPolicy@view), tickets.{ticketNumber}.staff (permission notes.view + view), staff.queue (permission tickets.claim), staff.alerts (dashboard.view-global), imports.{id} (owner).
- Make domain events ShouldBroadcast (afterCommit, queue `broadcasts`) per the events→channels table; INTERNAL NOTE events go only to the .staff channel. Payloads are small (ids/status/counts).
- Add the `broadcast` channel to the notifications' via() (toBroadcast) so the bell updates instantly and shows a toast.
- React hooks: useNotifications, useTicketChannel, useStaffQueue (debounced Inertia partial reloads), usePolling fallback + small connection indicator.
- Add composer script `dev:all` that runs serve, vite, queue worker(s), schedule:work and reverb:start.
Tests: channel authorization (customer cannot authorize another customer's ticket channel or any .staff channel; agent can; admin can); broadcast events fired with correct channels (Event::fake); internal note not broadcast on the public channel.
```

---

## Step 12 — Search & filtering

```
Step 12: search & filters.
- TicketFilterData + TicketFilterRequest (ticket number, keyword, status, priority, category, assigned agent, customer, created_from/to, sla_state).
- Ticket::scopeFilter: ticket_number exact/prefix (uses unique index), keyword via FULLTEXT MATCH ... AGAINST (boolean mode; LIKE fallback in a config flag), SLA predicates from ARCHITECTURE §8.3. Visibility scope always applied first.
- Pagination with withQueryString; per_page capped.
- React: TicketFilters + FilterCard using the reusable fields (SearchInput, selects, DateRangeInput, AgentSelect); filters synced to the URL with preserveState.
Tests: each filter; combined filters; SLA state filters; visibility respected with filters; query-count assertion (no N+1) on the index page; document EXPLAIN output for 4 hot queries in README.
```

---

## Step 13 — Dashboard

```
Step 13: dashboard.
- DashboardRepository: ONE aggregate query with conditional SUMs (open total, unassigned, critical, SLA breached, approaching, resolved) + GROUP BY priority + GROUP BY agent (joined with users). DashboardService caches per role/user for config('support.dashboard.cache_ttl') seconds.
- Admin (dashboard.view-global): global numbers; agent (dashboard.view-own): own workload + unassigned count + their approaching/breached; customer: simple own-ticket counts.
- React: StatCard grid, by-priority and by-agent lists, "approaching SLA" table with SlaBadge; live refresh via staff.queue channel (partial reload, debounced).
Tests: counts against seeded fixtures; scoped numbers per role; cache hit/expiry; query count stays constant.
```

---

## Step 14 — CSV import (queue + progress)

```
Step 14: CSV import. Follow ARCHITECTURE §13 + QUEUE doc.
- Admin page (permission imports.manage): upload CSV (type/size validation) → store on private disk → imports row (pending) → dispatch ProcessTicketImportJob on queue `imports` (timeout 3600, tries 1, failed() marks import failed).
- Stream with league/csv (never load whole file); chunks of config('support.import.chunk_size'); per-row validation (reuse rules), resolve category/customer, dedupe by external_ref (also inside the file), invalid rows → import_errors with row number + messages.
- Per chunk: one transaction → reserve ticket numbers (TicketNumberGenerator::reserve) → compute SLA via SlaService → bulk insert tickets + one `ticket.imported` activity per ticket; update counters; broadcast ImportProgressUpdated on imports.{id}.
- UI: imports index/create/show with progress bar (Reverb + polling fallback) and downloadable error CSV; sample file docs/samples/tickets-import.csv.
Tests: valid file; invalid rows reported; duplicates skipped; 20k generated rows within a memory limit; chunk failure rolls back only that chunk; job failure state.
```

---

## Step 15 — Hardening & test review

```
Step 15: hardening.
- Rate limiting: login, replies, uploads, import.
- Review every route for authorization (list route → middleware/policy/permission table and show gaps).
- Confirm internal-note secrecy end-to-end (replies, activity, search, notifications, broadcasts, attachments) with tests.
- Run pint, pest, tsc, build. Report coverage of critical areas vs docs/IMPLEMENTATION_PLAN.md test strategy and add missing tests.
- Check N+1 with preventLazyLoading by visiting every page via feature tests.
- Verify no secrets in repo; .env.example complete (incl. REVERB_*).
Output a short security + performance review with any remaining risks.
```

---

## Step 16 — Docs, README, optional extras

```
Step 16: finish documentation.
- Complete README.md: setup, env, migrate/seed, queue workers, scheduler (cron), Reverb start, tests, demo accounts, assumptions (SLA = business hours, recompute from creation), EXPLAIN outputs, a "Background processing" section copied from docs/QUEUE_SCHEDULER_REALTIME.md §1, key architectural decisions.
- Make sure docs/ARCHITECTURE.md, DATABASE.md and CODING_STRUCTURE.md match the final code (spatie, Reverb, final index names).
- Optional (only if everything above is solid): Docker compose (php, nginx, mysql, redis, mailpit, reverb), OpenAPI docs, auto-close resolved tickets, SLA pause on waiting_for_customer.
Finish with an interview cheat-sheet: the 10 decisions I must be able to explain, and for each one the file + line range to open.
```

---

## Handy "between steps" prompts

**Review before commit**
```
Review the current diff against CLAUDE.md: layering violations, missing transactions/indexes, authorization gaps, internal-note leaks, N+1, missing tests. List problems by severity and fix them.
```
**Interview rehearsal**
```
Quiz me on the code we just wrote: ask 5 progressively harder questions (why this design, edge cases, what breaks under concurrency), wait for my answer each time, then grade it.
```
**Live-change drill**
```
Simulate an interviewer request: "<e.g. add a 'Urgent' priority with 1-hour SLA / agents may not reassign / change business hours to Mon–Fri>". Show the minimal change set, which single place it lives in, and the tests to update.
```
