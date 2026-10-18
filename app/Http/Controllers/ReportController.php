<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\ReportFilterRequest;
use App\Services\ReportService;
use App\Services\TicketAssignmentService;
use App\Services\TicketService;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly TicketService $tickets,
        private readonly TicketAssignmentService $assignments,
    ) {}

    public function index(ReportFilterRequest $request): Response
    {
        $this->authorize('viewReports');

        $tab = $request->tab();
        $this->authorize('viewReportTab', $tab);

        // Filter options only matter to someone who can filter across agents.
        $canViewAll = $request->user()->can(Permission::ReportsView->value);

        return Inertia::render('reports/index', [
            'tab' => $tab->value,
            'report' => $this->reports->forTab($tab, $request->user(), $request->toData()),
            'filters' => $request->effectiveFilters(),
            'today' => $request->today(),
            'percentileMaxDays' => ReportService::PERCENTILE_MAX_DAYS,
            'options' => [
                'categories' => $canViewAll ? $this->tickets->categoryOptions() : [],
                'agents' => $canViewAll ? $this->assignments->agentOptions() : [],
            ],
        ]);
    }
}
