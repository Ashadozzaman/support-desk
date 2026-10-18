<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\TicketListResource;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        $data = $this->dashboard->forUser($request->user());

        return Inertia::render('dashboard', [
            'scope' => $data['scope'],
            'stats' => $data['stats'],
            'byPriority' => $data['byPriority'],
            'byAgent' => $data['byAgent'],
            'trend' => $data['trend'],
            // Mapped individually rather than ::collection() — a
            // ResourceCollection always wraps in a "data" key even nested
            // inside other props (see HandleInertiaRequests for the same
            // pattern with notifications).
            'approachingSla' => $data['approachingSla']->map(
                fn ($ticket) => new TicketListResource($ticket)
            )->values(),
        ]);
    }
}
