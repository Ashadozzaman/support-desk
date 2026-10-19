<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTOs\CreateTicketData;
use App\DTOs\TicketFilterData;
use App\DTOs\UpdateTicketData;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as BaseCollection;

interface TicketRepositoryInterface
{
    public function create(CreateTicketData $data, ?CarbonImmutable $slaDueAt, ?CarbonImmutable $slaWarningAt): Ticket;

    public function findByNumberOrFail(string $ticketNumber): Ticket;

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginateFor(User $viewer, string $tab, TicketFilterData $filters, int $perPage = 15): LengthAwarePaginator;

    public function countActiveForAgent(int $agentId): int;

    public function update(Ticket $ticket, UpdateTicketData $data, ?CarbonImmutable $slaDueAt, ?CarbonImmutable $slaWarningAt): Ticket;

    /**
     * Sets resolved_at on Resolved, keeps it on Closed, and clears it —
     * together with sla_met/resolution_minutes — on any other target, since
     * the ticket is no longer resolved.
     */
    public function updateStatus(Ticket $ticket, TicketStatus $status): Ticket;

    public function recordResolutionMetrics(Ticket $ticket, ?bool $slaMet, int $resolutionMinutes): Ticket;

    public function recordFirstResponseMinutes(Ticket $ticket, int $minutes): Ticket;

    /**
     * Atomic `reopened_count = reopened_count + 1` — never read-then-write.
     */
    public function incrementReopenedCount(Ticket $ticket): Ticket;

    /**
     * Resolved/responded tickets whose report metrics are still NULL
     * (reports:backfill), paged by id so filling them mid-iteration is safe.
     *
     * @param  callable(Collection<int, Ticket>): void  $callback
     */
    public function chunkMissingMetrics(int $size, callable $callback): void;

    public function updateMetricsById(int $ticketId, ?int $firstResponseMinutes, ?int $resolutionMinutes, ?bool $slaMet): void;

    /**
     * Sets reopened_count from the activity trail (ticket.reopened, or a
     * status.changed resolved → open). Returns the number of rows changed.
     */
    public function recountReopensFromActivity(): int;

    /**
     * Updates last_reply_at (every reply) and first_response_at (the first
     * staff public reply only, left untouched once set).
     */
    public function recordReply(Ticket $ticket, bool $isStaffReply): Ticket;

    /**
     * Atomic conditional UPDATE (ARCHITECTURE.md §7): claims the ticket only
     * if it is still open and unassigned. Returns true iff exactly one row
     * was affected — false means another agent won the race first.
     */
    public function claimIfUnassigned(Ticket $ticket, int $agentId): bool;

    /**
     * Re-reads the ticket with `SELECT … FOR UPDATE` so the caller's
     * transaction decides on the committed row, not on a copy loaded before
     * it started. Must be called inside a transaction (the lock is released
     * on commit).
     */
    public function findForUpdate(int $ticketId): ?Ticket;

    /**
     * Manual/auto assignment (admin assign, reassign, auto-assign). Moves
     * an Open ticket to InProgress; leaves the status alone otherwise (e.g.
     * reassigning a ticket that is already InProgress).
     */
    public function assign(Ticket $ticket, int $agentId): Ticket;

    /**
     * Ids of the oldest open, unassigned tickets created at or before
     * `$createdBefore`, at most `$limit`. `$categoryIds === null` means any
     * category; otherwise only those categories plus uncategorised tickets.
     *
     * @param  array<int, int>|null  $categoryIds
     * @return BaseCollection<int, int>
     */
    public function unassignedIdsForSweep(CarbonImmutable $createdBefore, ?array $categoryIds, int $limit): BaseCollection;
}
