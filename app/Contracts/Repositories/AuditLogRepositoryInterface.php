<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTOs\AuditLogFilterData;
use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

interface AuditLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function create(AuditEvent $event, ?User $causer, ?Model $subject, array $properties): Activity;

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    public function paginate(AuditLogFilterData $filters, int $perPage): LengthAwarePaginator;

    /**
     * @return Collection<int, Activity>
     */
    public function latest(AuditLogFilterData $filters, int $limit): Collection;

    public function count(AuditLogFilterData $filters): int;
}
