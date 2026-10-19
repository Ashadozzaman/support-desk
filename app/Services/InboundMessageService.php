<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\InboundMessageRepositoryInterface;
use App\Contracts\Repositories\TicketRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\DTOs\CreateReplyData;
use App\DTOs\CreateTicketData;
use App\DTOs\InboundMessageData;
use App\Enums\ActivityEvent;
use App\Enums\InboundMessageStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\InboundMessage;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns a message from any channel into a ticket or a public reply
 * (docs/IMAP.md §1). Not a second ticket system: everything goes through
 * TicketService / ReplyService, so SLA, numbering, activity, auto-assign,
 * notifications and broadcasts behave exactly as for a web ticket.
 */
class InboundMessageService
{
    /** `ticket_replies.body` / `tickets.description` are TEXT: 64 KB. */
    private const MAX_BODY_BYTES = 65000;

    public function __construct(
        private readonly InboundMessageRepositoryInterface $inbound,
        private readonly TicketRepositoryInterface $tickets,
        private readonly UserRepositoryInterface $users,
        private readonly TicketService $ticketService,
        private readonly ReplyService $replies,
        private readonly AttachmentService $attachments,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * One transaction: the dedupe row, the ticket or reply, its attachments
     * and the thread key commit together — so a crash halfway leaves nothing,
     * and the retry processes the message from scratch.
     *
     * @return InboundMessage|null null when this message was already received
     */
    public function receive(InboundMessageData $data): ?InboundMessage
    {
        $externalId = $this->normaliseKey($data->externalMessageId);

        if ($externalId === '') {
            throw new InvalidArgumentException('An inbound message needs an external message id.');
        }

        return DB::transaction(function () use ($data, $externalId) {
            $message = $this->inbound->createIfNew($data, $externalId, $this->subjectFor($data));

            if ($message === null) {
                return null;
            }

            [$sender, $skipReason] = $data->skipReason !== null
                ? [null, $data->skipReason]
                : $this->resolveSender($data);

            if ($sender === null) {
                $this->inbound->recordOutcome($message, [
                    'status' => InboundMessageStatus::Skipped,
                    'skip_reason' => $skipReason,
                ]);

                return $message;
            }

            $ticket = $this->existingConversation($data, $sender);
            $reply = null;

            if ($ticket === null) {
                $ticket = $this->ticketService->create(new CreateTicketData(
                    subject: $this->subjectFor($data),
                    description: $this->bodyFor($data),
                    // No triage yet: an agent sets priority and category after reading it.
                    priority: TicketPriority::Medium,
                    categoryId: null,
                    createdBy: $sender->id,
                    source: $data->channel->ticketSource(),
                ), $sender);
            } else {
                // Always a public reply — inbound text is never an internal note (CLAUDE.md rule 6).
                $reply = $this->replies->addReply($ticket, new CreateReplyData($this->bodyFor($data)), $sender);
            }

            $rejected = $this->storeAttachments($data, $ticket, $reply, $sender);

            $this->inbound->rememberThreadKey($data->channel, $externalId, $ticket);
            $this->inbound->recordOutcome($message, [
                'status' => $reply === null ? InboundMessageStatus::TicketCreated : InboundMessageStatus::ReplyAdded,
                'ticket_id' => $ticket->id,
                'ticket_reply_id' => $reply?->id,
                'rejected_attachments' => $rejected === [] ? null : $rejected,
            ]);

            return $message;
        }, attempts: 3);
    }

    /**
     * The sender's account, created as a customer on first contact
     * (docs/IMAP.md §6). No email is sent to a new account: the From address
     * can be forged, so mailing it a link would let anyone make us email a
     * stranger. They can use "Forgot password" when they want to sign in.
     *
     * @return array{0: User|null, 1: string|null} [sender, skip reason]
     */
    private function resolveSender(InboundMessageData $data): array
    {
        $email = mb_strtolower(trim($data->senderAddress));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [null, 'invalid_sender'];
        }

        // Before any account is created: a flood (e.g. an auto-responder answering our own
        // confirmations without an Auto-Submitted header) stops here.
        $recent = $this->inbound->countFromSenderSince($data->channel, $email, now()->subHour());
        if ($recent > config('support.inbound.max_per_sender_per_hour')) {
            return [null, 'rate_limited'];
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            $user = $this->users->create([
                'name' => $this->senderNameFor($data, $email),
                'email' => $email,
                'password' => Str::password(32),
                'is_active' => true,
            ]);
            // Assigning the built-in role, like registration does — not authorizing by it.
            $this->users->syncRole($user, Role::Customer->value);
        }

        if (! $user->is_active) {
            return [null, 'sender_inactive'];
        }

        if (! $user->can(Permission::TicketsCreate->value)) {
            return [null, 'sender_not_allowed'];
        }

        // Staff work tickets in the app. A staff address writing in is almost always a reply
        // to one of their own notifications — importing it would open a ticket in their name.
        if ($user->can(Permission::TicketsViewAny->value) || $user->can(Permission::TicketsViewQueue->value)) {
            return [null, 'staff_sender'];
        }

        return [$user, null];
    }

