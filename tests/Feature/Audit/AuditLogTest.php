<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Models\AgentProfile;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SlaService;
use App\Support\Logging\LogRedactor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function auditUser(RoleEnum $role): User
{
    $user = User::factory()->create(['password' => 'correct-password-1']);
    $user->assignRole($role->value);

    if ($role === RoleEnum::Agent) {
        AgentProfile::factory()->create(['user_id' => $user->id]);
    }

    return $user;
}

function auditRows(AuditEvent $event)
{
    return Activity::query()->where('event', $event->value)->get();
}

// --- auth log ---------------------------------------------------------------

test('a login writes exactly one auth row with causer, ip and user agent', function () {
    $user = auditUser(RoleEnum::Customer);

    $this->withHeader('User-Agent', 'PestBrowser/1.0')
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-password-1'])
        ->assertRedirect();

    $rows = auditRows(AuditEvent::Login);
    expect($rows)->toHaveCount(1);
    expect($rows[0]->log_name)->toBe('auth')
        ->and($rows[0]->causer_id)->toBe($user->id)
        ->and($rows[0]->properties['ip'])->toBe('127.0.0.1')
        ->and($rows[0]->properties['user_agent'])->toBe('PestBrowser/1.0')
        ->and($rows[0]->properties['remember'])->toBeFalse();
});

test('a logout writes one auth row for that user', function () {
    $user = auditUser(RoleEnum::Customer);

    $this->actingAs($user)->post(route('logout'));

    expect(auditRows(AuditEvent::Logout))->toHaveCount(1)
        ->and(auditRows(AuditEvent::Logout)[0]->causer_id)->toBe($user->id);
});

test('a failed login has no causer and stores the email only as a keyed hash', function () {
    $user = auditUser(RoleEnum::Customer);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $row = auditRows(AuditEvent::LoginFailed)->sole();
    $raw = json_encode(DB::table('activity_log')->where('id', $row->id)->first());

    expect($row->causer_id)->toBeNull()
        ->and($row->subject_id)->toBe($user->id)
        ->and($row->properties['email_hash'])->toBe(LogRedactor::email($user->email))
        ->and($raw)->not->toContain($user->email)
        ->and($raw)->not->toContain('wrong-password');
});

test('a lockout is recorded without the email in plain text', function () {
    $request = Request::create('/login', 'POST', ['email' => 'someone@example.com']);

    event(new Lockout($request));

    $row = auditRows(AuditEvent::Lockout)->sole();
    expect($row->properties['email_hash'])->toBe(LogRedactor::email('someone@example.com'))
        ->and(json_encode($row->properties))->not->toContain('someone@example.com');
});

test('two-factor is logged when confirmed or disabled, not when setup merely starts', function () {
    $user = auditUser(RoleEnum::Admin);

    TwoFactorAuthenticationEnabled::dispatch($user);
    expect(auditRows(AuditEvent::TwoFactorEnabled))->toBeEmpty();

    TwoFactorAuthenticationConfirmed::dispatch($user);
    TwoFactorAuthenticationDisabled::dispatch($user);

    expect(auditRows(AuditEvent::TwoFactorEnabled))->toHaveCount(1)
        ->and(auditRows(AuditEvent::TwoFactorDisabled))->toHaveCount(1);
});

test('changing the password is recorded, without the password', function () {
    $user = auditUser(RoleEnum::Customer);

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'correct-password-1',
        'password' => 'brand-new-password-9',
        'password_confirmation' => 'brand-new-password-9',
    ])->assertSessionHasNoErrors();

    $row = auditRows(AuditEvent::PasswordChanged)->sole();
    expect($row->causer_id)->toBe($user->id)
        ->and(json_encode($row->properties))->not->toContain('brand-new-password-9');
});

test('properties outside the allow-list are dropped', function () {
    $row = app(AuditLogService::class)->record(AuditEvent::Login, null, properties: [
        'ip' => '10.0.0.1',
        'password' => 'leaked',
        'token' => 'abc',
    ]);

    expect($row->properties->keys()->all())->toBe(['ip']);
});

// --- admin log --------------------------------------------------------------

test('an SLA policy edit logs only the changed fields, old and new', function () {
    $admin = auditUser(RoleEnum::Admin);
    foreach (TicketPriority::cases() as $priority) {
        SlaPolicy::factory()->create(['priority' => $priority->value, 'resolution_minutes' => 120, 'warning_threshold_percent' => 80, 'is_active' => true]);
    }

    $this->actingAs($admin)->put(route('admin.sla-policies.update'), [
        'policies' => collect(TicketPriority::cases())->map(fn (TicketPriority $p) => [
            'priority' => $p->value,
            'resolution_minutes' => $p === TicketPriority::Critical ? 180 : 120,
            'warning_threshold_percent' => 80,
            'is_active' => true,
        ])->all(),
    ])->assertRedirect();

    $row = auditRows(AuditEvent::SlaPoliciesUpdated)->sole();
    expect($row->log_name)->toBe('admin')
        ->and($row->causer_id)->toBe($admin->id)
        // toEqual, not toBe: MySQL's JSON type re-orders object keys.
        ->and($row->properties['changes'])->toEqual([
            'critical' => ['old' => ['resolution_minutes' => 120], 'new' => ['resolution_minutes' => 180]],
        ]);
});

