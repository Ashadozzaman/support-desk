<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
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

    SlaPolicy::factory()->create([
        'priority' => TicketPriority::Critical->value,
        'resolution_minutes' => 120,
        'warning_threshold_percent' => 80,
        'is_active' => true,
    ]);

    SlaPolicy::factory()->create([
        'priority' => TicketPriority::Low->value,
        'resolution_minutes' => 2880,
        'warning_threshold_percent' => 80,
        'is_active' => true,
    ]);
});

test('creating a ticket computes sla_due_at and sla_warning_at from the priority policy', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 17:00:00', 'Asia/Dhaka'));

    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $category = Category::factory()->create();

    $this->actingAs($customer)->post(route('tickets.store'), [
        'subject' => 'Server is down',
        'description' => 'Everything is on fire.',
        'priority' => TicketPriority::Critical->value,
        'category_id' => $category->id,
    ]);

    $ticket = Ticket::where('created_by', $customer->id)->firstOrFail();

    expect($ticket->sla_due_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 10:00');
    // 80% of 120 minutes = 96 minutes -> Thu 17:00+60=18:00 (60 consumed), 36 remain -> Sun 09:00+36=09:36
    expect($ticket->sla_warning_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 09:36');
});

test('changing priority recomputes the sla window from the original created_at, not the change time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 17:00:00', 'Asia/Dhaka'));

    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $category = Category::factory()->create();
    $ticket = Ticket::factory()->create([
        'priority' => TicketPriority::Low->value,
        'assigned_agent_id' => $agent->id,
        'category_id' => $category->id,
        'created_at' => CarbonImmutable::now(),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-01-02 09:00:00', 'Asia/Dhaka'));

    $this->actingAs($agent)->put(route('tickets.update', $ticket->ticket_number), [
        'subject' => $ticket->subject,
        'description' => $ticket->description,
        'priority' => TicketPriority::Critical->value,
        'category_id' => $category->id,
    ]);

    $ticket->refresh();

    // Recomputed from created_at (Thu 17:00), not from the Friday update moment.
    expect($ticket->sla_due_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-01-04 10:00');

    $this->assertDatabaseHas('ticket_activities', [
        'ticket_id' => $ticket->id,
        'event' => 'priority.changed',
    ]);
});
