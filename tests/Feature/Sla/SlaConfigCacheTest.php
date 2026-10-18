<?php

declare(strict_types=1);

use App\Contracts\Repositories\SlaConfigRepositoryInterface;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * This app's database cache store sets serializable_classes = false
 * (config/cache.php) — unserializing any object (even an Eloquent model
 * wrapped as string attributes) degrades to __PHP_Incomplete_Class. The
 * .env.testing CACHE_STORE=array never round-trips through serialize(),
 * so it would never catch this; force the database driver here instead.
 */
beforeEach(function () {
    config(['cache.default' => 'database']);

    BusinessHour::factory()->create(['day_of_week' => 0]);
    SlaPolicy::factory()->create(['priority' => 'critical']);
    Holiday::factory()->create(['date' => '2026-03-26']);
});

test('business hours survive a cache hit, not just a cache miss', function () {
    $repo = app(SlaConfigRepositoryInterface::class);

    $repo->businessHours();
    $second = $repo->businessHours();

    expect($second->first()->is_working_day)->toBeBool();
});

test('sla policies survive a cache hit, not just a cache miss', function () {
    $repo = app(SlaConfigRepositoryInterface::class);

    $repo->policies();
    $second = $repo->policies();

    expect($second->get('critical')->resolution_minutes)->toBeInt();
});

test('holiday dates survive a cache hit, not just a cache miss', function () {
    $repo = app(SlaConfigRepositoryInterface::class);

    $repo->holidayDates();
    $second = $repo->holidayDates();

    expect($second->all())->toBe(['2026-03-26']);
});