test('saving SLA policies without changes writes no audit row', function () {
    $admin = auditUser(RoleEnum::Admin);
    SlaPolicy::factory()->create(['priority' => 'critical', 'resolution_minutes' => 120, 'warning_threshold_percent' => 80, 'is_active' => true]);

    app(SlaService::class)->updatePolicies(['critical' => ['resolution_minutes' => 120, 'warning_threshold_percent' => 80, 'is_active' => true]], $admin);

    expect(auditRows(AuditEvent::SlaPoliciesUpdated))->toBeEmpty();
});

test('the audit row rolls back together with the change it describes', function () {
    $admin = auditUser(RoleEnum::Admin);
    SlaPolicy::factory()->create(['priority' => 'critical', 'resolution_minutes' => 120, 'warning_threshold_percent' => 80, 'is_active' => true]);

    try {
        DB::transaction(function () use ($admin) {
            app(SlaService::class)->updatePolicies(['critical' => ['resolution_minutes' => 999, 'warning_threshold_percent' => 80, 'is_active' => true]], $admin);

            throw new RuntimeException('later step failed');
        });
    } catch (RuntimeException) {
    }

    expect(SlaPolicy::query()->where('priority', 'critical')->value('resolution_minutes'))->toBe(120)
        ->and(Activity::query()->count())->toBe(0);
});

test('business-hours and holiday changes are recorded', function () {
    $admin = auditUser(RoleEnum::Admin);
    foreach (range(0, 6) as $day) {
        BusinessHour::factory()->create(['day_of_week' => $day, 'is_working_day' => true, 'start_time' => '09:00:00', 'end_time' => '18:00:00']);
    }
    $sla = app(SlaService::class);

    $sla->updateBusinessHours([5 => ['is_working_day' => false, 'start_time' => null, 'end_time' => null]], $admin);
    $this->actingAs($admin)->post(route('admin.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day']);
    $this->actingAs($admin)->delete(route('admin.holidays.destroy', Holiday::query()->sole()));

    expect(auditRows(AuditEvent::BusinessHoursUpdated)->sole()->properties['changes'][5]['new'])
        ->toEqual(['is_working_day' => false, 'start_time' => null, 'end_time' => null])
        ->and(auditRows(AuditEvent::HolidayAdded)->sole()->properties['holiday'])->toBe(['date' => '2026-12-16', 'name' => 'Victory Day'])
        ->and(auditRows(AuditEvent::HolidayRemoved)->sole()->properties['holiday']['name'])->toBe('Victory Day');
});

// --- scope: ticket actions stay in ticket_activities --------------------------

test('ticket create, claim, reply and internal note write nothing to activity_log', function () {
    Queue::fake();
    $customer = auditUser(RoleEnum::Customer);
    $agent = auditUser(RoleEnum::Agent);

    $this->actingAs($customer)->post(route('tickets.store'), [
        'subject' => 'Printer broken',
        'description' => 'NOTE-MARKER never in the audit log',
        'priority' => 'low',
    ]);
    $ticket = Ticket::query()->latest('id')->firstOrFail();
    $this->actingAs($agent)->post(route('tickets.claim', $ticket->ticket_number));
    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'On it.']);
    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Secret note', 'type' => 'internal_note']);

    expect(Activity::query()->count())->toBe(0)
        ->and(DB::table('ticket_activities')->where('ticket_id', $ticket->id)->count())->toBeGreaterThan(0);
});

test('the Tickets log reads ticket_activities: admin sees every ticket action, linked to its ticket', function () {
    Queue::fake();
    $admin = auditUser(RoleEnum::Admin);
    $customer = auditUser(RoleEnum::Customer);
    $agent = auditUser(RoleEnum::Agent);

    $this->actingAs($customer)->post(route('tickets.store'), ['subject' => 'Printer broken', 'description' => 'It jams.', 'priority' => 'low']);
    $ticket = Ticket::query()->latest('id')->firstOrFail();
    $this->actingAs($agent)->post(route('tickets.claim', $ticket->ticket_number));
    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'NOTE-BODY-MARKER', 'type' => 'internal_note']);

    $response = $this->actingAs($admin)->get(route('admin.audit-log', ['log' => 'tickets']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.log', 'tickets')
            ->where('entries.data.0.ticket.number', $ticket->ticket_number)
            ->where('entries.data', fn ($rows) => collect($rows)->pluck('event')->contains('note.added')
                && collect($rows)->pluck('event')->contains('ticket.created')));

    // Only that a note was added — never what it said.
    expect($response->getContent())->not->toContain('NOTE-BODY-MARKER');

    $this->actingAs($admin)->get(route('admin.audit-log', ['event' => 'ticket.created']))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.label', 'Ticket created'));
});

