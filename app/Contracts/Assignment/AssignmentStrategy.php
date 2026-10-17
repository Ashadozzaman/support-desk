<?php

declare(strict_types=1);

namespace App\Contracts\Assignment;

use App\Models\User;

interface AssignmentStrategy
{
    /**
     * Picks and locks one available agent for the given category
     * (ARCHITECTURE.md §6.2), updating `last_assigned_at` as part of the
     * pick. Returns null if no candidate is currently available — the
     * ticket stays unassigned for the `tickets:auto-assign` sweep to retry.
     */
    public function pick(?int $categoryId): ?User;
}
