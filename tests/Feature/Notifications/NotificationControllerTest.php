<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeNotificationFor(User $user): DatabaseNotification
{
    return DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TicketCreated',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => ['message' => 'Test', 'url' => '/tickets'],
    ]);
}

test('the shared bell prop serialises recent notifications as a plain array', function () {
    $user = User::factory()->create();
    makeNotificationFor($user);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('notifications.unreadCount', 1)
        ->has('notifications.recent', 1)
        ->has('notifications.recent.0.data.message')
    );
});

test('the notifications index page renders without the shared bell prop crashing it', function () {
    $user = User::factory()->create();
    makeNotificationFor($user);

    $response = $this->actingAs($user)->get(route('notifications.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('notifications/index')
        ->has('items.data', 1)
        ->has('items.meta.last_page')
    );
});

test('a user can mark their own notification read', function () {
    $user = User::factory()->create();
    $notification = makeNotificationFor($user);

    $this->actingAs($user)
        ->post(route('notifications.read', $notification->id))
        ->assertRedirect();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('a user cannot mark another user\'s notification read', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $notification = makeNotificationFor($owner);

    $this->actingAs($intruder)
        ->post(route('notifications.read', $notification->id))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

test('mark all read clears every unread notification for the user', function () {
    $user = User::factory()->create();
    makeNotificationFor($user);
    makeNotificationFor($user);

    $this->actingAs($user)
        ->post(route('notifications.read-all'))
        ->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(0);
});
