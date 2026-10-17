<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    public function definition(): array
    {
        return [
            'date' => fake()->unique()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'name' => ucfirst(fake()->words(2, true)),
        ];
    }
}
