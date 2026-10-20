# Support Desk — Database

MySQL 8, InnoDB, `utf8mb4`/`utf8mb4_unicode_ci`. All timestamps stored UTC (`created_at`/`updated_at` from Eloquent unless noted). Every table below has its FKs and indexes listed explicitly — a new query path must add/verify an index here and the change must `EXPLAIN` cleanly (see §7).

Companion docs: layering/SLA/assignment design → [`ARCHITECTURE.md`](ARCHITECTURE.md); queue/scheduler/Reverb usage of these tables → [`QUEUE_SCHEDULER_REALTIME.md`](QUEUE_SCHEDULER_REALTIME.md).

---

## 1. Conventions

* Primary keys: `id BIGINT UNSIGNED AUTO_INCREMENT` (Eloquent default) except `ticket_sequences` (`year` is the PK).
* Foreign keys: `{singular}_id`, `ON DELETE` chosen per relationship (see table notes), always indexed (FK creation indexes it automatically in MySQL/InnoDB, but composite indexes below are added where the FK column alone isn't the right index for a query).
* Enums are stored as `VARCHAR` + validated by PHP backed enums (not MySQL `ENUM`) so adding a value never needs a migration that rewrites the column type — the PHP enum is the single source of truth for allowed values.
* Money/none in this domain. Durations are stored as **minutes** (`INT UNSIGNED`), never as `TIME`/`DATETIME` diffs.
* Soft deletes are **not** used anywhere (tickets/replies/activity are audit data — hard delete would violate the audit trail; nothing in the assessment calls for undelete).

---

## 2. spatie/laravel-permission tables (package migration, unmodified)

`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, plus `permission_cache` is not a table (it's `Cache::rememberForever`, config `permission.cache`). Guard: `web`. These are published via `php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"` in Step 3 — do not hand-edit their migration.

---

## 3. Custom tables

### `users` (extends Breeze/starter-kit default)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name, email, password | as scaffolded | `email` UNIQUE |
| is_active | boolean, default true | deactivated agents/admins can't log in / be assigned |
| email_verified_at, remember_token, timestamps | as scaffolded | |

No `role` column — role comes from spatie's `model_has_roles`.

Indexes: `UNIQUE(email)` (scaffolded).

---

### `categories`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(100) | |
| slug | varchar(120) | |
| is_active | boolean, default true | |
| timestamps | | |

Indexes: `UNIQUE(slug)`.

---

### `agent_profiles`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK → users.id, UNIQUE | one profile per agent; `ON DELETE CASCADE` |
| is_available | boolean, default true | |
| max_active_tickets | smallint unsigned, default 10 | capacity cap used by `LeastWorkloadStrategy` |
| last_assigned_at | timestamp, nullable | round-robin tie-break |
| timestamps | | |

Indexes: `UNIQUE(user_id)`, `INDEX(is_available, last_assigned_at)` — this is the row the auto-assign pick does `SELECT … FOR UPDATE SKIP LOCKED` against (ARCHITECTURE §6.2), so availability + ordering must be index-covered.

---

### `category_agent` (pivot — agent skills)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| category_id | bigint FK → categories.id, `ON DELETE CASCADE` | |
| user_id | bigint FK → users.id, `ON DELETE CASCADE` | |

Indexes: `UNIQUE(category_id, user_id)`, `INDEX(user_id)` (reverse lookup "categories this agent handles").

---

### `ticket_sequences`
| Column | Type | Notes |
|---|---|---|
| year | smallint unsigned PK | e.g. `2026` |
| last_number | int unsigned, default 0 | incremented under `SELECT … FOR UPDATE` |

No FKs. This table exists purely to serialise `TicketNumberGenerator::next()`/`reserve()` (ARCHITECTURE §4).

---

### `sla_policies`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| priority | varchar(20) | backed by `TicketPriority` enum values |
| resolution_minutes | int unsigned | business minutes, e.g. 2880/1440/480/120 |
| warning_threshold_percent | tinyint unsigned, default 80 | |
| is_active | boolean, default true | |
| timestamps | | |

Indexes: `UNIQUE(priority)`.

---

### `business_hours`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| day_of_week | tinyint unsigned | 0=Sun … 6=Sat |
| is_working_day | boolean | |
| start_time, end_time | time, nullable | null when `is_working_day = false` |

Indexes: `UNIQUE(day_of_week)`.

---

### `holidays`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| date | date | |
| name | varchar(100) | |

Indexes: `UNIQUE(date)`.

---

### `tickets`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | internal use only (never in URLs) |
| ticket_number | varchar(32) | `TKT-{YYYY}-{%06d}` |
| subject | varchar(200) | |
| description | text | |
| status | varchar(24) | `TicketStatus` enum values |
| priority | varchar(16) | `TicketPriority` enum values |
| source | varchar(16), default `web` | `TicketSource`: web / email / chat / import (Step 25a; older rows are all `web`) |
| category_id | bigint FK → categories.id, nullable, `ON DELETE SET NULL` | |
| created_by | bigint FK → users.id, `ON DELETE RESTRICT` | the customer |
| assigned_agent_id | bigint FK → users.id, nullable, `ON DELETE SET NULL` | |
| external_ref | varchar(64), nullable | CSV import dedupe key |
| sla_due_at | timestamp, nullable | |
| sla_warning_at | timestamp, nullable | |
| sla_warning_notified_at | timestamp, nullable | idempotency flag for `ProcessSlaWarningJob` |
| sla_breach_notified_at | timestamp, nullable | idempotency flag for `ProcessSlaBreachJob` |
| escalated_at | timestamp, nullable | |
| escalation_level | tinyint unsigned, default 0 | |
| first_response_at | timestamp, nullable | first staff public reply |
| first_response_minutes | int unsigned, nullable | business minutes `created_at` → `first_response_at`; written once, with the first staff public reply (`TicketMetricsService`) |
| last_reply_at | timestamp, nullable | |
| resolved_at | timestamp, nullable | |
| resolution_minutes | int unsigned, nullable | business minutes `created_at` → `resolved_at`; set on resolve, kept on close, cleared on reopen |
| reopened_count | smallint unsigned, default 0 | +1 on every Resolved → Open transition (atomic increment) |
| sla_met | boolean, nullable | set at resolution: `resolved_at <= sla_due_at` (null when the ticket has no SLA); cleared on reopen |
| version | int unsigned, default 0 | optimistic-locking counter (ARCHITECTURE §7) |
| assigned_at | timestamp, nullable | |
| timestamps | | |

Indexes:
* `UNIQUE(ticket_number)`
* `UNIQUE(external_ref)` — nullable-unique (MySQL allows multiple `NULL`s)
* `INDEX(status, sla_warning_at)` — `sla:scan` approaching-SLA pass (see §7: at volume the optimizer skips it — known gap)
* `INDEX(status, sla_due_at)` — `sla:scan` breach pass
* `INDEX(assigned_agent_id, status, resolved_at, assigned_at)` — agent's active-ticket count
  (`LeastWorkloadStrategy`, dashboard "own workload", leftmost prefix `(assigned_agent_id,
  status)`) **and** a fully-covering index for the by-agent performance aggregates
  (`DashboardRepository::byAgent()`, `AgentRepository::paginatePerformance()`, ARCHITECTURE.md
  §14/§14.1) — `resolved_at`/`assigned_at` are trailing columns so those conditional SUMs never
  touch the base row. Replaces a plain `(assigned_agent_id, status)` index that left those two
  aggregates doing a full row lookup per ticket (migration
  `2026_10_18_190000_add_agent_performance_indexes_to_tickets_table`).
* `INDEX(created_by, status)` — customer "my tickets" list
* `INDEX(status, priority, assigned_agent_id, created_at, sla_due_at)` — dashboard GROUP BY priority / queue filters (leftmost `(status, priority)`), **and** covering for the live report backlog/aging counts over active tickets (`ReportRepository::backlogNow/agingBuckets`). Replaced plain `(status, priority)` in `2026_10_19_100000_create_report_tables`: without the extra columns those counts read every active row (1.4–3.2s at ~1M tickets)
* `INDEX(created_at)` — `reports:aggregate`: tickets created on one business day (a one-day range on `(status, created_at)` was a full scan)
* `INDEX(resolved_at, priority, resolution_minutes)` — `reports:aggregate` resolved events, **and** covering for the resolution-time frequency table behind median/p90
* `INDEX(first_response_at, priority, first_response_minutes)` — same, for first response
* `INDEX(escalated_at)` — `reports:aggregate` escalations per day
* `INDEX(status, created_at)` — ticket list tab (active/resolved) ordered by newest-first; see ARCHITECTURE.md §12's "top-N-per-branch, then merge" — without this, `ORDER BY created_at` under a multi-value `status IN (...)` forces a full table scan + filesort at real volume
* `FULLTEXT(subject, description)` — keyword search (`MATCH … AGAINST`)

---

### `ticket_daily_stats` (report rollup — Step 17b)
| Column | Type | Notes |
|---|---|---|
| stat_date | date | business-timezone day the event happened on |
| category_id | bigint unsigned | 0 = no category (PK columns can't be NULL, so no FK) |
| priority | varchar(16) | |
| agent_id | bigint unsigned | 0 = unassigned (current assignee) |
| created_count, resolved_count, sla_met_count, sla_breached_count, responded_count, escalated_count, reopened_count | int unsigned, default 0 | each dated by its own event timestamp (reopens from `ticket_activities`) |
| sum_resolution_minutes, sum_first_response_minutes | bigint unsigned, default 0 | averages = sum / count |

PK `(stat_date, category_id, priority, agent_id)`. **Recomputed, never incremented** by `reports:aggregate`
(`DELETE` the range + `INSERT`, one transaction) — so it can always be rebuilt from `tickets` +
`ticket_activities`. Dimensions are the ticket's *current* category/priority/assignee. No `closed_count`:
there's no `closed_at` to date it by. No CSAT columns until Step 21.

### `personal_access_tokens` (REST API — Sanctum)
Sanctum's own migration, unmodified (`2026_10_19_082753_create_personal_access_tokens_table`): `tokenable` morph
(always `App\Models\User`), `name` (device name), `token` (SHA-256 of the secret, `UNIQUE` — the lookup on
every API request), `abilities`, `last_used_at`, `expires_at` (indexed — `sanctum:prune-expired`). See `API.md`.

### Monitoring tables (Step 19b)
`pulse_values`, `pulse_entries`, `pulse_aggregates` — Laravel Pulse's own migration, unmodified (`2026_10_18_172452_create_pulse_tables`). spatie/laravel-health stores nothing (in-memory result store), so its history table isn't created. `SlaBacklogCheck` reads `tickets` via `INDEX(status, sla_due_at)` with a bounded `sla_due_at` range (`EXPLAIN`: `range`, ~4 rows, 1.2 ms at ~1M tickets).

### `ticket_daily_snapshots` (report backlog trend — Step 17b)
| Column | Type | Notes |
|---|---|---|
| stat_date | date PK | |
| open_count, unassigned_count, breached_open_count | int unsigned | live backlog at `reports:snapshot` time (23:55 business tz) |
| taken_at | timestamp | |

Status isn't stored per day, so past backlog can't be rebuilt — the trend starts the day snapshots start.

### `activity_log` (security & admin audit — Step 19c)
spatie/laravel-activitylog's own three migrations, published unmodified (`2026_10_19_001243…001245`): `id, log_name,
description, subject_type/subject_id, event, causer_type/causer_id, properties json, batch_uuid, timestamps`, package
indexes `log_name`, `subject`, `causer`. Only `log_name` `auth` and `admin` are written (ARCHITECTURE.md §15.1);
ticket events stay in `ticket_activities`. No FK on the morph columns (package design) — a deleted user's rows keep
their `causer_id`.

Added in `2026_10_19_001246_add_audit_indexes_to_activity_log_table`:
* `INDEX(created_at)` — page date window (`Backward index scan; Using index`)
* `INDEX(log_name, created_at)` — page filtered by log, and the per-log `activitylog:clean <log> --days=` delete
* `INDEX(event, created_at)` — page filtered by event (+ date range)

### `ticket_replies`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → tickets.id, `ON DELETE CASCADE` | |
| user_id | bigint FK → users.id, `ON DELETE RESTRICT` | author |
| type | varchar(16) | `reply` \| `internal_note` (`ReplyType` enum) |
| body | text | plain text |
| timestamps | | |

Indexes: `INDEX(ticket_id, type, created_at)` — covers "all public replies for ticket, ordered" and "all notes for ticket, ordered" in one composite shape.

---

### `attachments`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → tickets.id, `ON DELETE CASCADE` | |
| ticket_reply_id | bigint FK → ticket_replies.id, nullable, `ON DELETE CASCADE` | null = attached directly to the ticket |
| uploaded_by | bigint FK → users.id, `ON DELETE RESTRICT` | |
| disk_path | varchar(255) | `tickets/{ticket_id}/{uuid}.{ext}` on the private disk |
| original_name | varchar(255) | |
| mime_type | varchar(100) | |
| size_bytes | int unsigned | |
| checksum_sha256 | varchar(64) | |
| timestamps | | |

Indexes: `INDEX(ticket_id)`, `INDEX(ticket_reply_id)`.

---

### `ticket_activities`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → tickets.id, `ON DELETE CASCADE` | |
| actor_id | bigint FK → users.id, nullable, `ON DELETE SET NULL` | null = system (e.g. scheduler-triggered escalation) |
| event | varchar(32) | `ActivityEvent` enum values |
| old_values | json, nullable | |
| new_values | json, nullable | |
| metadata | json, nullable | |
| created_at | timestamp | append-only: no `updated_at` |

Indexes: `INDEX(ticket_id, created_at)` — the timeline query is always "this ticket, chronological."
`INDEX(created_at)` (`2026_10_19_001247_…`) — audit page Tickets log, every ticket newest first (`Backward index scan;
Using index`); filtered by event it uses the reports' `INDEX(event, created_at)`.

---

### `imports`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK → users.id, `ON DELETE RESTRICT` | admin who started it |
| status | varchar(24) | `ImportStatus` enum: `pending, processing, completed, completed_with_errors, failed` |
| original_filename | varchar(255) | |
| disk_path | varchar(255) | private disk |
| total_rows, processed_rows, success_rows, failed_rows | int unsigned, default 0 | counters updated per chunk |
| timestamps | | |

Indexes: `INDEX(user_id, created_at)`.

---

### `kb_articles` (Step 22)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| title | varchar(200) | |
| slug | varchar(120) | URL: `/help/{slug}` |
| body | text | plain text, rendered escaped |
| is_published | boolean, default false | drafts visible only to `knowledge-base.manage` |
| category_id | bigint FK → categories.id, nullable, `ON DELETE SET NULL` | |
| timestamps | | |

Indexes: `UNIQUE(slug)`, `INDEX(is_published, title)` (help centre A–Z: `ref`, no filesort), `INDEX(updated_at)`
(admin list), `FULLTEXT(title, body)` (help-centre search + chatbot retrieval, natural-language mode).

---

### `ai_interactions` (Step 22)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| feature | varchar(32) | `AiFeature` enum |
| model | varchar(64) | |
| user_id | bigint FK → users.id, nullable, `ON DELETE SET NULL` | |
| ticket_id | bigint FK → tickets.id, nullable, `ON DELETE SET NULL` | |
| tokens_in, tokens_out | int unsigned, default 0 | |
| latency_ms | int unsigned | |
| status | varchar(16) | `AiInteractionStatus`: succeeded / refused / failed |
| outcome | varchar(16), nullable | human decision on a suggestion (Step 23) |
| error_type | varchar(64), nullable | provider error type for failed calls |
| prompt_hash | char(64) | sha256 of the redacted prompt — the prompt itself is never stored |
| created_at | timestamp | append-only, no `updated_at` |

Indexes: `INDEX(feature, created_at)` (AI KPIs per period), `INDEX(created_at)` (`model:prune` retention: `range`).

---

### `chat_sessions` / `chat_messages` (Step 24)
| Column | Type | Notes |
|---|---|---|
| **chat_sessions**.id | bigint PK | |
| user_id | bigint FK → users.id, `ON DELETE CASCADE` | the customer (or staff member, data assistant) |
| assistant | varchar(16), default `support` | `ChatAssistant`: support (help chat) / data (staff data assistant, Step 24b) |
| status | varchar(16) | `ChatSessionStatus`: active / handed_off / closed |
| ticket_id | bigint FK → tickets.id, nullable, `ON DELETE SET NULL` | set by the hand-off |
| timestamps | | |
| **chat_messages**.id | bigint PK | |
| chat_session_id | bigint FK → chat_sessions.id, `ON DELETE CASCADE` | |
| role | varchar(16) | `ChatMessageRole`: user / assistant |
| content | text | stored as typed (only the copy sent to the provider is redacted) |
| kb_article_ids | json, nullable | sources of an answer, in citation order |
| suggests_handoff | boolean, default false | assistant couldn't answer from the articles |
| created_at | timestamp | append-only |

Indexes: `chat_sessions INDEX(user_id, assistant, status)` (the user's active conversation with that assistant, every chat request; replaced `(user_id, status)` in `2026_10_19_160000_…`),
`chat_sessions INDEX(created_at)` (AI report + `model:prune`), `chat_messages INDEX(chat_session_id, id)`
(conversation in order / last N turns).

---

### `inbound_messages` / `ticket_channel_threads` (Step 25a, docs/IMAP.md)
| Column | Type | Notes |
|---|---|---|
| **inbound_messages**.id | bigint PK | one row per message received on any channel |
| channel | varchar(16) | `InboundChannel` (email) |
| external_message_id | varchar(255) | email Message-ID without `<>`; longer ids stored as `sha256:…` |
| sender_address, subject | varchar(255) / varchar(200) | |
| status | varchar(24) | `InboundMessageStatus`: ticket_created / reply_added / skipped |
| skip_reason | varchar(64), nullable | invalid_sender / sender_inactive / sender_not_allowed |
| ticket_id, ticket_reply_id | FK, nullable, `ON DELETE SET NULL` | what the message became |
| rejected_attachments | json, nullable | `[{name, reason}]` refused by the upload allow-list |
| received_at, timestamps | | |
| **ticket_channel_threads**.id | bigint PK | |
| channel, thread_key | varchar(16) / varchar(255) | a message id in the ticket's conversation |
| ticket_id | FK → tickets.id, `ON DELETE CASCADE` | |
| created_at | timestamp | append-only |

Indexes: `inbound_messages UNIQUE(channel, external_message_id)` — the duplicate guard (`INSERT IGNORE`, so a
redelivered message or retried job inserts nothing); `inbound_messages INDEX(created_at)` (sync history); `inbound_messages INDEX(channel, sender_address, created_at)`
(25c per-sender hourly limit, `2026_10_20_090000_…`);
`ticket_channel_threads UNIQUE(channel, thread_key)` (In-Reply-To / References lookup; a key never moves ticket).
`tickets.source` (`TicketSource`, default `web`, `2026_10_19_170000_…`) is not indexed: nothing filters on it yet.

### `mailbox_settings` / `mailbox_syncs` (Step 25b, docs/IMAP.md §3)
`mailbox_settings` — one row: `is_enabled`, `host`, `port`, `encryption` (`MailboxEncryption`), `username`,
`password` (text, **`encrypted` cast** with APP_KEY, nullable = use `IMAP_PASSWORD`), `folder`,
`fetch_interval_minutes`, `batch_size`, `last_uid` / `uid_validity` (sync cursor), `last_synced_at`, timestamps.
No indexes beyond the PK (always read as "the row").

`mailbox_syncs` — one row per sync: `triggered_by` (FK → users, nullable = scheduler, `ON DELETE SET NULL`),
`status` (`MailboxSyncStatus`), `fetched/created/replied/skipped/duplicate/failed_count` (int unsigned, atomic
`+1` from the jobs), `error` (varchar 255, a fixed safe message), `finished_at`, timestamps.
Indexes: `INDEX(created_at)` — history newest first and the 30-day `model:prune` (MassPrunable).

### `import_errors`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| import_id | bigint FK → imports.id, `ON DELETE CASCADE` | |
| row_number | int unsigned | |
| errors | json | validation messages |
| raw_row | json | original CSV row, for the downloadable error report |

Indexes: `INDEX(import_id)`.

---

> **Altering `tickets` at volume:** because `tickets` has a FULLTEXT index, InnoDB can't add a column
> `INSTANT` or `INPLACE` — every `ADD COLUMN` rebuilds the table (`copy to tmp table`), blocking writes
> for its duration. Laravel emits one `ALTER` per column, so a migration adding N columns copies the
> table N times (the 3-column report-metrics migration took 20.5 min in total at ~1M rows). For a
> production table this size, batch new columns into a single `ALTER` (one `DB::statement`) or use an
> online schema-change tool.

## 4. Migration order

Respect FK dependency order: `categories` → `agent_profiles` → `category_agent` → `ticket_sequences` → `sla_policies` → `business_hours` → `holidays` → `tickets` → `ticket_replies` → `attachments` → `ticket_activities` → `imports` → `import_errors`. spatie's own migration runs whenever its publish command is invoked (Step 3) and is independent of this chain except that `model_has_roles`/`model_has_permissions` reference `users`, which already exists.

---

## 5. Query → index map

| Query | Index used |
|---|---|
| Find ticket by number | `tickets.UNIQUE(ticket_number)` |
| Mailbox sync history / retention (Step 25b) | `mailbox_syncs.INDEX(created_at)` |
| Inbound dedupe / thread lookup (Step 25a) | `inbound_messages.UNIQUE(channel, external_message_id)` / `ticket_channel_threads.UNIQUE(channel, thread_key)` |
| Ticket list, any role (top-N-per-branch, then merge — ARCHITECTURE.md §12) | `tickets.INDEX(status, created_at)`, one branch per status × visibility rule |
| Customer/agent active-ticket counts (dashboard own-workload, `LeastWorkloadStrategy`) | `tickets.INDEX(created_by, status)` / `tickets.INDEX(assigned_agent_id, status, resolved_at, assigned_at)` |
| By-agent performance aggregates (dashboard by-agent widget, Agents list/detail pages) | `tickets.INDEX(assigned_agent_id, status, resolved_at, assigned_at)` (fully covering) |
| `sla:scan` approaching pass | `tickets.INDEX(status, sla_warning_at)` |
| `sla:scan` breach pass | `tickets.INDEX(status, sla_due_at)` |
| Keyword search | `tickets.FULLTEXT(subject, description)` |
| Dashboard GROUP BY priority | `tickets.INDEX(status, priority, …)` (leftmost prefix) |
| `reports:aggregate`, one business day per event type | `tickets.INDEX(created_at)` / `(resolved_at, …)` / `(first_response_at, …)` / `(escalated_at)`; `ticket_activities.INDEX(event, created_at)` |
| Report median/p90 frequency table | `tickets.INDEX(resolved_at, priority, resolution_minutes)` / `(first_response_at, priority, first_response_minutes)` (covering) |
| Report live backlog / aging | `tickets.INDEX(status, priority, assigned_agent_id, created_at, sla_due_at)` (covering) |
| Report rollup reads | `ticket_daily_stats` PK range on `stat_date` |
| Auto-assign candidate pick | `agent_profiles.INDEX(is_available, last_assigned_at)` |
| CSV dedupe check | `tickets.UNIQUE(external_ref)` |
| Ticket timeline | `ticket_activities.INDEX(ticket_id, created_at)` |
| Replies/notes for a ticket | `ticket_replies.INDEX(ticket_id, type, created_at)` |
| Admin audit log page / `activitylog:clean` | `activity_log.INDEX(created_at)` / `(log_name, created_at)` / `(event, created_at)` |
| Admin audit log page, Tickets log | `ticket_activities.INDEX(created_at)` / `(event, created_at)` |
| Help centre list (published, A–Z) | `kb_articles.INDEX(is_published, title)` |
| Help-centre search / chatbot KB retrieval | `kb_articles.FULLTEXT(title, body)` |
| AI interaction retention (`model:prune`), AI report usage | `ai_interactions.INDEX(created_at)` |
| Chat: the user's active conversation | `chat_sessions.INDEX(user_id, assistant, status)` (`ref`) |
| Data assistant `resolved_tickets` | `tickets.INDEX(resolved_at, priority, resolution_minutes)` (`range`) |
| Chat: messages / last N turns | `chat_messages.INDEX(chat_session_id, id)` (`ref`, backward scan) |
| AI report sessions, chat retention | `chat_sessions.INDEX(created_at)` (`range`) |

---

## 6. ACID notes specific to schema

* `tickets.version` exists only for optimistic locking on non-claim edits; the claim itself uses the compare-and-set `UPDATE … WHERE assigned_agent_id IS NULL` (ARCHITECTURE §7), which does not need `version`.
* `sla_warning_notified_at` / `sla_breach_notified_at` are the idempotency flags the escalation jobs condition their `UPDATE` on — never read-then-write these.
* `ticket_sequences.last_number` is only ever mutated under `SELECT … FOR UPDATE` inside the ticket-creation transaction.

---

## 7. EXPLAIN results — hot queries

Measured 2026-10-20 on the ~1M-ticket dev DB (`PerformanceSeeder`); full output in the README "EXPLAIN evidence".

| Query | type / key | Rows examined (est.) | Time |
|---|---|---|---|
| Customer list, one status branch | `ref` `(created_by, status)` + filesort of ~21 rows | 21 | 8 ms |
| Agent queue, "mine" branch | `ref` `(assigned_agent_id, status, …)` + filesort | 21 | 11 ms |
| Agent queue, "unassigned" branch | `index` `(created_at)`, backward scan, stops after 15 matches | ~900 | 9 ms |
| Admin list, one status branch | `ref` `(status, created_at)`, backward scan, `LIMIT` stops early | 15 read | 10 ms |
| Keyword search ("password", 66k matches) | `fulltext` + filesort of the matches | 66k | 0.9 s |
| Dashboard summary, one agent | `ref` `(assigned_agent_id, status, …)` | 30 | 8 ms |
| Dashboard summary, global | `index` `(status, sla_due_at)`, `Using index` (covering, full index scan) | 960k | 0.5 s — runs on the queue (`WarmDashboardCacheJob`), never on a request |
| `sla:scan` approaching / breach | `index` PRIMARY (`chunkById` orders by id) | whole table | **0.9 s per pass** |

**Known gap — `sla:scan`.** `(status, sla_due_at)` can't tell "already notified" rows apart, and on this data
~350k active tickets are past due and already notified, so the optimizer reads the PRIMARY key instead (0 rows
pending, still ~0.9 s each minute per pass). The fix is an index that leads with the flag, e.g.
`(sla_breach_notified_at, status, sla_due_at)` / `(sla_warning_notified_at, status, sla_warning_at)`: not done
in Step 16 (docs only).

### Report queries (Step 17b) — measured on the ~1M-ticket dev DB

| Query | EXPLAIN | Time |
|---|---|---|
| `reports:aggregate`, one day of resolved events | `range` on `(resolved_at, priority, resolution_minutes)`, ~4.7k rows | 0.38s |
| `reports:aggregate`, one day of created events (before: full scan, 959k rows) | `range` on `(created_at)` | <0.01s |
| `reports:aggregate`, one day of reopens | `range` on `ticket_activities(event, created_at)` → `eq_ref` tickets PK | <0.01s |
| Rollup read, 90-day compliance by priority | `range` on `ticket_daily_stats` PK | 0.10s |
| Median/p90 resolution, 30 days, as a frequency table | `range`, `Using index` (covering) | 0.44s |
| Live aging over active tickets | `range`, `Using index` on `(status, priority, assigned_agent_id, created_at, sla_due_at)` | 0.8–1.8s (cached 60s) |

Rejected along the way: `ROW_NUMBER() OVER` window functions for percentiles — sorting every matching row
cost 5.5s (90 days, full scan) → 3.0s even with the covering index, and a combined overall+per-priority
window made it worse (13s). A `GROUP BY minutes` frequency table + nearest-rank in PHP
(`App\Support\Percentile`) reads the same covering index without the sort. Percentile window capped at 30
days: the 90-day frequency table was still ~3s (91k distinct values in the seeded data).

Full-history rebuild (`reports:aggregate --from=… --to=…`, 93 days): 31s, ~98k rollup rows, 128 MB peak.
The scheduled 15-minute "today" run: ~0.6s. Migration adding these indexes (one `ALTER … INPLACE`): ~25s.
