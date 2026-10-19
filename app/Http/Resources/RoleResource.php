<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Role as RoleEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Expects `permissions` and `users_count` loaded by RoleRepository.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => Str::headline($this->name),
            'is_system' => RoleEnum::isSystem($this->name),
            'is_locked' => $this->name === RoleEnum::Admin->value,
            'users_count' => (int) $this->users_count,
            'permissions' => $this->permissions->pluck('name')->values()->all(),
        ];
    }
}
