<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SlaPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read SlaPolicy $resource
 *
 * @mixin SlaPolicy
 */
class SlaPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'priority' => $this->priority,
            'resolution_minutes' => $this->resolution_minutes,
            'warning_threshold_percent' => $this->warning_threshold_percent,
            'is_active' => $this->is_active,
        ];
    }
}
