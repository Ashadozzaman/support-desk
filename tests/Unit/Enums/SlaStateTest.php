<?php

declare(strict_types=1);

use App\Enums\SlaState;
use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;

$now = CarbonImmutable::parse('2026-01-04 12:00:00');

test('no sla_due_at means no sla state', function () use ($now) {
    expect(SlaState::resolve(TicketStatus::Open, null, null, null, $now))->toBeNull();
});

test('within sla before the warning time', function () use ($now) {
    $state = SlaState::resolve(
        TicketStatus::Open,
        null,
        $now->addHours(5),
        $now->addHours(2),
        $now,
    );

    expect($state)->toBe(SlaState::WithinSla);
});

test('approaching once past the warning time but before the due time', function () use ($now) {
    $state = SlaState::resolve(
        TicketStatus::InProgress,
        null,
        $now->addHour(),
        $now->subHour(),
        $now,
    );

    expect($state)->toBe(SlaState::Approaching);
});

test('breached once past the due time', function () use ($now) {
    $state = SlaState::resolve(
        TicketStatus::InProgress,
        null,
        $now->subMinute(),
        $now->subHour(),
        $now,
    );

    expect($state)->toBe(SlaState::Breached);
});

test('resolved tickets report met or met-late from the stored flag, ignoring the clock', function () use ($now) {
    $met = SlaState::resolve(TicketStatus::Resolved, true, $now->subDay(), $now->subDay(), $now);
    $metLate = SlaState::resolve(TicketStatus::Closed, false, $now->subDay(), $now->subDay(), $now);

    expect($met)->toBe(SlaState::Met);
    expect($metLate)->toBe(SlaState::MetLate);
});
