# Support Desk — Implementation Plan

Phases mirror the steps in [`PROMPTS.md`](PROMPTS.md) 1:1 — this doc is the tracker: what "done" means for each phase and what tests gate moving on. Work **one phase at a time**; don't start a phase until the previous one's Definition of Done (DoD) is met (per CLAUDE.md's working agreement).

Legend: ✅ test type required — `unit` (Pest, no DB side effects beyond factories), `feature` (HTTP/Inertia), `concurrency` (real parallel DB connections), `fake` (`Queue::fake`/`Notification::fake`/`Event::fake`/`Storage::fake`).

---

## Phase 1 — Bootstrap & configuration
**Goal:** app boots with the right env, config, folder skeleton, and CI.
**Deliverables:** `.env.example` (MySQL, `QUEUE_CONNECTION=database`, `MAIL_MAILER=log`, `BROADCAST_CONNECTION=log`), `config/support.php`, empty `RepositoryServiceProvider`, `Model::shouldBeStrict()` + `preventLazyLoading` in non-prod, folder skeleton from `CODING_STRUCTURE.md`, GitHub Actions (pint, pest w/ MySQL service, tsc, build), `.env.testing.example`.
**Tests:** none yet beyond `php artisan test` passing on the default scaffold.
**DoD:** app boots, login page loads, `php artisan test` green, `./vendor/bin/pint --test` clean, CI workflow runs on push.

## Phase 2 — Enums, migrations, models, factories, seeders
**Goal:** full schema from `DATABASE.md` exists and is seedable.
**Deliverables:** `TicketStatus`, `TicketPriority`, `ReplyType`, `ActivityEvent`, `ImportStatus`, `SlaState`, `Role` enums; migrations in FK-dependency order (§4 of `DATABASE.md`) with every index/FK/unique/FULLTEXT; models + relationships + casts + `scopeFilter`/`scopeActive`/`scopeResolved` (placeholder `scopeVisibleTo`, finished in Phase 3); factories for every model; `CategorySeeder`, `SlaPolicySeeder`, `BusinessHourSeeder`, `DatabaseSeeder`.
**Tests:** `unit` — duplicate `ticket_number` fails at the DB level; each FK/unique constraint exists.
**DoD:** `php artisan migrate:fresh --seed` succeeds; constraint tests pass.

## Phase 3 — RBAC (spatie/laravel-permission)
**Goal:** server-side authorization is real and testable.
**Deliverables:** spatie installed + migrated; `RolesAndPermissionsSeeder` (idempotent, busts permission cache); demo users (1 admin, 3 agents w/ profiles + skills, 5 customers); `TicketPolicy`, `ReplyPolicy`, `AttachmentPolicy`, `ImportPolicy`; `Ticket::scopeVisibleTo` finished; `auth.user` shared via Inertia incl. permissions; `useCan` hook; admin routes behind `role:`/`permission:` middleware.
**Tests:** `feature` — permission matrix dataset (role × action × own/other/unassigned → allow/deny); customer cannot view another customer's ticket; agent cannot view a ticket assigned to another agent; `unit` — seeder idempotency.
**DoD:** permission matrix test suite green; no controller/route relies on `hasRole()` directly for authorization decisions (grep check).

## Phase 4 — Ticket numbering + ticket backend
**Goal:** Controller → Service → Repository fully wired for tickets, numbering race-safe.
**Deliverables:** `TicketNumberGenerator` (`next()`, `reserve()`); `TicketRepositoryInterface` + Eloquent impl bound in the provider; `CreateTicketData`/`UpdateTicketData` + `FormRequest::toData()`; `TicketService::create/update/changeStatus`; route model binding by `ticket_number`; `TicketController` (index w/ active|resolved tabs, create, store, show, edit, update); `TicketResource`/`TicketListResource` with `can` flags.
**Tests:** `unit` — 50 sequential creates → unique ordered numbers; year rollover; `reserve(100)` block; invalid transition → 422; `feature` — validation rules; DB unique index backstop.
**DoD:** full CRUD works end-to-end through Inertia; numbering tests green under sequential load.

## Phase 5 — Ticket UI
**Goal:** ticket CRUD has a real UI built only from reusable components.
**Deliverables:** `components/form/*`, `components/cards/*`, `components/ticket/*` (`StatusBadge`, `PriorityBadge`, `TicketForm`, `TicketTable`), `components/data/*`, `components/feedback/*`; pages `tickets/index|create|edit|show`.
**Tests:** none backend-new; acceptance is manual + build checks.
**DoD:** `npx tsc --noEmit` and `npm run build` pass; role-appropriate buttons render (cosmetic) while server still enforces if bypassed.

## Phase 6 — Activity / audit history
**Goal:** every mutation leaves exactly one correct activity row, atomically.
**Deliverables:** `ActivityLogService::log()`; wired into `TicketService` (created/updated/status/priority/category changes); `ActivityResource` + human-readable rendering; `ActivityTimeline` component; customer-filtered timeline.
**Tests:** `unit`/`feature` — each mutation type writes exactly the expected activity; a rolled-back transaction leaves no activity row; customer timeline hides staff-only events.
**DoD:** timeline renders correctly for all three roles; no mutation path exists that skips activity logging.

## Phase 7 — Replies, internal notes, attachments
**Goal:** conversation + files work, internal-note secrecy is airtight.
**Deliverables:** `ReplyRepositoryInterface::forTicket` (excludes `internal_note` without `notes.view`); `ReplyService::addReply/addInternalNote` (reply + attachments + `last_reply_at`/`first_response_at`/reopen + activity, one transaction); `AttachmentService` (private disk, allow-list, compensating delete on DB failure); `AttachmentController@download` behind policy; throttling; `ReplyThread`/`ReplyForm`/`FileUploadInput`/`AttachmentCard`.
**Tests:** `feature` — customer never receives internal notes via HTTP, Inertia props, activity, attachments, or search; disallowed MIME/size rejected; stranger gets 403/404 on download; `fake` — `Storage::fake` cleanup on insert failure; reopen-on-customer-reply behaviour.
**DoD:** the full internal-note-secrecy test suite (CLAUDE.md rule 6) is green across every surface listed.

## Phase 8 — Assignment (manual, automatic, concurrent claim)
**Goal:** exactly one agent ever wins a claim; auto-assign respects workload/availability/skill/capacity.
**Deliverables:** `claimIfUnassigned()` atomic UPDATE; `TicketAssignmentService::claim/assign/autoAssign`; `AssignmentStrategy` + `LeastWorkloadStrategy`/`RoundRobinStrategy` selected via config; `AutoAssignTicketJob` (`ShouldBeUnique`, afterCommit) + `tickets:auto-assign` scheduled command; `ClaimTicketButton`/`AssignAgentDialog`.
**Tests:** `feature` — sequential double claim; `concurrency` — real two-connection claim test, exactly one winner; `unit` — strategy selection (workload, availability, category, capacity, tie-break); `fake` — job dispatched only after commit; `feature` — manual assign requires `tickets.assign`.
**DoD:** the concurrency test passes against real MySQL (not SQLite); written explanation of why the conditional UPDATE is race-safe is in the final phase summary.

## Phase 9 — SLA & business hours
**Goal:** SLA deadlines compute correctly against configurable business hours, with no hard-coded hours anywhere.
**Deliverables:** `SlaConfigRepository` (cached, busted on update); `BusinessHoursCalculator::addBusinessMinutes`; `SlaService::calculate` (+ recompute on priority change); `SlaState` + scope; admin pages for policies/business hours/holidays; `SlaBadge`.
**Tests:** `unit` dataset — the worked example (Critical 120 min, Thu 17:00 → Sun 10:00) + the boundary/holiday/config-change cases listed in `PROMPTS.md` Step 9.
**DoD:** every dataset case passes; changing `sla_policies.resolution_minutes` via the admin UI changes computed deadlines with zero code changes.

## Phase 10 — Scheduler escalation + queued notifications
**Goal:** SLA breaches/warnings are detected on a schedule and notified exactly once via a queue.
**Deliverables:** `sla:scan` command; `ProcessSlaWarningJob`/`ProcessSlaBreachJob` (atomic flag update, activity, afterCommit events); domain events + queued listeners; notification classes (`mail`+`database` only — `broadcast` added in Phase 11); notification bell UI; scheduler registration incl. daily prune commands.
**Tests:** `fake` — scan run twice → one escalation; resolved tickets never escalated; internal note never notifies the customer; jobs dispatched after commit; `unit` — schedule registration assertion.
**DoD:** idempotency tests green; escalation flow observable end-to-end with `queue:work`+`schedule:work` running locally.

## Phase 11 — Real-time with Reverb
**Goal:** live updates over private, correctly-authorised channels; internal notes never leak to a public channel.
**Deliverables:** `install:broadcasting` (Reverb) + Echo on the frontend; `routes/channels.php` per `QUEUE_SCHEDULER_REALTIME.md` §4; events made `ShouldBroadcast` (afterCommit, queue `broadcasts`); `broadcast` added to notification `via()`; `useNotifications`/`useTicketChannel`/`useStaffQueue`/`usePolling`; `composer dev:all`.
**Tests:** `feature` — channel authorization matrix (customer cannot authorise another customer's channel or any `.staff` channel; agent/admin can); `fake` — `Event::fake` asserts correct channels per event; internal note never appears on the public channel.
**DoD:** channel-auth test suite green; manually verified in two browser sessions (agent + customer) that a note posted by the agent never reaches the customer's live view.

## Phase 12 — Search & filtering
**Goal:** list queries stay fast and N+1-free under every filter combination.
**Deliverables:** `TicketFilterData`/`TicketFilterRequest`; `scopeFilter` (ticket number, FULLTEXT keyword w/ LIKE fallback, SLA predicates); `withQueryString` pagination; `TicketFilters`/`FilterCard` UI synced to the URL.
**Tests:** `feature` — each filter individually + combined; SLA-state filters; visibility scope still applied under filters; query-count assertion on the index page (no N+1).
**DoD:** query-count test passes at a fixed count regardless of result size; `EXPLAIN` output for the 4 hottest queries captured for the README.

## Phase 13 — Dashboard
**Goal:** one aggregate query per role, cached, correct per role's scope.
**Deliverables:** `DashboardRepository` (single conditional-SUM query + GROUP BYs); `DashboardService` (cached `support.dashboard.cache_ttl` seconds per role/user); `StatCard` grid + lists + "approaching SLA" table; live refresh via `staff.queue`.
**Tests:** `feature` — counts match seeded fixtures; numbers correctly scoped per role; `unit` — cache hit/expiry; query count stays constant regardless of ticket volume.
**DoD:** dashboard query count is O(1) (not per-widget); cache tests green.

## Phase 14 — CSV import
**Goal:** large files import without loading the whole file into memory, errors are reported per row, duplicates are skipped.
**Deliverables:** admin upload page; `ProcessTicketImportJob` (streamed via `league/csv`, chunked); per-chunk transaction (reserve numbers, compute SLA, bulk insert, one activity per ticket); `import_errors` + downloadable error CSV; progress UI (Reverb + polling fallback); `docs/samples/tickets-import.csv`.
**Tests:** `feature` — valid file end-to-end; invalid rows reported with correct row numbers; duplicates (by `external_ref`) skipped; a ~20k-row generated file stays within a defined memory limit; chunk failure rolls back only that chunk; job `failed()` sets import status.
**DoD:** memory-bounded import test passes; one malformed row never fails the whole file.

## Phase 15 — Hardening & test review
**Goal:** close any remaining authorization/performance gaps before polishing docs.
**Deliverables:** rate limiting (login, replies, uploads, import); full route-by-route authorization audit (table of route → middleware/policy/permission, gaps called out and fixed); end-to-end internal-note-secrecy re-verification; `pint`/`pest`/`tsc`/`build` all green; N+1 check via `preventLazyLoading` across every page (feature tests that visit each); secrets scan; `.env.example` completeness check (incl. `REVERB_*`).
**Tests:** whatever the audit surfaces, written as new Pest tests before being fixed (bug-fix-starts-with-a-failing-test rule).
**DoD:** a short security + performance review is produced listing any remaining known risks (not silently fixed/hidden).

## Phase 16 — Docs, README, optional extras
**Goal:** the repo is reviewer-ready.
**Deliverables:** complete `README.md` (setup, env, migrate/seed, queue workers, scheduler cron, Reverb start, tests, demo accounts, assumptions incl. SLA-as-business-hours and priority-change recompute-from-creation, `EXPLAIN` outputs, "Background processing" section copied from `QUEUE_SCHEDULER_REALTIME.md` §1, key decisions log); `ARCHITECTURE.md`/`DATABASE.md`/`CODING_STRUCTURE.md` reconciled against final code; optional-only-if-solid extras (Docker compose, OpenAPI, auto-close, SLA pause).
**Tests:** none new; this phase is a correctness pass over documentation vs. shipped code.
**DoD:** interview cheat-sheet produced — the decisions in ARCHITECTURE.md §19 each mapped to a file + line range; every doc matches the code it describes.

---

## Overall Definition of Done (all phases)

* Every multi-write operation listed in CLAUDE.md's "Database rules" runs inside a service-owned transaction.
* No layering violation: controllers never query, repositories never open transactions or dispatch events.
* Internal notes are unreachable by a customer through any surface (replies, timeline, notifications, attachments, search, broadcasts, Inertia props).
* No hard-coded SLA hours anywhere; business-hours math only in `BusinessHoursCalculator`.
* Every job: dispatched after commit, idempotent, has `tries`/`backoff`/`timeout`/`failed()`.
* `./vendor/bin/pint --test`, `php artisan test --compact`, `npx tsc --noEmit`, `npm run build` all pass at each phase boundary.
