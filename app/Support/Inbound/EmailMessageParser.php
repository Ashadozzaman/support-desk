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
    public function parse(string $raw): InboundMessageData
    {
        $message = Message::fromString($raw);
        $from = $message->getFrom()->first();
        $subject = (string) $message->getSubject();

        return new InboundMessageData(
            channel: InboundChannel::Email,
            externalMessageId: $this->messageId($message, $raw),
            senderAddress: (string) ($from->mail ?? ''),
            senderName: isset($from->personal) && $from->personal !== '' ? (string) $from->personal : null,
            subject: $subject,
            body: $this->body($message),
            receivedAt: $this->date($message),
            threadKeys: $this->threadKeys($message),
            ticketNumberHint: preg_match('/\[(TKT-\d{4}-\d{6,})\]/i', $subject, $match) === 1 ? $match[1] : null,
            attachments: $this->attachments($message),
        );
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

        $headers = strstr(str_replace("\r\n", "\n", $raw), "\n\n", true) ?: $raw;

        return 'generated-'.hash('sha256', $headers);
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
