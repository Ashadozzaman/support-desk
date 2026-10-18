<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\TicketRepositoryInterface;
use App\Models\Ticket;
use App\Support\Sla\BusinessHoursCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Report metrics stored on the ticket at the moment of the event
 * (ENHANCEMENTS.md §1.2), so reports are plain aggregates over columns.
 * The record*() methods never open a transaction: they run inside the
 * caller's (TicketService / ReplyService), atomic with the status change or
 * reply that triggered them. Durations are business minutes from created_at.
 */
class TicketMetricsService
{
    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly BusinessHoursCalculator $calculator,
    ) {}

    /**
     * Called right after the ticket moved to Resolved (resolved_at is set).
     * A reopen clears both values, so a second resolution measures
     * created_at → the final resolution.
     */
    public function recordResolution(Ticket $ticket): void
    {
        $this->tickets->recordResolutionMetrics(
            $ticket,
            $this->slaMet($ticket->sla_due_at, $ticket->resolved_at),
            $this->calculator->businessMinutesBetween($ticket->created_at, $ticket->resolved_at),
        );
    }

    /**
     * Called only for the reply that set first_response_at, so later staff
     * replies never move the number.
     */
    public function recordFirstResponse(Ticket $ticket): void
    {
        $this->tickets->recordFirstResponseMinutes(
            $ticket,
            $this->calculator->businessMinutesBetween($ticket->created_at, $ticket->first_response_at),
        );
    }

    public function recordReopen(Ticket $ticket): void
    {
        $this->tickets->incrementReopenedCount($ticket);
    }

    /**
     * reports:backfill — fills metrics for tickets that predate this
     * feature (or were bulk-seeded). Idempotent: only rows with a NULL
     * metric are selected, and the values are deterministic. One
     * transaction per chunk keeps each commit small on ~1M rows.
     *
     * @param  (callable(int): void)|null  $onChunk  receives the running total
     * @return array{metrics: int, reopens: int}
     */
    public function backfill(int $chunkSize = 1000, ?callable $onChunk = null): array
    {
        $filled = 0;

        $this->tickets->chunkMissingMetrics($chunkSize, function (Collection $chunk) use (&$filled, $onChunk) {
            DB::transaction(function () use ($chunk) {
                foreach ($chunk as $ticket) {
                    $this->tickets->updateMetricsById(
                        $ticket->id,
                        $ticket->first_response_at !== null
                            ? $this->calculator->businessMinutesBetween($ticket->created_at, $ticket->first_response_at)
                            : null,
                        $ticket->resolved_at !== null
                            ? $this->calculator->businessMinutesBetween($ticket->created_at, $ticket->resolved_at)
                            : null,
                        $this->slaMet($ticket->sla_due_at, $ticket->resolved_at),
                    );
                }
            });

            $filled += $chunk->count();

            if ($onChunk !== null) {
                $onChunk($filled);
            }
        });

        $reopens = DB::transaction(fn () => $this->tickets->recountReopensFromActivity());

        return ['metrics' => $filled, 'reopens' => $reopens];
    }

    /**
     * DATABASE.md: "set at resolution: resolved_at <= sla_due_at". No SLA
     * (inactive/missing policy) means there is nothing to meet → null.
     */
    private function slaMet(?CarbonImmutable $dueAt, ?CarbonImmutable $resolvedAt): ?bool
    {
        if ($dueAt === null || $resolvedAt === null) {
            return null;
        }

        return $resolvedAt->lte($dueAt);
    }
}
