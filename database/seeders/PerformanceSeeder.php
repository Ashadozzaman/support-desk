<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Services\SlaService;
use App\Support\Sla\BusinessHoursCalculator;
use App\Support\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Load-testing fixture: ~1M tickets across a larger customer/agent pool,
 * spread over the last few months. Deliberately NOT wired into
 * DatabaseSeeder — normal `db:seed` must stay fast. Run explicitly:
 *
 *   php artisan db:seed --class=PerformanceSeeder
 *
 * Tunable via env (defaults shown):
 *   SUPPORT_SEED_TICKETS=1000000 SUPPORT_SEED_CUSTOMERS=5000 \
 *   SUPPORT_SEED_AGENTS=30 SUPPORT_SEED_MONTHS=3 SUPPORT_SEED_CHUNK=2000 \
 *   php artisan db:seed --class=PerformanceSeeder
 *
 * Why bulk INSERT instead of TicketFactory::create() in a loop: 1M
 * Eloquent saves (model events, casts, a cached-repo SLA lookup per row)
 * would take hours. Chunked `DB::table(...)->insert()` calls (a few
 * thousand rows per statement) move the same volume in minutes. This is a
 * fixture-generation script, not application code, so it intentionally
 * bypasses the Controller->Service->Repository layering in CLAUDE.md —
 * there is no request to serve and no business rule being exercised.
 *
 * Ticket numbering still goes through the real TicketNumberGenerator
 * (ARCHITECTURE.md §4), so ticket_sequences stays correct for tickets
 * created after this seeder runs. SLA windows are computed with the real
 * BusinessHoursCalculator/SlaService, fed from business hours/policies
 * loaded into memory once (bypassing the Cache::rememberForever lookups,
 * which would otherwise be a DB round trip per ticket on the `database`
 * cache store times 2 million calls).
 */
class PerformanceSeeder extends Seeder
{
    use WithoutModelEvents;

    private const STATUS_WEIGHTS = [
        TicketStatus::Resolved->value => 45,
        TicketStatus::Closed->value => 20,
        TicketStatus::Open->value => 10,
        TicketStatus::InProgress->value => 15,
        TicketStatus::WaitingForCustomer->value => 10,
    ];

    private const PRIORITY_WEIGHTS = [
        TicketPriority::Low->value => 40,
        TicketPriority::Medium->value => 30,
        TicketPriority::High->value => 20,
        TicketPriority::Critical->value => 10,
    ];

    private int $totalTickets;

    private int $totalCustomers;

    private int $totalAgents;

    private int $months;

    private int $chunkSize;

    public function run(): void
    {
        // One-off CLI fixture, not a request — unbounded memory avoids
        // tuning php.ini just to run it.
        ini_set('memory_limit', '-1');
        DB::connection()->disableQueryLog();

        $this->totalTickets = (int) env('SUPPORT_SEED_TICKETS', 1_000_000);
        $this->totalCustomers = (int) env('SUPPORT_SEED_CUSTOMERS', 5_000);
        $this->totalAgents = (int) env('SUPPORT_SEED_AGENTS', 30);
        $this->months = (int) env('SUPPORT_SEED_MONTHS', 3);
        $this->chunkSize = (int) env('SUPPORT_SEED_CHUNK', 2_000);

        $categoryIds = Category::query()->pluck('id');

        if ($categoryIds->isEmpty()) {
            $this->command?->error('Run the standard seeders first — categories/roles are required: php artisan db:seed');

            return;
        }

        $this->command?->info("Seeding {$this->totalCustomers} customers...");
        $customerIds = $this->seedCustomers();

        $this->command?->info("Seeding {$this->totalAgents} agents...");
        $agentIds = $this->seedAgents($categoryIds);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Seeding {$this->totalTickets} tickets...");
        $this->seedTickets($customerIds, $agentIds, $categoryIds);

        $this->command?->info('Done.');
    }

