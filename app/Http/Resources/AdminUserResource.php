<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as the admin Users list/edit form sees them. Expects roles,
 * agentProfile and categories to be eager-loaded by the repository.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->agentProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->roles->first()?->name,
            'is_active' => $this->is_active,
            'agent' => $profile === null ? null : [
                'is_available' => $profile->is_available,
                'max_active_tickets' => $profile->max_active_tickets,
                'category_ids' => $this->categories->pluck('id')->all(),
            ],
            'created_at' => $this->created_at,
        ];
    }
}
