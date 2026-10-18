<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Services\SlaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

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

    foreach (TicketPriority::cases() as $priority) {
        SlaPolicy::factory()->create([
            'priority' => $priority->value,
            'resolution_minutes' => 120,
            'warning_threshold_percent' => 80,
            'is_active' => true,
        ]);
    }
});

function slaPolicyRows(int $criticalMinutes = 120): array
{
    return collect(TicketPriority::cases())->map(fn (TicketPriority $priority) => [
        'priority' => $priority->value,
        'resolution_minutes' => $priority === TicketPriority::Critical ? $criticalMinutes : 120,
        'warning_threshold_percent' => 80,
        'is_active' => true,
    ])->values()->all();
}

test('an agent cannot update sla policies', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    $this->actingAs($agent)
        ->put(route('admin.sla-policies.update'), ['policies' => slaPolicyRows()])
        ->assertForbidden();
});

test('a customer cannot manage business hours or holidays', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $this->actingAs($customer)
        ->post(route('admin.holidays.store'), ['date' => '2026-03-26', 'name' => 'Independence Day'])
        ->assertForbidden();
});

test('an admin can update sla policies and the change is picked up without code changes', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    $this->travelTo(CarbonImmutable::parse('2026-01-04 09:00:00', 'Asia/Dhaka'));

    $before = app(SlaService::class)->calculate(TicketPriority::Critical, CarbonImmutable::now());
    expect($before->dueAt->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('11:00');

    $this->actingAs($admin)
        ->put(route('admin.sla-policies.update'), ['policies' => slaPolicyRows(180)])
        ->assertRedirect(route('admin.sla-policies.index'));

    $this->assertDatabaseHas('sla_policies', [
        'priority' => TicketPriority::Critical->value,
        'resolution_minutes' => 180,
    ]);

    $after = app(SlaService::class)->calculate(TicketPriority::Critical, CarbonImmutable::now());
    expect($after->dueAt->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('12:00');
});

test('an admin can update business hours', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    $rows = collect(range(0, 6))->map(fn (int $day) => [
        'day_of_week' => $day,
        'is_working_day' => in_array($day, [1, 2, 3, 4, 5], true),
        'start_time' => in_array($day, [1, 2, 3, 4, 5], true) ? '08:00' : null,
        'end_time' => in_array($day, [1, 2, 3, 4, 5], true) ? '17:00' : null,
    ])->values()->all();

    $this->actingAs($admin)
        ->put(route('admin.business-hours.update'), ['hours' => $rows])
        ->assertRedirect(route('admin.business-hours.index'));

    $this->assertDatabaseHas('business_hours', [
        'day_of_week' => 1,
        'is_working_day' => true,
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
    ]);

    $this->assertDatabaseHas('business_hours', [
        'day_of_week' => 0,
        'is_working_day' => false,
    ]);
});

test('an admin can add and remove a holiday', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    $this->actingAs($admin)
        ->post(route('admin.holidays.store'), ['date' => '2026-03-26', 'name' => 'Independence Day'])
        ->assertRedirect(route('admin.business-hours.index'));

    $this->assertDatabaseHas('holidays', ['date' => '2026-03-26', 'name' => 'Independence Day']);

    $holiday = Holiday::where('date', '2026-03-26')->firstOrFail();

    $this->actingAs($admin)
        ->delete(route('admin.holidays.destroy', $holiday))
        ->assertRedirect(route('admin.business-hours.index'));

    $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);
});
