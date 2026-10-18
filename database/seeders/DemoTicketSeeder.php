<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActivityEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SlaService;
use App\Support\Sla\BusinessHoursCalculator;
use App\Support\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ~2,000 tickets over the last 90 days for the demo accounts, so reports
 * (Step 17b/18) are not empty after `migrate:fresh --seed`: a mix of active,
 * resolved within SLA, resolved late (escalated), and reopened tickets, with
 * the report metrics filled exactly as the services would fill them.
 *
 * Bulk INSERT, like PerformanceSeeder: fixture generation, not a use case —
 * going through TicketService would fire events/notifications/auto-assign
 * jobs for every row. Numbers still come from the real
 * TicketNumberGenerator, and SLA windows/business minutes from the real
 * SlaService/BusinessHoursCalculator.
 *
 * Idempotent: skips when the demo customers already have tickets.
 */
class DemoTicketSeeder extends Seeder
{
    private const TICKETS = 2000;

    private const DAYS = 90;

    /** Share of tickets that end up resolved/closed (rest stay active). */
    private const RESOLVED_PERCENT = 70;

    /** Share of resolved tickets that miss their SLA. */
    private const BREACH_PERCENT = 20;

    /** Share of resolved tickets that were reopened once before the final resolution. */
    private const REOPEN_PERCENT = 8;

