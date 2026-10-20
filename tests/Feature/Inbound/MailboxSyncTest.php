<?php

declare(strict_types=1);

use App\Contracts\Inbound\MailboxClient;
use App\Enums\MailboxEncryption;
use App\Enums\MailboxSyncStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketSource;
use App\Jobs\ProcessInboundEmailJob;
use App\Jobs\SyncMailboxJob;
use App\Models\MailboxSetting;
use App\Models\MailboxSync;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\MailboxSyncService;
use App\Support\Inbound\FakeMailboxClient;
use Database\Seeders\BusinessHourSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, SlaPolicySeeder::class, BusinessHourSeeder::class]);
    Storage::fake('local');
    Notification::fake();

    $this->fake = new FakeMailboxClient;
    $this->app->instance(MailboxClient::class, $this->fake);

    User::factory()->create(['email' => 'jane@example.com'])->assignRole(RoleEnum::Customer->value);

    $this->settings = MailboxSetting::create([
        'is_enabled' => true,
        'host' => 'imap.gmail.com',
        'port' => 993,
        'encryption' => MailboxEncryption::Ssl,
        'username' => 'support@example.com',
        'password' => 'secret',
        'folder' => 'INBOX',
        'fetch_interval_minutes' => 5,
        'batch_size' => 25,
    ]);
});

/** Runs one sync the way the queue would (QUEUE_CONNECTION=sync in tests). */
function runMailboxSync(): ?MailboxSync
{
    return app(MailboxSyncService::class)->sync(null);
}

test('the first sync only records the starting point — old mail is never imported', function () {
    $this->fake->deliver(rawEmail());
    $this->fake->deliver(rawEmail());

    $sync = runMailboxSync();

    expect($sync->status)->toBe(MailboxSyncStatus::Baseline)
        ->and(Ticket::count())->toBe(0)
        ->and($this->settings->fresh()->last_uid)->toBe(2)
        ->and($this->settings->fresh()->uid_validity)->toBe(1);
});

test('new mail after the starting point becomes tickets and replies', function () {
    runMailboxSync();

    $this->fake->deliver(rawEmail(['Message-ID' => '<first@mail.example.com>']));
    $this->fake->deliver(rawEmail(['In-Reply-To' => '<first@mail.example.com>', 'Subject' => 'Re: Order missing'], 'Any news?'));

    $sync = runMailboxSync()->fresh();

    $ticket = Ticket::sole();
    expect($ticket->source)->toBe(TicketSource::Email)
        ->and(TicketReply::sole()->ticket_id)->toBe($ticket->id)
        ->and($sync->status)->toBe(MailboxSyncStatus::Completed)
        ->and($sync->fetched_count)->toBe(2)
        ->and($sync->created_count)->toBe(1)
        ->and($sync->replied_count)->toBe(1)
        ->and($this->settings->fresh()->last_uid)->toBe(2);

    // Spooled files are removed once processed.
    expect(Storage::disk('local')->allFiles('inbound'))->toBe([]);
});

test('a message delivered again under a new UID is counted as a duplicate, not a second ticket', function () {
    runMailboxSync();
    $raw = rawEmail(['Message-ID' => '<same@mail.example.com>']);
    $this->fake->deliver($raw);
    runMailboxSync();

    $this->fake->deliver($raw);
    $sync = runMailboxSync()->fresh();

    expect(Ticket::count())->toBe(1)->and($sync->duplicate_count)->toBe(1);
});

test('a sync takes at most batch_size messages and the next one continues', function () {
    $this->settings->update(['batch_size' => 2]);
    runMailboxSync();
    foreach (range(1, 3) as $i) {
        $this->fake->deliver(rawEmail());
    }

    runMailboxSync();
    expect(Ticket::count())->toBe(2);

    runMailboxSync();
    expect(Ticket::count())->toBe(3);
});

test('a connection failure is recorded with a safe message and the cursor stays', function () {
    runMailboxSync();
    $this->fake->failWith = 'Could not connect to imap.gmail.com:993 — check host, port and encryption.';

    $sync = runMailboxSync();

    expect($sync->status)->toBe(MailboxSyncStatus::Failed)
        ->and($sync->error)->toBe('Could not connect to imap.gmail.com:993 — check host, port and encryption.')
        ->and($this->settings->fresh()->last_uid)->toBe(0)
        ->and($this->settings->fresh()->last_synced_at)->not->toBeNull();
});

test('a new UIDVALIDITY means a renumbered folder: a new starting point, nothing re-imported', function () {
    $this->fake->deliver(rawEmail());
    runMailboxSync();

    $this->fake->uidValidity = 2;
    $sync = runMailboxSync();

    expect($sync->status)->toBe(MailboxSyncStatus::Baseline)->and(Ticket::count())->toBe(0);
});

test('a disabled mailbox is never contacted', function () {
    $this->settings->update(['is_enabled' => false]);

    expect(runMailboxSync())->toBeNull()
        ->and($this->fake->connections)->toBe([])
        ->and(app(MailboxSyncService::class)->isDue())->toBeFalse();
});

test('the command respects the fetch interval, --now ignores it', function () {
    Queue::fake();
    $this->settings->update(['last_synced_at' => now()->subMinutes(2)]);

    $this->artisan('mailbox:sync')->assertSuccessful();
    Queue::assertNotPushed(SyncMailboxJob::class);

    $this->artisan('mailbox:sync --now')->assertSuccessful();
    Queue::assertPushed(SyncMailboxJob::class, 1);
});

test('the command queues a sync once the interval has passed', function () {
    Queue::fake();
    $this->settings->update(['last_synced_at' => now()->subMinutes(6)]);

    $this->artisan('mailbox:sync')->assertSuccessful();

    Queue::assertPushed(SyncMailboxJob::class, 1);
});

test('mailbox:sync is scheduled every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'mailbox:sync'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

test('the processing job can be retried safely', function () {
    $sync = MailboxSync::create(['status' => MailboxSyncStatus::Completed]);
    Storage::disk('local')->put('inbound/one.eml', rawEmail(['Message-ID' => '<retry@mail.example.com>']));

    $job = new ProcessInboundEmailJob('inbound/one.eml', $sync->id);
    app()->call([$job, 'handle']);
    // Second run: the file is gone → nothing happens.
    app()->call([$job, 'handle']);

    // A copy that survived (e.g. the worker died before deleting it) is a duplicate, not a new ticket.
    Storage::disk('local')->put('inbound/one.eml', rawEmail(['Message-ID' => '<retry@mail.example.com>']));
    app()->call([$job, 'handle']);

    expect(Ticket::count())->toBe(1)
        ->and($sync->fresh()->created_count)->toBe(1)
        ->and($sync->fresh()->duplicate_count)->toBe(1);
});

test('a job that fails for good is counted and keeps its file for queue:retry', function () {
    $sync = MailboxSync::create(['status' => MailboxSyncStatus::Completed]);
    Storage::disk('local')->put('inbound/bad.eml', 'not an email');

    (new ProcessInboundEmailJob('inbound/bad.eml', $sync->id))->failed(new RuntimeException('boom'));

    expect($sync->fresh()->failed_count)->toBe(1);
    Storage::disk('local')->assertExists('inbound/bad.eml');
});
