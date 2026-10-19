<?php

declare(strict_types=1);

namespace Tests\Unit\Assignment;

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Assignment\RoundRobinStrategy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundRobinStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function createAgent(array $profileAttributes = []): User
    {
        $agent = User::factory()->create();
        // Candidates must hold tickets.claim (Step 19e), not just a profile.
        $agent->assignRole(RoleEnum::Agent->value);
        AgentProfile::factory()->create([...$profileAttributes, 'user_id' => $agent->id]);

        return $agent;
    }

    public function test_picks_purely_by_oldest_last_assigned_at_ignoring_workload(): void
    {
        $this->createAgent(['last_assigned_at' => now()->subMinute()]);
        $longAgoAssigned = $this->createAgent(['last_assigned_at' => now()->subHour()]);

        // Give the "long ago" agent a heavier workload than the recent one —
        // round robin must still pick it, unlike LeastWorkloadStrategy.
        Ticket::factory()->count(3)->create([
            'assigned_agent_id' => $longAgoAssigned->id,
            'status' => TicketStatus::Open->value,
            'category_id' => null,
        ]);

        $picked = app(RoundRobinStrategy::class)->pick(null);

        $this->assertSame($longAgoAssigned->id, $picked?->id);
    }

    public function test_an_agent_never_assigned_before_is_picked_ahead_of_one_assigned_recently(): void
    {
        $this->createAgent(['last_assigned_at' => now()->subSecond()]);
        $neverAssigned = $this->createAgent(['last_assigned_at' => null]);

        $picked = app(RoundRobinStrategy::class)->pick(null);

        $this->assertSame($neverAssigned->id, $picked?->id);
    }

    public function test_returns_null_when_no_agent_is_available(): void
    {
        $picked = app(RoundRobinStrategy::class)->pick(null);

        $this->assertNull($picked);
    }
}
