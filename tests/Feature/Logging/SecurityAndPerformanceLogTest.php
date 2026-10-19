<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAssignmentService;
use App\Support\Logging\LogRedactor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function securityLogUser(RoleEnum $role): User
{
    $user = User::factory()->create(['password' => 'correct-password-1']);
    $user->assignRole($role->value);

    if ($role === RoleEnum::Agent) {
        AgentProfile::factory()->create(['user_id' => $user->id]);
    }

    return $user;
}

test('a real 403 is logged once to the security channel with the route', function () {
    $log = captureLog('security');
    $agent = securityLogUser(RoleEnum::Agent);

    $this->actingAs($agent)->get(route('admin.agents.index'))->assertForbidden();

    expect($log->getRecords())->toHaveCount(1);
    $record = $log->getRecords()[0];
    expect($record->message)->toBe('access.denied')
        ->and($record->context['route'])->toBe('admin.agents.index')
        ->and($record->context['user_id'])->toBe($agent->id);
});

test('policy checks behind resource can-flags are not logged as denials', function () {
    $log = captureLog('security');
    $agent = securityLogUser(RoleEnum::Agent);
    // An agent may not assign/update these, so their `can` flags evaluate to false.
    Ticket::factory()->count(3)->create(['status' => TicketStatus::Open->value, 'assigned_agent_id' => null]);

    $this->actingAs($agent)->get(route('tickets.index'))->assertOk();

    expect($log->getRecords())->toBeEmpty();
});

test('a failed login logs a hashed email and never the password', function () {
    $log = captureLog('security');
    securityLogUser(RoleEnum::Customer)->update(['email' => 'victim@example.com']);

    $this->post(route('login.store'), ['email' => 'victim@example.com', 'password' => 'Wr0ng-Secret-Pass!']);

    $output = json_encode(array_map(fn ($r) => $r->toArray(), $log->getRecords()));
    expect($log->hasWarningThatContains('auth.login_failed'))->toBeTrue()
        ->and($output)->not->toContain('Wr0ng-Secret-Pass!')
        ->and($output)->not->toContain('victim@example.com')
        ->and($output)->toContain(LogRedactor::email('victim@example.com'));
});

test('a login lockout (429) is logged with a hashed email', function () {
    $log = captureLog('security');

    foreach (range(1, 6) as $_) {
        $last = $this->post(route('login.store'), ['email' => 'someone@example.com', 'password' => 'nope-nope-nope']);
    }

    $last->assertStatus(429);
    $lockout = collect($log->getRecords())->firstWhere('message', 'auth.lockout');
    expect($lockout)->not->toBeNull()
        ->and($lockout->context['email_hash'])->toBe(LogRedactor::email('someone@example.com'));
});

test('ticket bodies never reach any log channel', function () {
    $logs = collect(['app', 'security', 'sla', 'performance', 'queue', config('logging.default')])
        ->mapWithKeys(fn (string $c) => [$c => captureLog($c)]);
    config(['support.monitoring.slow_query_ms' => 0]); // log every query too
    Queue::fake(); // keep auto-assign from taking the ticket before the claims
    $customer = securityLogUser(RoleEnum::Customer);
    $agent = securityLogUser(RoleEnum::Agent);
    $other = securityLogUser(RoleEnum::Agent);

    $this->actingAs($customer)->post(route('tickets.store'), [
        'subject' => 'Printer broken',
        'description' => 'BODY-MARKER-7f3a my card number is 4111',
        'priority' => 'low',
    ]);
    $ticket = Ticket::query()->latest('id')->firstOrFail();
    $this->actingAs($agent)->post(route('tickets.claim', $ticket->ticket_number));
    // The losing side of the race: it loaded the ticket while it was still
    // unassigned (over HTTP the policy would now 403 before the service runs).
    expect(fn () => app(TicketAssignmentService::class)->claim($ticket, $other))
        ->toThrow(TicketAlreadyAssignedException::class);

    $output = $logs->map(fn ($h) => json_encode(array_map(fn ($r) => $r->toArray(), $h->getRecords())))->implode('');
    expect($output)->toContain('ticket.claim_conflict')
        ->and($output)->not->toContain('BODY-MARKER-7f3a')
        ->and($output)->not->toContain('correct-password-1');
});

test('slow queries are logged with placeholder SQL and without bindings', function () {
    $log = captureLog('performance');
    config(['support.monitoring.slow_query_ms' => 0]);

    DB::select('select ? as secret_value', ['top-secret-binding']);

    $record = collect($log->getRecords())->first(fn ($r) => str_contains($r->context['sql'], 'secret_value'));
    expect($record)->not->toBeNull()
        ->and($record->message)->toBe('query.slow')
        ->and(json_encode($record->toArray()))->not->toContain('top-secret-binding');
});

test('fast queries are not logged', function () {
    $log = captureLog('performance');
    config(['support.monitoring.slow_query_ms' => 60_000]);

    DB::select('select 1');

    expect($log->getRecords())->toBeEmpty();
});

test('query exception messages are redacted to placeholder SQL', function () {
    $e = new QueryException('mysql', 'insert into ticket_replies (body) values (?)', ['BODY-MARKER-9'], new Exception('Data too long'));

    expect(LogRedactor::exceptionMessage($e))
        ->toContain('insert into ticket_replies (body) values (?)')
        ->not->toContain('BODY-MARKER-9');
});
