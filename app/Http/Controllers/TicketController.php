<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\AssignTicketRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\TicketFilterRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Requests\UpdateTicketStatusRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\AssignableAgentResource;
use App\Http\Resources\ReplyResource;
use App\Http\Resources\TicketListResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\ActivityLogService;
use App\Services\ReplyService;
use App\Services\TicketAssignmentService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly ActivityLogService $activity,
        private readonly ReplyService $replies,
        private readonly TicketAssignmentService $assignments,
    ) {}

    public function index(TicketFilterRequest $request): Response
    {
        $this->authorize('viewAny', Ticket::class);

        $tab = $request->tab();
        $user = $request->user();
        $filters = $request->toData();

        $tickets = $this->tickets->paginate($user, $tab, $filters, $request->perPage());

        $isStaff = $user->can(Permission::TicketsViewAny->value) || $user->can(Permission::TicketsViewQueue->value);

        return Inertia::render('tickets/index', [
            'tickets' => TicketListResource::collection($tickets),
            'tab' => $tab,
            'filters' => $request->only([
                'ticket_number', 'keyword', 'status', 'priority', 'category_id',
                'assigned_agent_id', 'created_by', 'created_from', 'created_to', 'sla_state',
            ]),
            'categories' => $this->tickets->categoryOptions(),
            'agents' => $isStaff ? $this->assignments->agentOptions() : [],
            // Per-row Assign dialog; the filter above only needs id + name.
            'assignableAgents' => AssignableAgentResource::collection(
                $user->can(Permission::TicketsAssign->value) ? $this->assignments->assignOptions() : [],
            ),
            'customers' => $user->can(Permission::TicketsViewAny->value) ? $this->tickets->customerOptions() : [],
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
                'claim' => $user->can('claim', $ticket),
                'assign' => $user->can('assign', $ticket),
            ],
            'agents' => AssignableAgentResource::collection(
                $user->can('assign', $ticket) ? $this->assignments->assignOptions() : [],
            ),
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

    public function claim(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('claim', $ticket);

        $this->assignments->claim($ticket, $request->user());

        return redirect()->route('tickets.show', $ticket->ticket_number);
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('assign', $ticket);

        $this->assignments->assign($ticket, $request->agentId(), $request->user());

        return redirect()->route('tickets.show', $ticket->ticket_number);
    }
}
