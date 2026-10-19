<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['support.monitoring.health_secret' => 'test-health-secret']);
});

/**
 * What a healthy deployment looks like: the scheduler and every watched
 * queue's worker wrote their heartbeat within the last minute.
 */
function freshHeartbeats(?int $timestamp = null): void
{
    $timestamp ??= now()->timestamp;

    Cache::put('health:checks:schedule:latestHeartbeatAt', $timestamp);
    foreach (config('support.monitoring.queues') as $queue) {
        Cache::put("health:checks:queue:latestHeartbeatAt.{$queue}", $timestamp);
    }
}

function getHealth(?string $secret = 'test-health-secret')
{
    return test()->getJson(route('health'), $secret === null ? [] : ['X-Health-Secret' => $secret]);
}

function checkStatus($response, string $name): ?string
{
    return collect($response->json('checks'))->firstWhere('name', $name)['status'] ?? null;
}

test('the health endpoint needs the secret header', function () {
    getHealth(null)->assertUnauthorized();
    getHealth('wrong-secret')->assertUnauthorized();
});

test('the health endpoint is closed when no secret is configured', function () {
    config(['support.monitoring.health_secret' => null]);

    getHealth('')->assertUnauthorized();
});

test('a healthy app returns 200 with every check listed', function () {
    freshHeartbeats();

    $response = getHealth()->assertOk()->assertJson(['healthy' => true]);

    expect(checkStatus($response, 'Schedule'))->toBe('ok')
        ->and(checkStatus($response, 'SlaBacklog'))->toBe('ok')
        // Tests broadcast to the log driver, so the Reverb check doesn't apply.
        ->and(checkStatus($response, 'Reverb'))->toBe('skipped');
});

test('a stale scheduler heartbeat returns 503', function () {
    freshHeartbeats();
    Cache::put('health:checks:schedule:latestHeartbeatAt', now()->subMinutes(10)->timestamp);

    $response = getHealth()->assertStatus(503)->assertJson(['healthy' => false]);

    expect(checkStatus($response, 'Schedule'))->toBe('failed');
});

test('a ticket past its SLA without a breach flag returns 503', function () {
    freshHeartbeats();
    Ticket::factory()->create([
        'status' => TicketStatus::InProgress->value,
        'sla_due_at' => now()->subMinutes(10),
        'sla_breach_notified_at' => null,
    ]);

    $response = getHealth()->assertStatus(503);

    expect(checkStatus($response, 'SlaBacklog'))->toBe('failed');
});

test('a recently due ticket, a flagged one, or a resolved one does not fail the SLA check', function () {
    freshHeartbeats();
    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'sla_due_at' => now()->subMinutes(2), 'sla_breach_notified_at' => null]);
    Ticket::factory()->create(['status' => TicketStatus::Open->value, 'sla_due_at' => now()->subHour(), 'sla_breach_notified_at' => now()->subMinutes(59)]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved->value, 'sla_due_at' => now()->subHour(), 'sla_breach_notified_at' => null]);

    expect(checkStatus(getHealth()->assertOk(), 'SlaBacklog'))->toBe('ok');
});

test('a queue backlog older than the limit fails the backlog check', function () {
    freshHeartbeats();
    DB::table('jobs')->insert([
        'queue' => 'sla',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(20)->timestamp,
        'created_at' => now()->subMinutes(20)->timestamp,
    ]);

    $response = getHealth()->assertStatus(503);

    expect(checkStatus($response, 'QueueBacklog'))->toBe('failed');
});

test('a few failed jobs are a warning, which still answers 200', function () {
    freshHeartbeats();
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException',
        'failed_at' => now()->subHour(),
    ]);

    $response = getHealth()->assertOk()->assertJson(['healthy' => true]);

    expect(checkStatus($response, 'FailedJobs'))->toBe('warning');
});
