<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAssignmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ARCHITECTURE.md §7: proves the atomic conditional UPDATE is race-safe
 * across two REAL, independent MySQL connections — not just two sequential
 * calls in the same process.
 *
 * Deliberately does NOT use RefreshDatabase. RefreshDatabase wraps the
 * whole test in one uncommitted transaction on the main connection, so a
 * second, truly independent connection (a separate OS process, spawned via
 * Concurrency::run()'s "process" driver) would never see the fixture row.
 * The fixtures are committed for real here and cleaned up manually in
 * tearDown() instead.
 */
final class TicketClaimConcurrencyTest extends TestCase
{
    private const AGENT_ONE_EMAIL = 'race-agent-one@support-desk.test';

    private const AGENT_TWO_EMAIL = 'race-agent-two@support-desk.test';

    private const CUSTOMER_EMAIL = 'race-customer@support-desk.test';

    protected function setUp(): void
    {
        parent::setUp();

        app(RolesAndPermissionsSeeder::class)->run();
    }

    protected function tearDown(): void
    {
        DB::table('tickets')->where('subject', 'Concurrency race fixture')->delete();
        DB::table('users')->whereIn('email', [self::AGENT_ONE_EMAIL, self::AGENT_TWO_EMAIL, self::CUSTOMER_EMAIL])->delete();

        parent::tearDown();
    }

    public function test_two_concurrent_claims_produce_exactly_one_winner(): void
    {
        $agentOne = User::factory()->create(['email' => self::AGENT_ONE_EMAIL]);
        $agentOne->assignRole(RoleEnum::Agent->value);

        $agentTwo = User::factory()->create(['email' => self::AGENT_TWO_EMAIL]);
        $agentTwo->assignRole(RoleEnum::Agent->value);

        $customer = User::factory()->create(['email' => self::CUSTOMER_EMAIL]);
        $customer->assignRole(RoleEnum::Customer->value);

        $ticket = Ticket::factory()->create([
            'subject' => 'Concurrency race fixture',
            'status' => TicketStatus::Open->value,
            'created_by' => $customer->id,
            'assigned_agent_id' => null,
        ]);

        $ticketId = $ticket->id;
        $agentOneId = $agentOne->id;
        $agentTwoId = $agentTwo->id;

        $results = Concurrency::driver('process')->run([
            function () use ($ticketId, $agentOneId) {
                $ticket = Ticket::find($ticketId);
                $agent = User::find($agentOneId);

                try {
                    app(TicketAssignmentService::class)->claim($ticket, $agent);

                    return true;
                } catch (TicketAlreadyAssignedException) {
                    return false;
                }
            },
            function () use ($ticketId, $agentTwoId) {
                $ticket = Ticket::find($ticketId);
                $agent = User::find($agentTwoId);

                try {
                    app(TicketAssignmentService::class)->claim($ticket, $agent);

                    return true;
                } catch (TicketAlreadyAssignedException) {
                    return false;
                }
            },
        ]);

        $winners = array_filter($results);

        $this->assertCount(1, $winners, 'Exactly one of the two concurrent claims must win.');

        $ticket->refresh();
        $this->assertContains($ticket->assigned_agent_id, [$agentOneId, $agentTwoId]);
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
    }
}
