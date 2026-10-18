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
