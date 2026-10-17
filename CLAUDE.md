# CLAUDE.md — Support Desk (Laravel Support Ticket & SLA Management)

Laravel assessment project. Quality of core features matters more than ticking every box. I (the developer) must be able to explain every line in a technical interview — keep code simple, explicit and idiomatic. Do not add cleverness or packages without a stated reason.

## Stack
- PHP 8.3+, latest stable Laravel, MySQL 8 (InnoDB, utf8mb4), Pest
- Frontend: Laravel **React starter kit** (Inertia + React + TypeScript + Tailwind + shadcn/ui)
- RBAC: **spatie/laravel-permission** (roles admin/agent/customer + granular permissions; no `users.role` column)
- Real-time: **Laravel Reverb** (private channels; broadcasts queued)
- Queue: `database` driver (Redis optional). Scheduler: `routes/console.php`
- Mail: log/Mailpit locally. Files: private disk only.

## Read these first (source of truth)
- @docs/ARCHITECTURE.md — layers, SLA, assignment, concurrency, transactions, security
- @docs/DATABASE.md — schema, indexes, query→index map
- @docs/CODING_STRUCTURE.md — folder tree, layer templates, React component rules
- @docs/IMPLEMENTATION_PLAN.md — phases, tests, definition of done
- @docs/QUEUE_SCHEDULER_REALTIME.md — exactly what runs on queue / scheduler / Reverb / synchronously
- @docs/PROMPTS.md — step-by-step build prompts

If code and docs disagree, update the docs in the same change (or ask).

## Commands
```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
composer dev                         # or: php artisan serve + npm run dev + queue:work + schedule:work
php artisan queue:work --queue=sla,default,notifications,broadcasts
php artisan queue:work --queue=imports --timeout=3600
php artisan schedule:work            # SLA scan + auto-assign sweep
php artisan reverb:start             # WebSockets
php artisan test --compact           # Pest (MySQL test DB via .env.testing)
./vendor/bin/pint                    # format PHP
npx tsc --noEmit && npm run build    # type-check + build frontend
```

## Architecture rules (non-negotiable)
1. **Controller → Service → Repository.**
   - Controller: `FormRequest` → `$this->authorize()` → one service call → Inertia/redirect. No queries, no business rules, no transactions.
   - Service: business rules, `DB::transaction`, events/jobs, domain exceptions. Depends on repository **interfaces**.
   - Repository: Eloquent/query only (scopes, eager loading, locks, bulk ops). No events, no authorization, no business decisions, never opens a transaction.
2. **SOLID**: small single-purpose classes, interfaces in `app/Contracts`, bindings in `RepositoryServiceProvider`, strategies for assignment (`AssignmentStrategy`). Constructor injection only; no `new Service()` and no facades for domain logic inside services where injection works.
3. **DTOs** (`readonly`) go into services; **API Resources** come out to Inertia. Never pass `$request` into a service.
4. **Enums** for status, priority, role, reply type, activity event. No magic strings.
5. **spatie permissions + Policies** for every ticket/reply/attachment/import action: policies check `$user->can('permission')` AND ownership/assignment. Authorization is server-side; UI hiding is cosmetic. Repository `visibleTo($viewer)` scope also restricts lists. Check permissions, never role names, inside policies/services.
6. **Internal notes must never reach customers** — not in replies, activity timeline, notifications, attachments, search or props. Any new feature that touches replies must preserve this and include a test.

## Database rules (MySQL, ACID)
- Every table has FKs + the indexes in `docs/DATABASE.md`. New query path ⇒ add/verify an index and run `EXPLAIN`.
- **Transactions** (in services) for every multi-write operation: create ticket, claim/assign, status/priority change (incl. SLA recompute), reply + attachments, escalation, import chunk, admin SLA edits.
- Activity rows are written **inside** the same transaction. Events/notifications dispatch **after commit** (`DB::afterCommit` / `ShouldDispatchAfterCommit`).
- **Race conditions**: use atomic conditional `UPDATE … WHERE assigned_agent_id IS NULL` for claiming; `lockForUpdate` on `ticket_sequences` for numbers; `SKIP LOCKED` for auto-assign agent pick; idempotent flag updates for escalation. Never "read then write" for these.
- No N+1: eager load, select list columns only, always paginate. `Model::shouldBeStrict()` is on in dev.
- Timestamps in UTC; business-hours maths in `config('support.sla.timezone')`.
- Migrations only — never edit the DB by hand. Never edit an already-shared migration; add a new one.

## Domain rules to remember
- Ticket number: `TKT-{YYYY}-{000001}` from `ticket_sequences`, unique index; call generator late in the transaction.
- Statuses: `open, in_progress, waiting_for_customer, resolved, closed` — transitions validated by `TicketStatus::canTransitionTo()`. `escalated` is a flag, not a status.
- SLA rules live in `sla_policies`, business hours in `business_hours` + `holidays` (cached, bust on update). Do **not** hard-code SLA hours anywhere. Default SLA values are **business** minutes. Deadline = `BusinessHoursCalculator::addBusinessMinutes()`; store `sla_due_at` + `sla_warning_at`.
- Escalation runs from scheduler → queued jobs; must be idempotent (`sla_*_notified_at` flags).
- Auto-assign: least active tickets → oldest `last_assigned_at`; respects availability, category skill, capacity.
- Attachments: private disk, random names, allow-listed MIME/size from `config/support.php`, download only via authorized controller.
- Roles: `admin`, `agent`, `customer` via spatie (permission matrix in ARCHITECTURE.md §3 and PROMPTS.md Step 3).

