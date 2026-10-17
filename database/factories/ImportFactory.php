<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    protected $model = Import::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => ImportStatus::Pending->value,
            'original_filename' => 'tickets.csv',
            'disk_path' => 'imports/'.fake()->uuid().'.csv',
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
        ];
    }
}
