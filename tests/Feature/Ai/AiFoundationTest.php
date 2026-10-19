<?php

declare(strict_types=1);

use App\Contracts\Ai\AiClient;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\AiInteraction;
use App\Models\User;
use App\Services\AiGuard;
use App\Support\Ai\AnthropicClient;
use App\Support\Ai\FakeAiClient;
use App\Support\Health\AiProviderCheck;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Enums\Status;
use Spatie\Health\Health;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('ai.enabled middleware hides AI endpoints with a 404 while AI is off', function () {
    Route::middleware(['web', 'auth', 'ai.enabled'])->get('/_test/ai', fn () => 'ok');
    $user = User::factory()->create();

    config(['ai.enabled' => false]);
    $this->actingAs($user)->get('/_test/ai')->assertNotFound();

    config(['ai.enabled' => true]);
    $this->actingAs($user)->get('/_test/ai')->assertOk();
});

test('the fake client is bound unless AI is on with the anthropic provider', function (bool $enabled, string $provider, string $expected) {
    config(['ai.enabled' => $enabled, 'ai.provider' => $provider]);

    expect(app(AiClient::class))->toBeInstanceOf($expected);
})->with([
    'disabled, anthropic' => [false, 'anthropic', FakeAiClient::class],
    'enabled, fake' => [true, 'fake', FakeAiClient::class],
    'enabled, anthropic' => [true, 'anthropic', AnthropicClient::class],
]);

test('health check: missing key fails, open breaker or used budget warns, otherwise ok', function () {
    config(['ai.enabled' => true, 'ai.provider' => 'anthropic', 'ai.anthropic.api_key' => '', 'ai.daily_token_budget' => 1000]);
    expect((new AiProviderCheck)->run()->status)->toBe(Status::failed());

    config(['ai.anthropic.api_key' => 'test-key']);
    expect((new AiProviderCheck)->run()->status)->toBe(Status::ok());

    cache()->put('ai:circuit:open', true, 300);
    expect((new AiProviderCheck)->run()->status)->toBe(Status::warning());
    cache()->forget('ai:circuit:open');

    config(['ai.daily_token_budget' => 0]);
    expect((new AiProviderCheck)->run()->status)->toBe(Status::warning());
});

test('the health check only runs while AI is on', function () {
    $running = fn () => collect(app(Health::class)->registeredChecks())
        ->filter(fn ($check) => $check->shouldRun())
        ->map(fn ($check) => $check->getName());

    config(['ai.enabled' => false]);
    expect($running())->not->toContain('AiProvider');

    config(['ai.enabled' => true]);
    expect($running())->toContain('AiProvider');
});

test('a fresh seed gives the built-in roles their AI and knowledge-base defaults', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = Role::findByName(RoleEnum::Admin->value);
    $agent = Role::findByName(RoleEnum::Agent->value);
    $customer = Role::findByName(RoleEnum::Customer->value);

    expect($admin->hasPermissionTo(PermissionEnum::KnowledgeBaseManage->value))->toBeTrue()
        ->and($admin->hasPermissionTo(PermissionEnum::AiManage->value))->toBeTrue()
        ->and($agent->hasPermissionTo(PermissionEnum::AiUseAssistant->value))->toBeTrue()
        ->and($agent->hasPermissionTo(PermissionEnum::KnowledgeBaseManage->value))->toBeFalse()
        ->and($customer->hasPermissionTo(PermissionEnum::AiChat->value))->toBeTrue()
        ->and($customer->hasPermissionTo(PermissionEnum::AiUseAssistant->value))->toBeFalse();
});

test('the migration grants the new permissions to roles that already exist', function () {
    // An existing database: roles were created before Step 22's permissions.
    $this->seed(RolesAndPermissionsSeeder::class);
    $new = [PermissionEnum::KnowledgeBaseManage, PermissionEnum::AiUseAssistant, PermissionEnum::AiChat, PermissionEnum::AiManage];
    Permission::query()->whereIn('name', array_map(fn ($p) => $p->value, $new))->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require database_path('migrations/2026_10_19_140002_grant_ai_and_knowledge_base_permissions.php');
    $migration->up();

    expect(Role::findByName(RoleEnum::Admin->value)->hasPermissionTo(PermissionEnum::AiManage->value))->toBeTrue()
        ->and(Role::findByName(RoleEnum::Agent->value)->hasPermissionTo(PermissionEnum::AiUseAssistant->value))->toBeTrue()
        ->and(Role::findByName(RoleEnum::Customer->value)->hasPermissionTo(PermissionEnum::AiChat->value))->toBeTrue()
        ->and(Role::findByName(RoleEnum::Customer->value)->hasPermissionTo(PermissionEnum::KnowledgeBaseManage->value))->toBeFalse();
});

test('interactions older than the retention window are pruned', function () {
    config(['ai.retention_days' => 90]);
    $old = AiInteraction::factory()->create(['created_at' => now()->subDays(91)]);
    $recent = AiInteraction::factory()->create(['created_at' => now()->subDays(89)]);

    $this->artisan('model:prune', ['--model' => AiInteraction::class])->assertSuccessful();

    expect(AiInteraction::pluck('id')->all())->toBe([$recent->id])
        ->and(AiInteraction::find($old->id))->toBeNull();
});

test('nothing in the guard path touches the network in the test environment', function () {
    expect(config('ai.enabled'))->toBeFalse()
        ->and(app(AiClient::class))->toBeInstanceOf(FakeAiClient::class)
        ->and(app(AiGuard::class))->toBeInstanceOf(AiGuard::class);
});
