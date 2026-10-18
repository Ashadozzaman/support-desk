<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Models\Import;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Hits the real /broadcasting/auth endpoint (routes/channels.php). The
 * "log" broadcaster used everywhere else in testing is a no-op for auth()
 * (LogBroadcaster::auth() is empty), which would make every assertion here
 * meaningless, so this file alone switches to the "reverb" driver —
 * scoped here rather than in .env.testing so the rest of the suite still
 * never attempts a real network broadcast (ARCHITECTURE.md §3 /
 * QUEUE_SCHEDULER_REALTIME.md §4).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'testing-key',
        'broadcasting.connections.reverb.secret' => 'testing-secret',
        'broadcasting.connections.reverb.app_id' => 'testing',
    ]);

    // routes/channels.php already ran once at boot — against whatever
    // driver .env.testing names (log) — registering every Broadcast::channel()
    // callback on THAT broadcaster instance. The override above only takes
    // effect for channel registrations made from this point on, so the
    // channels have to be (re-)registered against the now-current default.
    require base_path('routes/channels.php');

    $this->seed(RolesAndPermissionsSeeder::class);
});

function authorizeChannel(User $user, string $channel): TestResponse
{
    return test()->actingAs($user)->postJson('/broadcasting/auth', [
        'channel_name' => "private-{$channel}",
        'socket_id' => '1234.1234',
    ]);
}

function authorizeChannelAsGuest(string $channel): TestResponse
{
    return test()->postJson('/broadcasting/auth', [
        'channel_name' => "private-{$channel}",
        'socket_id' => '1234.1234',
    ]);
}

// --- tickets.{ticket} -------------------------------------------------

test('a customer can authorise their own ticket channel', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    authorizeChannel($customer, "tickets.{$ticket->ticket_number}")->assertOk();
});

test('a customer cannot authorise another customer\'s ticket channel', function () {
    $owner = User::factory()->create();
    $owner->assignRole(RoleEnum::Customer->value);
    $stranger = User::factory()->create();
    $stranger->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $owner->id]);

    authorizeChannel($stranger, "tickets.{$ticket->ticket_number}")->assertForbidden();
});

test('an agent can authorise an unassigned ticket\'s channel (it is in their queue)', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => null]);

    authorizeChannel($agent, "tickets.{$ticket->ticket_number}")->assertOk();
});

test('an agent cannot authorise a ticket channel assigned to a different agent', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $otherAgent = User::factory()->create();
    $otherAgent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $otherAgent->id]);

    authorizeChannel($agent, "tickets.{$ticket->ticket_number}")->assertForbidden();
});

test('an admin can authorise any ticket channel', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $ticket = Ticket::factory()->create();

    authorizeChannel($admin, "tickets.{$ticket->ticket_number}")->assertOk();
});

test('a guest cannot authorise a ticket channel', function () {
    $ticket = Ticket::factory()->create();

    authorizeChannelAsGuest("tickets.{$ticket->ticket_number}")->assertForbidden();
});

// --- tickets.{ticket}.staff (CLAUDE.md rule 6) -------------------------

test('a customer cannot authorise the staff channel for their own ticket', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    authorizeChannel($customer, "tickets.{$ticket->ticket_number}.staff")->assertForbidden();
});

test('an agent assigned to the ticket can authorise its staff channel', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id]);

    authorizeChannel($agent, "tickets.{$ticket->ticket_number}.staff")->assertOk();
});

test('an admin can authorise any ticket\'s staff channel', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $ticket = Ticket::factory()->create();

    authorizeChannel($admin, "tickets.{$ticket->ticket_number}.staff")->assertOk();
});

// --- staff.queue --------------------------------------------------------

test('a customer cannot authorise staff.queue', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    authorizeChannel($customer, 'staff.queue')->assertForbidden();
});

test('an agent can authorise staff.queue', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    authorizeChannel($agent, 'staff.queue')->assertOk();
});

// --- staff.alerts ---------------------------------------------------------

test('a customer cannot authorise staff.alerts', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    authorizeChannel($customer, 'staff.alerts')->assertForbidden();
});

test('an agent cannot authorise staff.alerts (admin only)', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    authorizeChannel($agent, 'staff.alerts')->assertForbidden();
});

test('an admin can authorise staff.alerts', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    authorizeChannel($admin, 'staff.alerts')->assertOk();
});

// --- App.Models.User.{id} -------------------------------------------------

test('a user can authorise their own notification channel', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleEnum::Customer->value);

    authorizeChannel($user, "App.Models.User.{$user->id}")->assertOk();
});

test('a user cannot authorise another user\'s notification channel', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleEnum::Customer->value);
    $other = User::factory()->create();
    $other->assignRole(RoleEnum::Customer->value);

    authorizeChannel($user, "App.Models.User.{$other->id}")->assertForbidden();
});

// --- imports.{import} ------------------------------------------------------

test('the admin who started an import can authorise its channel', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $import = Import::factory()->create(['user_id' => $admin->id]);

    authorizeChannel($admin, "imports.{$import->id}")->assertOk();
});

test('a different admin cannot authorise someone else\'s import channel', function () {
    $owner = User::factory()->create();
    $owner->assignRole(RoleEnum::Admin->value);
    $other = User::factory()->create();
    $other->assignRole(RoleEnum::Admin->value);
    $import = Import::factory()->create(['user_id' => $owner->id]);

    authorizeChannel($other, "imports.{$import->id}")->assertForbidden();
});
