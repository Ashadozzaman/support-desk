<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'status', 'original_filename', 'disk_path',
    'total_rows', 'processed_rows', 'success_rows', 'failed_rows',
])]
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
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
     * @return HasMany<ImportError, $this>
     */
    public function importErrors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }
}
