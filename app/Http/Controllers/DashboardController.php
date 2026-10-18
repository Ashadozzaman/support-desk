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
            // pattern with notifications). Each item must be ->resolve()'d
            // to a plain array here: Inertia's prop resolver recurses into
            // array values and re-wraps any bare JsonResource it finds in
            // "data" (it treats Responsable the same at every depth), so
            // leaving these as resource objects double-wraps every row.
            'approachingSla' => $data['approachingSla']->map(
                fn ($ticket) => (new TicketListResource($ticket))->resolve()
            )->values(),
        ]);
    }
}
