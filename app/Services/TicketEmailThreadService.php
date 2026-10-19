<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\InboundMessageRepositoryInterface;
use App\Enums\InboundChannel;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Inbound\TicketMessageId;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * Makes a ticket email part of the customer's conversation (docs/IMAP.md
 * §4), so pressing "Reply" in their mail client lands on the same ticket:
 * a Message-ID we remember as a thread key, In-Reply-To/References to the
 * conversation, the ticket number in the subject, Reply-To the mailbox.
 */
class TicketEmailThreadService
{
    /** References grows with every message; the latest few are enough to thread. */
    private const MAX_REFERENCES = 10;

    public function __construct(
        private readonly InboundMessageRepositoryInterface $inbound,
        private readonly MailboxSettingsService $mailbox,
    ) {}

    /**
     * Only mail to the ticket's own customer is threaded: staff answer in the
     * app, and their replies by email are skipped by the importer anyway.
     */
    public function thread(MailMessage $mail, Ticket $ticket, object $recipient): MailMessage
    {
        if (! $recipient instanceof User || $recipient->id !== $ticket->created_by) {
            return $mail;
        }

        $messageId = TicketMessageId::generate($ticket);
        $references = $this->inbound->threadKeysFor(InboundChannel::Email, $ticket, self::MAX_REFERENCES);
        // Remembered before sending: if the send fails the key just points at the ticket, unused.
        $this->inbound->rememberThreadKey(InboundChannel::Email, $messageId, $ticket);

        $tag = "[{$ticket->ticket_number}]";
        $mail->subject(str_contains((string) $mail->subject, $tag) ? (string) $mail->subject : "{$tag} {$mail->subject}");

        $replyTo = $this->mailbox->replyToAddress();
        if ($replyTo !== null) {
            $mail->replyTo($replyTo);
        }

        return $mail->withSymfonyMessage(function (Email $email) use ($messageId, $references) {
            $headers = $email->getHeaders();
            $headers->addIdHeader('Message-ID', $messageId);

            if ($references !== []) {
                $headers->addIdHeader('In-Reply-To', $references[array_key_last($references)]);
                $headers->addIdHeader('References', $references);
            }

            // RFC 3834: tells other systems' auto-responders not to answer us (loop prevention).
            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
            $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
        });
    }
}