    public function run(): void
    {
        $customerIds = User::query()->where('email', 'like', 'customer%@support-desk.test')->pluck('id')->all();
        $agentIds = User::query()->where('email', 'like', 'agent%@support-desk.test')->pluck('id')->all();
        $categoryIds = Category::query()->pluck('id')->all();

        if ($customerIds === [] || $agentIds === [] || $categoryIds === []) {
            $this->command?->warn('DemoTicketSeeder: run DemoUsersSeeder and CategorySeeder first — skipped.');

            return;
        }

        if (Ticket::query()->whereIn('created_by', $customerIds)->exists()) {
            $this->command?->info('DemoTicketSeeder: demo tickets already exist — skipped.');

            return;
        }

        $sla = app(SlaService::class);
        $calculator = app(BusinessHoursCalculator::class);
        $policies = $sla->policies();
        $now = CarbonImmutable::now();
        // Ticket numbers are reserved under the current year, so keep
        // created_at inside it too.
        $windowStart = $now->subDays(self::DAYS)->max($now->startOfYear());
        $windowSeconds = max((int) $windowStart->diffInSeconds($now), 1);

        DB::transaction(function () use ($customerIds, $agentIds, $categoryIds, $sla, $calculator, $policies, $now, $windowStart, $windowSeconds) {
            $numbers = app(TicketNumberGenerator::class)->reserve(self::TICKETS);
            $reopenedNumbers = [];

            foreach (array_chunk($numbers, 500) as $batch) {
                $rows = [];

                foreach ($batch as $number) {
                    $createdAt = $windowStart->addSeconds(random_int(0, $windowSeconds));
                    $priority = fake()->randomElement(TicketPriority::cases());
                    $window = $sla->calculate($priority, $createdAt);
                    $slaMinutes = $policies->get($priority->value)?->resolution_minutes ?? 1440;

                    $row = [
                        'ticket_number' => $number,
                        'subject' => fake()->sentence(6),
                        'description' => fake()->paragraph(),
                        'priority' => $priority->value,
                        'category_id' => fake()->randomElement($categoryIds),
                        'created_by' => fake()->randomElement($customerIds),
                        'assigned_agent_id' => null,
                        'assigned_at' => null,
                        'status' => TicketStatus::Open->value,
                        'sla_due_at' => $window->dueAt,
                        'sla_warning_at' => $window->warningAt,
                        'sla_warning_notified_at' => null,
                        'sla_breach_notified_at' => null,
                        'escalated_at' => null,
                        'escalation_level' => 0,
                        'first_response_at' => null,
                        'first_response_minutes' => null,
                        'last_reply_at' => null,
                        'resolved_at' => null,
                        'resolution_minutes' => null,
                        'sla_met' => null,
                        'reopened_count' => 0,
                        'version' => 0,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];

                    // ~10% of tickets are still waiting in the unassigned queue.
                    if (random_int(1, 100) > 10) {
                        $row['assigned_agent_id'] = fake()->randomElement($agentIds);
                        $row['assigned_at'] = $calculator->addBusinessMinutes($createdAt, random_int(1, 60));
                        $row['status'] = fake()->randomElement([TicketStatus::InProgress, TicketStatus::WaitingForCustomer])->value;

                        $firstResponseAt = $calculator->addBusinessMinutes($createdAt, random_int(5, 240));
                        if ($firstResponseAt->lte($now)) {
                            $row['first_response_at'] = $firstResponseAt;
                            $row['first_response_minutes'] = $calculator->businessMinutesBetween($createdAt, $firstResponseAt);
                            $row['last_reply_at'] = $firstResponseAt;
                        }

                        if (random_int(1, 100) <= self::RESOLVED_PERCENT) {
                            $row = $this->resolveTicket($row, $slaMinutes, $calculator, $now);
                        }
                    }

                    $row = $this->flagEscalations($row, $now);

                    if ($row['reopened_count'] > 0) {
                        $reopenedNumbers[] = $number;
                    }

                    $rows[] = $row;
                }

                DB::table('tickets')->insert($rows);
            }

            $this->writeReopenActivities($reopenedNumbers);
        });

        $this->command?->info('DemoTicketSeeder: '.self::TICKETS.' tickets seeded.');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resolveTicket(array $row, int $slaMinutes, BusinessHoursCalculator $calculator, CarbonImmutable $now): array
    {
        $late = random_int(1, 100) <= self::BREACH_PERCENT;
        $factor = $late ? random_int(105, 250) / 100 : random_int(10, 95) / 100;
        $resolvedAt = $calculator->addBusinessMinutes($row['created_at'], max(1, (int) round($slaMinutes * $factor)));

        // Too recent to have been resolved yet — leave it active.
        if ($resolvedAt->gt($now)) {
            return $row;
        }

        $row['status'] = random_int(1, 100) <= 25 ? TicketStatus::Closed->value : TicketStatus::Resolved->value;
        $row['resolved_at'] = $resolvedAt;
        $row['resolution_minutes'] = $calculator->businessMinutesBetween($row['created_at'], $resolvedAt);
        $row['sla_met'] = $row['sla_due_at'] !== null ? $resolvedAt->lte($row['sla_due_at']) : null;

        // Every resolved ticket had a staff reply no later than its resolution.
        if ($row['first_response_at'] === null || $row['first_response_at']->gt($resolvedAt)) {
            $row['first_response_at'] = $resolvedAt;
            $row['first_response_minutes'] = $row['resolution_minutes'];
        }

        $row['reopened_count'] = random_int(1, 100) <= self::REOPEN_PERCENT ? 1 : 0;
        $row['last_reply_at'] = $resolvedAt;
        $row['updated_at'] = $resolvedAt;

        return $row;
    }

    /**
     * Sets the flags sla:scan / the escalation jobs would have set, so the
     * first scan after seeding doesn't notify for hundreds of past
     * deadlines at once (same reason as PerformanceSeeder).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function flagEscalations(array $row, CarbonImmutable $now): array
    {
        $endOfWatch = $row['resolved_at'] ?? $now;

        if ($row['sla_warning_at'] !== null && $row['sla_warning_at']->lte($endOfWatch)) {
            $row['sla_warning_notified_at'] = $row['sla_warning_at']->addMinute();
        }

        if ($row['sla_due_at'] !== null && $row['sla_due_at']->lte($endOfWatch)) {
            $row['sla_breach_notified_at'] = $row['sla_due_at']->addMinute();
            $row['escalated_at'] = $row['sla_due_at']->addMinute();
            $row['escalation_level'] = 1;
        }

        return $row;
    }

    /**
     * One ticket.reopened activity per reopened ticket, between creation
     * and the final resolution, so daily reopen counts (read from the
     * activity trail) agree with tickets.reopened_count.
     *
     * @param  list<string>  $ticketNumbers
     */
    private function writeReopenActivities(array $ticketNumbers): void
    {
        $tickets = Ticket::query()
            ->whereIn('ticket_number', $ticketNumbers)
            ->get(['id', 'created_by', 'created_at', 'resolved_at']);

        $rows = $tickets->map(function (Ticket $ticket) {
            $span = max((int) $ticket->created_at->diffInSeconds($ticket->resolved_at), 2);

            return [
                'ticket_id' => $ticket->id,
                'actor_id' => $ticket->created_by,
                'event' => ActivityEvent::TicketReopened->value,
                'old_values' => json_encode(['status' => TicketStatus::Resolved->value]),
                'new_values' => json_encode(['status' => TicketStatus::Open->value]),
                'metadata' => null,
                'created_at' => $ticket->created_at->addSeconds(random_int(1, $span - 1)),
            ];
        })->all();

        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('ticket_activities')->insert($batch);
        }
    }
}
