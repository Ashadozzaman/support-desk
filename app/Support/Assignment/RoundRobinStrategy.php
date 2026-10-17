<?php

declare(strict_types=1);

namespace App\Support\Assignment;

use App\Contracts\Assignment\AssignmentStrategy;
use App\Contracts\Repositories\AgentRepositoryInterface;
use App\Models\AgentProfile;
use App\Models\User;

/**
 * Second strategy implementation (ARCHITECTURE.md §6.2) demonstrating the
 * Open/Closed strategy slot: pure round-robin by oldest last_assigned_at,
 * ignoring current workload.
 */
final class RoundRobinStrategy implements AssignmentStrategy
{
    public function __construct(private readonly AgentRepositoryInterface $agents) {}

    public function pick(?int $categoryId): ?User
    {
        $candidates = $this->agents->candidatesFor($categoryId)
            ->sortBy(fn (AgentProfile $profile) => $profile->last_assigned_at?->timestamp ?? 0);

        foreach ($candidates as $profile) {
            $locked = $this->agents->lockForAssignment($profile->id);

            if ($locked !== null) {
                $this->agents->touchLastAssigned($locked);

                return $profile->user;
            }
        }

        return null;
    }
}
