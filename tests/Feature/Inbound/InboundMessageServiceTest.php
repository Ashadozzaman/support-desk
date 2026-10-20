<?php

declare(strict_types=1);

use App\DTOs\InboundAttachmentData;
use App\DTOs\InboundMessageData;
use App\Enums\ActivityEvent;
use App\Enums\InboundChannel;
use App\Enums\InboundMessageStatus;
use App\Enums\ReplyType;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Jobs\AutoAssignTicketJob;
use App\Models\Attachment;
use App\Models\InboundMessage;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\InboundMessageService;
use App\Services\ReplyService;
use Carbon\CarbonImmutable;
use Database\Seeders\BusinessHourSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, SlaPolicySeeder::class, BusinessHourSeeder::class]);
    Storage::fake('local');
    Queue::fake();
    Notification::fake();

    $this->customer = User::factory()->create(['email' => 'jane@example.com']);
    $this->customer->assignRole(RoleEnum::Customer->value);
});

/**
 * An email as the IMAP adapter (Step 25b) will hand it over.
 *
 * @param  array<string, mixed>  $overrides
 */
function inboundEmail(array $overrides = []): InboundMessageData
{
    return new InboundMessageData(...array_merge([
        'channel' => InboundChannel::Email,
        'externalMessageId' => '<'.Str::uuid().'@mail.example.com>',
        'senderAddress' => 'jane@example.com',
        'senderName' => 'Jane Doe',
        'subject' => 'Cannot log in',
        'body' => 'My password reset link never arrives.',
        'receivedAt' => CarbonImmutable::now(),
    ], $overrides));
}

function receiveInbound(InboundMessageData $data): ?InboundMessage
{
    return app(InboundMessageService::class)->receive($data);
}

test('a new email creates a ticket through TicketService', function () {
    $message = receiveInbound(inboundEmail(['externalMessageId' => '<first@mail.example.com>']));

    $ticket = Ticket::sole();
    expect($ticket->created_by)->toBe($this->customer->id)
        ->and($ticket->ticket_number)->toMatch('/^TKT-\d{4}-\d{6}$/')
        ->and($ticket->subject)->toBe('Cannot log in')
        ->and($ticket->description)->toBe('My password reset link never arrives.')
        ->and($ticket->source)->toBe(TicketSource::Email)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->sla_due_at)->not->toBeNull()
        ->and($message->status)->toBe(InboundMessageStatus::TicketCreated)
        ->and($message->ticket_id)->toBe($ticket->id)
        ->and($message->external_message_id)->toBe('first@mail.example.com');

    expect(TicketActivity::where('ticket_id', $ticket->id)->where('event', ActivityEvent::TicketCreated)->count())->toBe(1);
    Queue::assertPushed(AutoAssignTicketJob::class, fn (AutoAssignTicketJob $job) => $job->ticketId === $ticket->id);
});

test('an unknown sender gets a customer account without any email being sent', function () {
    receiveInbound(inboundEmail(['senderAddress' => 'New.Person@Example.com', 'senderName' => 'New Person']));

    $user = User::where('email', 'new.person@example.com')->sole();
    expect($user->hasRole(RoleEnum::Customer->value))->toBeTrue()
        ->and($user->name)->toBe('New Person')
        ->and($user->email_verified_at)->toBeNull()
        ->and(Ticket::sole()->created_by)->toBe($user->id);

    Notification::assertNothingSentTo($user, ResetPassword::class);
});

test('a reply by In-Reply-To is added to the same ticket as a public reply', function () {
    receiveInbound(inboundEmail(['externalMessageId' => '<first@mail.example.com>']));

    $message = receiveInbound(inboundEmail([
        'subject' => 'Re: Cannot log in',
        'body' => 'Still broken.',
        'threadKeys' => ['<first@mail.example.com>'],
    ]));

    $ticket = Ticket::sole();
    $reply = TicketReply::sole();
    expect($reply->ticket_id)->toBe($ticket->id)
        ->and($reply->type)->toBe(ReplyType::Reply)
        ->and($reply->user_id)->toBe($this->customer->id)
        ->and($reply->body)->toBe('Still broken.')
        ->and($message->status)->toBe(InboundMessageStatus::ReplyAdded)
        ->and($message->ticket_reply_id)->toBe($reply->id);
});

test('a reply found only through References still threads, and a later reply to that reply too', function () {
    receiveInbound(inboundEmail(['externalMessageId' => '<first@mail.example.com>']));
    receiveInbound(inboundEmail([
        'externalMessageId' => '<second@mail.example.com>',
        'threadKeys' => ['<unknown@elsewhere>', '<first@mail.example.com>'],
    ]));
    receiveInbound(inboundEmail(['threadKeys' => ['<second@mail.example.com>']]));

    expect(Ticket::count())->toBe(1)->and(TicketReply::count())->toBe(2);
});

test('a ticket number in the subject threads only for the ticket owner', function () {
    $ticket = Ticket::factory()->create(['created_by' => $this->customer->id]);

    receiveInbound(inboundEmail(['ticketNumberHint' => strtolower($ticket->ticket_number)]));

    expect(Ticket::count())->toBe(1)
        ->and(TicketReply::where('ticket_id', $ticket->id)->count())->toBe(1);
});

test('a stranger quoting someone else\'s ticket number or message id gets a new ticket of their own', function () {
    receiveInbound(inboundEmail(['externalMessageId' => '<first@mail.example.com>']));
    $original = Ticket::sole();

    receiveInbound(inboundEmail([
        'senderAddress' => 'mallory@example.com',
        'threadKeys' => ['<first@mail.example.com>'],
        'ticketNumberHint' => $original->ticket_number,
    ]));

    $mallory = User::where('email', 'mallory@example.com')->sole();
    expect(TicketReply::count())->toBe(0)
        ->and(Ticket::count())->toBe(2)
        ->and(Ticket::where('created_by', $mallory->id)->count())->toBe(1);
});

