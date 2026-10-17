<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ReplyRepositoryInterface;
use App\Enums\Permission;
use App\Enums\ReplyType;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ReplyRepository implements ReplyRepositoryInterface
{
    public function create(Ticket $ticket, User $user, ReplyType $type, string $body): TicketReply
    {
        return $ticket->replies()->create([
            'user_id' => $user->id,
            'type' => $type,
            'body' => $body,
        ]);
    }

    public function forTicket(Ticket $ticket, User $viewer): Collection
    {
        $query = $ticket->replies()
            ->with(['user:id,name', 'attachments'])
            ->oldest('created_at');

        if (! $viewer->can(Permission::NotesView->value)) {
            $query->where('type', ReplyType::Reply->value);
        }

        return $query->get();
    }
}
