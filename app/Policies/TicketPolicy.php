<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

/**
 * Every ability checks a PERMISSION first, then ownership/assignment
 * (ARCHITECTURE.md §3) — never $user->hasRole() directly.
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TicketsViewAny->value)
            || $user->can(Permission::TicketsViewQueue->value)
            || $user->can(Permission::TicketsViewOwn->value);
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->can(Permission::TicketsViewAny->value)) {
            return true;
        }

        if ($user->can(Permission::TicketsViewQueue->value)) {
            return $ticket->assigned_agent_id === $user->id || $ticket->assigned_agent_id === null;
        }

        return $user->can(Permission::TicketsViewOwn->value) && $ticket->created_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::TicketsCreate->value);
    }

    public function update(User $user, Ticket $ticket): bool
    {
        if ($user->can(Permission::TicketsUpdate->value)) {
            return $user->can(Permission::TicketsViewAny->value) || $ticket->assigned_agent_id === $user->id;
        }

        if ($user->can(Permission::TicketsUpdateOwn->value)) {
            return $ticket->created_by === $user->id && $ticket->status === TicketStatus::Open;
        }

        return false;
    }

    public function changeStatus(User $user, Ticket $ticket): bool
    {
        if (! $user->can(Permission::TicketsChangeStatus->value)) {
            return false;
        }

        return $user->can(Permission::TicketsViewAny->value) || $ticket->assigned_agent_id === $user->id;
    }

    public function claim(User $user, Ticket $ticket): bool
    {
        return $user->can(Permission::TicketsClaim->value) && $ticket->assigned_agent_id === null;
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->can(Permission::TicketsAssign->value);
    }
}
