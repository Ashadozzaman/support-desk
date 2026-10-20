<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\MailboxEncryption;
use SensitiveParameter;

/** What a MailboxClient needs to log in. Built by MailboxSettingsService only. */
readonly class MailboxConnectionData
{
    public function __construct(
        public string $host,
        public int $port,
        public MailboxEncryption $encryption,
        public string $username,
        #[SensitiveParameter] public string $password,
        public string $folder,
    ) {}
}
