<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

class WritesLogLineJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Log::channel('queue')->info('test.job_ran');
    }
}

function contextUser(): User
{
    $user = User::factory()->create();
    $user->assignRole(RoleEnum::Customer->value);

    return $user;
}

test('every web response carries a generated request id', function () {
    $response = $this->actingAs(contextUser())->get(route('dashboard'));

    expect(Str::isUuid($response->headers->get('X-Request-Id')))->toBeTrue();
});

test('a well-formed incoming request id is reused, a malformed one is replaced', function () {
    $user = contextUser();

    $this->actingAs($user)
        ->withHeader('X-Request-Id', 'lb-req-0123456789')
        ->get(route('dashboard'))
        ->assertHeader('X-Request-Id', 'lb-req-0123456789');

    $replaced = $this->actingAs($user)
        ->withHeader('X-Request-Id', "bad id\nInjected: header")
        ->get(route('dashboard'))
        ->headers->get('X-Request-Id');

    expect(Str::isUuid($replaced))->toBeTrue();
});

test('request id and user id are on log lines written during the request', function () {
    $user = contextUser();
    $log = captureLog('security');

    // A real 403 writes a security line inside the request.
    $this->actingAs($user)
        ->withHeader('X-Request-Id', 'trace-abcdef123')
        ->get(route('admin.agents.index'))
        ->assertForbidden();

    $record = $log->getRecords()[0];
    expect($record->extra['request_id'])->toBe('trace-abcdef123')
        ->and($record->extra['user_id'])->toBe($user->id);
});

test('the request id travels with a queued job and is on the line it logs', function () {
    config(['queue.default' => 'database']);
    $log = captureLog('queue');

    Context::add('request_id', 'trace-from-http-1');
    WritesLogLineJob::dispatch();

    // Simulates the worker: a separate run with none of the request's context.
    Context::flush();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    $record = collect($log->getRecords())->firstWhere('message', 'test.job_ran');
    expect($record)->not->toBeNull()
        ->and($record->extra['request_id'])->toBe('trace-from-http-1');
});
