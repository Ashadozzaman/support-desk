<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

test('sla:scan is scheduled to run every minute', function () {
    $events = app(Schedule::class)->events();

    $event = collect($events)->first(fn ($e) => str_contains($e->command, 'sla:scan'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('* * * * *');
});

test('tickets:auto-assign is scheduled to run every minute', function () {
    $events = app(Schedule::class)->events();

    $event = collect($events)->first(fn ($e) => str_contains($e->command, 'tickets:auto-assign'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('* * * * *');
});

test('dashboard:warm-cache is scheduled to run every 30 seconds', function () {
    $events = app(Schedule::class)->events();

    $event = collect($events)->first(fn ($e) => str_contains($e->command, 'dashboard:warm-cache'));

    expect($event)->not->toBeNull();
    expect($event->repeatSeconds)->toBe(30);
});

test('report rollups are scheduled: today every 15 minutes, yesterday and the snapshot nightly', function () {
    $events = collect(app(Schedule::class)->events());

    $today = $events->first(fn ($e) => str_ends_with($e->command, 'reports:aggregate'));
    $yesterday = $events->first(fn ($e) => str_contains($e->command, 'reports:aggregate --yesterday'));
    $snapshot = $events->first(fn ($e) => str_contains($e->command, 'reports:snapshot'));

    expect($today->expression)->toBe('*/15 * * * *')
        ->and($today->withoutOverlapping)->toBeTrue()
        ->and($today->onOneServer)->toBeTrue()
        ->and($yesterday->expression)->toBe('30 0 * * *')
        ->and($yesterday->timezone)->toBe(config('support.sla.timezone'))
        ->and($snapshot->expression)->toBe('55 23 * * *')
        ->and($snapshot->timezone)->toBe(config('support.sla.timezone'));
});
