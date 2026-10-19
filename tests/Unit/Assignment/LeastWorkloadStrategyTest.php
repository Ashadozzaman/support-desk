<?php

declare(strict_types=1);

namespace Tests\Unit\Assignment;

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Assignment\LeastWorkloadStrategy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeastWorkloadStrategyTest extends TestCase
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

    public function test_picks_the_agent_with_fewer_active_tickets(): void
    {
        $busy = $this->createAgent();
        $idle = $this->createAgent();

        Ticket::factory()->count(3)->create([
            'assigned_agent_id' => $busy->id,
            'status' => TicketStatus::Open->value,
            'category_id' => null,
        ]);

        $picked = app(LeastWorkloadStrategy::class)->pick(null);

        $this->assertSame($idle->id, $picked?->id);
    }

    public function test_ties_on_workload_are_broken_by_the_oldest_last_assigned_at(): void
    {
        $this->createAgent(['last_assigned_at' => now()->subMinute()]);
        $neverAssigned = $this->createAgent(['last_assigned_at' => null]);

        $picked = app(LeastWorkloadStrategy::class)->pick(null);

        $this->assertSame($neverAssigned->id, $picked?->id);
    }

    public function test_an_unavailable_agent_is_never_picked(): void
    {
        $this->createAgent(['is_available' => false]);
        $available = $this->createAgent(['is_available' => true]);

        $picked = app(LeastWorkloadStrategy::class)->pick(null);

        $this->assertSame($available->id, $picked?->id);
    }

    public function test_an_agent_at_full_capacity_is_skipped(): void
    {
        $full = $this->createAgent(['max_active_tickets' => 1]);
        Ticket::factory()->create([
            'assigned_agent_id' => $full->id,
            'status' => TicketStatus::Open->value,
            'category_id' => null,
        ]);

        $underCapacity = $this->createAgent(['max_active_tickets' => 1]);

        $picked = app(LeastWorkloadStrategy::class)->pick(null);

        $this->assertSame($underCapacity->id, $picked?->id);
    }

    public function test_only_an_agent_skilled_in_the_category_or_unrestricted_is_eligible(): void
    {
        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();

        $wrongSkill = $this->createAgent();
        $wrongSkill->categories()->attach($otherCategory);

        $rightSkill = $this->createAgent();
        $rightSkill->categories()->attach($category);

        $picked = app(LeastWorkloadStrategy::class)->pick($category->id);

        $this->assertSame($rightSkill->id, $picked?->id);
    }

    public function test_an_agent_with_no_configured_skills_is_treated_as_unrestricted(): void
    {
        $category = Category::factory()->create();
        $unrestricted = $this->createAgent();

        $picked = app(LeastWorkloadStrategy::class)->pick($category->id);

        $this->assertSame($unrestricted->id, $picked?->id);
    }

    public function test_returns_null_when_no_agent_is_available(): void
    {
        $picked = app(LeastWorkloadStrategy::class)->pick(null);

        $this->assertNull($picked);
    }
}
