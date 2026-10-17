<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AgentRepositoryInterface;
use App\Models\AgentProfile;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class AgentRepository implements AgentRepositoryInterface
{
    public function candidatesFor(?int $categoryId): Collection
    {
        return AgentProfile::query()
            ->select('agent_profiles.*')
            ->addSelect([
                // Correlated subquery: one query, no N+1 per candidate.
                'active_count' => Ticket::query()
                    ->selectRaw('count(*)')
                    ->active()
                    ->whereColumn('tickets.assigned_agent_id', 'agent_profiles.user_id'),
            ])
            ->with('user:id,name')
            ->where('is_available', true)
            ->when($categoryId !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($categoryId) {
                $inner->whereHas('user.categories', fn (Builder $q) => $q->whereKey($categoryId))
                    ->orWhereDoesntHave('user.categories');
            }))
            ->get()
            ->filter(fn (AgentProfile $profile) => (int) $profile->active_count < $profile->max_active_tickets)
            ->values();
    }

    public function lockForAssignment(int $agentProfileId): ?AgentProfile
    {
        // MySQL 8 SKIP LOCKED: Laravel's query builder has no skipLocked()
        // helper, so the raw lock clause is passed directly to lock().
        return AgentProfile::query()
            ->whereKey($agentProfileId)
            ->lock('for update skip locked')
            ->first();
    }

    public function touchLastAssigned(AgentProfile $profile): void
    {
        $profile->update(['last_assigned_at' => now()]);
    }
}
