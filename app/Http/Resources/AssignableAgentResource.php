<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One option in the AssignAgentDialog: who the agent is and how loaded they
 * are right now.
 *
 * @property-read User $resource
 *
 * @mixin User
 */
class AssignableAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'active_tickets_count' => (int) $this->active_tickets_count,
            'max_active_tickets' => $this->agentProfile->max_active_tickets,
            'is_available' => $this->agentProfile->is_available,
        ];
    }
}
