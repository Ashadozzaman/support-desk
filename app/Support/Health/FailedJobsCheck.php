<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Jobs that exhausted their retries in the last 24 hours. A few is a
 * warning (alerts already went out via AlertOnFailedJob); many means
 * something systemic.
 */
class FailedJobsCheck extends Check
{
    protected int $failWhenAtLeast = 10;

    public function failWhenAtLeast(int $count): self
    {
        $this->failWhenAtLeast = $count;

        return $this;
    }

    public function run(): Result
    {
        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        $result = Result::make()->meta(['last_24h' => $count])->shortSummary("{$count} in 24h");

        return match (true) {
            $count >= $this->failWhenAtLeast => $result->failed("{$count} jobs failed in the last 24 hours."),
            $count > 0 => $result->warning("{$count} jobs failed in the last 24 hours."),
            default => $result->ok(),
        };
    }
}
