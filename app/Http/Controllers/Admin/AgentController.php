<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AgentMonthFilterRequest;
use App\Http\Requests\Admin\AgentPerformanceFilterRequest;
use App\Models\User;
use App\Services\AgentPerformanceService;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentController extends Controller
{
    public function __construct(private readonly AgentPerformanceService $performance) {}

    public function index(AgentPerformanceFilterRequest $request): Response
    {
        $this->authorize('viewAnyPerformance', User::class);

        [$from, $to] = $request->dates();

        return Inertia::render('admin/agents/index', [
            // Rows are already plain arrays; JsonResource::collection only
            // adds the {data, links, meta} paginator shape the UI expects.
            'agents' => JsonResource::collection($this->performance->paginate($request->toData(), $request->perPage())),
            'filters' => ['from' => $from, 'to' => $to],
            'today' => $request->today(),
        ]);
    }

    public function show(User $agent, AgentMonthFilterRequest $request): Response
    {
        $this->authorize('viewPerformance', $agent);

        return Inertia::render('admin/agents/show', [
            'agent' => ['id' => $agent->id, 'name' => $agent->name, 'email' => $agent->email],
            ...$this->performance->detail($agent),
            'daily' => $this->performance->daily($agent, $request->toData()),
        ]);
    }

    public function dailyExport(User $agent, AgentMonthFilterRequest $request): StreamedResponse
    {
        $this->authorize('viewPerformance', $agent);

        $daily = $this->performance->daily($agent, $request->toData());

        return response()->streamDownload(function () use ($daily) {
            $handle = fopen('php://output', 'w');
            // CRLF per RFC 4180 — same as the import error report, so Excel
            // doesn't mis-render a bare-LF CSV as one row.
            fputcsv($handle, ['Date', 'Assigned', 'Completed'], ',', '"', '\\', "\r\n");

            foreach ($daily['days'] as $day) {
                fputcsv($handle, [$day['date'], $day['assigned'], $day['completed']], ',', '"', '\\', "\r\n");
            }

            fputcsv($handle, ['Total', $daily['totals']['assigned'], $daily['totals']['completed']], ',', '"', '\\', "\r\n");
            fclose($handle);
        }, "agent-{$agent->id}-{$daily['month']}.csv", ['Content-Type' => 'text/csv']);
    }
}
