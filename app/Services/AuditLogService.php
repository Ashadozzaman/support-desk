<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ActivityRepositoryInterface;
use App\Contracts\Repositories\AuditLogRepositoryInterface;
use App\DTOs\AuditLogFilterData;
use App\Enums\AuditEvent;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Spatie\Activitylog\Models\Activity;

/**
 * Security/admin audit trail (Step 19c, ARCHITECTURE.md §15). Ticket
 * changes never come through here — they go to ticket_activities via
 * ActivityLogService.
 *
 * Properties are allow-listed: anything else a caller passes is dropped, so
 * a password, token or ticket body can't end up in the audit table by
 * accident.
 */
class AuditLogService
{
    /** @var list<string> */
    private const ALLOWED_PROPERTIES = ['ip', 'user_agent', 'guard', 'remember', 'email_hash', 'changes', 'holiday'];

    public function __construct(
        private readonly AuditLogRepositoryInterface $audit,
        private readonly ActivityRepositoryInterface $ticketActivity,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(AuditEvent $event, ?User $causer, ?User $subject = null, array $properties = []): Activity
    {
        $properties = array_intersect_key($properties, array_flip(self::ALLOWED_PROPERTIES));

        if (isset($properties['user_agent']) && is_string($properties['user_agent'])) {
            $properties['user_agent'] = mb_substr($properties['user_agent'], 0, 255);
        }

        return $this->audit->create($event, $causer, $subject, $properties);
    }

    /**
     * The Tickets log is a read-only window onto ticket_activities — the
     * ticket audit trail is never copied into activity_log.
     *
     * "All logs" merges both tables with the ticket list's top-N-then-merge
     * (ARCHITECTURE.md §12): each side returns only its newest
     * page × perPage rows (index-only backward scans), the union is sorted
     * and sliced. Exact: a row outside its own table's top-N has N newer
     * rows in that table alone, so it can't be in the global top-N. A SQL
     * UNION … ORDER BY created_at would read and sort both tables in full.
     *
     * @return LengthAwarePaginatorContract<int, Activity|TicketActivity>
     */
    public function paginate(AuditLogFilterData $filters, int $perPage): LengthAwarePaginatorContract
    {
        if ($filters->showsOnlyTickets()) {
            return $this->ticketActivity->paginateAll($filters->ticketEvent, $filters->from, $filters->to, $perPage);
        }

        if ($filters->showsOnlyAuditLog()) {
            return $this->audit->paginate($filters, $perPage);
        }

        // Same source Eloquent's own paginate() reads the page number from.
        $page = Paginator::resolveCurrentPage();
        $take = $page * $perPage;

        $rows = $this->audit->latest($filters, $take)->toBase()
            ->concat($this->ticketActivity->latestAll($filters->from, $filters->to, $take))
            ->sort(fn (Model $a, Model $b) => $b->created_at <=> $a->created_at)
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        $total = $this->audit->count($filters) + $this->ticketActivity->countAll($filters->from, $filters->to);

        return (new LengthAwarePaginator($rows, $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]))
            ->withQueryString();
    }
}
