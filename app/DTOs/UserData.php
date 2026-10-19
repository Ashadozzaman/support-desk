<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Admin create/edit user input. `agent` is always filled from the form but
 * only applied when the chosen role can work tickets (`tickets.claim`) —
 * UserManagementService decides that, not the request.
 */
readonly class UserData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $role,
        public bool $isActive,
        public AgentProfileData $agent,
    ) {}
}
