<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BusinessHour;
use Illuminate\Database\Seeder;

class BusinessHourSeeder extends Seeder
{
    /**
     * Default business week: Sun-Thu 09:00-18:00 (ARCHITECTURE.md §8.1). day_of_week: 0 = Sunday ... 6 = Saturday.
     */
    public function run(): void
    {
        $workingDays = [0, 1, 2, 3, 4];

        for ($day = 0; $day <= 6; $day++) {
            $isWorkingDay = in_array($day, $workingDays, true);

            BusinessHour::query()->updateOrCreate(
                ['day_of_week' => $day],
                [
                    'is_working_day' => $isWorkingDay,
                    'start_time' => $isWorkingDay ? '09:00:00' : null,
                    'end_time' => $isWorkingDay ? '18:00:00' : null,
                ],
            );
        }
    }
}
