<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class MailboxFetchResult
{
    /**
     * @param  array<int, string>  $messages  IMAP UID => raw RFC 822 message, oldest first
     */
    public function __construct(
        public int $uidValidity,
        public int $highestUid,
        public array $messages = [],
    ) {}
}
