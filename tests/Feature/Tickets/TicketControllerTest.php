<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a customer can create a ticket and receives a ticket number', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $category = Category::factory()->create();

    $response = $this->actingAs($customer)->post(route('tickets.store'), [
        'subject' => 'Cannot log in',
        'description' => 'I get an error every time I try to log in.',
        'priority' => TicketPriority::High->value,
        'category_id' => $category->id,
    ]);

    $ticket = Ticket::where('created_by', $customer->id)->firstOrFail();

    $response->assertRedirect(route('tickets.show', $ticket->ticket_number));
    expect($ticket->ticket_number)->toMatch('/^TKT-\d{4}-\d{6}$/');
    expect($ticket->status->value)->toBe('open');
});

test('ticket creation validates required fields', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $this->actingAs($customer)
        ->post(route('tickets.store'), [])
        ->assertInvalid(['subject', 'description', 'priority']);
});

test('ticket creation rejects an unknown category', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $this->actingAs($customer)->post(route('tickets.store'), [
        'subject' => 'Subject',
        'description' => 'Description',
        'priority' => TicketPriority::Low->value,
        'category_id' => 999_999,
    ])->assertInvalid(['category_id']);
});

test('a customer cannot view another customer\'s ticket', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $other = User::factory()->create();
    $other->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $other->id]);

    $this->actingAs($customer)
        ->get(route('tickets.show', $ticket->ticket_number))
        ->assertForbidden();
});

test('a customer can update their own open ticket', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id, 'status' => 'open']);
    $category = Category::factory()->create();

    $this->actingAs($customer)->put(route('tickets.update', $ticket->ticket_number), [
        'subject' => 'Updated subject',
        'description' => $ticket->description,
        'priority' => $ticket->priority->value,
        'category_id' => $category->id,
    ])->assertRedirect(route('tickets.show', $ticket->ticket_number));

    expect($ticket->refresh()->subject)->toBe('Updated subject');
});

test('the ticket index tab defaults to active tickets only', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    $active = Ticket::factory()->create(['status' => 'open']);
    $resolved = Ticket::factory()->create(['status' => 'resolved']);

    // The 'tickets/index' React page is only built in Step 5, so this
    // requests the Inertia XHR variant (X-Inertia header) directly rather
    // than a full-page render, which would fail resolving the Vite manifest.
    $version = hash_file('xxh128', public_path('build/manifest.json'));

    $response = $this->actingAs($admin)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->get(route('tickets.index'))
        ->assertOk();

    $page = json_decode($response->getContent(), true);

    expect($page['component'])->toBe('tickets/index');
    expect($page['props']['tab'])->toBe('active');
    expect($page['props']['tickets']['data'])->toHaveCount(1);
    expect($page['props']['tickets']['data'][0]['ticket_number'])->toBe($active->ticket_number);
});
