<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AuditLogRepositoryInterface;
use App\DTOs\AuditLogFilterData;
use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * The only class that talks to spatie/laravel-activitylog. Its insert is a
 * plain Eloquent write on the default connection, so it joins whatever
 * transaction the calling service opened.
 */
class AuditLogRepository implements AuditLogRepositoryInterface
{
    public function create(AuditEvent $event, ?User $causer, ?Model $subject, array $properties): Activity
    {
        $logger = activity($event->logName())
            ->event($event->value)
            ->withProperties($properties);

        // Without an explicit causer the package falls back to the logged-in
        // user — wrong for e.g. a failed login, which has no actor.
        $causer === null ? $logger->causedByAnonymous() : $logger->causedBy($causer);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        /** @var Activity */
        return $logger->log($event->label());
    }

    /**
     * Filters are one equality (event, or else log_name) plus a created_at
     * range, ordered by created_at — matching INDEX(event, created_at) /
     * INDEX(log_name, created_at) / INDEX(created_at). No `log_name IN
     * (auth, admin)` guard: this class is the table's only writer, and that
     * IN forced a filesort on the unfiltered page.
     */
    public function paginate(AuditLogFilterData $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    public function latest(AuditLogFilterData $filters, int $limit): Collection
    {
        return $this->query($filters)->limit($limit)->get();
    }

    public function count(AuditLogFilterData $filters): int
    {
        return $this->query($filters)->toBase()->getCountForPagination();
    }

    /**
     * @return Builder<Activity>
     */
    private function query(AuditLogFilterData $filters): Builder
    {
        return Activity::query()
            ->select(['id', 'log_name', 'event', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'created_at'])
            ->when($filters->event, fn ($query, AuditEvent $event) => $query->where('event', $event->value))
            ->when($filters->event === null && $filters->logName !== null, fn ($query) => $query->where('log_name', $filters->logName))
            ->when($filters->from, fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($filters->to, fn ($query, $to) => $query->where('created_at', '<=', $to))
            ->with(['causer:id,name', 'subject:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
