<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ReplyRepositoryInterface;
use App\Contracts\Repositories\TicketRepositoryInterface;
use App\DTOs\CreateReplyData;
use App\Enums\ActivityEvent;
use App\Enums\Permission;
use App\Enums\ReplyType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ReplyService
{
    public function __construct(
        private readonly ReplyRepositoryInterface $replies,
        private readonly TicketRepositoryInterface $tickets,
        private readonly AttachmentService $attachments,
        private readonly ActivityLogService $activity,
    ) {}

    public function addReply(Ticket $ticket, CreateReplyData $data, User $actor): TicketReply
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $reply = $this->replies->create($ticket, $actor, ReplyType::Reply, $data->body);

            foreach ($data->attachments as $file) {
                $this->attachments->store($ticket, $reply, $actor, $file);
            }

            $isStaffReply = $this->isStaff($actor);
            $this->tickets->recordReply($ticket, $isStaffReply);

            // A customer reply to a resolved ticket means the issue isn't
            // actually fixed — reopen it (ARCHITECTURE.md §5). Staff replies
            // never reopen; closed tickets have no outgoing transition.
            if (! $isStaffReply && $ticket->status === TicketStatus::Resolved) {
                $old = $ticket->status->value;
                $this->tickets->updateStatus($ticket, TicketStatus::Open);

                $this->activity->log(
                    $ticket,
                    ActivityEvent::TicketReopened,
                    $actor,
                    ['status' => $old],
                    ['status' => TicketStatus::Open->value],
                );
            }

            $this->activity->log($ticket, ActivityEvent::ReplyAdded, $actor, [], ['reply_id' => $reply->id]);

            if ($data->attachments !== []) {
                $this->activity->log(
                    $ticket,
                    ActivityEvent::AttachmentAdded,
                    $actor,
                    [],
                    ['reply_id' => $reply->id, 'count' => count($data->attachments)],
                );
            }

            return $reply;
        });
    }

    /**
     * Internal notes, and any attachments on them, must never be visible to
     * customers (CLAUDE.md rule 6). The note.added activity row already
     * records that attachments exist for staff viewers; a separate
     * attachment.added row is deliberately NOT written here because that
     * event is customer-visible and would leak the note's existence.
     */
    public function addInternalNote(Ticket $ticket, CreateReplyData $data, User $actor): TicketReply
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $note = $this->replies->create($ticket, $actor, ReplyType::InternalNote, $data->body);

            foreach ($data->attachments as $file) {
                $this->attachments->store($ticket, $note, $actor, $file);
            }

            $this->activity->log($ticket, ActivityEvent::NoteAdded, $actor, [], ['reply_id' => $note->id]);

            return $note;
        });
    }

    /**
     * @return Collection<int, TicketReply>
     */
    public function threadFor(Ticket $ticket, User $viewer): Collection
    {
        return $this->replies->forTicket($ticket, $viewer);
    }

    private function isStaff(User $actor): bool
    {
        return $actor->can(Permission::TicketsViewAny->value) || $actor->can(Permission::TicketsViewQueue->value);
    }
}
