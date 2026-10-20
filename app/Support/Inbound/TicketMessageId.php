<?php

declare(strict_types=1);

namespace App\Support\Inbound;

use App\Models\Ticket;
use Illuminate\Support\Str;

/**
 * The Message-IDs we put on ticket emails: `ticket.{number}.{random}@{our
 * domain}`. One format in one place, so the importer can recognise its own
 * mail coming back (docs/IMAP.md §4).
 */
class TicketMessageId
{
    public static function generate(Ticket $ticket): string
    {
        return 'ticket.'.$ticket->ticket_number.'.'.Str::lower(Str::random(16)).'@'.self::domain();
    }

    public static function isOurs(string $messageId): bool
    {
        $id = trim($messageId, " <>\t\r\n");

        return str_starts_with($id, 'ticket.') && str_ends_with(Str::lower($id), '@'.self::domain());
    }

    private static function domain(): string
    {
        $from = (string) config('mail.from.address');

        return Str::lower(str_contains($from, '@')
            ? Str::after($from, '@')
            : (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
    }
}
