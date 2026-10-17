<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Http\UploadedFile;

final readonly class CreateReplyData
{
    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    public function __construct(
        public string $body,
        public array $attachments = [],
    ) {}
}
