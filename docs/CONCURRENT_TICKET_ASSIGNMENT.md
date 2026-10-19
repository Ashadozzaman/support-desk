# Concurrent Ticket Assignment

Assessment §5: *two agents accept the same unassigned ticket at about the same time — the ticket must never end up
assigned to both.* This note explains how each assignment path is protected, and records the fixes made on
2026-10-19 (with the tests that proved each bug first).

Design reference: [`ARCHITECTURE.md`](ARCHITECTURE.md) §6 (assignment) and §7 (concurrent accept).

---

## 1. The three ways a ticket gets an agent

| Path | Who | Race guard | Code |
|---|---|---|---|
| **Claim** ("Accept") — the §5 scenario | agent | atomic conditional `UPDATE` (compare-and-set) | `TicketRepository::claimIfUnassigned()`, `TicketAssignmentService::claim()` |
| **Auto-assign** | queued job / scheduler sweep | `SELECT … FOR UPDATE` on the ticket, then `FOR UPDATE SKIP LOCKED` on the agent profile | `TicketAssignmentService::autoAssign()`, `AgentRepository::lockForAssignment()` |
| **Manual assign / reassign** | admin | `SELECT … FOR UPDATE` on the ticket (added by Fix 1 below) | `TicketAssignmentService::assign()` |

All three run inside `DB::transaction`, write their activity row in that same transaction, and dispatch
`TicketAssigned` only after commit (`DB::afterCommit`).

---

## 2. Claim — why one atomic UPDATE is enough

```php
// app/Repositories/Eloquent/TicketRepository.php
$affected = Ticket::query()
    ->whereKey($ticket->id)
    ->where('status', TicketStatus::Open->value)
    ->whereNull('assigned_agent_id')          // the check lives inside the write
    ->update([
        'assigned_agent_id' => $agentId,
        'assigned_at' => now(),
        'status' => TicketStatus::InProgress->value,
        'version' => DB::raw('version + 1'),
    ]);

return $affected === 1;                      // 1 = this agent won, 0 = someone else already did
```

Timeline with agents A and B:

```
A: UPDATE … WHERE id=7 AND assigned_agent_id IS NULL   → takes the row lock, 1 row
B: UPDATE … WHERE id=7 AND assigned_agent_id IS NULL   → waits for A's lock
A: COMMIT                                               → lock released
B: re-evaluates WHERE on the committed row → assigned_agent_id = A → 0 rows
B: service throws TicketAlreadyAssignedException        → HTTP 409 "already claimed"
```

* InnoDB re-checks the `WHERE` against the latest committed row after waiting for the lock (an UPDATE does a
  "current read", not a snapshot read), so B can never overwrite A.
* Check and write are **one statement** — there is no gap between "is it free?" and "take it" for another request
  to slip into. The classic bug is `if ($ticket->assigned_agent_id === null) { $ticket->update(...); }`.
* Only the winner writes the activity row and fires the event.

### Why not `lockForUpdate()` for claim?

```php
$t = Ticket::whereKey($id)->lockForUpdate()->first();   // SELECT … FOR UPDATE
if ($t->assigned_agent_id !== null) { throw …; }
$t->update([...]);
```

This is also correct, but it takes two round trips and holds the lock longer. It also depends on remembering
`lockForUpdate()`: without it the code still runs and silently goes back to the race. The atomic UPDATE can't be
"half written". Use `lockForUpdate()` when the decision needs more than a `WHERE` clause can express (see
auto-assign and manual assign below).

### Tests
* `tests/Feature/Tickets/TicketClaimTest.php` — sequential double claim: the second claim gets a 409.
* `tests/Concurrency/TicketClaimConcurrencyTest.php` — real parallel processes against MySQL: exactly one winner.

---

## 3. Auto-assign — why it locks the row instead

Auto-assign has to **read** the ticket (its category), **decide** (run the `AssignmentStrategy`), then **write**.
A single `UPDATE` can't express "pick the least-busy agent for this category", so it locks the ticket row first:

