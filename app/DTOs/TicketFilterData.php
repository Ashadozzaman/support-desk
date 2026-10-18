<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;

readonly class TicketFilterData
{
    public function __construct(
        public ?string $ticketNumber = null,
        public ?string $keyword = null,
        public ?TicketStatus $status = null,
        public ?TicketPriority $priority = null,
        public ?int $categoryId = null,
        public ?int $assignedAgentId = null,
        public ?int $createdBy = null,
        public ?CarbonImmutable $createdFrom = null,
        public ?CarbonImmutable $createdTo = null,
        public ?SlaState $slaState = null,
    ) {}
}
