<?php

declare(strict_types=1);

namespace App\Support\Inbound;

use App\DTOs\InboundAttachmentData;
use App\DTOs\InboundMessageData;
use App\Enums\InboundChannel;
use Carbon\CarbonImmutable;
use Throwable;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

/**
 * Raw RFC 822 email → InboundMessageData (the email adapter, docs/IMAP.md §1).
 * Decoding (MIME parts, encoded headers, charsets) is the library's; this
 * class only decides what the intake core needs.
 */
class EmailMessageParser
{
    /**
     * @param  list<string>  $ownAddresses  our From / mailbox addresses (mail from them is never a customer's)
     */
    public function parse(string $raw, array $ownAddresses = []): InboundMessageData
    {
        $message = Message::fromString($raw);
        $from = $message->getFrom()->first();
        $subject = (string) $message->getSubject();
        $senderAddress = (string) ($from->mail ?? '');
        $messageId = $this->messageId($message, $raw);

        return new InboundMessageData(
            channel: InboundChannel::Email,
            externalMessageId: $messageId,
            senderAddress: $senderAddress,
            senderName: isset($from->personal) && $from->personal !== '' ? (string) $from->personal : null,
            subject: $subject,
            body: $this->body($message),
            receivedAt: $this->date($message),
            threadKeys: $this->threadKeys($message),
            ticketNumberHint: preg_match('/\[(TKT-\d{4}-\d{6,})\]/i', $subject, $match) === 1 ? $match[1] : null,
            attachments: $this->attachments($message),
            skipReason: $this->skipReason($raw, $senderAddress, $messageId, $ownAddresses),
        );
    }

    /**
     * Mail that must never become a ticket or reply (loop prevention,
     * docs/IMAP.md §4): auto-replies, bounces, list mail, and our own mail
     * coming back. RFC 3834: anything with Auto-Submitted other than "no".
     *
     * @param  list<string>  $ownAddresses
     */
    private function skipReason(string $raw, string $senderAddress, string $messageId, array $ownAddresses): ?string
    {
        $headers = $this->rawHeaders($raw);
        $sender = mb_strtolower(trim($senderAddress));

        return match (true) {
            TicketMessageId::isOurs($messageId),
            in_array($sender, array_map(mb_strtolower(...), $ownAddresses), true) => 'own_mail',
            preg_match('/^(mailer-daemon|postmaster)@/i', $sender) === 1 => 'bounce',
            ($this->header($headers, 'Auto-Submitted') ?? 'no') !== 'no',
            $this->header($headers, 'X-Autoreply') !== null,
            $this->header($headers, 'X-Autorespond') !== null => 'auto_reply',
            in_array($this->header($headers, 'Precedence'), ['bulk', 'junk', 'list', 'auto_reply'], true),
            $this->header($headers, 'List-Id') !== null => 'bulk_mail',
            default => null,
        };
    }

    /** The header block, with folded lines joined (RFC 5322 §2.2.3). */
    private function rawHeaders(string $raw): string
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $headers = strstr($raw, "\n\n", true) ?: $raw;

        return (string) preg_replace('/\n[ \t]+/', ' ', $headers);
    }

    private function header(string $headers, string $name): ?string
    {
        return preg_match('/^'.preg_quote($name, '/').':\s*(.*)$/mi', $headers, $match) === 1
            ? mb_strtolower(trim($match[1]))
            : null;
    }

    /**
     * A message without a Message-ID still needs a stable key to dedupe on:
     * the same raw headers always hash the same.
     */
    private function messageId(Message $message, string $raw): string
    {
        $id = trim((string) $message->getMessageId());

        if ($id !== '') {
            return $id;
        }

        return 'generated-'.hash('sha256', $this->rawHeaders($raw));
    }

    /**
     * Most specific first: the message it answers, then References from the
     * newest back to the start of the conversation.
     *
     * @return list<string>
     */
    private function threadKeys(Message $message): array
    {
        $keys = [
            ...$this->ids($message->getInReplyTo()->toArray()),
            ...array_reverse($this->ids($message->getReferences()->toArray())),
        ];

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            // One header value can hold several ids: "<a@x> <b@y>".
            foreach (preg_split('/[\s,]+/', (string) $value) ?: [] as $id) {
                $id = trim($id, " <>\t\r\n");

                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Plain text only (the app renders user text as plain text). The quoted
     * history under "On … wrote:" is dropped: it is already on the ticket.
     */
    private function body(Message $message): string
    {
        $text = $message->hasTextBody()
            ? $message->getTextBody()
            : html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)\b[^>]*>/i', "\n", $message->getHTMLBody()) ?? ''), ENT_QUOTES | ENT_HTML5);

        $text = str_replace("\r\n", "\n", $text);
        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(On\s.{1,300}\swrote:|-{2,}\s*Original Message\s*-{2,})\s*$/i', $line) === 1) {
                break;
            }

            $kept[] = $line;
        }

        $body = trim(implode("\n", $kept));

        // A reply that is *only* a quote keeps the quote rather than becoming empty.
        return $body !== '' ? $body : trim($text);
    }

    private function date(Message $message): CarbonImmutable
    {
        try {
            return CarbonImmutable::instance($message->getDate()->toDate())->utc();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    /**
     * @return list<InboundAttachmentData>
     */
    private function attachments(Message $message): array
    {
        $files = [];

        /** @var Attachment $attachment */
        foreach ($message->getAttachments() as $attachment) {
            $name = (string) $attachment->getName();

            if ($name !== '') {
                $files[] = new InboundAttachmentData($name, (string) $attachment->getContent());
            }
        }

        return $files;
    }
}
