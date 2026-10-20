<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class InboundAttachmentData
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {}
}
