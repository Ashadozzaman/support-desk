<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\TicketPriority;

readonly class CreateTicketData
{
    public function __construct(
        public string $subject,
        public string $description,
        public TicketPriority $priority,
        public ?int $categoryId,
        public int $createdBy,
        public ?string $externalRef = null,
    ) {}
}