    /**
     * The open ticket this message continues, or null for a new one. Headers
     * first, then a ticket number from the subject; never the subject text.
     * Both are typed by the sender, so a match counts only on the sender's
     * own ticket — quoting someone else's ticket number gets you a new
     * ticket, not a reply on theirs. A closed ticket stays closed (no
     * outgoing transition): the message starts a new one.
     */
    private function existingConversation(InboundMessageData $data, User $sender): ?Ticket
    {
        $keys = array_values(array_filter(array_map($this->normaliseKey(...), $data->threadKeys)));
        $ticket = $this->inbound->ticketForThreadKeys($data->channel, $keys);

        if ($ticket === null && $data->ticketNumberHint !== null) {
            $ticket = $this->tickets->findByNumber(strtoupper(trim($data->ticketNumberHint)));
        }

        if ($ticket === null || $ticket->created_by !== $sender->id || $ticket->status === TicketStatus::Closed) {
            return null;
        }

        return $ticket;
    }

    /**
     * Each file goes through the web upload's rules. A refused file is
     * dropped and recorded on the inbound message — one bad attachment
     * never loses the customer's email.
     *
     * @return list<array{name: string, reason: string}>
     */
    private function storeAttachments(InboundMessageData $data, Ticket $ticket, ?TicketReply $reply, User $sender): array
    {
        $rejected = [];
        $stored = 0;
        $maxFiles = config('support.uploads.max_files');

        foreach ($data->attachments as $file) {
            $name = mb_substr($file->filename, 0, 255);

            if ($stored >= $maxFiles) {
                $rejected[] = ['name' => $name, 'reason' => "More than {$maxFiles} attachments."];

                continue;
            }

            try {
                $this->attachments->storeContents($ticket, $reply, $sender, $file->filename, $file->contents);
                $stored++;
            } catch (ValidationException $e) {
                $rejected[] = ['name' => $name, 'reason' => (string) collect($e->errors())->flatten()->first()];
            }
        }

        if ($stored > 0) {
            $this->activity->log(
                $ticket,
                ActivityEvent::AttachmentAdded,
                $sender,
                [],
                ['reply_id' => $reply?->id, 'count' => $stored],
            );
        }

        return $rejected;
    }

    /**
     * Message ids as stored: no angle brackets; ids longer than the column
     * are hashed (a hash still dedupes and threads, a cut-off id might not).
     */
    private function normaliseKey(string $key): string
    {
        $key = trim($key, " \t\n\r\0\x0B<>");

        return strlen($key) > 255 ? 'sha256:'.hash('sha256', $key) : $key;
    }

    private function subjectFor(InboundMessageData $data): string
    {
        $subject = mb_substr(Str::squish($data->subject), 0, 200);

        return $subject === '' ? '(no subject)' : $subject;
    }

    private function bodyFor(InboundMessageData $data): string
    {
        $body = trim($data->body);

        return $body === '' ? '(no message text)' : mb_strcut($body, 0, self::MAX_BODY_BYTES);
    }

    private function senderNameFor(InboundMessageData $data, string $email): string
    {
        $name = Str::squish((string) $data->senderName);

        return mb_substr($name !== '' ? $name : Str::before($email, '@'), 0, 255);
    }
}
