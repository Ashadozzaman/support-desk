<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityEvent;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogFilterRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\TicketAuditResource;
use App\Models\TicketActivity;
use App\Services\AuditLogService;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(AuditLogFilterRequest $request): Response
    {
        $this->authorize('viewAuditLog');

        $filters = $request->toData();
        $entries = $this->audit->paginate($filters, 25);

        return Inertia::render('admin/audit-log/index', [
            // Rows can be either table's model ("All logs" merges both), so
            // each is shaped by its own resource; JsonResource::collection
            // only adds the {data, links, meta} paginator shape.
            'entries' => JsonResource::collection($entries->through(fn (Activity|TicketActivity $row) => $row instanceof TicketActivity
                ? TicketAuditResource::make($row)->resolve($request)
                : AuditLogResource::make($row)->resolve($request))),
            'filters' => $request->filters(),
            'today' => $request->today(),
            'retention' => [
                'default_days' => (int) config('support.audit.page_default_days'),
                'auth_days' => (int) config('support.audit.auth_retention_days'),
                'admin_days' => (int) config('support.audit.admin_retention_days'),
            ],
            'events' => [
                ...array_map(
                    fn (AuditEvent $event) => ['value' => $event->value, 'label' => $event->label(), 'log' => $event->logName()],
                    AuditEvent::cases(),
                ),
                ...array_map(
                    fn (ActivityEvent $event) => ['value' => $event->value, 'label' => $event->label(), 'log' => AuditEvent::LOG_TICKETS],
                    ActivityEvent::cases(),
                ),
            ],
        ]);
    }
}