test('"All logs" merges sign-ins, admin changes and ticket actions newest first, across pages', function () {
    $admin = auditUser(RoleEnum::Admin);
    $ticket = Ticket::factory()->create();
    $audit = app(AuditLogService::class);
    $base = now()->subHour()->startOfMinute();

    // 30 rows alternating between the two tables, one minute apart.
    foreach (range(0, 29) as $i) {
        $this->travelTo($base->copy()->addMinutes($i));
        $i % 2 === 0
            ? $audit->record(AuditEvent::Login, $admin, $admin, ['ip' => "10.0.0.{$i}"])
            : DB::table('ticket_activities')->insert(['ticket_id' => $ticket->id, 'actor_id' => $admin->id, 'event' => 'status.changed', 'old_values' => json_encode(['status' => 'open']), 'new_values' => json_encode(['status' => 'in_progress']), 'created_at' => now()]);
    }
    $this->travelBack();

    $page1 = $this->actingAs($admin)->get(route('admin.audit-log'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 25)
            ->where('entries.meta.total', 30)
            ->where('entries.data.0.log', 'tickets')        // minute 29
            ->where('entries.data.0.label', 'Status changed from Open to In progress')
            ->where('entries.data.1.log', 'auth')           // minute 28
            ->where('entries.data.1.ip', '10.0.0.28'));

    $this->actingAs($admin)->get(route('admin.audit-log', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 5)
            ->where('entries.data.4.ip', '10.0.0.0'));     // the oldest row comes last
});

test('"All logs" query count does not grow with the number of rows', function () {
    $admin = auditUser(RoleEnum::Admin);
    $audit = app(AuditLogService::class);
    $countQueries = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.audit-log'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $log = fn (Ticket $ticket) => DB::table('ticket_activities')->insert(['ticket_id' => $ticket->id, 'actor_id' => $admin->id, 'event' => 'ticket.created', 'created_at' => now()]);

    $audit->record(AuditEvent::Login, $admin, $admin);
    $log(Ticket::factory()->create());
    $countQueries();
    $few = $countQueries();

    Ticket::factory()->count(15)->create()->each($log);
    foreach (User::factory()->count(15)->create() as $user) {
        $audit->record(AuditEvent::Login, $user, $user);
    }
    $many = $countQueries();

    expect($many)->toBe($few);
});

test('the Tickets log query count does not grow with the number of rows', function () {
    $admin = auditUser(RoleEnum::Admin);
    $countQueries = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.audit-log', ['log' => 'tickets']))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $log = fn (Ticket $ticket) => DB::table('ticket_activities')->insert(['ticket_id' => $ticket->id, 'actor_id' => $admin->id, 'event' => 'ticket.created', 'created_at' => now()]);

    $log(Ticket::factory()->create());
    $countQueries();
    $few = $countQueries();

    Ticket::factory()->count(15)->create()->each($log);
    $many = $countQueries();

    expect($many)->toBe($few);
});

// --- page ---------------------------------------------------------------------

test('agents and customers cannot open the audit log', function (RoleEnum $role) {
    $this->actingAs(auditUser($role))->get(route('admin.audit-log'))->assertForbidden();
})->with([RoleEnum::Agent, RoleEnum::Customer]);

test('an admin sees entries newest first, filterable by log and event', function () {
    $admin = auditUser(RoleEnum::Admin);
    $audit = app(AuditLogService::class);
    $this->travelTo(now()->subMinutes(2));
    $audit->record(AuditEvent::Login, $admin, $admin, ['ip' => '10.0.0.1']);
    $this->travelBack();
    $audit->record(AuditEvent::HolidayAdded, $admin, properties: ['holiday' => ['date' => '2026-12-16', 'name' => 'Victory Day']]);

    $this->actingAs($admin)->get(route('admin.audit-log'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/audit-log/index')
            ->where('entries.data.0.event', AuditEvent::HolidayAdded->value)
            ->where('entries.data.1.event', AuditEvent::Login->value)
            ->where('entries.data.1.causer.name', $admin->name)
            ->missing('entries.data.1.email_hash'));

    $this->actingAs($admin)->get(route('admin.audit-log', ['log' => 'auth']))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.log', 'auth'));

    $this->actingAs($admin)->get(route('admin.audit-log', ['event' => AuditEvent::HolidayAdded->value]))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.holiday.name', 'Victory Day'));
});

test('the date filter uses business-timezone days', function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $admin = auditUser(RoleEnum::Admin);
    // 2026-10-18 20:00 UTC is already 2026-10-19 02:00 in Dhaka.
    $this->travelTo('2026-10-18 20:00:00');
    app(AuditLogService::class)->record(AuditEvent::Login, $admin, $admin);
    $this->travelBack();

    $this->actingAs($admin)->get(route('admin.audit-log', ['from' => '2026-10-19', 'to' => '2026-10-19']))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    $this->actingAs($admin)->get(route('admin.audit-log', ['from' => '2026-10-18', 'to' => '2026-10-18']))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
});