1. `findForUpdate($ticketId)` — `SELECT … FOR UPDATE`; if the ticket is already assigned, stop.
2. Strategy picks an agent; the agent profile is locked `FOR UPDATE SKIP LOCKED`, so two jobs never both load
   the same agent past `max_active_tickets`.
3. Assign, update `last_assigned_at`, write activity, commit.

Auto-assign vs. a claim at the same moment: the claim's `UPDATE` waits for the ticket lock, then sees
`assigned_agent_id` is no longer `NULL` → 0 rows → 409. Or the claim wins first and auto-assign's locked re-read
sees the agent → stop. Either way there is one owner.

---

## 4. Fixes made (2026-10-19)

Both started from a failing test (CLAUDE.md: *a bug fix starts with a failing test*).

### Fix 1 — Manual assign worked from a stale ticket

**Bug.** `assign()` decided everything from the `$ticket` model that route-model binding loaded *before* the
transaction started. If an agent claimed the ticket between the admin opening the assign dialog and submitting it:

* the activity row said `ticket.assigned` with previous agent **none** — it should be `ticket.reassigned` from the
  claiming agent (the audit trail was wrong);
* `version` was written as `stale + 1`, cancelling the claim's own version bump (a lost update on the
  optimistic-locking counter).

The admin's assignment still winning is **intended** (an admin may reassign); only the record of it was wrong.

**Fix.** Lock and re-read the row inside the transaction, then decide from the locked copy:

```php
// app/Services/TicketAssignmentService.php — assign()
$locked = $this->tickets->findForUpdate($ticket->id)
    ?? throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->id]);

$event = $locked->assigned_agent_id === null
    ? ActivityEvent::TicketAssigned
    : ActivityEvent::TicketReassigned;
$previousAgentId = $locked->assigned_agent_id;

$updated = $this->tickets->assign($locked, $agent->id);
```

**Test.** `tests/Feature/Tickets/TicketAssignTest.php` — *"a manual assign racing a claim records the claiming
agent as the previous assignee"*: loads a stale copy, claims with another agent, assigns with the stale copy, then
asserts `version = 2`, event `ticket.reassigned` and `old_values = {assigned_agent_id: <claimer>}`. Before the
fix it failed on `version` (1 instead of 2).

### Fix 2 — Auto-assign queried the model from the service (layering)

**Bug.** `autoAssign()` ran `Ticket::query()->whereKey(...)->lockForUpdate()->first()` directly in the service.
CLAUDE.md rule 1: locks and queries belong in the repository; services depend on repository interfaces.

**Fix.** New repository method used by both `autoAssign()` and `assign()`:

```php
// app/Contracts/Repositories/TicketRepositoryInterface.php
public function findForUpdate(int $ticketId): ?Ticket;

// app/Repositories/Eloquent/TicketRepository.php
public function findForUpdate(int $ticketId): ?Ticket
{
    return Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();
}
```

The repository never opens a transaction itself — the lock lasts until the calling service's transaction commits.

**Test.** `tests/Feature/Tickets/AutoAssignLockTest.php` binds a recording subclass of `TicketRepository` and
asserts `autoAssign()` took its lock through `findForUpdate()`. Before the fix nothing was recorded.

---

## 5. Interview questions this covers

1. *Why is `UPDATE … WHERE assigned_agent_id IS NULL` race-safe under REPEATABLE READ?* — UPDATE does a current
   read under a row lock; the waiting statement re-checks the `WHERE` on the committed row.
2. *When do you use `lockForUpdate()` instead?* — When the decision needs logic between read and write
   (auto-assign's strategy, manual assign's "was it assigned or reassigned?").
3. *What was wrong with the manual assign before?* — It decided from a model loaded outside the transaction, so a
   concurrent claim produced a wrong audit row and a lost `version` increment.
4. *Deadlock risk?* — Lock order is always ticket → agent profile (ARCHITECTURE.md §16); claim locks only the ticket.
