<?php

declare(strict_types=1);

use App\Enums\ReplyType;
use App\Enums\Role as RoleEnum;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// CLAUDE.md rule 6: internal notes must never reach customers — the API is
// one more surface, so it gets its own secrecy tests.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');

    $this->customer = User::factory()->create();
    $this->customer->assignRole(RoleEnum::Customer->value);
    $this->agent = User::factory()->create();
    $this->agent->assignRole(RoleEnum::Agent->value);

    $this->ticket = Ticket::factory()->create([
        'created_by' => $this->customer->id,
        'assigned_agent_id' => $this->agent->id,
    ]);

    TicketReply::factory()->create([
        'ticket_id' => $this->ticket->id, 'user_id' => $this->agent->id,
        'type' => ReplyType::Reply->value, 'body' => 'Public answer',
    ]);
    $this->note = TicketReply::factory()->create([
        'ticket_id' => $this->ticket->id, 'user_id' => $this->agent->id,
        'type' => ReplyType::InternalNote->value, 'body' => 'SECRET staff note',
    ]);
});

test('a customer never receives internal notes from the replies endpoint', function () {
    Sanctum::actingAs($this->customer);

    $response = $this->getJson(route('api.v1.tickets.replies.index', $this->ticket->ticket_number))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'reply');

    expect($response->getContent())->not->toContain('SECRET staff note');
});

test('staff see both replies and internal notes', function () {
    Sanctum::actingAs($this->agent);

    $this->getJson(route('api.v1.tickets.replies.index', $this->ticket->ticket_number))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a customer cannot post an internal note', function () {
    Sanctum::actingAs($this->customer);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket->ticket_number), [
        'body' => 'sneaky', 'type' => 'internal_note',
    ])->assertForbidden();

    expect(TicketReply::where('body', 'sneaky')->exists())->toBeFalse();
});

test('a customer reply is created with 201 and attachment links point at the API', function () {
    Sanctum::actingAs($this->customer);

    $this->post(route('api.v1.tickets.replies.store', $this->ticket->ticket_number), [
        'body' => 'Here is the log',
        'attachments' => [UploadedFile::fake()->create('log.txt', 2, 'text/plain')],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'reply')
        ->assertJsonPath('data.attachments.0.original_name', 'log.txt')
        ->assertJsonPath('data.attachments.0.download_url', fn (string $url) => str_contains($url, '/api/v1/attachments/'));
});

test('a customer cannot download an internal-note attachment through the API', function () {
    $attachment = Attachment::factory()->create([
        'ticket_id' => $this->ticket->id,
        'ticket_reply_id' => $this->note->id,
        'uploaded_by' => $this->agent->id,
    ]);
    Storage::disk('local')->put($attachment->disk_path, 'secret');

    Sanctum::actingAs($this->customer);
    $this->get(route('api.v1.attachments.download', $attachment->id))->assertForbidden();

    Sanctum::actingAs($this->agent);
    $this->get(route('api.v1.attachments.download', $attachment->id))->assertOk();
});
