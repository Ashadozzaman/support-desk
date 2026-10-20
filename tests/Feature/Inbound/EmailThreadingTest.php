<?php

declare(strict_types=1);

use App\DTOs\CreateReplyData;
use App\Enums\InboundMessageStatus;
use App\Enums\MailboxEncryption;
use App\Enums\Role as RoleEnum;
use App\Models\InboundMessage;
use App\Models\MailboxSetting;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\ReplyReceived;
use App\Notifications\TicketCreated;
use App\Notifications\TicketResolved;
use App\Services\InboundMessageService;
use App\Services\MailboxSettingsService;
use App\Services\ReplyService;
use App\Support\Inbound\EmailMessageParser;
use App\Support\Inbound\TicketMessageId;
use Database\Seeders\BusinessHourSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, SlaPolicySeeder::class, BusinessHourSeeder::class]);
    Storage::fake('local');
    config(['mail.from.address' => 'noreply@support.test']);

    MailboxSetting::create([
        'is_enabled' => true, 'host' => 'imap.gmail.com', 'port' => 993, 'encryption' => MailboxEncryption::Ssl,
        'username' => 'help@support.test', 'password' => 'secret', 'folder' => 'INBOX',
        'fetch_interval_minutes' => 2, 'batch_size' => 25,
    ]);

    $this->customer = User::factory()->create(['email' => 'jane@example.com']);
    $this->customer->assignRole(RoleEnum::Customer->value);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleEnum::Admin->value);
});

/** The headers a MailMessage would be sent with. */
function sentHeaders(MailMessage $mail): Email
{
    $email = new Email;
    foreach ($mail->callbacks as $callback) {
        $callback($email);
    }

    return $email;
}

/** Parse a raw email exactly as the mailbox job does, then receive it. */
function receiveRawEmail(string $raw): ?InboundMessage
{
    return app(InboundMessageService::class)->receive(
        app(EmailMessageParser::class)->parse($raw, app(MailboxSettingsService::class)->ownAddresses()),
    );
}

test('mail to the customer carries the conversation headers and remembers its Message-ID', function () {
    // No real confirmation mail: this test builds that one mail itself.
    Notification::fake();
    receiveRawEmail(rawEmail(['Message-ID' => '<first@mail.example.com>']));
    $ticket = Ticket::sole();

    $mail = (new TicketCreated($ticket))->toMail($this->customer);
    $headers = sentHeaders($mail)->getHeaders();
    $messageId = $headers->get('Message-ID')->getBodyAsString();

    expect($mail->subject)->toStartWith("[{$ticket->ticket_number}] ")
        ->and($mail->replyTo[0][0])->toBe('help@support.test')
        ->and($headers->get('In-Reply-To')->getBodyAsString())->toBe('<first@mail.example.com>')
        ->and($headers->get('References')->getBodyAsString())->toBe('<first@mail.example.com>')
        ->and($headers->get('Auto-Submitted')->getBodyAsString())->toBe('auto-generated')
        ->and(TicketMessageId::isOurs($messageId))->toBeTrue()
        ->and(DB::table('ticket_channel_threads')->where('thread_key', trim($messageId, '<>'))->value('ticket_id'))->toBe($ticket->id);
});

test('mail to staff is not threaded', function () {
    $ticket = Ticket::factory()->create(['created_by' => $this->customer->id]);

    $mail = (new TicketCreated($ticket))->toMail($this->admin);

    expect($mail->subject)->not->toContain('[')
        ->and($mail->replyTo)->toBe([])
        ->and($mail->callbacks)->toBe([])
        ->and(DB::table('ticket_channel_threads')->count())->toBe(0);
});

