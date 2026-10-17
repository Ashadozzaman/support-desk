<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Tickets\TicketNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifty_sequential_creates_produce_unique_ordered_numbers(): void
    {
        $generator = app(TicketNumberGenerator::class);
        $year = now()->year;

        $numbers = [];
        for ($i = 0; $i < 50; $i++) {
            $numbers[] = $generator->next();
        }

        $this->assertCount(50, array_unique($numbers));

        foreach ($numbers as $index => $number) {
            $this->assertSame(sprintf('TKT-%d-%06d', $year, $index + 1), $number);
        }
    }

    public function test_year_rollover_starts_a_fresh_sequence(): void
    {
        $generator = app(TicketNumberGenerator::class);
        $currentYear = now()->year;

        DB::table('ticket_sequences')->insert(['year' => $currentYear - 1, 'last_number' => 999_990]);

        $number = $generator->next();

        $this->assertSame(sprintf('TKT-%d-%06d', $currentYear, 1), $number);
        $this->assertDatabaseHas('ticket_sequences', ['year' => $currentYear - 1, 'last_number' => 999_990]);
    }

    public function test_reserve_allocates_a_contiguous_block_under_one_lock(): void
    {
        $generator = app(TicketNumberGenerator::class);
        $year = now()->year;

        $numbers = $generator->reserve(100);

        $this->assertCount(100, $numbers);
        $this->assertSame(sprintf('TKT-%d-%06d', $year, 1), $numbers[0]);
        $this->assertSame(sprintf('TKT-%d-%06d', $year, 100), $numbers[99]);
        $this->assertDatabaseHas('ticket_sequences', ['year' => $year, 'last_number' => 100]);

        $next = $generator->next();

        $this->assertSame(sprintf('TKT-%d-%06d', $year, 101), $next);
    }
}
