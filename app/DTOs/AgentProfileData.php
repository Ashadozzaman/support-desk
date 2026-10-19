<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Agent-only part of the admin user form: capacity, availability and
 * category skills (agent_profiles + category_agent).
 */
readonly class AgentProfileData
{
    /**
     * @param  list<int>  $categoryIds  empty = handles every category
     */
    public function __construct(
        public bool $isAvailable,
        public int $maxActiveTickets,
        public array $categoryIds,
    ) {}
}
