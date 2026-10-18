<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => ReportExportStatus::Pending->value,
            'filters' => ['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()],
            'row_count' => 0,
            'disk_path' => null,
            'expires_at' => now()->addDays(7),
        ];
    }
}
