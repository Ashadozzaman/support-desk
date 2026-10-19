<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class UserFilterData
{
    public function __construct(
        public ?string $search,
        public ?string $role,
    ) {}
}
