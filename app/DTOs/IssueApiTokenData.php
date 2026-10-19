<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class IssueApiTokenData
{
    public function __construct(
        public string $email,
        public string $password,
        public string $deviceName,
        public ?string $code,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
