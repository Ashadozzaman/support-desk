<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Models\Attachment;
use App\Models\Ticket;
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

test('the ticket owner can download an attachment on a public reply', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    $this->actingAs($customer)->post(route('tickets.replies.store', $ticket->ticket_number), [
        'body' => 'See attached',
        'attachments' => [UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')],
    ]);

    $attachment = Attachment::where('ticket_id', $ticket->id)->firstOrFail();

    $this->actingAs($customer)
        ->get(route('attachments.download', $attachment->id))
        ->assertOk();
});

test('a stranger cannot download another customer\'s attachment', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

    $this->actingAs($customer)->post(route('tickets.replies.store', $ticket->ticket_number), [
        'body' => 'See attached',
        'attachments' => [UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')],
    ]);

    $attachment = Attachment::where('ticket_id', $ticket->id)->firstOrFail();

    $stranger = User::factory()->create();
    $stranger->assignRole(RoleEnum::Customer->value);

    $this->actingAs($stranger)
        ->get(route('attachments.download', $attachment->id))
        ->assertForbidden();
});

test('a customer cannot download an attachment on an internal note', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $ticket = Ticket::factory()->create(['created_by' => $customer->id, 'assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)->post(route('tickets.replies.store', $ticket->ticket_number), [
        'body' => 'Internal attachment',
        'type' => 'internal_note',
        'attachments' => [UploadedFile::fake()->create('internal.pdf', 10, 'application/pdf')],
    ]);

    $attachment = Attachment::where('ticket_id', $ticket->id)->firstOrFail();

    $this->actingAs($customer)
        ->get(route('attachments.download', $attachment->id))
        ->assertForbidden();
});

test('a non-existent attachment returns 404', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    $this->actingAs($admin)
        ->get(route('attachments.download', 999_999))
        ->assertNotFound();
});
