<?php

declare(strict_types=1);

use App\Contracts\Repositories\AgentRepositoryInterface;
use App\Enums\AuditEvent;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ReplyReceived;
use App\Notifications\SlaBreached;
use App\Services\TicketAssignmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function roleUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

/**
 * @param  list<PermissionEnum>  $permissions
 */
function customRole(string $name, array $permissions): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    $role->syncPermissions(array_map(fn (PermissionEnum $p) => $p->value, $permissions));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role;
}

// --- authorization ------------------------------------------------------------

test('only users with roles.manage can open or change roles', function (RoleEnum $role) {
    $user = roleUser($role->value);
    $agentRole = Role::findByName(RoleEnum::Agent->value);

    $this->actingAs($user)->get(route('admin.roles.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.roles.store'), ['name' => 'x', 'permissions' => []])->assertForbidden();
    $this->actingAs($user)->put(route('admin.roles.update', $agentRole), ['name' => 'agent', 'permissions' => []])->assertForbidden();

    expect(Role::query()->where('name', 'x')->exists())->toBeFalse();
})->with([RoleEnum::Agent, RoleEnum::Customer]);

test('the roles list shows each role with its user count', function () {
    $admin = roleUser(RoleEnum::Admin->value);
    roleUser(RoleEnum::Agent->value);
    roleUser(RoleEnum::Agent->value);

    $this->actingAs($admin)->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/index')
            ->has('roles.data', 3)
            ->where('roles.data.1.name', 'agent')
            ->where('roles.data.1.users_count', 2)
            ->where('roles.data.1.is_system', true)
            ->where('roles.data.0.is_locked', true));
});

// --- create / update / delete -----------------------------------------------------

test('an admin can create a role with chosen permissions, and it is audited', function () {
    $admin = roleUser(RoleEnum::Admin->value);

    $this->actingAs($admin)->post(route('admin.roles.store'), [
        'name' => 'supervisor',
        'permissions' => ['dashboard.view-global', 'reports.view'],
    ])->assertRedirect(route('admin.roles.index'));

    $role = Role::findByName('supervisor');
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['dashboard.view-global', 'reports.view']);

    $changes = auditChanges(AuditEvent::RoleCreated);
    expect($changes['role']['new']['name'])->toBe('supervisor')
        ->and($changes['permissions']['new'])->toEqual(['dashboard.view-global' => true, 'reports.view' => true]);
});

test('role names must be lowercase slugs, unique, and permissions must exist', function () {
    $admin = roleUser(RoleEnum::Admin->value);

    $this->actingAs($admin)->post(route('admin.roles.store'), ['name' => 'Senior Agent', 'permissions' => ['made.up']])
        ->assertSessionHasErrors(['name', 'permissions.0']);

    $this->actingAs($admin)->post(route('admin.roles.store'), ['name' => 'agent', 'permissions' => []])
        ->assertSessionHasErrors('name');
});

test('editing a role audits only the permissions that changed', function () {
    $admin = roleUser(RoleEnum::Admin->value);
    customRole('supervisor', [PermissionEnum::DashboardViewGlobal, PermissionEnum::ReportsView]);

    $this->actingAs($admin)->put(route('admin.roles.update', Role::findByName('supervisor')), [
        'name' => 'team-lead',
        'permissions' => ['dashboard.view-global', 'reports.export'],
    ])->assertSessionHasNoErrors();

    expect(Role::query()->where('name', 'team-lead')->exists())->toBeTrue();

    $changes = auditChanges(AuditEvent::RoleUpdated);
    expect($changes['role'])->toEqual(['old' => ['name' => 'supervisor'], 'new' => ['name' => 'team-lead']])
        ->and($changes['permissions']['old'])->toEqual(['reports.export' => false, 'reports.view' => true])
        ->and($changes['permissions']['new'])->toEqual(['reports.export' => true, 'reports.view' => false]);
});

test('the admin role cannot be edited and built-in roles cannot be renamed or deleted', function () {
    $admin = roleUser(RoleEnum::Admin->value);
    $adminRole = Role::findByName(RoleEnum::Admin->value);
    $agentRole = Role::findByName(RoleEnum::Agent->value);

    $this->actingAs($admin)->put(route('admin.roles.update', $adminRole), ['name' => 'admin', 'permissions' => []])
        ->assertSessionHasErrors('permissions');
    $this->actingAs($admin)->put(route('admin.roles.update', $agentRole), ['name' => 'helper', 'permissions' => []])
        ->assertSessionHasErrors('name');
    $this->actingAs($admin)->delete(route('admin.roles.destroy', $agentRole))->assertSessionHasErrors('role');

    expect($adminRole->fresh()->permissions()->count())->toBe(count(PermissionEnum::cases()))
        ->and(Role::query()->where('name', 'agent')->exists())->toBeTrue();
});

test('a role can be deleted only when nobody has it', function () {
    $admin = roleUser(RoleEnum::Admin->value);
    $role = customRole('supervisor', [PermissionEnum::ReportsView]);
    $holder = roleUser('supervisor');

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))->assertSessionHasErrors('role');
    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();

    $holder->syncRoles([RoleEnum::Customer->value]);

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));
    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and(auditChanges(AuditEvent::RoleDeleted)['role']['old']['name'])->toBe('supervisor');
});

