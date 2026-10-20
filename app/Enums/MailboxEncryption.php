<?php

declare(strict_types=1);

namespace App\Enums;

enum MailboxEncryption: string
{
    case Ssl = 'ssl';
    case Tls = 'tls';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Ssl => 'SSL (port 993)',
            self::Tls => 'STARTTLS (port 143)',
            self::None => 'None (not recommended)',
        };
    }
}
