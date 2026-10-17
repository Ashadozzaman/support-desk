<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AgentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'is_available', 'max_active_tickets', 'last_assigned_at'])]
class AgentProfile extends Model
{
    /** @use HasFactory<AgentProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'last_assigned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
