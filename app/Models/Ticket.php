<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
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
     * Minimal equality filters; FULLTEXT keyword search and SLA-state
     * predicates are added in Step 12 once TicketFilterData exists.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, $priority) => $q->where('priority', $priority))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['assigned_agent_id'] ?? null, fn (Builder $q, $agentId) => $q->where('assigned_agent_id', $agentId))
            ->when($filters['created_by'] ?? null, fn (Builder $q, $createdBy) => $q->where('created_by', $createdBy));
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
