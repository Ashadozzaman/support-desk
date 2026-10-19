# Support Desk — Support Ticket & SLA Management System

Laravel + React (Inertia) application for managing support tickets with SLA tracking, business-hours-aware deadlines, escalation, assignment and audit history.

> Template README: fill in the bracketed items as you build. Submission may be rejected without clear setup instructions.

## Features
Tickets (CRUD, resolve/close, active vs resolved views) · `TKT-YYYY-NNNNNN` numbering · roles (Admin / Agent / Customer) with policies · manual + automatic assignment · concurrency-safe ticket claim · replies + internal notes · secure attachments · SLA by priority with business hours · scheduled escalation · queued email/database notifications · search & filters · CSV import · dashboard · activity history.

## Tech stack
PHP 8.3+, Laravel [version], MySQL 8, React + TypeScript (Laravel React starter kit, Inertia, Tailwind, shadcn/ui), spatie/laravel-permission, Laravel Reverb, Pest.

## Requirements
PHP 8.3+, Composer, Node 20+, MySQL 8, (optional) Docker.

## Local setup
```bash
git clone [repo-url] support-desk && cd support-desk
composer install
npm install
cp .env.example .env
php artisan key:generate
```
Edit `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=support_desk
DB_USERNAME=[user]
DB_PASSWORD=[password]
QUEUE_CONNECTION=database
MAIL_MAILER=log            # or smtp → Mailpit
```
Create the database, then:
```bash
php artisan migrate --seed
php artisan storage:link   # not needed for attachments (private disk), only for public assets
npm run dev                # or: npm run build
php artisan serve
```

## Background processing (required: queue + scheduler + Reverb)
```bash
php artisan queue:work --queue=sla,default,notifications,broadcasts
php artisan queue:work --queue=imports --timeout=3600   # CSV import worker
php artisan schedule:work          # local; production cron: * * * * * php artisan schedule:run
php artisan reverb:start           # real-time notifications (WebSockets)
```
Shortcut: `composer dev:all` runs everything. Add the Reverb variables from `.env.example` (`BROADCAST_CONNECTION=reverb`, `REVERB_*`, `VITE_REVERB_*`).

| Requirement | Mechanism |
|---|---|
| Auto assignment | queued `AutoAssignTicketJob` + scheduled `tickets:auto-assign` sweep |
| SLA escalation | scheduled `sla:scan` (every minute) → queued `ProcessSla*Job` |
| Email / in-app notifications | queued notifications (`mail`, `database`, `broadcast`) |
| Real-time updates | Laravel Reverb private channels |
| CSV import | queued `ProcessTicketImportJob` (chunked, own worker) |
| Claim ticket, activity, replies | synchronous inside DB transactions |

Full detail: [`docs/QUEUE_SCHEDULER_REALTIME.md`](docs/QUEUE_SCHEDULER_REALTIME.md).

## Demo accounts (seeded)
| Role | Email | Password |
|---|---|---|
| Admin | admin@example.com | password |
| Agent | agent1@example.com | password |
| Customer | customer1@example.com | password |

(Local/demo only. No production credentials in the repo.)

## Running tests
```bash
cp .env.example .env.testing   # point DB_DATABASE to a separate MySQL database
php artisan test --compact
./vendor/bin/pint --test
npx tsc --noEmit
```

## CSV import
Admin → Imports → upload CSV. Expected columns: `external_ref, subject, description, priority, category, customer_email, status, created_at` (sample: `docs/samples/tickets-import.csv`). Processing is queued; progress and a downloadable error report are available on the import page.

## Architecture overview
Controller → Service → Repository (SOLID, DTOs, enums, spatie RBAC + policies). Transactions live in services; events dispatch after commit. Details: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), [`docs/DATABASE.md`](docs/DATABASE.md), [`docs/CODING_STRUCTURE.md`](docs/CODING_STRUCTURE.md).

### Key decisions
1. **Ticket numbers** — `ticket_sequences` row lock per year + unique index; width grows past 6 digits.
2. **Concurrent claim** — atomic `UPDATE … WHERE assigned_agent_id IS NULL`; loser gets HTTP 409.
3. **Automatic assignment** — least active tickets, tie-break by oldest `last_assigned_at`; honours availability, category skills, capacity; `SKIP LOCKED` pick.
4. **SLA** — rules in `sla_policies`; deadlines via `BusinessHoursCalculator` using `business_hours` + `holidays`; `sla_due_at` / `sla_warning_at` stored and indexed.
5. **Escalation** — scheduled `sla:scan` → idempotent queued jobs → activity + notifications.
6. **Internal notes** — hidden by repository scope, resources, notifications, attachment policy (tested).
7. **CSV import** — streamed, chunked, per-chunk transactions, duplicate detection by `external_ref`, error report.

### Assumptions
- SLA durations are **business hours** (Low 48h, Medium 24h, High 8h, Critical 2h), warning at 80 %.
- Business days Sun–Thu, 09:00–18:00 in `[timezone]`; holidays configurable.
- Priority change recalculates the deadline from ticket creation time.
- Admin-created accounts are marked email-verified (the admin vouches for the address); the user sets their own password from an emailed reset link.
- A user is an "agent" when their role has the `tickets.claim` permission and they have an agent profile — not because the role is named `agent`.
- [Add your own]

### EXPLAIN evidence
[Paste `EXPLAIN` output for: customer list, agent queue, SLA scan, keyword search.]

## Optional / bonus
[Docker, API docs, caching, real-time, etc. — list what you actually implemented.]

## Project structure
See `docs/CODING_STRUCTURE.md`.
