<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BusinessHour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BusinessHour $resource
 *
 * @mixin BusinessHour
 */
class BusinessHourResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'day_of_week' => $this->day_of_week,
            'is_working_day' => $this->is_working_day,
            'start_time' => $this->start_time === null ? null : substr($this->start_time, 0, 5),
            'end_time' => $this->end_time === null ? null : substr($this->end_time, 0, 5),
        ];
    }
}
