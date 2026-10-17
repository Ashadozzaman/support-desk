<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

class SlaPolicySeeder extends Seeder
{
    /**
     * Minutes are business minutes (ARCHITECTURE.md §8.1): Low 48h, Medium 24h, High 8h, Critical 2h.
     */
    public function run(): void
    {
        $resolutionMinutesByPriority = [
            TicketPriority::Low->value => 2880,
            TicketPriority::Medium->value => 1440,
            TicketPriority::High->value => 480,
            TicketPriority::Critical->value => 120,
        ];

        foreach ($resolutionMinutesByPriority as $priority => $minutes) {
            SlaPolicy::query()->updateOrCreate(
                ['priority' => $priority],
                [
                    'resolution_minutes' => $minutes,
                    'warning_threshold_percent' => 80,
                    'is_active' => true,
                ],
            );
        }
    }
}
