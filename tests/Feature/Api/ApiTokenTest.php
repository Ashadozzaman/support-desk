<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function apiUser(array $attributes = [], string $role = 'customer'): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

test('valid credentials return a bearer token that authenticates later requests', function () {
    $user = apiUser(['email' => 'cust@example.test'], RoleEnum::Customer->value);

    $token = $this->postJson(route('api.v1.tokens.store'), [
        'email' => 'cust@example.test',
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->json('token');

    $this->withToken($token)->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.roles', ['customer']);

    expect(Activity::where('event', AuditEvent::ApiTokenCreated->value)->where('causer_id', $user->id)->exists())->toBeTrue();
});

test('a wrong password, an unknown email and an inactive account all get the same error', function (string $case) {
    apiUser(['email' => 'known@example.test', 'is_active' => $case !== 'inactive']);

    $this->postJson(route('api.v1.tokens.store'), [
        'email' => $case === 'unknown' ? 'nobody@example.test' : 'known@example.test',
        'password' => $case === 'wrong password' ? 'not-the-password' : 'password',
        'device_name' => 'pest',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => __('auth.failed')]);

    expect(PersonalAccessToken::count())->toBe(0)
        ->and(Activity::where('event', AuditEvent::LoginFailed->value)->count())->toBe(1);
})->with(['wrong password', 'unknown', 'inactive']);

test('an unverified account cannot get a token', function () {
    apiUser(['email' => 'new@example.test', 'email_verified_at' => null]);

    $this->postJson(route('api.v1.tokens.store'), [
        'email' => 'new@example.test', 'password' => 'password', 'device_name' => 'pest',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(PersonalAccessToken::count())->toBe(0);
});

test('two-factor cannot be bypassed: a code is required and must be valid', function () {
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    apiUser([
        'email' => '2fa@example.test',
        'two_factor_secret' => encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ]);
    $credentials = ['email' => '2fa@example.test', 'password' => 'password', 'device_name' => 'pest'];

    $this->postJson(route('api.v1.tokens.store'), $credentials)
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson(route('api.v1.tokens.store'), [...$credentials, 'code' => '000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->postJson(route('api.v1.tokens.store'), [
        ...$credentials,
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertCreated();
});

test('validation errors are JSON even without an Accept header', function () {
    // A plain POST (no Accept: application/json) would normally redirect back.
    $this->post(route('api.v1.tokens.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

test('requests without a token get 401 JSON', function () {
    $this->get(route('api.v1.tickets.index'))->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
});

test('revoking the current token makes it unusable', function () {
    $user = apiUser();
    $token = $user->createToken('pest')->plainTextToken;

    $this->withToken($token)->deleteJson(route('api.v1.tokens.destroy'))->assertNoContent();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertUnauthorized();
    expect(Activity::where('event', AuditEvent::ApiTokenRevoked->value)->exists())->toBeTrue();
});

test('a deactivated user\'s existing token stops working at once', function () {
    $user = apiUser();
    $token = $user->createToken('pest')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.me'))->assertOk();

    $user->update(['is_active' => false]);
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertUnauthorized();
});

test('an expired token is rejected', function () {
    $user = apiUser();
    $token = $user->createToken('pest')->plainTextToken;

    $this->travel(config('sanctum.expiration') + 1)->minutes();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertUnauthorized();
});

test('token requests are rate limited', function () {
    foreach (range(1, 6) as $_) {
        $this->postJson(route('api.v1.tokens.store'), ['email' => 'x@example.test', 'password' => 'x', 'device_name' => 'x']);
    }

    $this->postJson(route('api.v1.tokens.store'), ['email' => 'x@example.test', 'password' => 'x', 'device_name' => 'x'])
        ->assertTooManyRequests();
});

test('the API docs need a signed-in user', function () {
    $this->get('/docs/api')->assertRedirect(route('login'));

    $this->actingAs(apiUser())->get('/docs/api')->assertOk();
    $this->get('/docs/api.json')->assertOk()->assertJsonPath('paths./v1/tickets.get.summary', 'List tickets');
});