    /**
     * @return list<int>
     */
    private function seedCustomers(): array
    {
        $roleId = SpatieRole::query()->where('name', RoleEnum::Customer->value)->value('id');
        $password = Hash::make('password');
        $now = now();
        $ids = [];

        foreach (array_chunk(range(1, $this->totalCustomers), $this->chunkSize) as $batch) {
            $rows = array_map(fn (int $n) => [
                'name' => "Perf Customer {$n}",
                'email' => "perf-customer-{$n}@support-desk.test",
                'password' => $password,
                'email_verified_at' => $now,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $batch);

            DB::table('users')->insert($rows);

            // MySQL: LAST_INSERT_ID() after a multi-row INSERT returns the id
            // generated for the FIRST row of that statement, so the batch's
            // ids are a contiguous run from there (safe: nothing else writes
            // to `users` while this seeder runs).
            $firstId = (int) DB::getPdo()->lastInsertId();
            $batchIds = range($firstId, $firstId + count($batch) - 1);
            $ids = array_merge($ids, $batchIds);

            DB::table('model_has_roles')->insert(array_map(fn (int $id) => [
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $id,
            ], $batchIds));
        }

        return $ids;
    }

    /**
     * @param  Collection<int, int>  $categoryIds
     * @return list<int>
     */
    private function seedAgents(Collection $categoryIds): array
    {
        $roleId = SpatieRole::query()->where('name', RoleEnum::Agent->value)->value('id');
        $password = Hash::make('password');
        $now = now();

        $userRows = [];
        for ($n = 1; $n <= $this->totalAgents; $n++) {
            $userRows[] = [
                'name' => "Perf Agent {$n}",
                'email' => "perf-agent-{$n}@support-desk.test",
                'password' => $password,
                'email_verified_at' => $now,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('users')->insert($userRows);
        $firstId = (int) DB::getPdo()->lastInsertId();
        $ids = range($firstId, $firstId + $this->totalAgents - 1);

        DB::table('model_has_roles')->insert(array_map(fn (int $id) => [
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $id,
        ], $ids));

        DB::table('agent_profiles')->insert(array_map(fn (int $id) => [
            'user_id' => $id,
            'is_available' => true,
            'max_active_tickets' => 10,
            'last_assigned_at' => $now->copy()->subMinutes(random_int(0, 500)),
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids));

        $skillRows = [];
        foreach ($ids as $agentId) {
            $skillCount = random_int(1, min(3, $categoryIds->count()));
            foreach ($categoryIds->random($skillCount) as $categoryId) {
                $skillRows[] = ['category_id' => $categoryId, 'user_id' => $agentId];
            }
        }
        DB::table('category_agent')->insert($skillRows);

        return $ids;
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $agentIds
     * @param  Collection<int, int>  $categoryIds
     */
    private function seedTickets(array $customerIds, array $agentIds, Collection $categoryIds): void
    {
        $slaService = $this->buildOfflineSlaService();
        $now = CarbonImmutable::now();

        // Keep ticket numbers consistent with their creation year: clamp the
        // random window so it never crosses into a previous year (the real
        // TicketNumberGenerator always reserves under the *current* year).
        $windowStart = $now->subMonths($this->months)->max($now->startOfYear());
        $windowSeconds = (int) $now->diffInSeconds($windowStart);

        $numbers = app(TicketNumberGenerator::class)->reserve($this->totalTickets);
        $categoryIdList = $categoryIds->all();

        $inserted = 0;

        DB::transaction(function () use (&$inserted, $numbers, $customerIds, $agentIds, $categoryIdList, $slaService, $now, $windowStart, $windowSeconds) {
            for ($offset = 0; $offset < $this->totalTickets; $offset += $this->chunkSize) {
                $batchSize = min($this->chunkSize, $this->totalTickets - $offset);
                $rows = [];

                for ($i = 0; $i < $batchSize; $i++) {
                    $rows[] = $this->buildTicketRow(
                        $numbers[$offset + $i],
                        $customerIds,
                        $agentIds,
                        $categoryIdList,
                        $slaService,
                        $now,
                        $windowStart,
                        $windowSeconds,
                    );
                }

                DB::table('tickets')->insert($rows);
                $inserted += $batchSize;

                if ($inserted % ($this->chunkSize * 25) === 0 || $inserted === $this->totalTickets) {
                    $this->command?->info("  {$inserted} / {$this->totalTickets}");
                }
            }
        }, attempts: 1);
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $agentIds
     * @param  list<int>  $categoryIdList
     * @return array<string, mixed>
     */
    private function buildTicketRow(
        string $ticketNumber,
        array $customerIds,
        array $agentIds,
        array $categoryIdList,
        SlaService $slaService,
        CarbonImmutable $now,
        CarbonImmutable $windowStart,
        int $windowSeconds,
    ): array {
        $createdAt = $windowStart->addSeconds(random_int(0, max($windowSeconds, 1)));
        $status = TicketStatus::from($this->weightedPick(self::STATUS_WEIGHTS));
        $priority = TicketPriority::from($this->weightedPick(self::PRIORITY_WEIGHTS));

        $window = $slaService->calculate($priority, $createdAt);
        $dueAt = $window->dueAt;
        $warningAt = $window->warningAt;

        $isActive = in_array($status, [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer], true);
        $unassigned = $status === TicketStatus::Open && random_int(1, 100) <= 60;

        $assignedAgentId = $unassigned ? null : $agentIds[array_rand($agentIds)];
        $assignedAt = $assignedAgentId !== null ? $createdAt->addMinutes(random_int(1, 120)) : null;

        $resolvedAt = null;
        $slaMet = null;
        if (! $isActive) {
            $maxResolveSeconds = max((int) $createdAt->diffInSeconds($now), 60);
            $resolvedAt = $createdAt->addSeconds(random_int(60, $maxResolveSeconds));
            $slaMet = $dueAt !== null ? $resolvedAt->lte($dueAt) : null;
        }

        $firstResponseAt = $assignedAgentId !== null ? $createdAt->addMinutes(random_int(5, 180)) : null;
        $lastReplyAt = $resolvedAt ?? $firstResponseAt;

        // Historical active tickets that are already past their warning/due
        // time need their idempotency flags backfilled — otherwise the very
        // first `sla:scan` after seeding would dispatch a notification job
        // for every one of them at once (ARCHITECTURE.md §9).
        $warningNotifiedAt = null;
        $breachNotifiedAt = null;
        $escalatedAt = null;
        $escalationLevel = 0;

        if ($isActive && $warningAt !== null && $warningAt->lte($now)) {
            $warningNotifiedAt = $warningAt->addMinutes(random_int(1, 30));

            if ($dueAt !== null && $dueAt->lte($now)) {
                $breachNotifiedAt = $dueAt->addMinutes(random_int(1, 60));
                $escalatedAt = $breachNotifiedAt;
                $escalationLevel = 1;
            }
        }

        return [
            'ticket_number' => $ticketNumber,
            'subject' => $this->fakeSubject(),
            'description' => $this->fakeDescription(),
            'status' => $status->value,
            'priority' => $priority->value,
            'category_id' => random_int(1, 100) <= 95 ? $categoryIdList[array_rand($categoryIdList)] : null,
            'created_by' => $customerIds[array_rand($customerIds)],
            'assigned_agent_id' => $assignedAgentId,
            'external_ref' => null,
            'sla_due_at' => $dueAt,
            'sla_warning_at' => $warningAt,
            'sla_warning_notified_at' => $warningNotifiedAt,
            'sla_breach_notified_at' => $breachNotifiedAt,
            'escalated_at' => $escalatedAt,
            'escalation_level' => $escalationLevel,
            'first_response_at' => $firstResponseAt,
            'last_reply_at' => $lastReplyAt,
            'resolved_at' => $resolvedAt,
            'sla_met' => $slaMet,
            'version' => 0,
            'assigned_at' => $assignedAt,
            'created_at' => $createdAt,
            'updated_at' => $lastReplyAt ?? $createdAt,
        ];
    }

    /**
     * @param  array<string, int>  $weights  value => relative weight
     */
    private function weightedPick(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, $total);

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    private function fakeSubject(): string
    {
        return self::SUBJECTS[array_rand(self::SUBJECTS)].' #'.random_int(1000, 9999);
    }

    private function fakeDescription(): string
    {
        return self::DESCRIPTIONS[array_rand(self::DESCRIPTIONS)];
    }

    private const SUBJECTS = [
        'Unable to log into my account',
        'Invoice amount looks incorrect',
        'Feature request: dark mode',
        'App crashes on checkout',
        'Password reset email never arrives',
        'How do I export my data',
        'Billing charged twice this month',
        'Integration with third-party API failing',
        'Slow page load on dashboard',
        'Need to update company details',
        'Attachment upload keeps failing',
        'Question about plan limits',
        'Mobile app not syncing',
        'Request to cancel subscription',
        'Error 500 when saving settings',
    ];

    private const DESCRIPTIONS = [
        'This started happening after the last update and I have not been able to resolve it myself. Please advise.',
        'I have tried clearing my cache and using a different browser, but the issue persists.',
        'Our team relies on this feature daily, so a quick resolution would be appreciated.',
        'Steps to reproduce: log in, open the relevant page, and the error appears immediately.',
        'Could someone take a look at this when they have a chance? It is not urgent but is annoying.',
        'This is blocking our workflow, please treat as high priority.',
        'Screenshot available on request. Let me know what additional details you need.',
        'Happened again today, same as last week. Seems intermittent.',
    ];

    /**
     * SlaService wired to in-memory config instead of the cached repository
     * — see class docblock for why. The Interface boundary (ARCHITECTURE.md
     * §1 "D" in SOLID) is what makes this substitution possible without
     * touching SlaService/BusinessHoursCalculator at all.
     */
    private function buildOfflineSlaService(): SlaService
    {
        $policies = SlaPolicy::query()->get()->keyBy(fn (SlaPolicy $p) => $p->priority->value);
        $businessHours = BusinessHour::query()->get()->keyBy('day_of_week');
        $holidayDates = Holiday::query()->get()->pluck('date')->map(fn ($d) => $d->toDateString());

        $config = new class($policies, $businessHours, $holidayDates) implements SlaConfigRepositoryInterface
        {
            public function __construct(
                private Collection $policies,
                private Collection $businessHours,
                private Collection $holidayDates,
            ) {}

            public function policyFor(TicketPriority $priority): ?SlaPolicy
            {
                return $this->policies->get($priority->value);
            }

            public function policies(): Collection
            {
                return $this->policies;
            }

            public function businessHours(): Collection
            {
                return $this->businessHours;
            }

            public function holidayDates(): Collection
            {
                return $this->holidayDates;
            }

            public function holidays(): Collection
            {
                return Holiday::query()->orderBy('date')->get();
            }

            public function updatePolicies(array $rows): void {}

            public function updateBusinessHours(array $rows): void {}

            public function addHoliday(string $date, string $name): void {}

            public function removeHoliday(int $id): void {}

            public function bustCache(): void {}
        };

        return new SlaService($config, new BusinessHoursCalculator($config));
    }
}