## Queue / scheduler / Reverb rules
- **Scheduler** (time-driven): `sla:scan`, `tickets:auto-assign`, prune commands — registered in `routes/console.php`, `withoutOverlapping()->onOneServer()`.
- **Queue** (event-driven/heavy): auto-assign job, SLA warning/breach jobs, notifications + listeners, broadcasts, CSV import. Queues: `sla`, `default`, `notifications`, `broadcasts`, `imports`. Every job: dispatched after commit, idempotent, defines tries/backoff/timeout/`failed()`.
- **Synchronous in the transaction**: ticket/reply/attachment/activity rows and the claim UPDATE — never queue these.
- **Reverb**: private channels only, authorised in `routes/channels.php`; internal-note events broadcast ONLY on `tickets.{n}.staff`; payloads are small, UI does Inertia partial reloads.

## Frontend rules
- **No raw `<input>/<select>/<textarea>` or hand-rolled card markup in pages.** Use `components/form/*` (TextInput, TextAreaInput, SelectInput, FileUploadInput, DateInput, SearchInput, SubmitButton, …) and `components/cards/*` (FormCard, StatCard, TicketCard, ReplyCard, …). Add a new component rather than duplicating markup.
- Every form: `FormCard` + Inertia `useForm()`; show server errors through the field's `error` prop.
- Create/Edit share one `TicketForm`. Status/priority/SLA shown via `StatusBadge`, `PriorityBadge`, `SlaBadge`.
- TypeScript strict, no `any`; types in `resources/js/types`. Responsive layouts. Plain-text rendering of user content (no `dangerouslySetInnerHTML`).

## Code style
- `declare(strict_types=1);`, typed properties/returns, `final` services & repositories, constructor promotion. Run Pint before finishing.
- Method names: services = use-case verbs (`create`, `claim`, `assign`, `changeStatus`, `addReply`); repositories = data verbs (`paginateFor`, `claimIfUnassigned`).
- Domain exceptions (`TicketAlreadyAssignedException` → 409, `InvalidStatusTransitionException` → 422) mapped in `bootstrap/app.php`.
- Comments explain **why** (race conditions, SLA edge cases), not what.
- Conventional Commits (`feat(sla): …`).

## Testing rules
- Every phase ships with Pest tests. Priorities: SLA/business-hours (data-provider), claim race (real concurrency, MySQL), authorization matrix, internal-note secrecy, escalation idempotency, ticket numbering, import (invalid rows/duplicates), search filters + query count.
- Use `Notification::fake()`, `Queue::fake()`, `Storage::fake()`, `Event::fake()` appropriately; use MySQL for tests (FULLTEXT, locks).
- A bug fix starts with a failing test.

## Working protocol (act like a careful human developer)

I verify every step myself. You never move on without my approval. State lives in `docs/PROGRESS.md` — read it at the start of every session and after every `/clear`.

### Status legend (used in docs/PROGRESS.md)
`⬜ Not started` · `🟡 In progress` · `⏳ Awaiting verification` · `🔁 Rework` · `✅ Verified`

### Before starting a step
1. Read `docs/PROGRESS.md` and the step's prompt in `docs/PROMPTS.md`. Only the **next unverified step** may start; never skip or merge steps.
2. Post a short plan (max ~10 lines): what you will build, files you expect to touch, and any assumption. If something is ambiguous or contradicts the assessment/docs, **ask me and wait** — don't guess.
3. Set the step to `🟡 In progress` in `docs/PROGRESS.md`.

### While working
- Small, reviewable changes; explain *why* in one line before any non-obvious command (migrations, installs, destructive commands).
- Run the relevant checks yourself: `php artisan test --compact`, `./vendor/bin/pint`, and for frontend `npx tsc --noEmit` (+ `npm run build` when UI changed). Fix failures before reporting; never hide or skip a failing test.
- Do **not** `git commit`, push, `migrate:fresh` on a DB with my data, delete files, or add packages that the docs don't mention without asking (settings.json enforces the prompts).

### When the step is finished — STOP and report
Print a **Step Report** in exactly this shape, update `docs/PROGRESS.md` to `⏳ Awaiting verification`, then **stop and wait**:

```
## Step N — <name> — ⏳ Awaiting verification
**Built:** 3–6 bullets of what now exists
**Files:** created / modified (grouped by layer: migration, model, repository, service, controller, request, policy, job, React component, test)
**Checks run:** command → result (pass/fail counts)
**How I verify (manual):** exact commands/URLs/demo accounts and what I should see; the one failure case to try
**Assessment sections covered:** e.g. §3 Ticket numbering, §5 Concurrent assignment
**Decisions & assumptions:** anything I should confirm
**Known gaps / not done:** honest list
**Be ready to explain:** 3 interview questions this step could trigger
```

### After my reply
- `approved` → mark `✅ Verified` (date), make ONE Conventional Commit for the step, tell me which step is next. Do not start it until I say so.
- `changes: …` → set `🔁 Rework`, fix only what I listed, re-run checks, send a new (shorter) report.
- A question → answer it; don't change code unless asked.

### General
- Work **one step at a time** from `docs/PROMPTS.md`. Prefer small, reviewable diffs.
- Don't invent requirements; record assumptions in the README "Assumptions" section.
- Never commit secrets; update `.env.example` when adding config.
- Don't add packages, abstractions, or "future-proofing" beyond the docs unless I ask.
- Slash commands available: `/step-start N`, `/step-report`, `/step-approve N`, `/step-rework`, `/status` (see `.claude/commands/`).
