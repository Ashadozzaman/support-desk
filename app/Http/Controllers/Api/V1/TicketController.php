<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTicketRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\TicketFilterRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Requests\UpdateTicketStatusRequest;
use App\Http\Resources\TicketListResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketAssignmentService;
use App\Services\TicketService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Same FormRequests, policies and services as the web TicketController —
 * only the response format differs, so the two can't drift apart on rules.
 *
 * @tags Tickets
 */
class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketAssignmentService $assignments,
    ) {}

    /**
     * List tickets.
     *
     * Only tickets the caller may see: customers their own, agents theirs
     * plus the unassigned queue, admins all. `tab=active` (default) or
     * `tab=resolved`.
     */
    public function index(TicketFilterRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Ticket::class);

        $tickets = $this->tickets->paginate($request->user(), $request->tab(), $request->toData(), $request->perPage());

        return TicketListResource::collection($tickets);
    }

    /**
     * Create a ticket.
     */
    public function store(StoreTicketRequest $request): JsonResponse
    {
        $this->authorize('create', Ticket::class);

        $ticket = $this->tickets->create($request->toData(), $request->user());

        return $this->ticketResource($ticket)->response()->setStatusCode(201);
    }

    /**
     * Show a ticket.
     */
    public function show(Ticket $ticket): TicketResource
    {
        $this->authorize('view', $ticket);

        return $this->ticketResource($ticket);
    }

    /**
     * Update a ticket.
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket): TicketResource
    {
        $this->authorize('update', $ticket);

        return $this->ticketResource($this->tickets->update($ticket, $request->toData(), $request->user()));
    }

    /**
     * Change status.
     *
     * Invalid transitions (e.g. closed → open) return 422.
     */
    public function changeStatus(UpdateTicketStatusRequest $request, Ticket $ticket): TicketResource
    {
        $this->authorize('changeStatus', $ticket);

        return $this->ticketResource($this->tickets->changeStatus($ticket, $request->status(), $request->user()));
    }

    /**
     * Claim an unassigned ticket.
     *
     * Returns 409 if another agent claimed it first.
     */
    #[Response(409, 'Another agent claimed the ticket first.', type: 'array{message: string}')]
    public function claim(Request $request, Ticket $ticket): TicketResource
    {
        $this->authorize('claim', $ticket);

        return $this->ticketResource($this->assignments->claim($ticket, $request->user()));
    }

    /**
     * Assign to an agent.
     */
    public function assign(AssignTicketRequest $request, Ticket $ticket): TicketResource
    {
        $this->authorize('assign', $ticket);

        return $this->ticketResource($this->assignments->assign($ticket, $request->agentId(), $request->user()));
    }

    private function ticketResource(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->load(['category:id,name', 'assignedAgent:id,name', 'creator:id,name']));
    }
}