test('taking users.manage off a role is refused when no other active user would keep it', function () {
    // Nobody holds the admin role; the only user managers are "people-ops".
    $role = customRole('people-ops', [PermissionEnum::UsersManage, PermissionEnum::RolesManage]);
    $manager = roleUser('people-ops');

    $this->actingAs($manager)->put(route('admin.roles.update', $role), [
        'name' => 'people-ops',
        'permissions' => ['roles.manage'],
    ])->assertSessionHasErrors('permissions');

    expect($role->fresh()->hasPermissionTo('users.manage'))->toBeTrue();

    roleUser(RoleEnum::Admin->value); // now someone else holds it

    $this->actingAs($manager)->put(route('admin.roles.update', $role), [
        'name' => 'people-ops',
        'permissions' => ['roles.manage'],
    ])->assertSessionHasNoErrors();
});

// --- custom roles actually work (no role-name checks left) ----------------------

test('a custom role works the admin pages its permissions allow, and only those', function () {
    customRole('sla-editor', [PermissionEnum::SlaManage]);
    $user = roleUser('sla-editor');

    $this->actingAs($user)->get(route('admin.sla-policies.index'))->assertOk();
    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.business-hours.index'))->assertForbidden();
});

test('a custom agent role is assignable and picked by auto-assign; removing tickets.claim takes agents out', function () {
    $admin = roleUser(RoleEnum::Admin->value);
    customRole('senior-agent', [PermissionEnum::TicketsViewQueue, PermissionEnum::TicketsClaim]);
    $senior = roleUser('senior-agent');
    AgentProfile::factory()->create(['user_id' => $senior->id, 'is_available' => true, 'max_active_tickets' => 5]);
    $agent = roleUser(RoleEnum::Agent->value);
    AgentProfile::factory()->create(['user_id' => $agent->id, 'is_available' => true, 'max_active_tickets' => 5]);

    $candidates = fn () => app(AgentRepositoryInterface::class)->candidatesFor(null)->pluck('user_id')->all();

    expect($candidates())->toContain($senior->id, $agent->id)
        ->and(app(TicketAssignmentService::class)->agentOptions()->pluck('id')->all())->toContain($senior->id);

    $agentRole = Role::findByName(RoleEnum::Agent->value);
    $this->actingAs($admin)->put(route('admin.roles.update', $agentRole), [
        'name' => 'agent',
        'permissions' => array_values(array_diff($agentRole->permissions->pluck('name')->all(), ['tickets.claim'])),
    ])->assertSessionHasNoErrors();

    expect($candidates())->toContain($senior->id)->not->toContain($agent->id)
        ->and(app(TicketAssignmentService::class)->agentOptions()->pluck('id')->all())->not->toContain($agent->id);
});

test('escalation emails go to whoever has the global dashboard, not to the admin role name', function () {
    Notification::fake();
    customRole('supervisor', [PermissionEnum::DashboardViewGlobal]);
    $supervisor = roleUser('supervisor');
    $inactiveAdmin = roleUser(RoleEnum::Admin->value, ['is_active' => false]);
    Ticket::factory()->create(['sla_warning_at' => now()->subHour(), 'sla_due_at' => now()->subMinute()]);

    $this->artisan('sla:scan');

    Notification::assertSentTo($supervisor, SlaBreached::class);
    Notification::assertNotSentTo($inactiveAdmin, SlaBreached::class);
});

test('an internal note never notifies a global-dashboard user who lacks notes.view', function () {
    Notification::fake();
    customRole('supervisor', [PermissionEnum::DashboardViewGlobal]);
    $supervisor = roleUser('supervisor');
    $admin = roleUser(RoleEnum::Admin->value);
    $agent = roleUser(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), [
        'body' => 'Internal only.',
        'type' => 'internal_note',
    ])->assertRedirect();

    Notification::assertSentTo($admin, ReplyReceived::class);
    Notification::assertNotSentTo($supervisor, ReplyReceived::class);
});

test('a custom customer-like role never sees internal notes', function () {
    customRole('vip-customer', [PermissionEnum::TicketsViewOwn, PermissionEnum::TicketsCreate, PermissionEnum::RepliesCreate]);
    $vip = roleUser('vip-customer');
    $agent = roleUser(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['created_by' => $vip->id, 'assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), [
        'body' => 'Internal only.',
        'type' => 'internal_note',
    ])->assertRedirect();

    $this->actingAs($vip)->get(route('tickets.show', $ticket->ticket_number))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('replies.data', 0));
});

test('re-running the seeder keeps permission edits made in the roles editor', function () {
    Role::findByName(RoleEnum::Agent->value)->revokePermissionTo(PermissionEnum::NotesView->value);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName(RoleEnum::Agent->value)->hasPermissionTo(PermissionEnum::NotesView->value))->toBeFalse()
        ->and(Role::findByName(RoleEnum::Admin->value)->permissions()->count())->toBe(count(PermissionEnum::cases()));
});

/**
 * @return array<string, mixed>
 */
function auditChanges(AuditEvent $event): array
{
    return Activity::query()->where('event', $event->value)->sole()->properties['changes'];
}
