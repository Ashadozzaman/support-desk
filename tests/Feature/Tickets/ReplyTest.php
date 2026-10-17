<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

test('a customer can add a public reply to their own ticket', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Any update?'])
        ->assertRedirect(route('tickets.show', $ticket->ticket_number));

    expect(TicketReply::where('ticket_id', $ticket->id)->where('type', 'reply')->exists())->toBeTrue();
});

test('an agent can add an internal note to an assigned ticket', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket->ticket_number), [
            'body' => 'Escalating to L2.',
            'type' => 'internal_note',
        ])
        ->assertRedirect(route('tickets.show', $ticket->ticket_number));

    expect(TicketReply::where('ticket_id', $ticket->id)->where('type', 'internal_note')->exists())->toBeTrue();
});

test('a customer cannot add an internal note', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), [
            'body' => 'Trying to sneak a note in.',
            'type' => 'internal_note',
        ])
        ->assertForbidden();

    expect(TicketReply::where('ticket_id', $ticket->id)->exists())->toBeFalse();
});

test('a reply requires a body', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), [])
        ->assertInvalid(['body']);
});

test('an attachment with a disallowed type is rejected', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    $file = UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload');

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), [
            'body' => 'See attached',
            'attachments' => [$file],
        ])
        ->assertInvalid(['attachments.0']);

    expect(TicketReply::where('ticket_id', $ticket->id)->exists())->toBeFalse();
});

test('an attachment exceeding the configured max size is rejected', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    $oversizeKb = (int) config('support.uploads.max_kb') + 100;
    $file = UploadedFile::fake()->create('large.pdf', $oversizeKb, 'application/pdf');

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), [
            'body' => 'See attached',
            'attachments' => [$file],
        ])
        ->assertInvalid(['attachments.0']);
});

test('a customer never sees an internal note, its activity, or its attachments', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id, 'assigned_agent_id' => $agent->id]);

    $file = UploadedFile::fake()->create('internal.pdf', 10, 'application/pdf');

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket->ticket_number), [
            'body' => 'Escalating internally, see attached log.',
            'type' => 'internal_note',
            'attachments' => [$file],
        ])
        ->assertRedirect();

    $version = hash_file('xxh128', public_path('build/manifest.json'));

    $response = $this->actingAs($customer)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->get(route('tickets.show', $ticket->ticket_number))
        ->assertOk();

    $page = json_decode($response->getContent(), true);

    expect($page['props']['replies']['data'])->toBeEmpty();

    $events = collect($page['props']['activities']['data'])->pluck('event');
    expect($events)->not->toContain('note.added');
    expect($events)->not->toContain('attachment.added');
});

test('a customer reply on a resolved ticket reopens it', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create([
        'created_by' => $customer->id,
        'status' => TicketStatus::Resolved->value,
        'resolved_at' => now(),
    ]);

    $this->actingAs($customer)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Still broken.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->resolved_at)->toBeNull();
});

test('a staff reply on a resolved ticket does not reopen it', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create([
        'assigned_agent_id' => $agent->id,
        'status' => TicketStatus::Resolved->value,
        'resolved_at' => now(),
    ]);

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Closing this out soon.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
});

test('the first staff reply sets first_response_at and every reply updates last_reply_at', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => $agent->id, 'first_response_at' => null]);

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'On it.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->first_response_at)->not->toBeNull();
    expect($ticket->last_reply_at)->not->toBeNull();

    $firstResponseAt = $ticket->first_response_at;

    $this->travel(1)->minutes();

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket->ticket_number), ['body' => 'Following up.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->first_response_at->equalTo($firstResponseAt))->toBeTrue();
});
