<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketPriority;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['priority', 'resolution_minutes', 'warning_threshold_percent', 'is_active'])]
class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'is_active' => 'boolean',
        ];
    }
}
