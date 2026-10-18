<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketActivity;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTicketSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('demo tickets cover the cases reports need, with consistent metrics', function () {
    $this->seed(DatabaseSeeder::class);

    $resolved = Ticket::query()->whereIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value]);

    expect(Ticket::query()->count())->toBe(2000)
        ->and((clone $resolved)->where('sla_met', true)->exists())->toBeTrue()
        ->and((clone $resolved)->where('sla_met', false)->exists())->toBeTrue()
        ->and((clone $resolved)->whereNull('resolution_minutes')->exists())->toBeFalse()
        ->and(Ticket::query()->active()->whereNotNull('resolution_minutes')->exists())->toBeFalse()
        ->and(Ticket::query()->where('reopened_count', '>', 0)->exists())->toBeTrue()
        ->and(TicketActivity::query()->where('event', ActivityEvent::TicketReopened->value)->count())
        ->toBe((int) Ticket::query()->sum('reopened_count'));

    // Active tickets past their deadline are already flagged, so the first
    // sla:scan after seeding doesn't fire a burst of notifications.
    expect(Ticket::query()->active()->where('sla_due_at', '<=', now())->whereNull('sla_breach_notified_at')->exists())->toBeFalse();
});

test('running the demo ticket seeder again adds nothing', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoTicketSeeder::class);

    expect(Ticket::query()->count())->toBe(2000);
});
