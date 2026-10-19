# Testing — Support Desk

Pest on **MySQL 8**. The tests need MySQL, not SQLite, because they rely on FULLTEXT search, row locks
(`FOR UPDATE`, `SKIP LOCKED`) and two real connections racing each other.

**Last full run (2026-10-19): 488 tests, 2085 assertions, all passing, ~4 min 40 s.**

---

## 1. One-time setup

```bash
cp .env.testing.example .env.testing        # points at a separate DB: support_desk_test
mysql -u root -e "CREATE DATABASE support_desk_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan key:generate --env=testing
```

The tests use their own database, so they never touch your dev data. Most tests use `RefreshDatabase`
and roll back after each test. The concurrency test commits real rows and deletes them itself in `tearDown()`.

With Docker: `docker compose run --rm tests` (see [`DOCKER.md`](DOCKER.md)).

---

## 2. How to run tests

| What | Command |
|---|---|
| Everything | `php artisan test --compact` |
| One suite | `php artisan test --testsuite=Unit` · `--testsuite=Feature` · `--testsuite=Concurrency` |
| One folder | `php artisan test tests/Feature/Rbac` |
| One file | `php artisan test tests/Unit/Sla/BusinessHoursCalculatorTest.php` |
| One test class by name | `php artisan test --filter=TicketClaimTest` |
| One test by its description (any part of it) | `php artisan test --filter="Thursday 17:00"` |
| List every test without running it | `php artisan test --list-tests` |
| Stop at the first failure | `php artisan test --stop-on-failure` |
| Code style / types (not tests, but run in CI too) | `./vendor/bin/pint --test` · `npx tsc --noEmit` |

The `--filter` value is a regular expression matched against the test name. For Pest tests, the name
is the sentence inside `test('…')`, so you can copy any part of it.

The CI pipeline (`Jenkinsfile`) runs Pint → tsc → `php artisan test` on MySQL → build.

---

## 3. What is tested, and why — the critical areas

The assessment leaves the choice of tests to us. These are the areas where a bug costs the most and
where a manual click-through can't catch it. Each row gives a command that runs just that area.

| # | Risk if it breaks | Test file(s) | Cases | Run just this |
|---|---|---|---|---|
| 1 | **Two agents claim the same ticket** | `tests/Concurrency/TicketClaimConcurrencyTest.php` (two real OS processes, two MySQL connections) · `tests/Feature/Tickets/TicketClaimTest.php` | 1 + 4 | Both files: `php artisan test --filter="TicketClaimTest\|TicketClaimConcurrencyTest"` |
| 2 | **Wrong SLA deadline** — business hours, weekends, holidays, the 18:00 boundary | `tests/Unit/Sla/BusinessHoursCalculatorTest.php` (dataset) · `tests/Feature/Sla/TicketSlaTest.php` | 24 + 2 | `php artisan test tests/Unit/Sla` |
| 3 | **A user sees or does something they shouldn't** | `tests/Feature/Rbac/TicketAbilityMatrixTest.php` (role × action × own/other/unassigned) · `TicketVisibilityScopeTest.php` · `AdminRouteGuardTest.php` | 29 + 3 + 2 | `php artisan test tests/Feature/Rbac` |
| 4 | **An internal note reaches a customer** — replies, timeline, attachments, notifications, broadcasts | `tests/Feature/Tickets/ReplyTest.php` · `tests/Feature/Realtime/InternalNoteBroadcastSecrecyTest.php` · `ChannelAuthorizationTest.php` · `tests/Feature/Notifications/NotificationRecipientsTest.php` | 10 + 2 + 18 + 9 | `php artisan test --filter="internal note"` |
| 5 | **The same breach is escalated or emailed twice** | `tests/Feature/Sla/SlaScanTest.php` | 4 | `php artisan test --filter="only escalates a breached ticket once"` |
| 6 | **Duplicate or skipped ticket numbers** | `tests/Unit/Support/TicketNumberGeneratorTest.php` (50 creates, year rollover, `reserve(100)`) · `tests/Feature/Database/ConstraintsTest.php` (unique index backstop) | 3 + 7 | `php artisan test --filter=TicketNumberGeneratorTest` |
| 7 | **Invalid status change** (e.g. Closed → Open) | `tests/Feature/Tickets/TicketStatusTransitionTest.php` | 5 | `php artisan test --filter=TicketStatusTransitionTest` |
| 8 | **Auto-assign overloads an agent or picks an unavailable one** | `tests/Unit/Assignment/LeastWorkloadStrategyTest.php` · `RoundRobinStrategyTest.php` · `tests/Feature/Tickets/AutoAssignDispatchTest.php` (dispatched only after commit) | 7 + 3 + 2 | `php artisan test tests/Unit/Assignment` |
| 9 | **One bad CSV row fails the whole import / duplicates get in** | `tests/Feature/Imports/TicketImportTest.php` | 12 | `php artisan test --filter=TicketImportTest` |
| 10 | **Ticket list gets slow (N+1) or a filter leaks rows** | `tests/Feature/Tickets/TicketFilterTest.php` · `TicketIndexPaginationTest.php` | 18 + 6 | `php artisan test --filter="TicketFilterTest|TicketIndexPaginationTest"` |

