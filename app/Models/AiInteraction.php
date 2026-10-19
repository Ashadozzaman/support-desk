<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiFeature;
use App\Enums\AiInteractionStatus;
use Database\Factories\AiInteractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row for one AI provider call (ENHANCEMENTS.md §3.2 rule 5) — written
 * by AiInteractionLogger. No prompt or output text, only a hash of the prompt.
 */
#[Fillable([
    'feature', 'model', 'user_id', 'ticket_id', 'tokens_in', 'tokens_out',
    'latency_ms', 'status', 'outcome', 'error_type', 'prompt_hash', 'created_at',
])]
class AiInteraction extends Model
{
    /** @use HasFactory<AiInteractionFactory> */
    use HasFactory, MassPrunable;

    // Append-only apart from `outcome`, which has no need for its own timestamp.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'status' => AiInteractionStatus::class,
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Swept by the existing daily `model:prune` schedule (same as
     * ReportExport) rather than a separate ai:prune command. MassPrunable:
     * there is no file to delete per row, so one range DELETE on
     * INDEX(created_at) is enough.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('ai.retention_days')));
    }
}
