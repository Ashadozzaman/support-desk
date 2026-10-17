<?php

declare(strict_types=1);

use App\Enums\ActivityEvent;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('creating a ticket writes exactly one ticket.created activity', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $this->actingAs($customer)->post(route('tickets.store'), [
        'subject' => 'Cannot log in',
        'description' => 'Error on login.',
        'priority' => TicketPriority::High->value,
        'category_id' => null,
    ]);

    $ticket = Ticket::where('created_by', $customer->id)->firstOrFail();

    expect($ticket->activities)->toHaveCount(1);
    expect($ticket->activities->first()->event)->toBe(ActivityEvent::TicketCreated);
    expect($ticket->activities->first()->actor_id)->toBe($customer->id);
});

test('changing priority writes exactly one priority.changed activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Low->value]);

    $this->actingAs($admin)->put(route('tickets.update', $ticket->ticket_number), [
        'subject' => $ticket->subject,
        'description' => $ticket->description,
        'priority' => TicketPriority::Critical->value,
        'category_id' => $ticket->category_id,
    ]);

    $activities = $ticket->activities()->get();

    expect($activities)->toHaveCount(1);
    expect($activities->first()->event)->toBe(ActivityEvent::PriorityChanged);
    expect($activities->first()->old_values)->toBe(['priority' => 'low']);
    expect($activities->first()->new_values)->toBe(['priority' => 'critical']);
});

test('changing category writes exactly one category.changed activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $before = Category::factory()->create();
    $after = Category::factory()->create();
    $ticket = Ticket::factory()->create(['category_id' => $before->id]);

    $this->actingAs($admin)->put(route('tickets.update', $ticket->ticket_number), [
        'subject' => $ticket->subject,
        'description' => $ticket->description,
        'priority' => $ticket->priority->value,
        'category_id' => $after->id,
    ]);

    $activities = $ticket->activities()->get();

    expect($activities)->toHaveCount(1);
    expect($activities->first()->event)->toBe(ActivityEvent::CategoryChanged);
});

test('changing subject and description writes exactly one ticket.updated activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $ticket = Ticket::factory()->create(['subject' => 'Old subject']);

    $this->actingAs($admin)->put(route('tickets.update', $ticket->ticket_number), [
        'subject' => 'New subject',
        'description' => $ticket->description,
        'priority' => $ticket->priority->value,
        'category_id' => $ticket->category_id,
    ]);

    $activities = $ticket->activities()->get();

    expect($activities)->toHaveCount(1);
    expect($activities->first()->event)->toBe(ActivityEvent::TicketUpdated);
    expect($activities->first()->old_values)->toBe(['subject' => 'Old subject']);
    expect($activities->first()->new_values)->toBe(['subject' => 'New subject']);
});

test('a valid status transition writes exactly one status.changed activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value]);

    $this->actingAs($admin)->patch(route('tickets.change-status', $ticket->ticket_number), [
        'status' => TicketStatus::InProgress->value,
    ]);

    $activities = $ticket->activities()->get();

    expect($activities)->toHaveCount(1);
    expect($activities->first()->event)->toBe(ActivityEvent::StatusChanged);
});

test('a rejected status transition leaves no activity behind', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed->value]);

    $this->actingAs($admin)->patch(route('tickets.change-status', $ticket->ticket_number), [
        'status' => TicketStatus::Open->value,
    ])->assertStatus(422);

    expect($ticket->activities()->count())->toBe(0);
});

test('a customer timeline hides staff-only events but an agent sees them', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    TicketActivity::factory()->create([
        'ticket_id' => $ticket->id,
        'event' => ActivityEvent::StatusChanged,
        'old_values' => ['status' => 'open'],
        'new_values' => ['status' => 'in_progress'],
    ]);
    TicketActivity::factory()->create([
        'ticket_id' => $ticket->id,
        'event' => ActivityEvent::NoteAdded,
    ]);
    TicketActivity::factory()->create([
        'ticket_id' => $ticket->id,
        'event' => ActivityEvent::SlaBreached,
    ]);

    $version = hash_file('xxh128', public_path('build/manifest.json'));

    $customerPage = json_decode(
        $this->actingAs($customer)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get(route('tickets.show', $ticket->ticket_number))
            ->getContent(),
        true,
    );
    $agentPage = json_decode(
        $this->actingAs($agent)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get(route('tickets.show', $ticket->ticket_number))
            ->getContent(),
        true,
    );

    expect($customerPage['props']['activities']['data'])->toHaveCount(1);
    expect($agentPage['props']['activities']['data'])->toHaveCount(3);
});