test('an unknown event filter is a validation error', function () {
    $this->actingAs(auditUser(RoleEnum::Admin))
        ->get(route('admin.audit-log', ['event' => 'not.an_event']))
        ->assertSessionHasErrors('event');
});

test('the page query count does not grow with the number of rows', function () {
    $admin = auditUser(RoleEnum::Admin);
    $audit = app(AuditLogService::class);
    $countQueries = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.audit-log'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $audit->record(AuditEvent::Login, $admin, $admin);
    $countQueries(); // warm-up: the first request also loads the permission cache
    $few = $countQueries();

    foreach (User::factory()->count(20)->create() as $user) {
        $audit->record(AuditEvent::Login, $user, $user);
    }
    $many = $countQueries();

    expect($many)->toBe($few);
});

test('the page opens on the last 30 days; older rows show when earlier dates are picked', function () {
    $admin = auditUser(RoleEnum::Admin);
    $audit = app(AuditLogService::class);
    $this->travelTo(now()->subDays(31));
    $audit->record(AuditEvent::Login, $admin, $admin, ['ip' => '10.0.0.31']);
    $this->travelBack();
    $audit->record(AuditEvent::Login, $admin, $admin, ['ip' => '10.0.0.1']);

    $today = now(config('support.sla.timezone'))->toDateString();

    $this->actingAs($admin)->get(route('admin.audit-log'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.ip', '10.0.0.1')
            ->where('filters.from', now(config('support.sla.timezone'))->subDays(29)->toDateString())
            ->where('filters.to', $today)
            ->where('retention.auth_days', 180));

    $this->actingAs($admin)->get(route('admin.audit-log', ['from' => now()->subDays(60)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 2));
});

test('retention: sign-ins go after 180 days, admin changes after 3 years, ticket history never', function () {
    $admin = auditUser(RoleEnum::Admin);
    $ticket = Ticket::factory()->create();
    $audit = app(AuditLogService::class);
    $at = function (int $daysAgo, Closure $write) {
        $this->travelTo(now()->subDays($daysAgo));
        $write();
        $this->travelBack();
    };

    $at(181, fn () => $audit->record(AuditEvent::Login, $admin, $admin, ['ip' => 'old-login']));
    $at(179, fn () => $audit->record(AuditEvent::Login, $admin, $admin, ['ip' => 'recent-login']));
    $at(400, fn () => $audit->record(AuditEvent::HolidayAdded, $admin, properties: ['holiday' => ['date' => '2025-01-01', 'name' => 'Kept']]));
    $at(1100, fn () => $audit->record(AuditEvent::HolidayAdded, $admin, properties: ['holiday' => ['date' => '2023-01-01', 'name' => 'Gone']]));
    DB::table('ticket_activities')->insert(['ticket_id' => $ticket->id, 'event' => 'ticket.created', 'created_at' => now()->subYears(5)]);

    // Exactly what the scheduler runs.
    collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'activitylog:clean'))
        ->each(fn ($e) => $this->artisan((string) str($e->command)->after('artisan')->replace("'", '')->trim())->assertSuccessful());

    expect(Activity::query()->where('log_name', 'auth')->get()->pluck('properties.ip')->all())->toBe(['recent-login'])
        ->and(Activity::query()->where('log_name', 'admin')->get()->pluck('properties.holiday.name')->all())->toBe(['Kept'])
        ->and(DB::table('ticket_activities')->count())->toBe(1);
});

test('activitylog:clean is scheduled daily, once per log with its own retention', function () {
    $commands = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'activitylog:clean'))
        ->mapWithKeys(fn ($e) => [(string) str($e->command)->after('activitylog:clean')->replace("'", '')->trim() => $e->expression]);

    expect($commands->all())->toBe([
        'auth --days=180 --force' => '0 0 * * *',
        'admin --days=1095 --force' => '0 0 * * *',
    ]);
});
