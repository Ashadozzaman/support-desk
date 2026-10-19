<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\MailboxEncryption;

readonly class MailboxSettingsData
{
    public function __construct(
        public bool $isEnabled,
        public string $host,
        public int $port,
        public MailboxEncryption $encryption,
        public string $username,
        /** Null = keep the stored password (the form never shows it). */
        public ?string $password,
        public string $folder,
        public int $fetchIntervalMinutes,
        public int $batchSize,
    ) {}
}
