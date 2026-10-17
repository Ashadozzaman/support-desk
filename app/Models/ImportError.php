<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImportErrorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['import_id', 'row_number', 'errors', 'raw_row'])]
class ImportError extends Model
{
    /** @use HasFactory<ImportErrorFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'raw_row' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
