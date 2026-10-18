<?php

declare(strict_types=1);

use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Support\Sla\BusinessHoursCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Sun-Thu 09:00-18:00, Fri/Sat non-working — ARCHITECTURE.md §8.1 default.
 * Dates are fixed to a known week: 2026-01-01 Thu, 02 Fri, 03 Sat, 04 Sun,
 * 05 Mon, 06 Tue, 07 Wed, 08 Thu.
 */
beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);

    foreach ([0, 1, 2, 3, 4] as $workingDay) {
        BusinessHour::factory()->create([
            'day_of_week' => $workingDay,
            'is_working_day' => true,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);
    }

    foreach ([5, 6] as $weekendDay) {
        BusinessHour::factory()->create([
            'day_of_week' => $weekendDay,
            'is_working_day' => false,
            'start_time' => null,
            'end_time' => null,
        ]);
    }
});

function dhaka(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Asia/Dhaka');
}

test('critical 120 minutes created Thursday 17:00 is due Sunday 10:00', function () {
    $result = app(BusinessHoursCalculator::class)->addBusinessMinutes(dhaka('2026-01-01 17:00'), 120);

    expect($result->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 10:00');
});

test('created on a non-working Friday starts counting from Sunday 09:00', function () {
    $result = app(BusinessHoursCalculator::class)->addBusinessMinutes(dhaka('2026-01-02 10:00'), 60);

    expect($result->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 10:00');
});

test('created before opening on a working day starts counting from opening time', function () {
    $result = app(BusinessHoursCalculator::class)->addBusinessMinutes(dhaka('2026-01-04 07:30'), 30);

    expect($result->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 09:30');
});

test('thursday 17:59 plus 120 minutes is due Sunday 10:59', function () {
    $result = app(BusinessHoursCalculator::class)->addBusinessMinutes(dhaka('2026-01-01 17:59'), 120);

    expect($result->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 10:59');
});

test('480 minutes from Sunday 09:00 consumes exactly one business day', function () {
    $result = app(BusinessHoursCalculator::class)->addBusinessMinutes(dhaka('2026-01-04 09:00'), 480);

    expect($result->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 17:00');
});

test('1440 minutes spans multiple business days, skipping the weekend', function () {
    $result = app(BusinessHoursCalculator::class)->addBusinessMinutes(dhaka('2026-01-04 09:00'), 1440);

    // Sun 09:00-18:00 (540) + Mon 09:00-18:00 (540) = 1080, 360 remain -> Tue 09:00 + 360min
    expect($result->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-06 15:00');
});

test('a holiday is skipped like a non-working day', function () {
    Holiday::factory()->create(['date' => '2026-01-04', 'name' => 'Test holiday']);

    $result = app(BusinessHoursCalculator::class)->addBusinessMinutes(dhaka('2026-01-01 17:00'), 120);

    expect($result->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-05 10:00');
});

test('exactly 18:00 is treated as past closing, not within hours', function () {
    $result = app(BusinessHoursCalculator::class)->addBusinessMinutes(dhaka('2026-01-01 18:00'), 30);

    expect($result->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 09:30');
});

/*
 * businessMinutesBetween() — the inverse of addBusinessMinutes(), used for
 * report metrics (first response / resolution time).
 */
dataset('business minutes between', [
    'same working day' => ['2026-01-04 09:00', '2026-01-04 11:30', 150],
    'before opening is not counted' => ['2026-01-04 07:00', '2026-01-04 10:00', 60],
    'after closing is not counted' => ['2026-01-04 17:00', '2026-01-04 21:00', 60],
    'whole working day' => ['2026-01-04 00:00', '2026-01-05 00:00', 540],
    'Thu 17:00 to Sun 10:00 skips the weekend' => ['2026-01-01 17:00', '2026-01-04 10:00', 120],
    'entirely inside the weekend' => ['2026-01-02 10:00', '2026-01-03 16:00', 0],
    'Sun 09:00 to next Sun 09:00 is five working days' => ['2026-01-04 09:00', '2026-01-11 09:00', 2700],
    'end before start' => ['2026-01-04 12:00', '2026-01-04 10:00', 0],
    'same instant' => ['2026-01-04 12:00', '2026-01-04 12:00', 0],
]);

test('business minutes between two instants', function (string $start, string $end, int $expected) {
    $minutes = app(BusinessHoursCalculator::class)->businessMinutesBetween(dhaka($start), dhaka($end));

    expect($minutes)->toBe($expected);
})->with('business minutes between');

test('business minutes between skips a holiday', function () {
    Holiday::factory()->create(['date' => '2026-01-04', 'name' => 'Test holiday']);

    // Thu 17:00 → Mon 10:00 with Sunday off: 60 (Thu) + 60 (Mon).
    $minutes = app(BusinessHoursCalculator::class)->businessMinutesBetween(dhaka('2026-01-01 17:00'), dhaka('2026-01-05 10:00'));

    expect($minutes)->toBe(120);
});

test('business minutes between is the inverse of addBusinessMinutes', function (string $start, int $minutes) {
    $calculator = app(BusinessHoursCalculator::class);
    $end = $calculator->addBusinessMinutes(dhaka($start), $minutes);

    expect($calculator->businessMinutesBetween(dhaka($start), $end))->toBe($minutes);
})->with([
    ['2026-01-01 17:00', 120],
    ['2026-01-01 17:59', 120],
    ['2026-01-02 11:00', 480],
    ['2026-01-04 07:30', 1440],
    ['2026-01-04 09:00', 2880],
]);

test('business minutes between is zero when no day is a working day', function () {
    BusinessHour::query()->update(['is_working_day' => false, 'start_time' => null, 'end_time' => null]);

    $minutes = app(BusinessHoursCalculator::class)->businessMinutesBetween(dhaka('2026-01-01 09:00'), dhaka('2026-01-08 09:00'));

    expect($minutes)->toBe(0);
});
