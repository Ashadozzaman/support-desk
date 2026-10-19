<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailboxSyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'triggered_by', 'status', 'fetched_count', 'created_count', 'replied_count', 'skipped_count',
    'duplicate_count', 'failed_count', 'error', 'finished_at',
])]
class MailboxSync extends Model
{
    use MassPrunable;

    /** A sync can run every minute; history older than this isn't useful. */
    private const RETENTION_DAYS = 30;

    protected function casts(): array
    {
        return [
            'status' => MailboxSyncStatus::class,
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
