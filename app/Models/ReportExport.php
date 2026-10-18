<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportExportStatus;
use Database\Factories\ReportExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'status', 'filters', 'row_count', 'disk_path', 'expires_at'])]
class ReportExport extends Model
{
    /** @use HasFactory<ReportExportFactory> */
    use HasFactory, Prunable;

    protected function casts(): array
    {
        return [
            'status' => ReportExportStatus::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'expires_at' => 'datetime',
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
     * Swept by the existing daily `model:prune` schedule — no separate
     * exports:prune command needed.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }

    /**
     * Prunable (not MassPrunable) so each row's file is deleted with it.
     */
    protected function pruning(): void
    {
        if ($this->disk_path !== null) {
            Storage::disk('local')->delete($this->disk_path);
        }
    }
}
