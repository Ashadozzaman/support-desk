<?php

declare(strict_types=1);

namespace App\Support\Tickets;

use Illuminate\Support\Facades\DB;

/**
 * Serialises ticket-number allocation under a row lock on ticket_sequences
 * (ARCHITECTURE.md §4). Must be called from inside the caller's transaction
 * — the lock is only meaningful while that transaction holds it open, and
 * as late as possible in it, since every other writer for the same year
 * blocks until this transaction commits.
 */
class TicketNumberGenerator
{
    public function next(): string
    {
        return $this->reserve(1)[0];
    }

    /**
     * Reserve a contiguous block of $count numbers for the current year in
     * one lock acquisition (used by CSV import to avoid locking per row).
     *
     * @return list<string>
     */
    public function reserve(int $count): array
    {
        $year = now()->year;

        DB::statement('INSERT IGNORE INTO ticket_sequences (year, last_number) VALUES (?, 0)', [$year]);

        $sequence = DB::table('ticket_sequences')->where('year', $year)->lockForUpdate()->first();

        $start = $sequence->last_number + 1;
        $end = $sequence->last_number + $count;

        DB::table('ticket_sequences')->where('year', $year)->update(['last_number' => $end]);

        $numbers = [];
        for ($number = $start; $number <= $end; $number++) {
            $numbers[] = sprintf('TKT-%d-%06d', $year, $number);
        }

        return $numbers;
    }
}
