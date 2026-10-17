<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\AgentProfile;
use Illuminate\Database\Eloquent\Collection;

interface AgentRepositoryInterface
{
    /**
     * Available agent profiles that either handle the given category or
     * have no category restriction, annotated with `active_count` and
     * still under `max_active_tickets` (ARCHITECTURE.md §6.2). Not locked
     * and not ordered — callers (AssignmentStrategy implementations)
     * decide the ranking.
     *
     * @return Collection<int, AgentProfile>
     */
    public function candidatesFor(?int $categoryId): Collection;

    /**
     * SELECT ... FOR UPDATE SKIP LOCKED on a single agent_profiles row.
     * Returns null if another concurrent pick already holds the lock.
     */
    public function lockForAssignment(int $agentProfileId): ?AgentProfile;

    public function touchLastAssigned(AgentProfile $profile): void;
}
