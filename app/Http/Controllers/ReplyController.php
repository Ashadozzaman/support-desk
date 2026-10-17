<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReplyType;
use App\Http\Requests\StoreReplyRequest;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\ReplyService;
use Illuminate\Http\RedirectResponse;

final class ReplyController extends Controller
{
    public function __construct(private readonly ReplyService $replies) {}

    public function store(StoreReplyRequest $request, Ticket $ticket): RedirectResponse
    {
        if ($request->hasFile('attachments')) {
            $this->authorize('upload', [Attachment::class, $ticket]);
        }

        $type = $request->replyType();
        $data = $request->toData();

        if ($type === ReplyType::InternalNote) {
            $this->authorize('createNote', [TicketReply::class, $ticket]);
            $this->replies->addInternalNote($ticket, $data, $request->user());
        } else {
            $this->authorize('create', [TicketReply::class, $ticket]);
            $this->replies->addReply($ticket, $data, $request->user());
        }

        return redirect()->route('tickets.show', $ticket->ticket_number);
    }
}
