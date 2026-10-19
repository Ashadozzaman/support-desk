<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The token owner, so a client knows which actions to offer. Permissions
 * are a hint only — every endpoint is still authorized on the server.
 *
 * @property-read User $resource
 *
 * @mixin User
 */
class ApiUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            /** @var list<string> */
            'roles' => $this->getRoleNames()->values()->all(),
            /** @var list<string> */
            'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
