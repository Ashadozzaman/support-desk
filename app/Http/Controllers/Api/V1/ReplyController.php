<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReplyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReplyRequest;
use App\Http\Resources\ReplyResource;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\ReplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Replies
 */
class ReplyController extends Controller
{
    public function __construct(private readonly ReplyService $replies) {}

    /**
     * List replies.
     *
     * Internal notes are included only for callers with `notes.view` —
     * a customer never receives them (ReplyRepository::forTicket).
     */
    public function index(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('view', $ticket);

        return ReplyResource::collection($this->replies->threadFor($ticket, $request->user()));
    }

    /**
     * Add a reply or internal note.
     *
     * `type=internal_note` needs `notes.create`. Attachments: send as
     * `multipart/form-data` with `attachments[]`.
     */
    public function store(StoreReplyRequest $request, Ticket $ticket): JsonResponse
    {
        if ($request->hasFile('attachments')) {
            $this->authorize('upload', [Attachment::class, $ticket]);
        }

        $data = $request->toData();

        if ($request->replyType() === ReplyType::InternalNote) {
            $this->authorize('createNote', [TicketReply::class, $ticket]);
            $reply = $this->replies->addInternalNote($ticket, $data, $request->user());
        } else {
            $this->authorize('create', [TicketReply::class, $ticket]);
            $reply = $this->replies->addReply($ticket, $data, $request->user());
        }

        return (new ReplyResource($reply->load(['user:id,name', 'attachments'])))->response()->setStatusCode(201);
    }
}
