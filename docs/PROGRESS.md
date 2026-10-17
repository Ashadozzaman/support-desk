# Progress tracker

Updated by Claude Code at the start/end of every step. **Only the developer (you) changes a step to ✅ Verified** — by replying `approved` (or running `/step-approve N`).

Legend: ⬜ Not started · 🟡 In progress · ⏳ Awaiting verification · 🔁 Rework · ✅ Verified

## Steps

| Step | Name | Status | Verified on | Commit | Notes |
|---|---|---|---|---|---|
| 0 | Create project & load context | ⬜ | | | |
| 1 | Bootstrap & configuration | ⬜ | | | |
| 2 | Enums, migrations, models, factories, seeders | ⬜ | | | |
| 3 | RBAC with spatie/laravel-permission | ⬜ | | | |
| 4 | Ticket numbering + ticket backend | ⬜ | | | |
| 5 | Ticket UI with reusable components | ⬜ | | | |
| 6 | Activity / audit history | ⬜ | | | |
| 7 | Replies, internal notes, attachments | ⬜ | | | |
| 8 | Assignment (manual, auto, concurrent claim) | ⬜ | | | |
| 9 | SLA & business hours | ⬜ | | | |
| 10 | Scheduler escalation + queued notifications | ⬜ | | | |
| 11 | Real-time with Reverb | ⬜ | | | |
| 12 | Search & filtering | ⬜ | | | |
| 13 | Dashboard | ⬜ | | | |
| 14 | CSV import | ⬜ | | | |
| 15 | Hardening & test review | ⬜ | | | |
| 16 | Docs, README, extras | ⬜ | | | |

## Assessment coverage (what is complete)

A requirement is ✅ only when **every** step that delivers it is ✅ Verified.

| Assessment requirement | Delivered in step(s) | Status |
|---|---|---|
| Ticket CRUD, active vs resolved views | 4, 5 | ⬜ |
| Ticket numbering `TKT-YYYY-NNNNNN` | 4 | ⬜ |
| Roles & permissions (RBAC) | 3 | ⬜ |
| Priority, categories, status workflow | 2, 4 | ⬜ |
| Manual + automatic assignment | 8 | ⬜ |
| Concurrent "accept ticket" safety | 8 | ⬜ |
| Replies + internal notes (hidden from customers) | 7 | ⬜ |
| Attachments (secure) | 7 | ⬜ |
| SLA by priority + business hours | 9 | ⬜ |
| Escalation (scheduler + queue) | 10 | ⬜ |
| Email / in-app notifications (queued) | 10 | ⬜ |
| Real-time notifications (Reverb) | 11 | ⬜ |
| Search & filtering (+ SLA filter) | 12 | ⬜ |
| Dashboard | 13 | ⬜ |
| CSV import (large files, background) | 14 | ⬜ |
| Activity history | 6 | ⬜ |
| DB design, indexes, transactions | 2, all | ⬜ |
| Tests | every step, 15 | ⬜ |
| README / documentation | 16 | ⬜ |

## Current step
_None started._

## Open questions / decisions waiting for the developer
- _(none)_

## Rework log
| Step | Date | What the developer asked to change | Done? |
|---|---|---|---|
