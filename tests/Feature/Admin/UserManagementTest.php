<?php

declare(strict_types=1);

use App\Contracts\Repositories\AgentRepositoryInterface;
use App\DTOs\AgentProfileData;
use App\DTOs\UserData;
use App\Enums\AuditEvent;
use App\Enums\Role as RoleEnum;
use App\Exceptions\UserLockoutException;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\User;
use App\Services\UserManagementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function userAdminWithRole(RoleEnum $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role->value);

    if ($role === RoleEnum::Agent) {
        AgentProfile::factory()->create(['user_id' => $user->id, 'is_available' => true, 'max_active_tickets' => 10]);
    }

    return $user;
}

/**
 * @return array<string, mixed>
 */
function userFormPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'New Person',
        'email' => 'new.person@example.com',
        'role' => RoleEnum::Agent->value,
        'is_active' => true,
        'agent' => ['is_available' => true, 'max_active_tickets' => 7, 'category_ids' => []],
    ], $overrides);
}

// --- authorization ------------------------------------------------------------

test('agents and customers cannot open or use the users pages', function (RoleEnum $role) {
    $user = userAdminWithRole($role);
    $other = userAdminWithRole(RoleEnum::Customer);

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.create'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.users.store'), userFormPayload())->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.edit', $other))->assertForbidden();
    $this->actingAs($user)->put(route('admin.users.update', $other), userFormPayload())->assertForbidden();

    expect(User::query()->where('email', 'new.person@example.com')->exists())->toBeFalse();
})->with([RoleEnum::Agent, RoleEnum::Customer]);

// --- list -----------------------------------------------------------------------

test('an admin can list users, filtered by role and by name or email', function () {
    $admin = userAdminWithRole(RoleEnum::Admin);
    $agent = userAdminWithRole(RoleEnum::Agent, ['name' => 'Rahim Agent']);
    userAdminWithRole(RoleEnum::Customer, ['name' => 'Karim Customer', 'email' => 'karim@example.com']);

    $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'agent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->has('users.data', 1)
            ->where('users.data.0.id', $agent->id)
            ->where('users.data.0.agent.max_active_tickets', 10));

    $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'karim@']))
        ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.name', 'Karim Customer'));
});

test('the list query count does not grow with the number of users', function () {
    $admin = userAdminWithRole(RoleEnum::Admin);

    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        return count(DB::getQueryLog());
    };

    userAdminWithRole(RoleEnum::Agent);
    $count(); // warms spatie's permission cache
    $few = $count();

    foreach (range(1, 10) as $i) {
        userAdminWithRole($i % 2 === 0 ? RoleEnum::Agent : RoleEnum::Customer);
    }

    expect($count())->toBe($few);
});

// --- create ---------------------------------------------------------------------

test('creating an agent saves the role, agent profile and skills, emails a set-password link and audits it', function () {
    Notification::fake();
    $admin = userAdminWithRole(RoleEnum::Admin);
    [$billing, $tech] = Category::factory()->count(2)->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), userFormPayload(['agent' => ['category_ids' => [$billing->id, $tech->id]]]))
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();

    expect($user->hasRole(RoleEnum::Agent->value))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->agentProfile->max_active_tickets)->toBe(7)
        ->and($user->agentProfile->is_available)->toBeTrue()
        ->and($user->categories()->pluck('categories.id')->sort()->values()->all())->toBe([$billing->id, $tech->id]);

    Notification::assertSentTo($user, ResetPassword::class);

    $row = Activity::query()->where('event', AuditEvent::UserCreated->value)->sole();
    expect($row->causer_id)->toBe($admin->id)
        ->and($row->subject_id)->toBe($user->id)
        ->and($row->properties['changes']['account']['new']['role'])->toBe('agent')
        ->and($row->properties['changes']['agent']['new']['max_active_tickets'])->toBe(7);
});

test('creating a customer ignores the agent fields', function () {
    Notification::fake();
    $admin = userAdminWithRole(RoleEnum::Admin);

    $this->actingAs($admin)->post(route('admin.users.store'), userFormPayload(['role' => RoleEnum::Customer->value]))
        ->assertRedirect();

    $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();
    expect($user->hasRole(RoleEnum::Customer->value))->toBeTrue()
        ->and($user->agentProfile()->exists())->toBeFalse();
});

test('an account created inactive gets no invite email', function () {
    Notification::fake();
    $admin = userAdminWithRole(RoleEnum::Admin);

    $this->actingAs($admin)->post(route('admin.users.store'), userFormPayload(['is_active' => false]))->assertRedirect();

    Notification::assertNothingSent();
});

