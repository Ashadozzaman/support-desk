# SLA — how it works

How a ticket gets a deadline, how its SLA state is decided, and what the system does when each state is reached.
Design background: [`ARCHITECTURE.md`](ARCHITECTURE.md) §8–§9. Scheduler/queue details:
[`QUEUE_SCHEDULER_REALTIME.md`](QUEUE_SCHEDULER_REALTIME.md).

---

## 1. The big picture

```
 ticket created / priority changed
        │
        ▼
 SlaService::calculate(priority, created_at)          ← reads sla_policies + business_hours + holidays
        │   BusinessHoursCalculator::addBusinessMinutes()
        ▼
 tickets.sla_warning_at  +  tickets.sla_due_at         ← two UTC timestamps, stored once
        │
        │   (nothing else is stored — the "state" is computed from these + now())
        ▼
 ┌──────────────── every minute: sla:scan ────────────────┐
 │ warning_at <= now, not yet notified → ProcessSlaWarningJob │
 │ due_at     <= now, not yet notified → ProcessSlaBreachJob  │
 └────────────────────────────────────────────────────────┘
        │  (queue `sla`, atomic flag UPDATE → activity → events after commit)
        ▼
 notifications (assignee + admins) · Reverb broadcast · activity timeline · badge turns amber / red
```

Two ideas carry the whole design:

1. **Deadlines are calculated once and stored** (`sla_warning_at`, `sla_due_at`). Reading a list never does
   business-hours maths, and every SLA filter is a plain indexed comparison against `now()`.
2. **The SLA state is never stored** — it is derived from `status`, `now()`, the two deadlines and `sla_met`.
   So it can't go stale: a ticket becomes "Approaching" the instant the clock passes `sla_warning_at`, even if
   the scheduler hasn't run yet. The scheduler only handles the _side effects_ (notify, escalate, log).

---

## 2. SLA policy (the rules)

Table `sla_policies`, one row per priority, editable by an admin at **Admin → SLA policies**
(`permission:sla.manage`). Seeded defaults (`SlaPolicySeeder`):

| Priority | `resolution_minutes` | = business hours | `warning_threshold_percent` | Warning after        |
| -------- | -------------------- | ---------------- | --------------------------- | -------------------- |
| Low      | 2880                 | 48 h             | 80                          | 2304 min (38 h 24 m) |
| Medium   | 1440                 | 24 h             | 80                          | 1152 min (19 h 12 m) |
| High     | 480                  | 8 h              | 80                          | 384 min (6 h 24 m)   |
| Critical | 120                  | 2 h              | 80                          | 96 min (1 h 36 m)    |

- **Minutes are business minutes** — they only tick inside working hours (assumption, see README).
- `is_active = false` (or no row for that priority) → the ticket gets **no SLA**: both columns `NULL`, no badge,
  never scanned. The system does not invent a default.
- `warning_threshold_percent` decides when "Approaching" starts: `warning minutes = round(resolution × % / 100)`.

### Business calendar

| Table                            | Meaning                                             | Seeded                           |
| -------------------------------- | --------------------------------------------------- | -------------------------------- |
| `business_hours`                 | one row per weekday: working day? open / close time | Sun–Thu 09:00–18:00, Fri/Sat off |
| `holidays`                       | specific non-working dates                          | none                             |
| `config('support.sla.timezone')` | the wall clock those hours are in                   | `Asia/Dhaka`                     |

All three are cached (`SlaConfigRepository`, `Cache::rememberForever`) and the cache is busted after every admin
edit. Every edit is also written to the admin audit log (`SlaService::updatePolicies/updateBusinessHours/…`).

> Changing a policy affects **new calculations only** (new tickets, priority changes). Existing tickets keep the
> deadlines they were given — they are stored values, not recomputed on read.

---

## 3. Calculating the deadline — `BusinessHoursCalculator::addBusinessMinutes()`

`app/Support/Sla/BusinessHoursCalculator.php`

1. Convert the start (UTC) to the business timezone.
2. **Snap to the next working moment**: before opening → today's opening; after closing, a non-working day, or a
   holiday → next working day's opening; inside hours → unchanged.
3. Loop: `available = close of today − cursor`.
    - `remaining ≤ available` → answer is `cursor + remaining`.
    - otherwise use up today (`remaining −= available`) and jump to the next working day's opening.
4. Convert the answer back to UTC.
5. Both loops stop after 366 iterations and throw — protects against a config with no working days.

`SlaService::calculate()` runs this **twice** from the same start: once with `resolution_minutes` (→ `sla_due_at`),
once with the warning minutes (→ `sla_warning_at`). So the warning point is also in business time.

### Worked example (from the assessment)

Critical (120 min, warning 96 min), created **Thursday 17:00**:

|              | Thursday 17:00 → 18:00 | Fri, Sat | Sunday from 09:00 | Result        |
| ------------ | ---------------------- | -------- | ----------------- | ------------- |
| Warning (96) | 60 used, 36 left       | skipped  | +36               | **Sun 09:36** |
| Due (120)    | 60 used, 60 left       | skipped  | +60               | **Sun 10:00** |

