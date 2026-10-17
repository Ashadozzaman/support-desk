<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Import;
use App\Models\ImportError;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportError>
 */
class ImportErrorFactory extends Factory
{
    protected $model = ImportError::class;

    public function definition(): array
    {
        return [
            'import_id' => Import::factory(),
            'row_number' => fake()->numberBetween(1, 1000),
            'errors' => ['subject' => ['The subject field is required.']],
            'raw_row' => ['subject' => '', 'description' => fake()->sentence()],
        ];
    }
}