### Running the claim tests (row 1)
Two files cover the claim, and `--filter=TicketClaimTest` matches only the first one:

```bash
php artisan test --filter=TicketClaimTest            # tests/Feature/Tickets/TicketClaimTest.php (4 tests)
php artisan test --testsuite=Concurrency             # tests/Concurrency/TicketClaimConcurrencyTest.php (1 test)
php artisan test tests/Concurrency/TicketClaimConcurrencyTest.php   # same file, by path
php artisan test --filter="TicketClaimTest|TicketClaimConcurrencyTest"   # both (5 tests)
```

`--testsuite=Concurrency` works because `phpunit.xml` defines a suite with that name pointing at the
`tests/Concurrency` folder (alongside `Unit` → `tests/Unit` and `Feature` → `tests/Feature`).

### Supporting tests (covered, but not the headline)
Activity history (`TicketActivityTest`), attachment download authorization (`AttachmentDownloadTest`), manual
assign (`TicketAssignTest`), dashboard counts and scoping (`DashboardStatsTest`), reports, audit log, admin
users/roles, monitoring/health, logging, scheduler registration (`ScheduleRegistrationTest`).

### Deliberately not tested
- **React components** — TypeScript strict mode (`npx tsc --noEmit`) and `npm run build` catch type and
  build errors. Server-side tests already prove that hiding a button isn't what protects an action.
- **Framework code** — the starter kit's `tests/Feature/Auth/*` and `Settings/*` were kept as shipped. They
  test Laravel/Fortify, not this project's logic.
- **Real email delivery / real WebSocket server** — faked with `Notification::fake()` / `Event::fake()`.
  We test *who* gets notified and *on which channel*, not SMTP or Reverb.

---

## 4. Test types used

| Type | Where | When we use it |
|---|---|---|
| **Feature** (≈ 80%) | `tests/Feature` | Anything a user triggers: the HTTP request → middleware → policy → service → DB path. Proves the whole request is protected. |
| **Unit** | `tests/Unit` | Pure logic with many edge cases: business-hours maths, SLA state, strategies, number generator. Fast, run as datasets. |
| **Concurrency** | `tests/Concurrency` | The claim race. Two sequential calls in one process can never prove a race is handled; this test runs two real processes with `Concurrency::run()`. |
| **Fakes** | inside Feature tests | `Queue::fake()`, `Notification::fake()`, `Event::fake()`, `Storage::fake()` — assert *that* a job/notification/broadcast happened without running it. |

---

## 5. Proving a test really protects something

A test that never fails proves nothing. To see one catch a bug, break the code on purpose, run the test,
watch it fail, then undo the change (`git checkout -- <file>`):

| Break this | Run | Expected |
|---|---|---|
| Remove `->whereNull('assigned_agent_id')` in `TicketRepository::claimIfUnassigned()` (`app/Repositories/Eloquent/TicketRepository.php`) | `php artisan test --testsuite=Concurrency` | Fails — both processes "win" |
| Remove `->whereNull('sla_breach_notified_at')` in `app/Jobs/ProcessSlaBreachJob.php` | `php artisan test --filter=SlaScanTest` | Fails — ticket escalated twice |
| Change `Closed => false` in `TicketStatus::canTransitionTo()` to `true` | `php artisan test --filter=TicketStatusTransitionTest` | Fails — invalid transition accepted |
| Make `ReplyRepository::forTicket()` return internal notes to everyone | `php artisan test --filter="internal note"` | Fails — customer sees the note |
