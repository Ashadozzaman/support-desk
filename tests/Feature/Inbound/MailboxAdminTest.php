<?php

declare(strict_types=1);

use App\Contracts\Inbound\MailboxClient;
use App\Enums\AuditEvent;
use App\Enums\MailboxEncryption;
use App\Enums\Role as RoleEnum;
use App\Jobs\SyncMailboxJob;
use App\Models\MailboxSetting;
use App\Models\User;
use App\Support\Inbound\FakeMailboxClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['support.mailbox.password' => null]);

    $this->fake = new FakeMailboxClient;
    $this->app->instance(MailboxClient::class, $this->fake);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleEnum::Admin->value);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mailboxForm(array $overrides = []): array
{
    return array_merge([
        'is_enabled' => false,
        'host' => 'imap.gmail.com',
        'port' => 993,
        'encryption' => 'ssl',
        'username' => 'support@example.com',
        'password' => '',
        'folder' => 'INBOX',
        'fetch_interval_minutes' => 2,
        'batch_size' => 25,
    ], $overrides);
}

test('only users with mailbox.manage reach any mailbox route', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('admin.mailbox.edit'))->assertForbidden();
    $this->actingAs($user)->put(route('admin.mailbox.update'), mailboxForm())->assertForbidden();
    $this->actingAs($user)->post(route('admin.mailbox.test'), mailboxForm())->assertForbidden();
    $this->actingAs($user)->post(route('admin.mailbox.sync'))->assertForbidden();

    expect(MailboxSetting::count())->toBe(0)->and($this->fake->connections)->toBe([]);
})->with([RoleEnum::Agent->value, RoleEnum::Customer->value]);

test('guests are sent to the login page', function () {
    $this->get(route('admin.mailbox.edit'))->assertRedirect(route('login'));
});

test('the page shows Gmail defaults before anything is saved', function () {
    $this->actingAs($this->admin)->get(route('admin.mailbox.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/mailbox/index')
            ->where('settings.data.host', 'imap.gmail.com')
            ->where('settings.data.port', 993)
            ->where('settings.data.encryption', 'ssl')
            ->where('settings.data.is_enabled', false)
            ->where('settings.data.password_source', null)
            ->has('syncs.data', 0));
});

test('the password is stored encrypted and never sent to the browser', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.mailbox.update'), mailboxForm(['password' => 'abcd efgh ijkl mnop']))
        ->assertRedirect(route('admin.mailbox.edit'));

    $stored = DB::table('mailbox_settings')->value('password');
    expect($stored)->not->toContain('abcd')
        ->and(decrypt($stored, unserialize: false))->toBe('abcd efgh ijkl mnop');

    $response = $this->actingAs($this->admin)->get(route('admin.mailbox.edit'));
    $response->assertInertia(fn (Assert $page) => $page
        ->where('settings.data.password_source', 'saved')
        ->missing('settings.data.password'));
    expect($response->getContent())->not->toContain('abcd efgh')
        ->and(json_encode(MailboxSetting::sole()->toArray()))->not->toContain('abcd');
});

test('a blank password keeps the stored one', function () {
    $this->actingAs($this->admin)->put(route('admin.mailbox.update'), mailboxForm(['password' => 'first-secret']));
    $this->actingAs($this->admin)->put(route('admin.mailbox.update'), mailboxForm(['password' => '', 'batch_size' => 50]));

    expect(MailboxSetting::sole()->password)->toBe('first-secret')
        ->and(MailboxSetting::sole()->batch_size)->toBe(50);
});

test('IMAP_PASSWORD from .env is used while none is saved', function () {
    config(['support.mailbox.password' => 'from-env']);

    $this->actingAs($this->admin)->get(route('admin.mailbox.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('settings.data.password_source', 'env'));

    $this->actingAs($this->admin)->post(route('admin.mailbox.test'), mailboxForm());
    expect($this->fake->connections[0]->password)->toBe('from-env');
});

test('enabling without any password is refused', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.mailbox.update'), mailboxForm(['is_enabled' => true]))
        ->assertSessionHasErrors('password');

    expect(MailboxSetting::count())->toBe(0);
});

test('validation rejects a URL as host and an unknown encryption', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.mailbox.update'), mailboxForm(['host' => 'imap://imap.gmail.com:993', 'encryption' => 'starttls']))
        ->assertSessionHasErrors(['host', 'encryption']);
});

test('the audit log records that the password changed, never its value', function () {
    $this->actingAs($this->admin)->put(route('admin.mailbox.update'), mailboxForm(['password' => 'top-secret']));

    $entry = Activity::query()->where('event', AuditEvent::MailboxSettingsUpdated->value)->sole();
    expect(json_encode($entry->properties))->not->toContain('top-secret')
        ->and($entry->properties['changes']['mailbox']['new']['password'])->toBe('changed');
});

test('test connection tries the typed values without saving them, spaces stripped', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.mailbox.test'), mailboxForm(['host' => 'imap.example.org', 'password' => 'abcd efgh']))
        ->assertRedirect(route('admin.mailbox.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(MailboxSetting::count())->toBe(0)
        ->and($this->fake->connections[0]->host)->toBe('imap.example.org')
        ->and($this->fake->connections[0]->password)->toBe('abcdefgh')
        ->and($this->fake->connections[0]->encryption)->toBe(MailboxEncryption::Ssl);
});

test('a failed connection shows the safe message', function () {
    $this->fake->failWith = 'Authentication failed — check the username and the app password.';

    $this->actingAs($this->admin)
        ->post(route('admin.mailbox.test'), mailboxForm(['password' => 'wrong']))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Authentication failed — check the username and the app password.');
});

test('sync now queues one sync, and only when enabled', function () {
    Queue::fake();

    $this->actingAs($this->admin)->post(route('admin.mailbox.sync'))->assertInertiaFlash('toast.type', 'error');
    Queue::assertNothingPushed();

    $this->actingAs($this->admin)->put(route('admin.mailbox.update'), mailboxForm(['is_enabled' => true, 'password' => 'secret']));
    $this->actingAs($this->admin)->post(route('admin.mailbox.sync'))->assertInertiaFlash('toast.type', 'success');
    $this->actingAs($this->admin)->post(route('admin.mailbox.sync'));

    // Unique job: the second request while one is queued is dropped.
    Queue::assertPushed(SyncMailboxJob::class, 1);
    Queue::assertPushed(SyncMailboxJob::class, fn (SyncMailboxJob $job) => $job->triggeredBy === $this->admin->id);
});

test('pointing at a different mailbox resets the sync cursor', function () {
    $this->actingAs($this->admin)->put(route('admin.mailbox.update'), mailboxForm(['password' => 'secret']));
    MailboxSetting::sole()->update(['last_uid' => 40, 'uid_validity' => 7]);

    $this->actingAs($this->admin)->put(route('admin.mailbox.update'), mailboxForm(['fetch_interval_minutes' => 5]));
    expect(MailboxSetting::sole()->last_uid)->toBe(40);

    $this->actingAs($this->admin)->put(route('admin.mailbox.update'), mailboxForm(['username' => 'other@example.com']));
    expect(MailboxSetting::sole()->last_uid)->toBeNull()->and(MailboxSetting::sole()->uid_validity)->toBeNull();
});
