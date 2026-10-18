<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

readonly class SlaWindowData
{
    public function __construct(
        public ?CarbonImmutable $dueAt,
        public ?CarbonImmutable $warningAt,
    ) {}
}