test('the same message received twice creates one ticket', function () {
    $data = inboundEmail(['externalMessageId' => '<dup@mail.example.com>']);

    expect(receiveInbound($data))->not->toBeNull()
        ->and(receiveInbound($data))->toBeNull()
        // Same id without angle brackets is still the same message.
        ->and(receiveInbound(inboundEmail(['externalMessageId' => 'dup@mail.example.com'])))->toBeNull();

    expect(Ticket::count())->toBe(1)->and(InboundMessage::count())->toBe(1);
});

test('a failure rolls everything back so the retry processes the message once', function () {
    $data = inboundEmail([
        'externalMessageId' => '<retry@mail.example.com>',
        'attachments' => [new InboundAttachmentData('notes.txt', 'some notes')],
    ]);

    $this->partialMock(AttachmentService::class)
        ->shouldReceive('storeContents')->once()->andThrow(new RuntimeException('disk full'));

    expect(fn () => receiveInbound($data))->toThrow(RuntimeException::class);
    expect(Ticket::count())->toBe(0)->and(InboundMessage::count())->toBe(0);

    $this->app->forgetInstance(AttachmentService::class);
    $this->app->offsetUnset(AttachmentService::class);

    receiveInbound($data);

    expect(Ticket::count())->toBe(1)->and(Attachment::count())->toBe(1)->and(InboundMessage::count())->toBe(1);
});

test('a customer reply to a resolved ticket reopens it', function () {
    $ticket = Ticket::factory()->create(['created_by' => $this->customer->id, 'status' => TicketStatus::Resolved]);

    receiveInbound(inboundEmail(['ticketNumberHint' => $ticket->ticket_number]));

    expect($ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and(TicketActivity::where('ticket_id', $ticket->id)->where('event', ActivityEvent::TicketReopened)->exists())->toBeTrue();
});

test('a reply to a closed ticket starts a new ticket', function () {
    $closed = Ticket::factory()->create(['created_by' => $this->customer->id, 'status' => TicketStatus::Closed]);

    receiveInbound(inboundEmail(['ticketNumberHint' => $closed->ticket_number]));

    expect(TicketReply::count())->toBe(0)
        ->and($closed->fresh()->status)->toBe(TicketStatus::Closed)
        ->and(Ticket::where('id', '!=', $closed->id)->where('created_by', $this->customer->id)->count())->toBe(1);
});

test('allowed attachments are stored privately and refused ones are recorded without losing the email', function () {
    config(['support.uploads.max_files' => 2]);

    $message = receiveInbound(inboundEmail(['attachments' => [
        new InboundAttachmentData('invoice.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"),
        new InboundAttachmentData('virus.exe', "MZ\x90\x00\x03\x00\x00\x00"),
        new InboundAttachmentData('renamed.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff"),
        new InboundAttachmentData('notes.txt', 'plain text notes'),
        new InboundAttachmentData('third.txt', 'one too many'),
    ]]));

    $ticket = Ticket::sole();
    $stored = Attachment::where('ticket_id', $ticket->id)->get();

    expect($stored->pluck('original_name')->all())->toEqualCanonicalizing(['invoice.pdf', 'notes.txt'])
        ->and($stored->firstWhere('original_name', 'invoice.pdf')->mime_type)->toBe('application/pdf')
        ->and(collect($message->rejected_attachments)->pluck('name')->all())->toBe(['virus.exe', 'renamed.pdf', 'third.txt']);

    foreach ($stored as $attachment) {
        Storage::disk('local')->assertExists($attachment->disk_path);
        expect($attachment->disk_path)->toStartWith("tickets/{$ticket->id}/");
    }

    expect(TicketActivity::where('ticket_id', $ticket->id)->where('event', ActivityEvent::AttachmentAdded)->sole()->new_values)
        ->toMatchArray(['count' => 2]);
});

test('inbound text never becomes an internal note and the customer can see it', function () {
    receiveInbound(inboundEmail(['externalMessageId' => '<first@mail.example.com>']));
    receiveInbound(inboundEmail(['threadKeys' => ['<first@mail.example.com>'], 'body' => 'Follow-up']));

    expect(TicketReply::where('type', ReplyType::InternalNote)->count())->toBe(0);

    $thread = app(ReplyService::class)->threadFor(Ticket::sole(), $this->customer);
    expect($thread->pluck('body')->all())->toContain('Follow-up');
});

test('inactive or invalid senders are skipped and recorded', function () {
    User::factory()->create(['email' => 'gone@example.com', 'is_active' => false])->assignRole(RoleEnum::Customer->value);

    $inactive = receiveInbound(inboundEmail(['senderAddress' => 'gone@example.com']));
    $invalid = receiveInbound(inboundEmail(['senderAddress' => 'not-an-address']));

    expect(Ticket::count())->toBe(0)
        ->and($inactive->status)->toBe(InboundMessageStatus::Skipped)
        ->and($inactive->skip_reason)->toBe('sender_inactive')
        ->and($invalid->skip_reason)->toBe('invalid_sender');
});

test('empty and oversized text is normalised to fit the columns', function () {
    receiveInbound(inboundEmail(['subject' => '   ', 'body' => '']));
    receiveInbound(inboundEmail(['subject' => str_repeat('a', 300), 'body' => str_repeat('é', 40000)]));

    [$empty, $long] = Ticket::orderBy('id')->get()->all();
    expect($empty->subject)->toBe('(no subject)')
        ->and($empty->description)->toBe('(no message text)')
        ->and(mb_strlen($long->subject))->toBe(200)
        ->and(strlen($long->description))->toBeLessThanOrEqual(65000);
});
