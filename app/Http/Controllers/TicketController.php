<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Requests\UpdateTicketStatusRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ReplyResource;
use App\Http\Resources\TicketListResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\ActivityLogService;
use App\Services\ReplyService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly ActivityLogService $activity,
        private readonly ReplyService $replies,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Ticket::class);

        $tab = $request->query('tab') === 'resolved' ? 'resolved' : 'active';

        $tickets = $this->tickets->paginate($request->user(), $tab);

        return Inertia::render('tickets/index', [
            'tickets' => TicketListResource::collection($tickets),
            'tab' => $tab,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Ticket::class);

        return Inertia::render('tickets/create', [
            'categories' => $this->tickets->categoryOptions(),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $ticket = $this->tickets->create($request->toData(), $request->user());

        return redirect()->route('tickets.show', $ticket->ticket_number);
    }

    public function show(Ticket $ticket, Request $request): Response
    {
        $this->authorize('view', $ticket);

        $ticket->load(['category:id,name', 'assignedAgent:id,name', 'creator:id,name']);

        $user = $request->user();

        return Inertia::render('tickets/show', [
            'ticket' => new TicketResource($ticket),
            'activities' => ActivityResource::collection(
                $this->activity->timelineFor($ticket, $user),
            ),
            'replies' => ReplyResource::collection(
                $this->replies->threadFor($ticket, $user),
            ),
            'can' => [
                'reply' => $user->can('create', [TicketReply::class, $ticket]),
                'note' => $user->can('createNote', [TicketReply::class, $ticket]),
            ],
        ]);
    }

    public function edit(Ticket $ticket): Response
    {
        $this->authorize('update', $ticket);

        $ticket->load(['category:id,name', 'assignedAgent:id,name', 'creator:id,name']);

        return Inertia::render('tickets/edit', [
            'ticket' => new TicketResource($ticket),
            'categories' => $this->tickets->categoryOptions(),
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $this->tickets->update($ticket, $request->toData(), $request->user());

        return redirect()->route('tickets.show', $ticket->ticket_number);
    }

    public function changeStatus(UpdateTicketStatusRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('changeStatus', $ticket);

        $this->tickets->changeStatus($ticket, $request->status(), $request->user());

        return redirect()->route('tickets.show', $ticket->ticket_number);
    }
}
