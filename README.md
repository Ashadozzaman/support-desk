# Support Desk — Support Ticket & SLA Management System

Laravel + React (Inertia) application for managing support tickets with SLA tracking, business-hours-aware deadlines, escalation, assignment and audit history.

**For the reviewer — where to look first:**
[Testing strategy](#testing-strategy--what-i-chose-to-test-and-why) ·
[Key decisions](#key-decisions) ·
[EXPLAIN evidence](#explain-evidence) ·
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) ·
[`docs/CONCURRENT_TICKET_ASSIGNMENT.md`](docs/CONCURRENT_TICKET_ASSIGNMENT.md) ·
[`docs/SLA.md`](docs/SLA.md) ·
[`docs/TEST.md`](docs/TEST.md) ·
[`docs/API.md`](docs/API.md) (REST API, docs at `/docs/api`)

## Features
Tickets (CRUD, resolve/close, active vs resolved views) · `TKT-YYYY-NNNNNN` numbering · roles (Admin / Agent / Customer) with policies · manual + automatic assignment · concurrency-safe ticket claim · replies + internal notes · secure attachments · SLA by priority with business hours · scheduled escalation · queued email/database notifications · search & filters · CSV import · dashboard · activity history.

## Tech stack
PHP 8.3+, Laravel 13, MySQL 8, React + TypeScript (Laravel React starter kit, Inertia, Tailwind, shadcn/ui), spatie/laravel-permission, Laravel Reverb, Pest.

## Requirements
PHP 8.3+, Composer, Node 20+, MySQL 8, (optional) Docker.

## Local setup
```bash
git clone https://github.com/Ashadozzaman/support-desk.git && cd support-desk
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
DB_USERNAME=root           # your MySQL user
DB_PASSWORD=               # your MySQL password
QUEUE_CONNECTION=database
MAIL_MAILER=log            # or smtp → Mailpit
SUPPORT_SLA_TIMEZONE=Asia/Dhaka   # business-hours timezone (DB stays UTC)
```
All `SUPPORT_*` settings (SLA warning %, assignment strategy, upload limits, import chunk size, dashboard cache) are listed in `.env.example` and read by `config/support.php`.

Create the database, then:
```bash
php artisan migrate --seed
# optional, for performance testing only: ~1M tickets (takes a while)
# php artisan db:seed --class=PerformanceSeeder
php artisan storage:link   # not needed for attachments (private disk), only for public assets
npm run dev                # or: npm run build
php artisan serve
```

## Run with Docker
Full stack (php-fpm, nginx, MySQL 8, Redis, Mailpit, queue + import workers, scheduler, Reverb), each with a healthcheck:
```bash
cp .env.docker.example .env.docker
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env.docker
docker compose up -d --build
docker compose exec app php artisan db:seed --force
```
App <http://localhost:8000> · Mailpit <http://localhost:8025> · tests `docker compose run --rm tests`.
Details (services, why no Supervisor, image stages, troubleshooting): [`docs/DOCKER.md`](docs/DOCKER.md).

## CI/CD (Jenkins)
Sample `Jenkinsfile`: install → configure → lint (Pint, tsc) → test (Pest on MySQL) → build; `Deploy` on `main` is a placeholder.

## Background processing (required: queue + scheduler + Reverb)
```bash
php artisan queue:work --queue=sla,default,mail,notifications,broadcasts,exports,ai
php artisan queue:work --queue=imports --timeout=3600   # CSV import worker
php artisan schedule:work          # local; production cron: * * * * * php artisan schedule:run
php artisan reverb:start           # real-time notifications (WebSockets)
```
Shortcut: `composer dev` runs serve, Vite, the main queue worker, `schedule:work` and Reverb together (`php artisan dev:list` shows them). The import worker is left out on purpose, so run it in its own terminal. Add the Reverb variables from `.env.example` (`BROADCAST_CONNECTION=reverb`, `REVERB_*`, `VITE_REVERB_*`).

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
| Admin | admin@support-desk.test | password |
| Agent | agent1@support-desk.test (also agent2, agent3) | password |
| Customer | customer1@support-desk.test (also customer2–5) | password |

(Local/demo only. No production credentials in the repo.)

## Testing
**488 Pest tests, 2085 assertions, all passing** (Unit + Feature + a real Concurrency suite) on MySQL 8.
Full guide, including how to run a single test: [`docs/TEST.md`](docs/TEST.md).

```bash
cp .env.testing.example .env.testing     # separate MySQL DB: support_desk_test
php artisan key:generate --env=testing
php artisan test --compact               # everything (~5 min)
php artisan test --testsuite=Concurrency # the two-agent claim race
php artisan test tests/Unit/Sla          # one folder
php artisan test --filter="Thursday 17:00"   # one test by name
./vendor/bin/pint --test && npx tsc --noEmit
```

The tests need MySQL because they rely on FULLTEXT search, row locks (`FOR UPDATE` / `SKIP LOCKED`) and two real connections.

### Testing strategy — what I chose to test and why
I tested the places where a bug costs the most and where clicking through the app by hand can't catch it:

| Risk | Proven by |
|---|---|
| Two agents claim the same ticket | `tests/Concurrency/TicketClaimConcurrencyTest.php`: two OS processes, two MySQL connections, exactly one winner |
| Wrong SLA deadline (business hours, weekends, holidays, 18:00 boundary) | `tests/Unit/Sla/BusinessHoursCalculatorTest.php`: dataset incl. Thu 17:00 + 120 min → Sun 10:00 |
| A user sees or does something they shouldn't | `tests/Feature/Rbac/TicketAbilityMatrixTest.php`: role × action × own/other/unassigned |
| An internal note reaches a customer | `ReplyTest`, `InternalNoteBroadcastSecrecyTest`, `ChannelAuthorizationTest`, `NotificationRecipientsTest` |
| The same breach is escalated or emailed twice | `tests/Feature/Sla/SlaScanTest.php`: scan run twice → one escalation |
| Duplicate ticket numbers | `TicketNumberGeneratorTest` (50 creates, year rollover, `reserve(100)`) + unique-index test |
| Invalid status change | `TicketStatusTransitionTest`: e.g. Closed → Open → 422 |
| Auto-assign overloads an agent | `LeastWorkloadStrategyTest`: availability, capacity, category skill, tie-break |
| A bad CSV row fails the import | `TicketImportTest`: invalid rows reported, duplicates skipped |
| Slow list (N+1) / filter leaks rows | `TicketFilterTest`, `TicketIndexPaginationTest`: query count + visibility under filters |

**Mostly feature tests**, so the whole request path (middleware → policy → service → DB) is proven. **Unit tests** cover pure
logic with many edge cases. **One concurrency test** covers the claim race, because sequential calls can't prove it.
**Not tested on purpose:** React components (covered by strict `tsc` + build; the server enforces every rule anyway), and
Laravel's own auth scaffolding.

## CSV import
Admin → Imports → upload CSV. Expected columns: `external_ref, subject, description, priority, category, customer_email, status, created_at` (sample: `docs/samples/tickets-import.csv`). Processing is queued; progress and a downloadable error report are available on the import page.

## Email-to-ticket (support mailbox)
A customer emails the support mailbox → a ticket is created through the normal `TicketService` (SLA, number,
auto-assign, notifications). Their reply to that conversation is added to **the same ticket** — matched by
`In-Reply-To` / `References`, or a `[TKT-…]` number in the subject, and only on the sender's own ticket; never by
subject text. The same message fetched twice never creates a second ticket (UNIQUE Message-ID).
Ticket emails to the customer carry the conversation headers (`Message-ID` we remember, `In-Reply-To`,
`References`, `[TKT-…]` subject, `Reply-To` the mailbox), so "Reply" in their mail client lands on the ticket.
Auto-replies, bounces, list mail, our own mail and staff addresses are never imported, and one address can send at
most 20 messages an hour (`INBOUND_MAX_PER_SENDER_PER_HOUR`).

**Setup (Gmail):**
1. On the Google account turn on 2-Step Verification, then create an app password at
   <https://myaccount.google.com/apppasswords> (16 characters, shown once).
2. Either put it in `.env` — `IMAP_USERNAME=you@gmail.com`, `IMAP_PASSWORD="abcd efgh ijkl mnop"` (quoted: it has
   spaces) — or type it on **Admin → Mailbox** (stored encrypted with `APP_KEY`; a saved password wins over `.env`).
   The password is never shown again, sent to the browser or written to logs; leave the field blank to keep it.
3. **Admin → Mailbox**: host `imap.gmail.com`, port `993`, SSL, folder `INBOX` are prefilled → **Test connection**
   → tick **Enabled** → **Save** → **Sync now**. The first sync only records the starting point: mail already in the
   inbox is never imported. Send a test email from another address and sync again.

**Running it:** a worker must listen on the `mail` queue and the scheduler must run:
```bash
php artisan queue:work --queue=sla,default,mail,notifications,broadcasts,exports,ai
php artisan schedule:work            # mailbox:sync every minute; it syncs when the configured interval has passed
php artisan mailbox:sync --now       # queue one sync immediately
```
Messages are read without being marked read, moved or deleted. Each sync is listed in **Sync history**
(fetched / new tickets / replies / skipped / duplicates / failed).

**Testing:** `php artisan test --compact tests/Feature/Inbound` — IMAP is replaced by `FakeMailboxClient`; no real
mailbox or credentials are needed.

**Troubleshooting:** *Authentication failed* → wrong or revoked app password (the normal Google password doesn't
work over IMAP). *Could not connect* → host/port/encryption, or outbound port 993 blocked. *Nothing imported* →
the first sync is the starting point; check that the `mail` worker and the scheduler run, and the Skipped/Failed
counts in Sync history (`inbound_messages.skip_reason` says why a message was skipped: `auto_reply`, `bounce`,
`bulk_mail`, `own_mail`, `staff_sender`, `rate_limited`, …). Customers receive agents'
replies by email only with a real mailer (`MAIL_MAILER=smtp`).

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
- Business days Sun–Thu, 09:00–18:00 in `Asia/Dhaka` (`SUPPORT_SLA_TIMEZONE`); hours and holidays are editable by an admin.
- Priority change recalculates the deadline from ticket creation time.
- Admin-created accounts are marked email-verified (the admin vouches for the address); the user sets their own password from an emailed reset link.
- Email-to-ticket (Step 25, `docs/IMAP.md`): an email from an unknown address creates an unverified customer account and **no email is sent to it** (the From address can be forged); email tickets start at Medium priority with no category; a reply threads only onto the sender's own ticket, and a reply to a closed ticket starts a new one.
- A user is an "agent" when their role has the `tickets.claim` permission and they have an agent profile — not because the role is named `agent`.
- Roles are editable by admins (`/admin/roles`); permissions are fixed in code. The role/permission table in `docs/ARCHITECTURE.md` §3 is the seeded default. Escalation and new-ticket emails go to everyone with `dashboard.view-global`.
- `escalated` is a flag (`escalated_at`, `escalation_level`), not a status, so a ticket can be escalated in any status.
- A customer reply on a *resolved* ticket reopens it; a *closed* ticket is final.
- The SLA clock does not pause while a ticket is `waiting_for_customer` (design hook only, not in v1).
- Ticket URLs use `ticket_number`, never the numeric id, so tickets can't be enumerated.
- Imported tickets get SLA deadlines like normal tickets but don't email customers.
- The help centre (`/help`) is for signed-in users only; there is no public, logged-out help page.
- AI is off unless `AI_ENABLED=true` and a provider with its key are set: `AI_PROVIDER=anthropic` + `ANTHROPIC_API_KEY`, or `AI_PROVIDER=openai` + `OPENAI_API_KEY` (set `AI_MODEL` to a model of that provider, e.g. `gpt-4.1-mini`). A Claude Pro subscription does not include API access. Without them a deterministic fake client is used, and AI endpoints return 404. Prompts and answers are never stored, only metadata (`ai_interactions`).
- A chat hand-off creates a Medium-priority ticket with no category (no AI triage); the agent adjusts it after reading the transcript.
- Chatbot retrieval uses MySQL FULLTEXT relevance over published articles, not vector embeddings (no vector type in MySQL 8.0, no extra provider).

### EXPLAIN evidence
[Paste `EXPLAIN` output for: customer list, agent queue, SLA scan, keyword search.]

## Optional / bonus
Implemented beyond the core requirements:
- **Real-time (Laravel Reverb):** private channels for the notification bell, live ticket pages and the staff queue. Internal notes broadcast only on `tickets.{n}.staff`.
- **Docker Compose:** full stack with healthchecks ([`docs/DOCKER.md`](docs/DOCKER.md)). **Jenkins** pipeline sample.
- **Caching:** SLA policies, business hours and holidays (`rememberForever`, cleared on admin edit); dashboard aggregates (60 s, kept warm by a scheduled job).
- **Reports:** SLA compliance, response/resolution times (median/p90), agent performance, aging, categories. Backed by a daily rollup table; CSV export (queued when large) and a weekly digest email.
- **Agents performance pages:** per-agent workload, "stuck" tickets, completed per day, CSV export.
- **Audit log:** sign-ins, failed logins, and admin changes to SLA, users and roles (`spatie/laravel-activitylog`), with a separate retention period per log.
- **Admin users & roles editor:** create users, edit roles and permissions, with lockout protection (the last active user who can manage users or roles can't lose that permission).
- **Observability:** JSON logs with a request id, slow-query log, failed-job alerts, `/health` endpoint, Laravel Pulse.
- **REST API v1 + OpenAPI docs:** Sanctum bearer tokens, tickets and replies (list/create/update/status/claim/assign, replies, attachment download), reusing the web app's FormRequests, policies and services. Interactive docs generated from the code (Scramble) at `/docs/api` for any signed-in user. Guide: [`docs/API.md`](docs/API.md).
- **AI data assistant for staff (Step 24b):** `/assistant` answers "latest tickets", "resolved today", "last 7 days report" by calling read-only lookups that run with the asker's own permissions (an agent only sees their queue). Never sees ticket descriptions, replies or internal notes; can't change anything.
- **AI support chatbot (Step 24):** `/help/chat` answers customers from published help-centre articles only, cites them, never sees tickets or internal notes, has no tools, and hands off to a normal ticket in one click. Reports → AI assistant tab shows the deflection rate. Needs `AI_ENABLED=true` + an Anthropic key.
- **Knowledge base + AI foundation (Step 22):** help centre with search, admin article editor, and a provider-neutral AI layer (`AiGuard`: on/off flag, rate limits, daily token budget, circuit breaker, PII redaction, audit rows). Off by default.
- **Scale-tested** on a ~1M-ticket database (`PerformanceSeeder`), see EXPLAIN evidence above.

Not done: auto-close of resolved tickets, SLA pause while waiting for the customer, API endpoints for admin screens/reports (see `docs/API.md` §7).

## Project structure
See `docs/CODING_STRUCTURE.md`.