More cases (Pest dataset in `tests/Unit/Sla/BusinessHoursCalculatorTest.php`): created Fri → counts from Sun 09:00; created Sun 07:30 → counts
from 09:00; Thu 17:59 + 120 → Sun 10:59; High from Sun 09:00 → Sun 17:00 (warning 15:24).

### When it is (re)calculated

| Moment                                    | Start used                                       | Code                      |
| ----------------------------------------- | ------------------------------------------------ | ------------------------- |
| Ticket created (UI)                       | `now()`                                          | `TicketService::create()` |
| Ticket imported (CSV)                     | `now()` (the import time)                        | `ProcessTicketImportJob`  |
| Ticket edited (priority/category/subject) | the ticket's **`created_at`**, not the edit time | `TicketService::update()` |

Recomputing from `created_at` means raising Low → Critical on a 3-hour-old ticket can make it breached
immediately — that is intentional: the customer has been waiting since creation (documented assumption).

---

## 4. The five SLA states

`app/Enums/SlaState.php` — `SlaState::resolve()`; mirrored on the frontend by `getSlaState()` in
`resources/js/types/ticket.ts` so the badge flips live without a reload.

Decision order (first match wins):

```
sla_due_at is NULL                         → (no SLA, no badge)
status is resolved or closed               → sla_met ? Met : Met late
now >= sla_due_at                          → Breached
now >= sla_warning_at                      → Approaching
otherwise                                  → Within SLA
```

On a timeline for one active ticket:

```
created_at ─────────── sla_warning_at ─────────── sla_due_at ───────────►
   │      Within SLA        │      Approaching        │      Breached
   │       (green)          │        (amber)          │        (red)
```

### What each state means and what the system does

| State           | Condition                                                             | Badge  | What happens automatically                                                                                                                                                                                                                                                                                                         | What the team should do                                      |
| --------------- | --------------------------------------------------------------------- | ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| **Within SLA**  | active, `now < sla_warning_at`                                        | green  | nothing                                                                                                                                                                                                                                                                                                                            | work normally                                                |
| **Approaching** | active, `sla_warning_at ≤ now < sla_due_at` (≥ 80 % of the time used) | amber  | **once**, via `ProcessSlaWarningJob`: set `sla_warning_notified_at`, activity `sla.warning`, event `SlaWarningTriggered` → notify assignee + every active user with `dashboard.view-global` (mail + database + broadcast), push to `staff.alerts` and `tickets.{n}.staff`. Ticket appears in the dashboard "Approaching SLA" table | prioritise it; if unassigned, claim it / admin assigns it    |
| **Breached**    | active, `now ≥ sla_due_at`                                            | red    | **once**, via `ProcessSlaBreachJob`: set `sla_breach_notified_at`, `escalated_at`, `escalation_level + 1`; activities `sla.breached` + `ticket.escalated`; event `SlaBreached` → same recipients as above. Counted in dashboard "SLA breached"                                                                                     | resolve ASAP; admin may reassign. The ticket stays escalated |
| **Met**         | resolved/closed and `sla_met = true`                                  | grey   | — (final state)                                                                                                                                                                                                                                                                                                                    | —                                                            |
| **Met late**    | resolved/closed and `sla_met = false`                                 | orange | — (final state; counts against compliance in Reports)                                                                                                                                                                                                                                                                              | review in reports                                            |

- "Active" = `open`, `in_progress`, `waiting_for_customer`.
- `sla_met` is written when the ticket is **resolved**: `resolved_at <= sla_due_at` (`TicketMetricsService`). Closing
  keeps it; a reopen clears it and the next resolution decides again.
- Customers see the badge but never the escalation/SLA activity rows or notifications (staff-only).

---

## 5. How the side effects run — `sla:scan` → jobs

`routes/console.php`: `Schedule::command('sla:scan')->everyMinute()->withoutOverlapping(5)->onOneServer();`

**`sla:scan`** (`app/Console/Commands/SlaScan.php`) — only _finds_ tickets, two passes, `chunkById(200)`:

| Pass        | Query                                                              | Index                      | Dispatches             |
| ----------- | ------------------------------------------------------------------ | -------------------------- | ---------------------- |
| Approaching | active, `sla_warning_at <= now`, `sla_warning_notified_at IS NULL` | `(status, sla_warning_at)` | `ProcessSlaWarningJob` |
| Breached    | active, `sla_due_at <= now`, `sla_breach_notified_at IS NULL`      | `(status, sla_due_at)`     | `ProcessSlaBreachJob`  |

A ticket that jumps straight past both points (e.g. priority raised) gets **both** jobs in the same run.

**The jobs** (queue `sla`, `tries 3`, `backoff [30, 120]`, `timeout 60`) — idempotent by an atomic flag:

```sql
UPDATE tickets SET sla_breach_notified_at = NOW(), escalated_at = NOW(), escalation_level = escalation_level + 1
WHERE id = ? AND sla_breach_notified_at IS NULL;
-- 1 row  → this job won: write activity, dispatch event after commit
-- 0 rows → already done by another run/worker: stop, nothing is sent twice
```

Why it's safe:

- The scan runs every minute, so the same ticket can be dispatched again before its first job ran — the
  `WHERE … IS NULL` makes only one of them do anything (no read-then-write).
- Flag + activity row are in one transaction; the event (→ notifications, broadcast) fires only
  **after commit** (`DB::afterCommit`), so a rolled-back job never emails anyone.
- Resolved/closed tickets are excluded by `active()` — they are never warned or escalated.

Notifications: listeners `SendSlaWarningNotifications` / `SendSlaBreachNotifications` (queue `notifications`).
Recipients = the assigned agent (if any) + all active users with `dashboard.view-global`, de-duplicated.
Logs: `sla` channel (`sla.warning`, `sla.breached`, `*_job_failed`).

---

## 6. Where the states are used (filters & counts)

Because the boundaries are columns, each state is an index-friendly predicate (`Ticket::scopeSlaState()`):

| State       | SQL                                                         |
| ----------- | ----------------------------------------------------------- |
| Within      | active `AND sla_warning_at > NOW()`                         |
| Approaching | active `AND sla_warning_at <= NOW() AND sla_due_at > NOW()` |
| Breached    | active `AND sla_due_at <= NOW()`                            |
| Met         | resolved/closed `AND sla_met = 1`                           |
| Met late    | resolved/closed `AND sla_met = 0`                           |

Used by: ticket list filter (`?sla_state=within|approaching|breached|met|met_late`), dashboard counters and
"Approaching SLA" table (`DashboardRepository`), reports (SLA compliance from `sla_met`), health check
`SlaBacklogCheck` (breached tickets the scan hasn't processed → scheduler/worker probably down).

---

## 7. Behaviour worth knowing (current limitations)

These are how the code behaves today — not bugs that slip silently, but things to be able to explain:

- **The clock never pauses.** `waiting_for_customer` still consumes SLA time (pause is a documented future hook,
  ARCHITECTURE §8.2).
- **Notification flags are one-shot per ticket.** `sla_warning_notified_at` / `sla_breach_notified_at` are never
  reset — not on priority change, not on reopen. So if a ticket was warned, then its priority is lowered (deadline
  moves later) and it approaches again, no second warning is sent; likewise a reopened ticket that breaches again
  isn't re-escalated. The _badge_ is always correct (it's computed), only the notification is not repeated.
- **Policy edits are not retroactive** — existing tickets keep their stored deadlines.
- **Only one escalation level** is implemented (breach → level 1). A "still open N hours after breach → level 2"
  step is described as optional in ARCHITECTURE §9.
- The scan runs every minute, so notifications can lag the true moment by up to ~1 minute plus queue delay.

## summery

## SLA System-এর সংক্ষিপ্ত সারাংশ

SLA (Service Level Agreement) হলো একটি নির্দিষ্ট সময়ের মধ্যে Support Ticket সমাধান করার সময়সীমা।

- Priority অনুযায়ী deadline: Low ৪৮ ঘণ্টা, Medium ২৪ ঘণ্টা, High ৮ ঘণ্টা এবং Critical ২ কর্মঘণ্টা।
- Warning: SLA সময়ের ৮০% পার হলে warning দেওয়া হয়।
- ৫টি State: Within SLA, Approaching, Breached, Met এবং Met late।
- Deadline Calculation: `SlaService` এবং `BusinessHoursCalculator` অফিসের সময় ও ছুটি হিসাব করে deadline নির্ধারণ করে।
- Database: `sla_warning_at` এবং `sla_due_at` timestamp সংরক্ষণ করা হয়।
- Scheduler: Laravel Scheduler প্রতি মিনিটে `sla:scan` চালিয়ে ticket পরীক্ষা করে।
- Queue Jobs: Warning ও deadline পার হলে আলাদা job কাজ করে।
- Notifications: Email, database notification, Reverb broadcast এবং activity log ব্যবহার করা হয়।
- Duplicate Prevention: Atomic database update দিয়ে একই ticket-এর notification একাধিকবার যাওয়া প্রতিরোধ করা হয়।
- Performance: Indexed database query ব্যবহার করে দ্রুত ticket filter করা যায়।

### প্রধান সীমাবদ্ধতা

- Customer-এর উত্তরের অপেক্ষায় থাকলেও SLA সময় চলতে থাকে।
- পুরোনো ticket-এর deadline policy পরিবর্তন করলে স্বয়ংক্রিয়ভাবে পরিবর্তিত হয় না।
- Ticket reopen হলে আগের notification flag reset হয় না।
- বর্তমানে breach-এর পর একটি escalation level-ই রয়েছে।

মূল কথা: Deadline একবার গণনা করে সংরক্ষণ করা হয়; Scheduler, Queue Jobs ও Notifications মিলে SLA monitoring, warning এবং escalation পরিচালনা করে।
