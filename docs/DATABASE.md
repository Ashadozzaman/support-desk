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
| last_reply_at | timestamp, nullable | |
| resolved_at | timestamp, nullable | |
| sla_met | boolean, nullable | set at resolution: `resolved_at <= sla_due_at` |
| version | int unsigned, default 0 | optimistic-locking counter (ARCHITECTURE §7) |
| assigned_at | timestamp, nullable | |
| timestamps | | |

Indexes:
* `UNIQUE(ticket_number)`
* `UNIQUE(external_ref)` — nullable-unique (MySQL allows multiple `NULL`s)
* `INDEX(status, sla_warning_at)` — `sla:scan` approaching-SLA pass
* `INDEX(status, sla_due_at)` — `sla:scan` breach pass
* `INDEX(assigned_agent_id, status)` — agent's active-ticket count (`LeastWorkloadStrategy`, dashboard "own workload")
* `INDEX(created_by, status)` — customer "my tickets" list
* `INDEX(status, priority)` — dashboard GROUP BY priority / queue filters
* `FULLTEXT(subject, description)` — keyword search (`MATCH … AGAINST`)

---

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

## 4. Migration order

Respect FK dependency order: `categories` → `agent_profiles` → `category_agent` → `ticket_sequences` → `sla_policies` → `business_hours` → `holidays` → `tickets` → `ticket_replies` → `attachments` → `ticket_activities` → `imports` → `import_errors`. spatie's own migration runs whenever its publish command is invoked (Step 3) and is independent of this chain except that `model_has_roles`/`model_has_permissions` reference `users`, which already exists.

---

## 5. Query → index map

| Query | Index used |
|---|---|
| Find ticket by number | `tickets.UNIQUE(ticket_number)` |
| Ticket list, customer view | `tickets.INDEX(created_by, status)` |
| Ticket list, agent queue (assigned-or-unassigned) | `tickets.INDEX(assigned_agent_id, status)` |
| `sla:scan` approaching pass | `tickets.INDEX(status, sla_warning_at)` |
| `sla:scan` breach pass | `tickets.INDEX(status, sla_due_at)` |
| Keyword search | `tickets.FULLTEXT(subject, description)` |
| Dashboard GROUP BY priority | `tickets.INDEX(status, priority)` |
| Auto-assign candidate pick | `agent_profiles.INDEX(is_available, last_assigned_at)` |
| CSV dedupe check | `tickets.UNIQUE(external_ref)` |
| Ticket timeline | `ticket_activities.INDEX(ticket_id, created_at)` |
| Replies/notes for a ticket | `ticket_replies.INDEX(ticket_id, type, created_at)` |

---

## 6. ACID notes specific to schema

* `tickets.version` exists only for optimistic locking on non-claim edits; the claim itself uses the compare-and-set `UPDATE … WHERE assigned_agent_id IS NULL` (ARCHITECTURE §7), which does not need `version`.
* `sla_warning_notified_at` / `sla_breach_notified_at` are the idempotency flags the escalation jobs condition their `UPDATE` on — never read-then-write these.
* `ticket_sequences.last_number` is only ever mutated under `SELECT … FOR UPDATE` inside the ticket-creation transaction.

---

## 7. EXPLAIN checklist (fill in during Step 12 / README)

Run `EXPLAIN` on, and paste output for:
1. Customer ticket list (filtered + paginated)
2. Agent queue (assigned-or-unassigned, filtered)
3. `sla:scan` approaching/breach selects
4. Keyword search (`FULLTEXT`)
5. Dashboard aggregate query
