<?php

declare(strict_types=1);

use App\Enums\ReportGranularity;
use App\Enums\TicketPriority;
use App\Http\Requests\ReportFilterRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
});

/**
 * @param  array<string, mixed>  $query
 */
function reportRequest(array $query): ReportFilterRequest
{
    $request = ReportFilterRequest::create('/reports', 'GET', $query);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

test('defaults to the last 30 business-timezone days by day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-31 20:00', 'UTC')); // already Feb 1 in Dhaka

    $data = reportRequest([])->toData();

    expect($data->from->toDateString())->toBe('2026-01-03')
        ->and($data->to->toDateString())->toBe('2026-02-01')
        ->and($data->granularity)->toBe(ReportGranularity::Day)
        ->and($data->toUtc()->toDateTimeString())->toBe('2026-02-01 18:00:00');
});

test('maps every filter onto the DTO', function () {
    $data = reportRequest(['from' => '2026-01-01', 'to' => '2026-03-31', 'granularity' => 'month', 'priority' => 'high'])->toData();

    expect($data->granularity)->toBe(ReportGranularity::Month)
        ->and($data->priority)->toBe(TicketPriority::High)
        ->and($data->categoryId)->toBeNull();
});

test('rejects invalid filters', function (array $query, string $field) {
    expect(fn () => reportRequest($query))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toHaveKey($field),
    );
})->with([
    'range over 366 days' => [['from' => '2025-01-01', 'to' => '2026-01-02'], 'to'],
    'to before from' => [['from' => '2026-02-01', 'to' => '2026-01-01'], 'to'],
    'unknown granularity' => [['granularity' => 'hour'], 'granularity'],
    'unknown priority' => [['priority' => 'urgent'], 'priority'],
    'unknown category' => [['category_id' => 999], 'category_id'],
]);

test('exactly 366 days is allowed', function () {
    expect(reportRequest(['from' => '2025-01-01', 'to' => '2026-01-01'])->toData()->to->toDateString())->toBe('2026-01-01');
});
