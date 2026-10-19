<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Jobs\AutoAssignTicketJob;
use App\Models\User;
use App\Notifications\JobFailedAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function failedJobUser(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

function fireJobFailed(string $displayName, ?Throwable $e = null): void
{
    $payload = json_encode(['displayName' => $displayName, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => []]);
    $job = new SyncJob(app(), $payload, 'database', 'default');

    event(new JobFailed('database', $job, $e ?? new RuntimeException('boom')));
}

test('a failed job is logged to the queue channel and alerts monitoring users once per window', function () {
    $log = captureLog('queue');
    $admin = failedJobUser(RoleEnum::Admin);
    $agent = failedJobUser(RoleEnum::Agent);

    fireJobFailed(AutoAssignTicketJob::class);
    fireJobFailed(AutoAssignTicketJob::class);

    expect($log->getRecords())->toHaveCount(2);
    expect($log->getRecords()[0]->context['job'])->toBe(AutoAssignTicketJob::class);
    Notification::assertSentToTimes($admin, JobFailedAlert::class, 1);
    Notification::assertNotSentTo($agent, JobFailedAlert::class);

    $this->travel(11)->minutes();
    fireJobFailed(AutoAssignTicketJob::class);

    Notification::assertSentToTimes($admin, JobFailedAlert::class, 2);
});

test('different job classes each get their own alert window', function () {
    $admin = failedJobUser(RoleEnum::Admin);

    fireJobFailed(AutoAssignTicketJob::class);
    fireJobFailed('App\Jobs\ProcessSlaBreachJob');

    Notification::assertSentToTimes($admin, JobFailedAlert::class, 2);
});

test('a failed notification job is logged but never alerts, so alerts cannot loop', function () {
    $log = captureLog('queue');
    failedJobUser(RoleEnum::Admin);

    fireJobFailed(SendQueuedNotifications::class);

    expect($log->getRecords())->toHaveCount(1);
    Notification::assertNothingSent();
});

test('the alert and the log carry no exception message text that could hold data', function () {
    $log = captureLog('queue');
    $admin = failedJobUser(RoleEnum::Admin);
    $sqlError = new QueryException('mysql', 'update tickets set subject = ?', ['SECRET-SUBJECT'], new Exception('x'));

    fireJobFailed(AutoAssignTicketJob::class, $sqlError);

    expect(json_encode($log->getRecords()[0]->toArray()))->not->toContain('SECRET-SUBJECT');
    Notification::assertSentTo($admin, JobFailedAlert::class, function (JobFailedAlert $n) use ($admin) {
        return ! str_contains(json_encode($n->toArray($admin)), 'SECRET-SUBJECT');
    });
});
