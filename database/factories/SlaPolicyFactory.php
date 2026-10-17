<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaPolicy>
 */
class SlaPolicyFactory extends Factory
{
    protected $model = SlaPolicy::class;

    public function definition(): array
    {
        return [
            'priority' => fake()->randomElement(TicketPriority::cases())->value,
            'resolution_minutes' => fake()->numberBetween(60, 2880),
            'warning_threshold_percent' => 80,
            'is_active' => true,
        ];
    }
}
