<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\TicketFilterData;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ticket_number', 'subject', 'description', 'status', 'priority', 'category_id',
    'created_by', 'assigned_agent_id', 'external_ref', 'sla_due_at', 'sla_warning_at',
    'sla_warning_notified_at', 'sla_breach_notified_at', 'escalated_at', 'escalation_level',
    'first_response_at', 'last_reply_at', 'resolved_at', 'sla_met', 'version', 'assigned_at',
    'first_response_minutes', 'resolution_minutes', 'reopened_count', 'source',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * Ticket URLs use the ticket number, not the auto-increment id (ARCHITECTURE.md §3).
     */
    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'source' => TicketSource::class,
            'sla_due_at' => 'datetime',
            'sla_warning_at' => 'datetime',
            'sla_warning_notified_at' => 'datetime',
            'sla_breach_notified_at' => 'datetime',
            'escalated_at' => 'datetime',
            'first_response_at' => 'datetime',
            'last_reply_at' => 'datetime',
            'resolved_at' => 'datetime',
            'assigned_at' => 'datetime',
            'sla_met' => 'boolean',
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'reopened_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /**
     * @return HasMany<TicketReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * @return HasMany<TicketActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }

    public function currentSlaState(): ?SlaState
    {
        return SlaState::resolve($this->status, $this->sla_met, $this->sla_due_at, $this->sla_warning_at);
    }

    /**
     * SLA filter predicates (ARCHITECTURE.md §8.3), consumed by the search
     * filters added in Step 12.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeSlaState(Builder $query, SlaState $state): Builder
    {
        return match ($state) {
            SlaState::WithinSla => $query->active()->where('sla_warning_at', '>', now()),
            SlaState::Approaching => $query->active()->where('sla_warning_at', '<=', now())->where('sla_due_at', '>', now()),
            SlaState::Breached => $query->active()->where('sla_due_at', '<=', now()),
            SlaState::Met => $query->resolved()->where('sla_met', true),
            SlaState::MetLate => $query->resolved()->where('sla_met', false),
        };
    }

    /**
     * Restricts the query to tickets the given user is allowed to see
     * (ARCHITECTURE.md §3 enforcement point 3). Placeholder: the permission
     * checks below resolve to false until spatie/laravel-permission and the
     * permission matrix are wired up in Step 3; ownership-only fallback is
     * safe in the meantime because it under-shows rather than leaks rows.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if ($viewer->can('tickets.view-any')) {
            return $query;
        }

        if ($viewer->can('tickets.view-queue')) {
            return $query->where(function (Builder $q) use ($viewer) {
                $q->where('assigned_agent_id', $viewer->id)->orWhereNull('assigned_agent_id');
            });
        }

        return $query->where('created_by', $viewer->id);
    }

    /**
     * TicketFilterData → WHERE clauses (ARCHITECTURE.md §12). `ticket_number`
     * uses a left-anchored LIKE so it stays index-friendly for both an exact
     * number and a year/prefix search; keyword uses the FULLTEXT index in
     * boolean mode (LIKE fallback behind `support.search.fulltext` for
     * engines without FULLTEXT). Callers must apply `visibleTo()` first.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeFilter(Builder $query, TicketFilterData $filters): Builder
    {
        return $query
            ->when(
                $filters->ticketNumber,
                fn (Builder $q, string $number) => $q->where('ticket_number', 'like', $number.'%'),
            )
            ->when($filters->keyword, function (Builder $q, string $keyword) {
                if (config('support.search.fulltext')) {
                    $q->whereFullText(['subject', 'description'], $keyword, ['mode' => 'boolean']);
                } else {
                    $q->where(function (Builder $sub) use ($keyword) {
                        $sub->where('subject', 'like', "%{$keyword}%")
                            ->orWhere('description', 'like', "%{$keyword}%");
                    });
                }
            })
            ->when($filters->status, fn (Builder $q, TicketStatus $status) => $q->where('status', $status->value))
            ->when($filters->priority, fn (Builder $q, TicketPriority $priority) => $q->where('priority', $priority->value))
            ->when($filters->categoryId, fn (Builder $q, int $id) => $q->where('category_id', $id))
            ->when($filters->assignedAgentId, fn (Builder $q, int $id) => $q->where('assigned_agent_id', $id))
            ->when($filters->createdBy, fn (Builder $q, int $id) => $q->where('created_by', $id))
            ->when($filters->createdFrom, fn (Builder $q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters->createdTo, fn (Builder $q, $to) => $q->where('created_at', '<=', $to))
            ->when($filters->slaState, fn (Builder $q, SlaState $state) => $q->slaState($state));
    }

    /**
     * "Upcoming/active" tickets (ARCHITECTURE.md §5).
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::Open->value,
            TicketStatus::InProgress->value,
            TicketStatus::WaitingForCustomer->value,
        ]);
    }

    /**
     * "Resolved/closed" tickets (ARCHITECTURE.md §5).
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::Resolved->value,
            TicketStatus::Closed->value,
        ]);
    }
}