test('create validates email uniqueness, role and agent capacity', function () {
    $admin = userAdminWithRole(RoleEnum::Admin);
    userAdminWithRole(RoleEnum::Customer, ['email' => 'taken@example.com']);

    $this->actingAs($admin)->post(route('admin.users.store'), userFormPayload([
        'email' => 'taken@example.com',
        'role' => 'no-such-role',
        'agent' => ['max_active_tickets' => 0],
    ]))->assertSessionHasErrors(['email', 'role', 'agent.max_active_tickets']);
});

// --- update ---------------------------------------------------------------------

test('changing an agent to a customer takes them out of auto-assign and audits only what changed', function () {
    $admin = userAdminWithRole(RoleEnum::Admin);
    $agent = userAdminWithRole(RoleEnum::Agent, ['name' => 'Same Name', 'email' => 'same@example.com']);

    $this->actingAs($admin)->put(route('admin.users.update', $agent), userFormPayload([
        'name' => 'Same Name',
        'email' => 'same@example.com',
        'role' => RoleEnum::Customer->value,
    ]))->assertRedirect(route('admin.users.index'));

    $agent->refresh();
    expect($agent->hasRole(RoleEnum::Customer->value))->toBeTrue()
        ->and($agent->hasRole(RoleEnum::Agent->value))->toBeFalse()
        ->and($agent->agentProfile->is_available)->toBeFalse()
        ->and(app(AgentRepositoryInterface::class)->candidatesFor(null)->pluck('user_id'))->not->toContain($agent->id);

    $changes = Activity::query()->where('event', AuditEvent::UserUpdated->value)->sole()->properties['changes'];
    expect($changes['account'])->toEqual(['old' => ['role' => 'agent'], 'new' => ['role' => 'customer']])
        ->and($changes['agent'])->toEqual(['old' => ['is_available' => true], 'new' => ['is_available' => false]]);
});

test('saving a user without changes writes no audit row', function () {
    $admin = userAdminWithRole(RoleEnum::Admin);
    $agent = userAdminWithRole(RoleEnum::Agent, ['email' => 'same@example.com']);

    $this->actingAs($admin)->put(route('admin.users.update', $agent), userFormPayload([
        'name' => $agent->name,
        'email' => 'same@example.com',
        'agent' => ['max_active_tickets' => 10],
    ]))->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', AuditEvent::UserUpdated->value)->count())->toBe(0);
});

test('an admin cannot deactivate themselves or change their own role', function () {
    $admin = userAdminWithRole(RoleEnum::Admin);
    userAdminWithRole(RoleEnum::Admin); // a second admin, so only the self rule applies

    $own = ['name' => $admin->name, 'email' => $admin->email, 'role' => RoleEnum::Admin->value];

    $this->actingAs($admin)->put(route('admin.users.update', $admin), userFormPayload([...$own, 'is_active' => false]))
        ->assertSessionHasErrors('is_active');

    $this->actingAs($admin)->put(route('admin.users.update', $admin), userFormPayload([...$own, 'role' => RoleEnum::Agent->value]))
        ->assertSessionHasErrors('role');

    expect($admin->fresh()->is_active)->toBeTrue()
        ->and($admin->fresh()->hasRole(RoleEnum::Admin->value))->toBeTrue();
});

test('the last active user who can manage users cannot lose that access', function () {
    // Models the race the lock guards: admin B was just demoted by A, but B's
    // own request — already past the route middleware — now tries to demote A.
    $a = userAdminWithRole(RoleEnum::Admin, ['email' => 'a@example.com']);
    $b = userAdminWithRole(RoleEnum::Customer);

    $data = new UserData($a->name, 'a@example.com', RoleEnum::Customer->value, true, new AgentProfileData(true, 10, []));

    expect(fn () => app(UserManagementService::class)->update($a, $data, $b))
        ->toThrow(UserLockoutException::class);

    expect($a->fresh()->hasRole(RoleEnum::Admin->value))->toBeTrue();
});

// --- deactivation actually blocks access --------------------------------------

test('a deactivated user is signed out on their next request', function () {
    $admin = userAdminWithRole(RoleEnum::Admin);
    $agent = userAdminWithRole(RoleEnum::Agent);

    $this->actingAs($admin)->put(route('admin.users.update', $agent), userFormPayload([
        'name' => $agent->name,
        'email' => $agent->email,
        'is_active' => false,
    ]))->assertSessionHasNoErrors();

    $this->actingAs($agent->fresh())->get(route('tickets.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a deactivated user cannot sign in with the right password', function () {
    $user = userAdminWithRole(RoleEnum::Customer, ['password' => 'correct-password-1', 'is_active' => false]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-password-1'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('auto-assign never picks a deactivated agent', function () {
    $active = userAdminWithRole(RoleEnum::Agent);
    $inactive = userAdminWithRole(RoleEnum::Agent, ['is_active' => false]);

    $candidates = app(AgentRepositoryInterface::class)->candidatesFor(null)->pluck('user_id');

    expect($candidates)->toContain($active->id)->not->toContain($inactive->id);
});
