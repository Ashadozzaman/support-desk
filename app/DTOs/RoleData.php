<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class RoleData
{
    /**
     * @param  list<string>  $permissions  Permission enum values
     */
    public function __construct(
        public string $name,
        public array $permissions,
    ) {}
}