test('the customer pressing Reply on our email lands on the same ticket', function () {
    receiveRawEmail(rawEmail(['Message-ID' => '<first@mail.example.com>']));
    $ticket = Ticket::sole();
    $ourId = sentHeaders((new TicketResolved($ticket))->toMail($this->customer))->getHeaders()->get('Message-ID')->getBodyAsString();

    $message = receiveRawEmail(rawEmail([
        'In-Reply-To' => $ourId,
        'References' => "<first@mail.example.com> {$ourId}",
        'Subject' => "Re: [{$ticket->ticket_number}] Ticket resolved",
    ], 'Still not working, sorry.'));

    expect($message->status)->toBe(InboundMessageStatus::ReplyAdded)
        ->and(Ticket::count())->toBe(1)
        ->and(TicketReply::sole()->body)->toBe('Still not working, sorry.');
});

test('the customer gets the whole agent reply, staff a preview', function () {
    $ticket = Ticket::factory()->create(['created_by' => $this->customer->id]);
    $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->admin->id, 'body' => str_repeat('Long answer. ', 40)]);

    $toCustomer = implode(' ', (new ReplyReceived($ticket, $reply))->toMail($this->customer)->introLines);
    $toStaff = implode(' ', (new ReplyReceived($ticket, $reply))->toMail($this->admin)->introLines);

    expect($toCustomer)->toContain(trim($reply->body))
        ->and(mb_strlen($toStaff))->toBeLessThan(mb_strlen($toCustomer));
});

test('auto-replies, bounces, bulk mail and our own mail are skipped', function (array $headers, string $reason) {
    $message = receiveRawEmail(rawEmail($headers));

    expect($message->status)->toBe(InboundMessageStatus::Skipped)
        ->and($message->skip_reason)->toBe($reason)
        ->and(Ticket::count())->toBe(0)
        ->and(User::where('email', 'like', '%@daemon.test')->exists())->toBeFalse();
})->with([
    'Auto-Submitted: auto-replied' => [['Auto-Submitted' => 'auto-replied'], 'auto_reply'],
    'X-Autoreply' => [['X-Autoreply' => 'yes'], 'auto_reply'],
    'Precedence: bulk' => [['Precedence' => 'bulk'], 'bulk_mail'],
    'mailing list' => [['List-Id' => '<news.example.com>'], 'bulk_mail'],
    'bounce' => [['From' => 'MAILER-DAEMON@daemon.test'], 'bounce'],
    'from our From address' => [['From' => 'noreply@support.test'], 'own_mail'],
    'from the mailbox itself' => [['From' => 'Help <HELP@support.test>'], 'own_mail'],
    'our own Message-ID' => [['Message-ID' => '<ticket.TKT-2026-000001.abc@support.test>'], 'own_mail'],
]);

test('Auto-Submitted: no is a normal message', function () {
    expect(receiveRawEmail(rawEmail(['Auto-Submitted' => 'no']))->status)->toBe(InboundMessageStatus::TicketCreated);
});

test('mail from a staff account never opens a ticket', function () {
    $agent = User::factory()->create(['email' => 'agent@example.com']);
    $agent->assignRole(RoleEnum::Agent->value);

    $message = receiveRawEmail(rawEmail(['From' => 'agent@example.com']));

    expect($message->skip_reason)->toBe('staff_sender')->and(Ticket::count())->toBe(0);
});

test('more than the hourly limit from one sender is skipped', function () {
    config(['support.inbound.max_per_sender_per_hour' => 3]);

    foreach (range(1, 4) as $i) {
        $last = receiveRawEmail(rawEmail());
    }

    expect(Ticket::count())->toBe(3)->and($last->skip_reason)->toBe('rate_limited');
});

test('an internal note is never emailed to the customer', function () {
    Notification::fake();
    $ticket = Ticket::factory()->create(['created_by' => $this->customer->id, 'assigned_agent_id' => $this->admin->id]);

    app(ReplyService::class)->addInternalNote($ticket, new CreateReplyData('Customer is a reseller'), $this->admin);

    Notification::assertNothingSentTo($this->customer);
    expect(DB::table('ticket_channel_threads')->count())->toBe(0);
});
