<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Import $resource
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'success_rows' => $this->success_rows,
            'failed_rows' => $this->failed_rows,
            'created_at' => $this->created_at,
            'errors' => $this->whenLoaded('importErrors', fn () => $this->importErrors->take(50)->map(fn ($error) => [
                'row_number' => $error->row_number,
                'errors' => $error->errors,
            ])->values()),
        ];
    }
}
