<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentProfile>
 */
class AgentProfileFactory extends Factory
{
    protected $model = AgentProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'is_available' => true,
            'max_active_tickets' => 10,
            'last_assigned_at' => null,
        ];
    }
}
