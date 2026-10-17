<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityEvent;
use Database\Factories\TicketActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'actor_id', 'event', 'old_values', 'new_values', 'metadata', 'created_at'])]
class TicketActivity extends Model
{
    /** @use HasFactory<TicketActivityFactory> */
    use HasFactory;

    /**
     * Append-only audit row — there is no updated_at column.
     */
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event' => ActivityEvent::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Null means a system actor (e.g. scheduler-triggered escalation).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
