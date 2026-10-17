<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BusinessHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHour>
 */
class BusinessHourFactory extends Factory
{
    protected $model = BusinessHour::class;

    public function definition(): array
    {
        return [
            'day_of_week' => fake()->numberBetween(0, 6),
            'is_working_day' => true,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ];
    }
}
